<?php

namespace Tests\Feature\Academic;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Academic\Models\AdmissionApplication;
use Modules\Academic\Models\Student;
use Modules\Academic\Models\StudentDocument;

/** Supporting documents and photos in the admission process and on student records. */
class AdmissionDocumentsTest extends AcademicTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function application(): AdmissionApplication
    {
        $section = $this->section();
        $this->asOwner()->post($this->at('admissions'), ['session_id' => $section->session_id, 'branch_id' => $section->branch_id, 'grade_level_id' => $section->grade_level_id,
            'first_name' => 'Meera', 'gender' => 'female', 'date_of_birth' => '2016-05-01', 'source' => 'walk_in', 'stage' => 'applied',
            'guardians' => [['name' => 'Suresh Rao', 'relation' => 'father', 'phone' => '9812345670']]])->assertSessionHasNoErrors();

        return $this->in(fn () => AdmissionApplication::latest()->firstOrFail());
    }

    private function upload(AdmissionApplication $app, string $type, UploadedFile $file): \Illuminate\Testing\TestResponse
    {
        return $this->post($this->at("admissions/{$app->id}/uploads"), ['type' => $type, 'file' => $file]);
    }

    public function test_uploads_mark_documents_received_and_admission_moves_them_to_the_student(): void
    {
        $app = $this->application();
        $this->upload($app, 'birth_certificate', UploadedFile::fake()->create('birth.pdf', 300, 'application/pdf'))->assertSessionHasNoErrors();
        $this->upload($app, 'photo', UploadedFile::fake()->image('meera.jpg', 400, 500))->assertSessionHasNoErrors();
        $this->upload($app, 'address_proof', UploadedFile::fake()->image('aadhaar-front.png', 1200, 800))->assertSessionHasNoErrors();

        $app = $this->in(fn () => $app->fresh());
        $this->assertSame([], $app->missingDocuments(['birth_certificate', 'photo', 'address_proof']));
        $docs = $this->in(fn () => StudentDocument::where('application_id', $app->id)->get());
        $this->assertCount(3, $docs);
        foreach ($docs as $d) {
            Storage::disk('local')->assertExists($d->path);
            $this->assertStringStartsWith("tenants/{$this->tenant->id}/admissions/{$app->id}/", $d->path);
            $this->assertSame(64, strlen($d->sha256));
        }
        $this->get($this->at("admissions/{$app->id}"))->assertOk()->assertSee('birth.pdf')->assertSee('meera.jpg');

        $section = $this->in(fn () => \Modules\Academic\Models\Section::firstOrFail());
        $this->post($this->at("admissions/{$app->id}/stage"), ['stage' => 'offered']);
        $this->post($this->at("admissions/{$app->id}/admit"), ['section_id' => $section->id])->assertSessionHasNoErrors();

        $student = $this->in(fn () => Student::firstOrFail());
        $photo = $docs->firstWhere('type', 'photo');
        $this->assertSame($photo->path, $student->photo_path);
        $this->assertSame(3, $this->in(fn () => StudentDocument::where('student_id', $student->id)->count()));
        $this->get($this->at("students/{$student->id}"))->assertOk()->assertSee('Documents and photo')->assertSee('birth.pdf');
        $this->get($this->at("students/{$student->id}/photo"))->assertOk()->assertHeader('content-type', 'image/jpeg');
    }

    public function test_upload_rules(): void
    {
        $app = $this->application();
        $this->upload($app, 'photo', UploadedFile::fake()->create('photo.pdf', 100, 'application/pdf'))->assertSessionHasErrors(['file' => 'Passport-size photo: The photo must be a JPG, PNG or WebP image.']);
        $this->upload($app, 'photo', UploadedFile::fake()->image('tiny.jpg', 80, 80))->assertSessionHasErrors('file');
        $this->upload($app, 'photo', UploadedFile::fake()->image('huge.jpg', 600, 800)->size(3000))->assertSessionHasErrors(['file' => 'Passport-size photo: The photo must be 2 MB or smaller.']);
        $this->upload($app, 'birth_certificate', UploadedFile::fake()->create('scan.exe', 10, 'application/x-msdownload'))->assertSessionHasErrors('file');
        $this->upload($app, 'birth_certificate', UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'))->assertSessionHasErrors(['file' => 'Birth certificate: The file must be 5 MB or smaller.']);
        $this->upload($app, 'passport', UploadedFile::fake()->create('p.pdf', 10, 'application/pdf'))->assertSessionHasErrors('file');
        $this->post($this->at("admissions/{$app->id}/uploads"), ['type' => 'photo'])->assertSessionHasErrors(['file' => 'Passport-size photo: Choose a file to upload.']);
        $this->assertSame(0, $this->in(fn () => StudentDocument::count()));
    }

    public function test_uploading_again_replaces_the_file_and_removing_clears_the_checklist(): void
    {
        $app = $this->application();
        $this->upload($app, 'birth_certificate', UploadedFile::fake()->create('first.pdf', 50, 'application/pdf'));
        $first = $this->in(fn () => StudentDocument::firstOrFail());
        $this->upload($app, 'birth_certificate', UploadedFile::fake()->create('second.pdf', 50, 'application/pdf'));

        $docs = $this->in(fn () => StudentDocument::get());
        $this->assertSame(['second.pdf'], $docs->pluck('original_name')->all());
        Storage::disk('local')->assertMissing($first->path);

        $this->post($this->at("documents/{$docs[0]->id}/verify"))->assertSessionHasNoErrors();
        $this->assertNotNull($this->in(fn () => $docs[0]->fresh()->verified_at));
        // The paper checklist cannot untick a document that has a file.
        $this->post($this->at("admissions/{$app->id}/documents"), ['documents' => []]);
        $this->assertTrue($this->in(fn () => $app->fresh()->documents['birth_certificate']));

        $this->delete($this->at("documents/{$docs[0]->id}"))->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($docs[0]->path);
        $this->assertFalse($this->in(fn () => $app->fresh()->documents['birth_certificate']));
    }

    public function test_files_can_be_attached_when_the_enquiry_is_created(): void
    {
        $section = $this->section();
        $this->asOwner()->post($this->at('admissions'), ['session_id' => $section->session_id, 'branch_id' => $section->branch_id, 'grade_level_id' => $section->grade_level_id,
            'first_name' => 'Kabir', 'source' => 'walk_in', 'guardians' => [['name' => 'Anil', 'relation' => 'father', 'phone' => '9812345671']],
            'uploads' => ['photo' => UploadedFile::fake()->image('kabir.png', 300, 400), 'previous_report' => UploadedFile::fake()->create('report.pdf', 80, 'application/pdf')]])->assertSessionHasNoErrors();
        $this->assertSame(['photo', 'previous_report'], $this->in(fn () => StudentDocument::orderBy('type')->pluck('type')->all()));

        // A bad file stops the whole form, so no half-saved enquiry is left behind.
        $this->post($this->at('admissions'), ['session_id' => $section->session_id, 'branch_id' => $section->branch_id, 'grade_level_id' => $section->grade_level_id,
            'first_name' => 'Bad', 'source' => 'walk_in', 'guardians' => [['name' => 'X', 'relation' => 'father', 'phone' => '9812345672']],
            'uploads' => ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]])->assertSessionHasErrors('uploads.photo');
        $this->assertSame(1, $this->in(fn () => AdmissionApplication::count()));
    }

    public function test_direct_admission_with_a_photo_and_later_uploads_on_the_student(): void
    {
        $section = $this->section();
        $this->asOwner()->post($this->at('students'), ['section_id' => $section->id, 'first_name' => 'Tara', 'gender' => 'female', 'date_of_birth' => '2015-02-02',
            'guardians' => [['name' => 'Ravi', 'relation' => 'father', 'phone' => '9812345673']], 'uploads' => ['photo' => UploadedFile::fake()->image('tara.jpg', 300, 380)]])->assertSessionHasNoErrors();
        $student = $this->in(fn () => Student::firstOrFail());
        $this->assertNotNull($student->photo_path);

        $this->post($this->at("students/{$student->id}/uploads"), ['type' => 'transfer_certificate', 'file' => UploadedFile::fake()->create('old-school-tc.pdf', 120, 'application/pdf')])->assertSessionHasNoErrors();
        $this->post($this->at("students/{$student->id}/uploads"), ['type' => 'photo', 'file' => UploadedFile::fake()->image('new.jpg', 300, 380)])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->in(fn () => StudentDocument::where('student_id', $student->id)->count()));
        $this->assertStringContainsString('/students/', $this->in(fn () => $student->fresh()->photo_path));
    }

    public function test_who_may_see_and_change_files(): void
    {
        $app = $this->application();
        $this->upload($app, 'birth_certificate', UploadedFile::fake()->create('birth.pdf', 50, 'application/pdf'));
        $doc = $this->in(fn () => StudentDocument::firstOrFail());

        $this->get($this->at("documents/{$doc->id}"))->assertOk()->assertHeader('content-disposition', 'inline; filename="Birth certificate - birth.pdf"')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->get($this->at("documents/{$doc->id}?download=1"))->assertOk()->assertHeader('content-disposition', 'attachment; filename="Birth certificate - birth.pdf"');

        $this->actingAs($this->member('teacher'), 'web');
        $this->get($this->at("documents/{$doc->id}"))->assertForbidden();
        $this->delete($this->at("documents/{$doc->id}"))->assertForbidden();

        $other = $this->provision();
        $this->actingAs($this->ownerUser($other), 'web')->get($this->at("documents/{$doc->id}", $other))->assertNotFound();
    }

    public function test_parent_sees_their_childs_photo_and_documents_in_the_api(): void
    {
        $section = $this->section();
        $mine = $this->student($section);
        $other = $this->student($section);
        $this->asOwner()->post($this->at("students/{$mine->id}/uploads"), ['type' => 'photo', 'file' => UploadedFile::fake()->image('me.jpg', 300, 380)]);
        $this->post($this->at("students/{$other->id}/uploads"), ['type' => 'photo', 'file' => UploadedFile::fake()->image('them.jpg', 300, 380)]);
        $parent = $this->member('parent');
        $this->in(fn () => \Modules\Academic\Models\Guardian::whereHas('students', fn ($q) => $q->whereKey($mine->id))->firstOrFail()->update(['user_id' => $parent->id]));
        $parent->forceFill(['password' => 'Secret-pass-123'])->save();
        $this->app['auth']->forgetGuards();
        $token = $this->postJson($this->api('auth/token'), ['email' => $parent->email, 'password' => 'Secret-pass-123', 'device_name' => 'Phone'])->json('token');

        $this->withToken($token)->getJson($this->api('me'))->assertJsonPath('students.0.photo_url', $this->api("students/{$mine->id}/photo"));
        $this->withToken($token)->get($this->api("students/{$mine->id}/photo"))->assertOk();
        $this->withToken($token)->get($this->api("students/{$other->id}/photo"))->assertForbidden();
        $this->withToken($token)->getJson($this->api("students/{$mine->id}/documents"))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'photo');
    }

    public function test_front_office_uploads_from_the_mobile_app(): void
    {
        $app = $this->application();
        $office = $this->member('front_office');
        $office->forceFill(['password' => 'Secret-pass-123'])->save();
        $this->app['auth']->forgetGuards();
        $token = $this->postJson($this->api('auth/token'), ['email' => $office->email, 'password' => 'Secret-pass-123', 'device_name' => 'Tablet'])->json('token');

        $this->withToken($token)->post($this->api("admissions/{$app->id}/documents"), ['type' => 'photo', 'file' => UploadedFile::fake()->image('cam.jpg', 600, 800)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.type', 'photo');
        $this->withToken($token)->post($this->api("admissions/{$app->id}/documents"), ['type' => 'photo', 'file' => UploadedFile::fake()->create('x.txt', 1, 'text/plain')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->withToken($token)->getJson($this->api("admissions/{$app->id}/documents"))->assertOk()->assertJsonPath('checklist.photo.received', true)->assertJsonPath('checklist.birth_certificate.required', true);
    }
}
