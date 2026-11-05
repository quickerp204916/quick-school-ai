<?php

namespace Tests\Feature\Academic;

use Modules\Academic\Models\AdmissionApplication;
use Modules\Academic\Models\Certificate;
use Modules\Academic\Models\CertificateTemplate;
use Modules\Academic\Models\Enrollment;
use Modules\Academic\Models\Guardian;
use Modules\Academic\Models\Student;
use Modules\Academic\Models\StudentTransfer;

class AdmissionsAndStudentsTest extends AcademicTestCase
{
    private function enquiry(array $extra = []): AdmissionApplication
    {
        $level = $this->level('Class 5');
        $this->asOwner()->post($this->at('admissions'), $extra + [
            'session_id' => $this->currentSession()->id, 'branch_id' => $this->section()->branch_id, 'grade_level_id' => $level->id,
            'first_name' => 'Aarav', 'last_name' => 'Sharma', 'gender' => 'male', 'date_of_birth' => '2016-02-10', 'source' => 'website',
            'guardians' => [['name' => 'Vikas Sharma', 'relation' => 'father', 'phone' => '9811122233', 'email' => 'vikas@family.test'], ['name' => '', 'phone' => '']],
            'stage' => 'applied',
        ])->assertSessionHasNoErrors();

        return $this->in(fn () => AdmissionApplication::latest()->firstOrFail());
    }

    public function test_admission_flow_from_application_to_student(): void
    {
        $app = $this->enquiry();
        $this->assertSame('applied', $app->stage);
        $this->assertMatchesRegularExpression('#^APP/2026-27/\d{5}$#', $app->number);
        $this->assertCount(1, $app->guardians, 'Empty second guardian row is dropped');

        // Admitting before an offer is refused, and so is a jump the stage rules do not allow.
        $section = $this->in(fn () => \Modules\Academic\Models\Section::firstOrFail());
        $this->post($this->at("admissions/{$app->id}/admit"), ['section_id' => $section->id])->assertSessionHasErrors('rule');
        $this->post($this->at("admissions/{$app->id}/stage"), ['stage' => 'admitted'])->assertSessionHasErrors();

        $this->post($this->at("admissions/{$app->id}/stage"), ['stage' => 'offered'])->assertSessionHasNoErrors();
        // Required documents (birth certificate, photo, address proof) must be in.
        $this->post($this->at("admissions/{$app->id}/admit"), ['section_id' => $section->id])->assertSessionHasErrors('documents');
        $this->post($this->at("admissions/{$app->id}/documents"), ['documents' => ['birth_certificate' => 1, 'photo' => 1, 'address_proof' => 1]]);

        $this->post($this->at("admissions/{$app->id}/admit"), ['section_id' => $section->id, 'roll_no' => 7])->assertSessionHasNoErrors();

        [$app, $student, $enrollment] = $this->in(fn () => [$app->fresh(), Student::firstOrFail(), Enrollment::firstOrFail()]);
        $this->assertSame('admitted', $app->stage);
        $this->assertSame($student->id, $app->student_id);
        $this->assertStringStartsWith('SPS', $student->admission_no);
        $this->assertSame([$section->id, 7, 'active'], [$enrollment->section_id, $enrollment->roll_no, $enrollment->status]);
        $this->assertSame('Vikas Sharma', $this->in(fn () => $student->primaryGuardian()->name));
    }

    public function test_rejecting_needs_a_reason(): void
    {
        $app = $this->enquiry();
        $this->post($this->at("admissions/{$app->id}/stage"), ['stage' => 'rejected'])->assertSessionHasErrors();
        $this->post($this->at("admissions/{$app->id}/stage"), ['stage' => 'rejected', 'reason' => 'Seats full'])->assertSessionHasNoErrors();
        $this->assertSame('Seats full', $this->in(fn () => $app->fresh()->rejection_reason));
    }

    public function test_admission_validation(): void
    {
        $this->asOwner()->post($this->at('admissions'), ['first_name' => '', 'guardians' => [['name' => 'X', 'relation' => 'father', 'phone' => '12345']]])
            ->assertSessionHasErrors(['first_name', 'session_id', 'grade_level_id', 'guardians.0.phone']);
    }

    public function test_siblings_share_one_guardian_record(): void
    {
        $section = $this->section();
        $a = $this->student($section, [], ['phone' => '9822200011']);
        $b = $this->student($this->section('Class 3'), ['first_name' => 'Younger'], ['phone' => '9822200011', 'name' => 'Ramesh Kumar']);

        $this->assertSame(1, $this->in(fn () => Guardian::where('phone', '9822200011')->count()));
        $this->asOwner()->get($this->at('guardians?siblings=1'))->assertOk()->assertSee($a->fullName())->assertSee('Younger');
    }

    public function test_capacity_and_roll_numbers(): void
    {
        $section = $this->section('Class 5', 'A', ['capacity' => 2]);
        $first = $this->student($section, ['roll_no' => 1]);
        $this->student($section);

        $this->expectExceptionMessage('full');
        $this->student($section);
    }

