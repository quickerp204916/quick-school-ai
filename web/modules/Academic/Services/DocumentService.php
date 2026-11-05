<?php

namespace Modules\Academic\Services;

use App\Core\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Academic\Exceptions\AcademicRuleException;
use Modules\Academic\Models\AdmissionApplication;
use Modules\Academic\Models\Student;
use Modules\Academic\Models\StudentDocument;
use Modules\Academic\Support\Audit;
use Modules\Academic\Support\Rules;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Supporting documents and photos for admissions and student records.
 *
 * - One current file per document type: uploading again replaces the old file.
 * - Files are private: stored under tenants/{tenant_id}/ and served only through the app, after the
 *   same permission checks as the record they belong to.
 * - Uploading a document marks it as received on the application's checklist; admitting the student
 *   carries every file over to the student record, and the photo becomes the student photo.
 */
final class DocumentService
{
    /** Checks one file without saving it, so a form can validate every upload before creating anything. */
    public function validate(string $type, mixed $file, string $field = 'file'): void
    {
        $isPhoto = $type === 'photo';
        $cfg = config('academic.uploads');
        $label = config("academic.admission.documents.{$type}", 'File');
        $v = \Illuminate\Support\Facades\Validator::make(['type' => $type, 'file' => $file], [
            'type' => ['required', Rule::in(array_keys(config('academic.admission.documents')))],
            'file' => $isPhoto
                ? ['required', 'file', 'mimes:'.implode(',', $cfg['photo_mimes']), 'max:'.$cfg['photo_max_kb'], "dimensions:min_width={$cfg['photo_min_px']},min_height={$cfg['photo_min_px']}"]
                : ['required', 'file', 'mimes:'.implode(',', $cfg['document_mimes']), 'max:'.$cfg['document_max_kb']],
        ], [
            'file.required' => 'Choose a file to upload.',
            'file.mimes' => $isPhoto ? 'The photo must be a JPG, PNG or WebP image.' : 'Upload a PDF, JPG, PNG or WebP file.',
            'file.max' => $isPhoto ? 'The photo must be 2 MB or smaller.' : 'The file must be 5 MB or smaller.',
            'file.dimensions' => "The photo must be at least {$cfg['photo_min_px']} × {$cfg['photo_min_px']} pixels.",
            'file.uploaded' => 'The file could not be uploaded. It may be larger than the server allows.',
            'type.in' => 'Choose a document type from the list.',
        ]);
        if ($v->fails()) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => $label.': '.$v->errors()->first()]);
        }
    }

    public function upload(AdmissionApplication|Student $owner, string $type, mixed $file, string $field = 'file'): StudentDocument
    {
        $this->validate($type, $file, $field);
        $cfg = config('academic.uploads');
        /** @var UploadedFile $file */
        if ($owner instanceof AdmissionApplication && ! $owner->isOpen()) {
            throw new AcademicRuleException('This application is closed. Add documents to the student record instead.', 'file');
        }

        $folder = $owner instanceof AdmissionApplication ? "admissions/{$owner->id}" : "students/{$owner->id}";
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = 'tenants/'.TenantContext::id()."/{$folder}/{$type}-".Str::uuid().".{$ext}";
        $disk = Storage::disk($cfg['disk']);
        $disk->putFileAs(dirname($path), $file, basename($path));

        return DB::transaction(function () use ($owner, $type, $file, $path, $disk) {
            $column = $owner instanceof AdmissionApplication ? 'application_id' : 'student_id';
            $old = StudentDocument::where($column, $owner->id)->where('type', $type)->get();

            $doc = StudentDocument::create([
                $column => $owner->id,
                'student_id' => $owner instanceof Student ? $owner->id : $owner->student_id,
                'type' => $type,
                'original_name' => Str::limit(preg_replace('/[^\pL\pN ._()-]+/u', '_', $file->getClientOriginalName()) ?: $type, 180, ''),
                'path' => $path, 'mime' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => request()->user()?->getAuthIdentifier(),
            ]);
            foreach ($old as $o) {
                $o->delete();
                DB::afterCommit(fn () => $disk->delete($o->path));
            }
            if ($owner instanceof AdmissionApplication) {
                $owner->update(['documents' => [$type => true] + ($owner->documents ?? [])]);
            }
            if ($type === 'photo' && $owner instanceof Student) {
                $owner->update(['photo_path' => $path]);
            }
            Audit::record('document.uploaded', $owner, ['type' => $type, 'name' => $doc->original_name, 'replaced' => $old->count()]);

            return $doc;
        });
    }

    public function remove(StudentDocument $doc): void
    {
        if ($doc->application_id && ! $doc->student_id && ! $doc->application->isOpen()) {
            throw new AcademicRuleException('This application is closed, so its documents cannot be removed.');
        }
        DB::transaction(function () use ($doc) {
            $doc->delete();
            if ($doc->application_id && ! $doc->student_id) {
                $app = $doc->application;
                $app->update(['documents' => [$doc->type => false] + ($app->documents ?? [])]);
            }
            if ($doc->type === 'photo' && $doc->student_id) {
                Student::whereKey($doc->student_id)->where('photo_path', $doc->path)->update(['photo_path' => null]);
            }
            Audit::record('document.removed', $doc, ['type' => $doc->type, 'name' => $doc->original_name]);
        });
        Storage::disk(config('academic.uploads.disk'))->delete($doc->path);
    }

    public function verify(StudentDocument $doc): void
    {
        $doc->update(['verified_at' => now(), 'verified_by' => request()->user()?->getAuthIdentifier()]);
        Audit::record('document.verified', $doc, ['type' => $doc->type]);
    }

    /** Called when an application is admitted: the files now belong to the student too. */
    public function attachToStudent(AdmissionApplication $app, Student $student): void
    {
        StudentDocument::where('application_id', $app->id)->update(['student_id' => $student->id]);
        if ($photo = StudentDocument::where('application_id', $app->id)->where('type', 'photo')->latest()->first()) {
            $student->update(['photo_path' => $photo->path]);
        }
    }

    /** Streams the file inline (images and PDFs open in the browser) or as a download. */
    public function stream(StudentDocument $doc, bool $download = false): StreamedResponse
    {
        $disk = Storage::disk(config('academic.uploads.disk'));
        abort_unless($disk->exists($doc->path), 404, 'The file is missing from storage.');
        $name = $doc->label().' - '.$doc->original_name;

        return $disk->response($doc->path, $name, [
            'Content-Type' => $doc->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $download || ! ($doc->isImage() || $doc->mime === 'application/pdf') ? 'attachment' : 'inline');
    }

    /** The student photo, or 404. */
    public function photo(Student $student): StreamedResponse
    {
        $doc = $student->photo_path ? StudentDocument::where('student_id', $student->id)->where('path', $student->photo_path)->first() : null;
        abort_unless($doc, 404, 'No photo yet.');

        return $this->stream($doc);
    }
}
