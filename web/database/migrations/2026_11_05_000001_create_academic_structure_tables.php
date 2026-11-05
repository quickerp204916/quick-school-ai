<?php

use App\Core\Tenancy\TenantSchema as T;
use App\Core\Tenancy\TenantTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Institution setup and the configurable academic structure:
 * programme → level → section (or batch) → subjects, plus sessions, terms, staff and grading.
 */
return new class extends Migration
{
    public const TENANT_TABLES = ['institution_profiles', 'tenant_counters', 'academic_terms', 'staff_members', 'grading_schemes',
        'grade_bands', 'programs', 'grade_levels', 'subjects', 'level_subjects', 'sections', 'section_subjects'];

    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('phone', 15)->nullable()->after('state_code');
            $table->string('email', 191)->nullable()->after('phone');
            $table->boolean('is_active')->default(true)->after('email');
        });

        Schema::table('academic_sessions', function (Blueprint $table) {
            $table->string('status', 12)->default('open')->after('is_current');   // planning | open | closed
        });

        Schema::create('institution_profiles', function (Blueprint $table) {
            T::base($table);
            $table->string('structure', 12);                       // school | college | coaching
            $table->string('board', 40)->nullable();
            $table->string('affiliation_no', 40)->nullable();
            $table->string('udise_code', 20)->nullable();
            $table->string('principal_name', 150)->nullable();
            $table->string('phone', 15)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('website', 191)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('attendance_mode', 10)->default('daily');   // daily | period
            $table->unsignedTinyInteger('attendance_edit_days')->default(3);
            $table->unsignedTinyInteger('low_attendance_percent')->default(75);
            $table->json('working_days');                          // ISO days 1 (Mon) … 7 (Sun)
            $table->string('admission_prefix', 10);
            $table->json('required_documents');
            $table->timestamps();
            T::key($table);
            $table->unique('tenant_id');
        });

        Schema::create('tenant_counters', function (Blueprint $table) {
            T::base($table, uuid: false);
            $table->string('series', 20);
            $table->string('period', 10);
            $table->unsignedInteger('next_value');
            $table->timestamps();
            $table->unique(['tenant_id', 'series', 'period']);
        });

        Schema::create('academic_terms', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'session_id', 'academic_sessions', cascade: true);
            $table->string('name', 40);
            $table->unsignedTinyInteger('sequence');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'session_id', 'name']);
        });

        Schema::create('staff_members', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'branch_id', 'branches', nullable: true);
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('employee_code', 20);
            $table->string('name', 150);
            $table->string('designation', 80)->nullable();
            $table->string('department', 80)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 15)->nullable();
            $table->boolean('is_teaching')->default(true);
            $table->string('status', 10)->default('active');
            $table->date('joined_on')->nullable();
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'employee_code']);
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('grading_schemes', function (Blueprint $table) {
            T::base($table);
            $table->string('name', 80);
            $table->string('type', 12);                            // grades | gpa | percentage
            $table->decimal('pass_percent', 5, 2);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('grade_bands', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'grading_scheme_id', 'grading_schemes', cascade: true);
            $table->string('grade', 20);
            $table->decimal('min_percent', 5, 2);
            $table->decimal('grade_point', 4, 2)->nullable();
            $table->string('remark', 80)->nullable();
            T::key($table);
            $table->unique(['tenant_id', 'grading_scheme_id', 'min_percent']);
            $table->unique(['tenant_id', 'grading_scheme_id', 'grade']);
        });

        Schema::create('programs', function (Blueprint $table) {
            T::base($table);
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            T::ref($table, 'grading_scheme_id', 'grading_schemes', nullable: true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('grade_levels', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'program_id', 'programs');
            $table->string('code', 20);
            $table->string('name', 80);
            $table->unsignedSmallInteger('sequence');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'program_id', 'sequence']);
            $table->unique(['tenant_id', 'program_id', 'code']);
        });

        Schema::create('subjects', function (Blueprint $table) {
            T::base($table);
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('type', 16)->default('core');
            $table->decimal('credits', 4, 1)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('level_subjects', function (Blueprint $table) {
            T::base($table, uuid: false);
            T::ref($table, 'grade_level_id', 'grade_levels', cascade: true);
            T::ref($table, 'subject_id', 'subjects');
            $table->boolean('is_optional')->default(false);
            $table->unique(['tenant_id', 'grade_level_id', 'subject_id']);
        });

        Schema::create('sections', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'session_id', 'academic_sessions');
            T::ref($table, 'branch_id', 'branches');
            T::ref($table, 'grade_level_id', 'grade_levels');
            $table->string('name', 50);
            $table->unsignedSmallInteger('capacity')->nullable();
            T::ref($table, 'class_teacher_id', 'staff_members', nullable: true);
            $table->string('room', 40)->nullable();
            $table->date('starts_on')->nullable();                 // batches
            $table->date('ends_on')->nullable();
            $table->string('schedule_note', 150)->nullable();      // e.g. Mon–Fri 7–9 am
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'session_id', 'branch_id', 'grade_level_id', 'name'], 'sections_unique_name');
        });

        Schema::create('section_subjects', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'section_id', 'sections', cascade: true);
            T::ref($table, 'subject_id', 'subjects');
            T::ref($table, 'teacher_id', 'staff_members', nullable: true);
            $table->unsignedTinyInteger('periods_per_week')->nullable();
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'section_id', 'subject_id']);
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
        Schema::table('academic_sessions', fn (Blueprint $t) => $t->dropColumn('status'));
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn(['phone', 'email', 'is_active']));
    }
};
