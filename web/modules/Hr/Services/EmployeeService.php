<?php

namespace Modules\Hr\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\CurrentMember;
use Modules\Hr\HrException;
use Modules\Hr\Models\EmployeeDocument;
use Modules\Hr\Models\EmployeeProfile;
use Modules\Hr\Models\SalaryStructure;
use Modules\Hr\Models\SalaryComponent;

/** The employee master: staff record, HR profile, documents and salary revisions. */
final class EmployeeService
{
    public function __construct(private readonly CurrentMember $member)
    {
    }

    /** Creates or updates a staff member and their HR profile. */
    public function save(array $d, ?StaffMember $staff = null): StaffMember
    {
        $this->member->authorize('hr.manage');
        foreach (['name' => 'Enter the employee\'s name.', 'employee_code' => 'Enter an employee code.'] as $k => $msg) {
            if (blank($d[$k] ?? null)) {
                throw new HrException($msg, $k);
            }
        }
        $pan = strtoupper(trim((string) ($d['pan'] ?? '')));
        if ($pan !== '' && ! preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $pan)) {
            throw new HrException('PAN must look like ABCDE1234F.', 'pan');
        }
        $ifsc = strtoupper(trim((string) ($d['bank_ifsc'] ?? '')));
        if ($ifsc !== '' && ! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
            throw new HrException('IFSC must look like HDFC0001234.', 'bank_ifsc');
        }
        $account = preg_replace('/\s+/', '', (string) ($d['bank_account'] ?? ''));
        if ($account !== '' && ! preg_match('/^\d{9,18}$/', $account)) {
            throw new HrException('The bank account number must be 9 to 18 digits.', 'bank_account');
        }
        if (filled($d['uan'] ?? null) && ! preg_match('/^\d{12}$/', $d['uan'])) {
            throw new HrException('UAN is 12 digits.', 'uan');
        }
        if (StaffMember::where('employee_code', $d['employee_code'])->when($staff, fn ($q) => $q->whereKeyNot($staff->id))->exists()) {
            throw new HrException('Another employee already has this code.', 'employee_code');
        }

