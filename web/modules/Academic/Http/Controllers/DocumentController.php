<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Academic\Models\AdmissionApplication;
use Modules\Academic\Models\Student;
use Modules\Academic\Models\StudentDocument;
use Modules\Academic\Services\DocumentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Upload, view, verify and remove supporting documents and photos. */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $docs)
    {
    }

    public function uploadForApplication(Request $request, AdmissionApplication $admission): RedirectResponse
    {
        $doc = $this->docs->upload($admission, (string) $request->input('type'), $request->file('file'));

        return $this->done("{$doc->label()} uploaded and marked as received.");
    }

    public function uploadForStudent(Request $request, Student $student): RedirectResponse
    {
        $doc = $this->docs->upload($student, (string) $request->input('type'), $request->file('file'));

        return $this->done($doc->type === 'photo' ? 'Photo updated.' : "{$doc->label()} uploaded.");
    }

    public function show(Request $request, StudentDocument $document): StreamedResponse
    {
        $this->mayView($document);

        return $this->docs->stream($document, $request->boolean('download'));
    }

    public function photo(Student $student): StreamedResponse
    {
        $this->member()->mustSeeStudent($student);

        return $this->docs->photo($student);
    }

    public function verify(StudentDocument $document): RedirectResponse
    {
        $this->mayChange($document);
        $this->docs->verify($document);

        return $this->done("{$document->label()} marked as verified against the original.");
    }

    public function destroy(StudentDocument $document): RedirectResponse
    {
        $this->mayChange($document);
        $this->docs->remove($document);

        return $this->done("{$document->label()} removed.");
    }

    /** Students' files follow the student's visibility; files only on an application need admissions access. */
    private function mayView(StudentDocument $doc): void
    {
        if ($doc->student_id) {
            $this->member()->mustSeeStudent($doc->student);
        } else {
            $this->member()->authorize('admissions.manage');
        }
    }

    private function mayChange(StudentDocument $doc): void
    {
        $this->member()->authorize($doc->student_id ? 'students.manage' : 'admissions.manage');
    }
}