    public function test_roll_numbers_are_unique_in_a_section(): void
    {
        $section = $this->section();
        $this->student($section, ['roll_no' => 4]);
        try {
            $this->student($section, ['roll_no' => 4]);
            $this->fail('Duplicate roll number accepted');
        } catch (\Modules\Academic\Exceptions\AcademicRuleException $e) {
            $this->assertStringContainsString('Roll', $e->getMessage());
        }
        $this->asOwner()->post($this->at("sections/{$section->id}/roll-numbers"), ['order' => 'name'])->assertSessionHasNoErrors();
        $this->assertSame([1], $this->in(fn () => Enrollment::where('section_id', $section->id)->pluck('roll_no')->all()));
    }

    public function test_student_search_is_case_insensitive_and_finds_guardian_phone(): void
    {
        $s = $this->student($this->section(), ['first_name' => 'Ishaan'], ['phone' => '9899988877']);
        $this->asOwner()->get($this->at('students?q=ishaan'))->assertSee('Ishaan');
        $this->get($this->at('students?q=98999888'))->assertSee('Ishaan');
        $this->get($this->at('students?q=zzz'))->assertDontSee('Ishaan');
    }

    public function test_section_change_and_branch_transfer(): void
    {
        $a = $this->section('Class 5', 'A');
        $b = $this->section('Class 5', 'B');
        $s = $this->student($a);

        $this->asOwner()->post($this->at("students/{$s->id}/move"), ['section_id' => $b->id, 'effective_on' => '2026-11-02', 'reason' => 'Parent request'])->assertSessionHasNoErrors();
        [$old, $new, $t] = $this->in(fn () => [Enrollment::where('section_id', $a->id)->first(), Enrollment::where('section_id', $b->id)->first(), StudentTransfer::first()]);
        $this->assertSame(['section_changed', '2026-11-01'], [$old->status, $old->left_on->format('Y-m-d')]);
        $this->assertSame(['active', '2026-11-02'], [$new->status, $new->joined_on->format('Y-m-d')]);
        $this->assertSame('section', $t->type);

        $this->post($this->at("students/{$s->id}/move"), ['section_id' => $b->id, 'effective_on' => '2026-11-03', 'reason' => 'Again'])->assertSessionHasErrors('section_id');
    }

    public function test_leaving_then_transfer_certificate(): void
    {
        $s = $this->student($this->section());
        $tc = $this->in(fn () => CertificateTemplate::where('type', 'transfer')->firstOrFail());

        $this->asOwner()->post($this->at('certificates'), ['student_id' => $s->id, 'template_id' => $tc->id])->assertSessionHasErrors();

        $this->post($this->at("students/{$s->id}/leave"), ['status' => 'transferred', 'left_on' => '2026-11-03', 'reason' => 'Family moving to Pune'])->assertSessionHasNoErrors();
        $this->post($this->at('certificates'), ['student_id' => $s->id, 'template_id' => $tc->id])->assertSessionHasNoErrors();

        $cert = $this->in(fn () => Certificate::firstOrFail());
        $this->assertSame('TC/2026-27/0001', $cert->number);
        $this->assertStringContainsString('Family moving to Pune', $cert->body);
        $this->assertStringContainsString('Ramesh Kumar', $cert->body);
        $this->assertSame('transferred', $this->in(fn () => $s->fresh()->status));

        // Only one live transfer certificate per student.
        $this->post($this->at('certificates'), ['student_id' => $s->id, 'template_id' => $tc->id])->assertSessionHasErrors();
        $this->post($this->at("certificates/{$cert->id}/cancel"), ['reason' => 'Wrong date']);
        $this->post($this->at('certificates'), ['student_id' => $s->id, 'template_id' => $tc->id])->assertSessionHasNoErrors();
        $this->assertSame('TC/2026-27/0002', $this->in(fn () => Certificate::where('status', 'issued')->value('number')));
    }

    public function test_bonafide_needs_purpose_and_a_current_student(): void
    {
        $s = $this->student($this->section());
        $tpl = $this->in(fn () => CertificateTemplate::where('type', 'bonafide')->firstOrFail());
        $this->asOwner()->post($this->at('certificates'), ['student_id' => $s->id, 'template_id' => $tpl->id])->assertSessionHasErrors('purpose');
        $this->post($this->at('certificates'), ['student_id' => $s->id, 'template_id' => $tpl->id, 'purpose' => 'passport application'])->assertSessionHasNoErrors();
        $cert = $this->in(fn () => Certificate::firstOrFail());
        $this->assertSame('BON/2026-27/0001', $cert->number);
        $this->assertStringContainsString('daughter of Ramesh Kumar', $cert->body);
        $this->assertStringContainsString('(fifteenth june two thousand fifteen)', strtolower($cert->body));
    }

    public function test_certificate_templates_reject_unknown_placeholders(): void
    {
        $this->asOwner()->post($this->at('setup/certificates'), ['type' => 'custom', 'name' => 'Sports', 'body' => 'Awarded to {student_name} for {sport}.', 'is_active' => 1])
            ->assertSessionHasErrors('body');
    }

    public function test_student_profile_validation(): void
    {
        $section = $this->section();
        $this->asOwner()->post($this->at('students'), ['section_id' => $section->id, 'first_name' => 'X', 'gender' => 'boy', 'date_of_birth' => '2030-01-01',
            'pincode' => '12', 'guardians' => [['name' => 'Y', 'relation' => 'father', 'phone' => '5551112222']]])
            ->assertSessionHasErrors(['gender', 'date_of_birth', 'pincode', 'guardians.0.phone']);
    }
}
