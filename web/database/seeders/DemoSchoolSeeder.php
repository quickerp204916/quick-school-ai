<?php

namespace Database\Seeders;

use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\Academic\Models\GradeLevel;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Models\Subject;
use Modules\Academic\Services\AttendanceService;
use Modules\Academic\Services\ExamService;
use Modules\Academic\Services\SetupService;
use Modules\Academic\Services\StudentService;
use Modules\Identity\Models\TenantMembership;
use Modules\Identity\Models\User;
use Modules\Institution\Models\AcademicSession;
use Modules\Institution\Models\Branch;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantProvisioner;

/**
 * Sample K-12 school for trying the institution app: php artisan db:seed --class=DemoSchoolSeeder
 * Signs in as owner@demo.test / Demo-School-2026 at demo.<base domain> (or /i/demo with TENANT_ROUTING=path).
 * Not for production databases.
 */
class DemoSchoolSeeder extends Seeder
{
    public function run(): void
    {
        if (Tenant::where('code', 'demo')->exists()) {
            $this->command?->warn('The demo school already exists.');

            return;
        }
        $tenant = app(TenantProvisioner::class)->provision([
            'code' => 'demo', 'name' => 'Sunrise Public School', 'type' => 'school', 'plan_id' => Plan::where('code', 'growth')->value('id'),
            'billing_cycle' => 'yearly', 'owner_name' => 'Sunita Rao', 'owner_email' => 'owner@demo.test', 'billing_state_code' => '09', 'city' => 'Noida',
        ]);
        $owner = User::where('email', 'owner@demo.test')->firstOrFail();
        $owner->forceFill(['password' => 'Demo-School-2026'])->save();
        auth()->setUser($owner);

        TenantContext::run($tenant, function () {
            $setup = app(SetupService::class);
            $students = app(StudentService::class);
            $session = AcademicSession::current();
            $branch = Branch::where('is_primary', true)->firstOrFail();
            $teachers = collect(['Meena Iyer', 'Rahul Verma', 'Farah Khan', 'Joseph Mathew'])->map(fn ($name, $i) => StaffMember::create([
                'employee_code' => 'T'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'name' => $name, 'designation' => 'TGT', 'is_teaching' => true,
                'status' => 'active', 'branch_id' => $branch->id, 'joined_on' => '2024-04-01',
            ]));
            $first = ['Aarav', 'Diya', 'Ishaan', 'Ananya', 'Vihaan', 'Saanvi', 'Kabir', 'Myra', 'Arjun', 'Kiara', 'Reyansh', 'Aadhya', 'Vivaan', 'Navya', 'Advik', 'Pari'];
            $last = ['Sharma', 'Verma', 'Gupta', 'Singh', 'Yadav', 'Agarwal', 'Mishra', 'Jain'];
            $phone = 9810010000;
            $n = 0;
            foreach (['Class 5', 'Class 6', 'Class 9'] as $li => $levelName) {
                foreach (['A', 'B'] as $si => $name) {
                    $section = $setup->saveSection(['session_id' => $session->id, 'branch_id' => $branch->id, 'grade_level_id' => GradeLevel::where('name', $levelName)->value('id'),
                        'name' => $name, 'capacity' => 40, 'class_teacher_id' => $teachers[($li * 2 + $si) % 4]->id, 'room' => (string) (101 + $li * 10 + $si), 'is_active' => true]);
                    foreach ($section->subjects as $k => $row) {
                        $row->update(['teacher_id' => $teachers[$k % 4]->id]);
                    }
                    for ($i = 0; $i < 12; $i++, $n++) {
                        $students->create([
                            'first_name' => $first[$n % 16], 'last_name' => $last[($n * 3) % 8], 'gender' => $n % 2 ? 'female' : 'male',
                            'date_of_birth' => Carbon::create(2016 - ($li === 2 ? 4 : $li), 1 + $n % 12, 1 + $n % 27)->format('Y-m-d'),
                            'section_id' => $section->id, 'admitted_on' => '2026-04-03', 'category' => ['general', 'obc', 'sc', 'ews'][$n % 4],
                            'guardians' => [['name' => 'Mr '.$last[($n * 3) % 8], 'relation' => 'father', 'phone' => (string) $phone++, 'email' => "parent{$n}@demo.test"]],
                        ]);
                    }
                    $students->assignRollNumbers($section);

                    // Two weeks of attendance.
                    $att = app(AttendanceService::class);
                    for ($d = today()->subDays(14); $d->lte(today()); $d->addDay()) {
                        if ($att->blockedReason($section, $d->format('Y-m-d'))) {
                            continue;
                        }
                        $roster = StudentService::rosterOn($section, $d->format('Y-m-d'));
                        $entries = $roster->mapWithKeys(fn ($e, $i) => [$e->student_id => ($i + $d->day) % 11 === 0 ? 'absent' : (($i + $d->day) % 17 === 0 ? 'late' : 'present')])->all();
                        $att->mark($section, ['date' => $d->format('Y-m-d'), 'entries' => $entries]);
                    }
                }
            }

            // A unit test with marks for Class 5.
            $exams = app(ExamService::class);
            $exam = $exams->saveExam(['session_id' => $session->id, 'name' => 'Unit test 2', 'type' => 'unit_test', 'starts_on' => today()->subDays(20)->format('Y-m-d')]);
            $class5 = \Modules\Academic\Models\Section::where('grade_level_id', GradeLevel::where('name', 'Class 5')->value('id'))->pluck('id')->all();
            $exams->addPapers($exam, ['section_ids' => $class5, 'subject_ids' => Subject::whereIn('code', ['ENG', 'MAT', 'SCI'])->pluck('id')->all(), 'max_marks' => 50, 'pass_marks' => 17]);
            $exams->setStatus($exam, 'open');
            foreach ($exam->papers as $p) {
                $exams->enterMarks($p, $exams->roster($p)->mapWithKeys(fn ($e, $i) => [$e->student_id => ['marks' => (string) (18 + ($i * 7 + strlen($p->subject_id)) % 32)]])->all());
            }
            $exams->setStatus($exam, 'published');
        });

        $this->command?->info('Demo school ready: owner@demo.test / Demo-School-2026');
    }
}