        return DB::transaction(function () use ($d, $staff, $pan, $ifsc, $account) {
            $staff ??= new StaffMember(['status' => 'active']);
            $staff->fill(array_intersect_key($d, array_flip(['employee_code', 'name', 'email', 'phone', 'joined_on', 'branch_id'])) + ['is_teaching' => (bool) ($d['is_teaching'] ?? false)]);
            $staff->save();
            $p = EmployeeProfile::firstOrNew(['staff_member_id' => $staff->id]);
            $fields = array_intersect_key($d, array_flip(['department_id', 'designation_id', 'shift_id', 'employment_type', 'date_of_birth', 'gender', 'uan', 'esi_number',
                'bank_name', 'account_holder', 'pt_state', 'tax_regime', 'notice_days']));
            $p->fill(array_map(fn ($v) => $v === '' ? null : $v, $fields) + ['pf_applicable' => (bool) ($d['pf_applicable'] ?? true), 'esi_applicable' => (bool) ($d['esi_applicable'] ?? true)]);
            if (array_key_exists('declared_80c', $d)) {
                $p->declared_80c = \Modules\Accounting\Support\Amount::parse($d['declared_80c']);
            }
            // Secrets are kept unless a new value is typed.
            if ($pan !== '') {
                $p->pan = $pan;
            }
            if ($account !== '') {
                $p->bank_account = $account;
            }
            if ($ifsc !== '') {
                $p->bank_ifsc = $ifsc;
            }
            $staff->designation = $p->designation?->name ?? $staff->designation;
            $staff->department = $p->department?->name ?? $staff->department;
            $staff->save();
            $p->save();
            Audit::record($p->wasRecentlyCreated ? 'hr.employee_created' : 'hr.employee_updated', $staff);

            return $staff;
        });
    }

    /** A salary revision from a date. Earlier revisions stay as history. */
    public function revise(StaffMember $staff, array $d): SalaryStructure
    {
        $this->member->authorize('payroll.configure');
        $from = $d['effective_from'] ?? null;
        if (! $from || ! strtotime($from)) {
            throw new HrException('Enter the date the salary applies from.', 'effective_from');
        }
        $approvedUpTo = \Modules\Hr\Models\PayrollRun::whereIn('status', ['approved', 'paid'])->max('period');
        if ($approvedUpTo && substr($from, 0, 7) <= $approvedUpTo) {
            throw new HrException("Payroll up to {$approvedUpTo} is approved. A revision can start from the next month; pay arrears as a one-off bonus.", 'effective_from');
        }
        $allowed = SalaryComponent::where('kind', 'earning')->where('is_computed', false)->where('code', '!=', 'BONUS')->pluck('code')->all();
        $c = [];
        foreach ((array) ($d['components'] ?? []) as $code => $amount) {
            $p = \Modules\Accounting\Support\Amount::parse($amount);
            if ($p > 0 && in_array($code, $allowed, true)) {
                $c[$code] = $p;
            }
        }
        if (($c['BASIC'] ?? 0) <= 0) {
            throw new HrException('Basic pay is required.', 'components');
        }
        $s = SalaryStructure::updateOrCreate(['staff_member_id' => $staff->id, 'effective_from' => $from],
            ['components' => $c, 'monthly_gross' => array_sum($c), 'note' => $d['note'] ?? null, 'created_by' => $this->member->userId()]);
        Audit::record('hr.salary_revised', $staff, ['from' => $from, 'gross' => array_sum($c)]);

        return $s;
    }

    public function upload(StaffMember $staff, string $type, string $title, UploadedFile $file, ?string $expires = null): EmployeeDocument
    {
        $this->member->authorize('hr.manage');
        if (! array_key_exists($type, config('hr.documents'))) {
            throw new HrException('Choose the document type.', 'type');
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, config('academic.uploads.document_mimes'), true) || ! in_array($file->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new HrException('Upload a PDF, JPG, PNG or WebP file.', 'file');
        }
        if ($file->getSize() > config('academic.uploads.document_max_kb') * 1024) {
            throw new HrException('The file is larger than '.(config('academic.uploads.document_max_kb') / 1024).' MB.', 'file');
        }
        $path = 'tenants/'.\App\Core\Tenancy\TenantContext::id().'/hr/'.$staff->id.'/'.Str::uuid().'.'.$ext;
        Storage::disk(config('academic.uploads.disk'))->putFileAs(dirname($path), $file, basename($path));
        $doc = EmployeeDocument::create(['staff_member_id' => $staff->id, 'type' => $type, 'title' => $title ?: config('hr.documents')[$type], 'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime' => $file->getMimeType(), 'size' => $file->getSize(),
            'expires_on' => $expires ?: null, 'uploaded_by' => $this->member->userId()]);
        Audit::record('hr.document_uploaded', $doc, ['type' => $type]);

        return $doc;
    }

    public function download(EmployeeDocument $doc)
    {
        $me = $this->member->staff();
        if (! $this->member->can('hr.manage') && $me?->id !== $doc->staff_member_id) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You cannot open this document.');
        }
        Audit::record('hr.document_viewed', $doc);
        $disk = Storage::disk(config('academic.uploads.disk'));
        abort_unless($disk->exists($doc->path), 404, 'The file is missing from storage.');

        return $disk->response($doc->path, $doc->original_name, ['Content-Type' => $doc->mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function deleteDocument(EmployeeDocument $doc): void
    {
        $this->member->authorize('hr.manage');
        $path = $doc->path;
        $doc->delete();
        DB::afterCommit(fn () => Storage::disk(config('academic.uploads.disk'))->delete($path));
        Audit::record('hr.document_deleted', null, ['title' => $doc->title, 'staff' => $doc->staff_member_id]);
    }

    /** Records resignation and relieving. Payroll stops after the relieving date; the full and final settlement pays the rest. */
    public function relieve(StaffMember $staff, array $d): EmployeeProfile
    {
        $this->member->authorize('hr.manage');
        if (blank($d['relieved_on'] ?? null)) {
            throw new HrException('Enter the relieving date.', 'relieved_on');
        }
        $p = EmployeeProfile::firstOrCreate(['staff_member_id' => $staff->id]);
        $p->update(['resigned_on' => $d['resigned_on'] ?? null ?: null, 'relieved_on' => $d['relieved_on'], 'exit_reason' => $d['exit_reason'] ?? null]);
        Audit::record('hr.employee_relieved', $staff, ['on' => $d['relieved_on']]);

        return $p;
    }
}
