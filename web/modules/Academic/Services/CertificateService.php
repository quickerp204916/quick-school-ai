<?php

namespace Modules\Academic\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Models\Certificate;
use Modules\Academic\Models\CertificateTemplate;
use Modules\Academic\Models\Student;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\Institution;
use Modules\Academic\Support\Numbering;
use Modules\Academic\Support\Rules;
use Modules\Academic\Support\Words;

/**
 * Certificates from editable templates with {placeholders}. Each issued certificate keeps a snapshot
 * of the text and data and a gapless number per type and financial year (e.g. TC/2026-27/0003).
 */
final class CertificateService
{
    public const PLACEHOLDERS = [
        'student_name' => 'Student’s full name', 'admission_no' => 'Admission number', 'father_name' => 'Father’s name',
        'mother_name' => 'Mother’s name', 'guardian_name' => 'Primary guardian', 'date_of_birth' => 'Date of birth (15 Aug 2012)',
        'date_of_birth_words' => 'Date of birth in words', 'gender' => 'Gender', 'son_daughter' => 'son / daughter / child',
        'he_she' => 'he / she / they', 'his_her' => 'his / her / their', 'his_her_cap' => 'His / Her / Their', 'him_her' => 'him / her / them',
        'class_section' => 'Current or last class and section', 'session' => 'Session of that class', 'admitted_on' => 'Admission date',
        'left_on' => 'Leaving date', 'leaving_reason' => 'Reason for leaving', 'conduct' => 'Conduct', 'nationality' => 'Nationality',
        'category' => 'Category', 'pen' => 'PEN (UDISE+)', 'institution_name' => 'Institution name', 'institution_address' => 'Institution address',
        'affiliation_no' => 'Affiliation number', 'principal_name' => 'Principal', 'certificate_no' => 'Certificate number',
        'issued_on' => 'Date of issue', 'purpose' => 'Purpose',
    ];

    public function saveTemplate(array $data, ?CertificateTemplate $template = null): CertificateTemplate
    {
        $d = Rules::check($data, [
            'type' => ['required', Rule::in(array_keys(config('academic.certificate_types')))],
            'name' => ['required', 'string', 'max:120', Rules::unique('certificate_templates', 'name', $template?->id)],
            'body' => ['required', 'string', 'max:10000'],
            'is_active' => ['boolean'],
        ]);
        preg_match_all('/\{([a-z_]+)\}/', $d['body'], $m);
        if ($unknown = array_diff(array_unique($m[1]), array_keys(self::PLACEHOLDERS))) {
            throw new AcademicRuleException('Unknown placeholders: {'.implode('}, {', $unknown).'}.', 'body');
        }
        $template ??= new CertificateTemplate();
        $template->fill($d)->save();

        return $template;
    }

    public function issue(Student $student, array $data): Certificate
    {
        $d = Rules::check($data, [
            'template_id' => ['required', Rules::exists('certificate_templates', ['is_active' => true])],
            'issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'purpose' => ['nullable', 'string', 'max:200'],
        ]);
        $template = CertificateTemplate::findOrFail($d['template_id']);

        if ($template->type === 'transfer') {
            if (! in_array($student->status, ['transferred', 'withdrawn', 'alumni'], true)) {
                throw new AcademicRuleException('Record that the student is leaving (Transfer or withdraw) before issuing a transfer certificate.');
            }
            if (Certificate::where('student_id', $student->id)->where('type', 'transfer')->where('status', 'issued')->exists()) {
                throw new AcademicRuleException('A transfer certificate has already been issued. Cancel it to issue a new one.');
            }
        }
        if (in_array($template->type, ['bonafide', 'study'], true) && $student->status !== 'active') {
            throw new AcademicRuleException('Bonafide and study certificates are only for current students.');
        }
        if ($template->type === 'bonafide' && empty($d['purpose'])) {
            throw new AcademicRuleException('Enter the purpose, for example “passport application”.', 'purpose');
        }

        return DB::transaction(function () use ($student, $template, $d) {
            $issuedOn = \Illuminate\Support\Carbon::parse($d['issued_on'] ?? today());
            $fy = \App\Core\Support\DocumentNumber::financialYear($issuedOn);
            $series = config("academic.certificate_types.{$template->type}.series", 'CER');
            $number = sprintf('%s/%s/%04d', $series, $fy, Numbering::next($series, $fy));

            $values = $this->values($student, $number, $issuedOn, $d['purpose'] ?? null);
            $body = strtr($template->body, collect($values)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => $v])->all());

            $cert = Certificate::create([
                'number' => $number, 'template_id' => $template->id, 'student_id' => $student->id, 'type' => $template->type,
                'issued_on' => $issuedOn, 'purpose' => $d['purpose'] ?? null, 'data' => $values + ['title' => $template->name],
                'body' => $body, 'status' => 'issued', 'issued_by' => request()->user()?->getAuthIdentifier(),
            ]);
            Audit::record('certificate.issued', $cert, ['number' => $number, 'student' => $student->admission_no]);

            return $cert;
        });
    }

    public function cancel(Certificate $cert, string $reason): void
    {
        if ($cert->status !== 'issued') {
            throw new AcademicRuleException('This certificate is already cancelled.');
        }
        if (trim($reason) === '') {
            throw new AcademicRuleException('Give a reason.', 'reason');
        }
        $cert->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
        Audit::record('certificate.cancelled', $cert, ['number' => $cert->number, 'reason' => $reason]);
    }

    /** All placeholder values for a student. */
    public function values(Student $student, string $number, \DateTimeInterface $issuedOn, ?string $purpose): array
    {
        $student->loadMissing('guardians');
        $last = $student->enrollments()->with(['section.level', 'session'])->first();
        $profile = Institution::profile();
        $g = $student->gender;
        $pick = fn (string $m, string $f, string $o) => $g === 'male' ? $m : ($g === 'female' ? $f : $o);
        $dash = '—';

        return [
            'student_name' => $student->fullName(), 'admission_no' => $student->admission_no,
            'father_name' => $student->guardianBy('father')?->name ?? $dash, 'mother_name' => $student->guardianBy('mother')?->name ?? $dash,
            'guardian_name' => $student->primaryGuardian()?->name ?? $dash,
            'date_of_birth' => $student->date_of_birth->format('j M Y'), 'date_of_birth_words' => Words::date($student->date_of_birth),
            'gender' => ucfirst($g), 'son_daughter' => $pick('son', 'daughter', 'child'), 'he_she' => $pick('he', 'she', 'they'),
            'his_her' => $pick('his', 'her', 'their'), 'his_her_cap' => $pick('His', 'Her', 'Their'), 'him_her' => $pick('him', 'her', 'them'),
            'class_section' => $last ? $last->section->label() : $dash, 'session' => $last?->session?->name ?? $dash,
            'admitted_on' => $student->admitted_on->format('j M Y'), 'left_on' => $student->left_on?->format('j M Y') ?? $dash,
            'leaving_reason' => $student->leaving_reason ?? $dash, 'conduct' => $student->conduct ?? 'Good',
            'nationality' => $student->nationality ?? 'Indian', 'category' => config('academic.categories.'.$student->category, $dash),
            'pen' => $student->pen ?? $dash, 'institution_name' => Institution::tenant()->name,
            'institution_address' => $profile->address ?? $dash, 'affiliation_no' => $profile->affiliation_no ?? $dash,
            'principal_name' => $profile->principal_name ?? 'Principal', 'certificate_no' => $number,
            'issued_on' => $issuedOn->format('j M Y'), 'purpose' => $purpose ?? $dash,
        ];
    }
}
