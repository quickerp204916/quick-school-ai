<?php

namespace Modules\Academic\Services;

use Illuminate\Support\Str;
use Modules\Academic\Models\AcademicTerm;
use Modules\Academic\Models\BellPeriod;
use Modules\Academic\Models\CertificateTemplate;
use Modules\Academic\Models\GradeLevel;
use Modules\Academic\Models\GradingScheme;
use Modules\Academic\Models\InstitutionProfile;
use Modules\Academic\Models\LevelSubject;
use Modules\Academic\Models\Program;
use Modules\Academic\Models\Subject;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;
use Modules\Platform\Models\Tenant;

/**
 * Defaults created when an institution is onboarded, so it can start straight away:
 * profile, grading scheme, terms, bell schedule and certificate templates, plus classes
 * and subjects for K-12 schools. Everything can be edited afterwards.
 * Runs inside the new tenant's context.
 */
final class AcademicSetup
{
    public function seed(Tenant $tenant, Branch $branch, AcademicSession $session): InstitutionProfile
    {
        $structure = config('academic.type_structure.'.$tenant->type, 'school');

        $profile = InstitutionProfile::create([
            'structure' => $structure,
            'phone' => $tenant->contact_phone,
            'email' => $tenant->contact_email,
            'address' => $tenant->address,
            'working_days' => [1, 2, 3, 4, 5, 6],
            'admission_prefix' => self::prefixFor($tenant->name),
            'required_documents' => config('academic.admission.default_required'),
        ]);

        $scheme = $this->gradingScheme(config("academic.structures.{$structure}.grading"), true);

        if ($structure !== 'coaching') {
            $mid = $session->starts_on->copy()->addMonthsNoOverflow(6);
            AcademicTerm::create(['session_id' => $session->id, 'name' => $structure === 'college' ? 'Odd semester' : 'Term 1', 'sequence' => 1,
                'starts_on' => $session->starts_on, 'ends_on' => $mid->copy()->subDay()]);
            AcademicTerm::create(['session_id' => $session->id, 'name' => $structure === 'college' ? 'Even semester' : 'Term 2', 'sequence' => 2,
                'starts_on' => $mid, 'ends_on' => $session->ends_on]);
        }

        if ($structure === 'school') {
            $this->schoolStructure($scheme);
        }

        $this->bellSchedule($branch);
        $this->certificateTemplates($structure);

        return $profile;
    }

    /** "Sunrise Public School" → "SPS" */
    public static function prefixFor(string $name): string
    {
        $words = array_filter(preg_split('/[^A-Za-z0-9]+/', $name) ?: []);
        $prefix = strtoupper(implode('', array_map(fn ($w) => $w[0], $words)));

        return substr($prefix !== '' ? $prefix : 'ADM', 0, 6);
    }

    public function gradingScheme(string $preset, bool $default = false): GradingScheme
    {
        $p = config("academic.grading_presets.{$preset}");
        $scheme = GradingScheme::create(['name' => $p['name'], 'type' => $p['type'], 'pass_percent' => $p['pass_percent'], 'is_default' => $default]);
        foreach ($p['bands'] as [$grade, $min, $gp, $remark]) {
            $scheme->bands()->create(['grade' => $grade, 'min_percent' => $min, 'grade_point' => $gp, 'remark' => $remark]);
        }

        return $scheme;
    }

