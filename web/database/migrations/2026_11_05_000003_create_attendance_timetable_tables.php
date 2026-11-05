<?php

use App\Core\Tenancy\TenantSchema as T;
use App\Core\Tenancy\TenantTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Holidays, bell schedule, timetable and attendance. */
return new class extends Migration
{
    public const TENANT_TABLES = ['holidays', 'bell_periods', 'timetable_entries', 'attendance_registers', 'attendance_entries'];

    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'branch_id', 'branches', nullable: true);   // null = all branches
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            T::key($table);
            $table->index(['tenant_id', 'starts_on']);
        });

        Schema::create('bell_periods', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'branch_id', 'branches');
            $table->string('name', 40);
            $table->unsignedTinyInteger('sequence');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->boolean('is_break')->default(false);
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'branch_id', 'sequence']);
        });

        Schema::create('timetable_entries', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'session_id', 'academic_sessions');
            T::ref($table, 'section_id', 'sections', cascade: true);
            $table->unsignedTinyInteger('day_of_week');
            T::ref($table, 'period_id', 'bell_periods');
            T::ref($table, 'subject_id', 'subjects');
            T::ref($table, 'teacher_id', 'staff_members', nullable: true);
            $table->string('room', 40)->nullable();
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'section_id', 'day_of_week', 'period_id'], 'timetable_section_slot');
            $table->index(['tenant_id', 'session_id', 'teacher_id', 'day_of_week', 'period_id'], 'timetable_teacher_slot');
        });

        Schema::create('attendance_registers', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'section_id', 'sections');
            $table->date('date');
            T::ref($table, 'period_id', 'bell_periods', nullable: true);   // null = whole-day register
            T::ref($table, 'subject_id', 'subjects', nullable: true);
            $table->string('marked_by', 36)->nullable();
            $table->timestamp('marked_at')->nullable();
            $table->timestamps();
            $table->string('slot', 36)->storedAs("COALESCE(CAST(period_id AS CHAR(36)), 'day')");
            T::key($table);
            $table->unique(['tenant_id', 'section_id', 'date', 'slot'], 'attendance_register_slot');
        });

        Schema::create('attendance_entries', function (Blueprint $table) {
            T::base($table, uuid: false);
            T::ref($table, 'register_id', 'attendance_registers', cascade: true);
            T::ref($table, 'student_id', 'students');
            $table->string('status', 10);
            $table->string('remark', 150)->nullable();
            $table->unique(['tenant_id', 'register_id', 'student_id']);
            $table->index(['tenant_id', 'student_id']);
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
