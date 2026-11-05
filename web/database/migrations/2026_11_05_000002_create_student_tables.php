<?php

use App\Core\Tenancy\TenantSchema as T;
use App\Core\Tenancy\TenantTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Students, guardians, enrolments, admissions, transfers and promotion runs. */
return new class extends Migration
{
    public const TENANT_TABLES = ['guardians', 'students', 'student_guardians', 'enrollments', 'admission_applications', 'student_transfers', 'promotion_runs'];

    public function up(): void
    {
        Schema::create('guardians', function (Blueprint $table) {
            T::base($table);
            $table->string('name', 150);
            $table->string('phone', 15);
            $table->string('alt_phone', 15)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('occupation', 100)->nullable();
            $table->string('address', 500)->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users');   // parent app login
            $table->timestamps();
            T::key($table);
            $table->index(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('students', function (Blueprint $table) {
            T::base($table);
            $table->string('admission_no', 30);
            $table->string('first_name', 80);
            $table->string('last_name', 80)->nullable();
            $table->string('gender', 10);
            $table->date('date_of_birth');
            $table->string('blood_group', 3)->nullable();
            $table->string('category', 10)->nullable();
            $table->string('nationality', 40)->default('Indian');
            $table->string('mother_tongue', 40)->nullable();
            $table->string('pen', 20)->nullable();                 // UDISE+ Permanent Education Number
            $table->string('apaar_id', 20)->nullable();
            $table->char('aadhaar_last4', 4)->nullable();          // never the full number
            $table->string('phone', 15)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('state_code', 2)->nullable();
            $table->char('pincode', 6)->nullable();
            $table->string('previous_school', 200)->nullable();
            T::ref($table, 'branch_id', 'branches');
            $table->date('admitted_on');
            T::ref($table, 'admitted_session_id', 'academic_sessions');
            $table->string('status', 12)->default('active');       // active | alumni | transferred | withdrawn
            $table->date('left_on')->nullable();
            $table->string('leaving_reason', 200)->nullable();
            $table->string('conduct', 40)->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('photo_path', 255)->nullable();
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'admission_no']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'first_name', 'last_name']);
        });

        Schema::create('student_guardians', function (Blueprint $table) {
            T::base($table, uuid: false);
            T::ref($table, 'student_id', 'students', cascade: true);
            T::ref($table, 'guardian_id', 'guardians');
            $table->string('relation', 12);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_emergency')->default(true);
            $table->boolean('can_pickup')->default(true);
            $table->unique(['tenant_id', 'student_id', 'guardian_id']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'student_id', 'students');
            T::ref($table, 'session_id', 'academic_sessions');
            T::ref($table, 'section_id', 'sections');
            $table->unsignedSmallInteger('roll_no')->nullable();
            // active | promoted | retained | completed | section_changed | transferred | withdrawn
            $table->string('status', 16)->default('active');
            $table->date('joined_on');
            $table->date('left_on')->nullable();
            $table->string('remarks', 200)->nullable();
            $table->timestamps();
            // Database guarantees: one active enrolment per student per session, and unique roll numbers in a section.
            $table->string('active_session_id', 36)->nullable()->storedAs("CASE WHEN status = 'active' THEN CAST(session_id AS CHAR(36)) END");
            $table->unsignedSmallInteger('active_roll_no')->nullable()->storedAs("CASE WHEN status = 'active' THEN roll_no END");
            T::key($table);
            $table->unique(['tenant_id', 'student_id', 'active_session_id'], 'enrollments_one_active');
            $table->unique(['tenant_id', 'section_id', 'active_roll_no'], 'enrollments_roll_unique');
            $table->index(['tenant_id', 'section_id', 'status']);
        });

        Schema::create('admission_applications', function (Blueprint $table) {
            T::base($table);
            $table->string('number', 30);
            T::ref($table, 'session_id', 'academic_sessions');
            T::ref($table, 'branch_id', 'branches');
            T::ref($table, 'grade_level_id', 'grade_levels');
            $table->string('first_name', 80);
            $table->string('last_name', 80)->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('previous_school', 200)->nullable();
            $table->json('guardians');                             // [{name, relation, phone, email, occupation}]
            $table->string('phone', 15);                           // main contact, for search and follow-ups
            $table->string('email', 191)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('source', 12)->default('walk_in');
            $table->string('stage', 14)->default('enquiry');
            $table->timestamp('stage_changed_at')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->date('test_on')->nullable();
            $table->decimal('test_score', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->json('documents')->nullable();                 // {birth_certificate: true, ...}
            $table->string('rejection_reason', 200)->nullable();
            T::ref($table, 'student_id', 'students', nullable: true);
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'stage']);
            $table->index(['tenant_id', 'phone']);
        });

        Schema::create('student_transfers', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'student_id', 'students');
            $table->string('type', 10);                            // section | branch | out
            T::ref($table, 'from_section_id', 'sections', nullable: true);
            T::ref($table, 'to_section_id', 'sections', nullable: true);
            T::ref($table, 'from_branch_id', 'branches', nullable: true);
            T::ref($table, 'to_branch_id', 'branches', nullable: true);
            $table->date('effective_on');
            $table->string('reason', 200);
            $table->string('created_by', 36)->nullable();
            $table->timestamps();
            T::key($table);
            $table->index(['tenant_id', 'student_id']);
        });

        Schema::create('promotion_runs', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'from_section_id', 'sections');
            T::ref($table, 'to_session_id', 'academic_sessions');
            $table->json('counts');
            $table->string('created_by', 36)->nullable();
            $table->timestamps();
            T::key($table);
        });

        foreach (self::TENANT_TABLES as $t) {
            TenantTriggers::create($t);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            TenantTriggers::drop($t);
            Schema::dropIfExists($t);
        }
    }
};