    private function schoolStructure(GradingScheme $scheme): void
    {
        $program = Program::create(['code' => 'K12', 'name' => 'School (Nursery to Class 12)', 'grading_scheme_id' => $scheme->id, 'sort_order' => 1]);

        $subjects = [];
        foreach (config('academic.school_subjects') as [$code, $name, $type]) {
            $subjects[$code] = Subject::create(['code' => $code, 'name' => $name, 'type' => $type]);
        }

        $plans = [
            'pre' => ['ENG' => false, 'HIN' => false, 'MAT' => false, 'ART' => false],
            'primary' => ['ENG' => false, 'HIN' => false, 'MAT' => false, 'SCI' => false, 'COM' => true, 'ART' => false, 'PHE' => false],
            'middle' => ['ENG' => false, 'HIN' => false, 'MAT' => false, 'SCI' => false, 'SST' => false, 'SAN' => true, 'COM' => true, 'ART' => false, 'PHE' => false],
            'senior' => ['ENG' => false, 'MAT' => true, 'COM' => true, 'PHE' => false],
        ];

        foreach (config('academic.school_levels') as $i => $name) {
            $code = str_starts_with($name, 'Class ') ? 'C'.substr($name, 6) : strtoupper(substr($name, 0, 3));
            $level = GradeLevel::create(['program_id' => $program->id, 'code' => $code, 'name' => $name, 'sequence' => $i + 1]);
            $n = str_starts_with($name, 'Class ') ? (int) substr($name, 6) : 0;
            $plan = match (true) { $n === 0 => 'pre', $n <= 5 => 'primary', $n <= 10 => 'middle', default => 'senior' };
            foreach ($plans[$plan] as $sub => $optional) {
                LevelSubject::create(['grade_level_id' => $level->id, 'subject_id' => $subjects[$sub]->id, 'is_optional' => $optional]);
            }
        }
    }

    private function bellSchedule(Branch $branch): void
    {
        $t = now()->setTime(8, 0);
        $seq = 1;
        foreach ([40, 40, 40, 40, 'break', 40, 40, 40, 40] as $slot) {
            $len = $slot === 'break' ? 20 : $slot;
            BellPeriod::create([
                'branch_id' => $branch->id, 'sequence' => $seq, 'is_break' => $slot === 'break',
                'name' => $slot === 'break' ? 'Lunch break' : 'Period '.($seq > 5 ? $seq - 1 : $seq),
                'starts_at' => $t->format('H:i:s'), 'ends_at' => $t->copy()->addMinutes($len)->format('H:i:s'),
            ]);
            $t->addMinutes($len);
            $seq++;
        }
    }

    private function certificateTemplates(string $structure): void
    {
        $inst = '{institution_name}';
        $templates = [
            ['bonafide', 'Bonafide certificate', "This is to certify that {student_name}, {son_daughter} of {father_name}, is a bonafide student of {$inst}, studying in {class_section} during the academic session {session}. {his_her_cap} admission number is {admission_no} and {his_her} date of birth as per our records is {date_of_birth} ({date_of_birth_words}).\n\nThis certificate is issued on request for the purpose of {purpose}."],
            ['character', 'Character certificate', "This is to certify that {student_name}, {son_daughter} of {father_name}, was a student of {$inst} (admission number {admission_no}). During {his_her} stay {his_her} conduct and character were {conduct}.\n\nWe wish {him_her} success in life."],
            ['transfer', 'Transfer certificate', "Admission number: {admission_no}\nName of student: {student_name}\nFather's name: {father_name}\nMother's name: {mother_name}\nDate of birth: {date_of_birth} ({date_of_birth_words})\nNationality: {nationality}\nCategory: {category}\nPEN (UDISE+): {pen}\nDate of admission: {admitted_on}\nClass last studied: {class_section}\nDate of leaving: {left_on}\nReason for leaving: {leaving_reason}\nGeneral conduct: {conduct}\n\nCertified that the above information is in accordance with the school records."],
        ];
        if ($structure === 'coaching') {
            $templates[] = ['course_completion', 'Course completion certificate', "This is to certify that {student_name} has successfully completed the {class_section} course at {$inst} during {session}."];
        } else {
            $templates[] = ['study', 'Study certificate', "This is to certify that {student_name}, {son_daughter} of {father_name}, studied in {$inst} from {admitted_on} and is presently studying in {class_section} during the session {session}."];
        }
        foreach ($templates as [$type, $name, $body]) {
            CertificateTemplate::create(['type' => $type, 'name' => $name, 'body' => $body]);
        }
    }
}
