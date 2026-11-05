<?php

use App\Core\Tenancy\TenantSchema as T;
use App\Core\Tenancy\TenantTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Exams, papers, marks and published report cards. */
return new class extends Migration
{
    public const TENANT_TABLES = ['exams', 'exam_papers', 'marks', 'report_cards'];

    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'session_id', 'academic_sessions');
            T::ref($table, 'term_id', 'academic_terms', nullable: true);
            $table->string('name', 80);
            $table->string('type', 12);
            T::ref($table, 'grading_scheme_id', 'grading_schemes');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 10)->default('draft');        // draft | open | published
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'session_id', 'name']);
        });

        Schema::create('exam_papers', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'exam_id', 'exams', cascade: true);
            T::ref($table, 'section_id', 'sections');
            T::ref($table, 'subject_id', 'subjects');
            $table->date('exam_date')->nullable();
            $table->time('starts_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->decimal('max_marks', 6, 2);
            $table->decimal('pass_marks', 6, 2);
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'exam_id', 'section_id', 'subject_id'], 'exam_papers_unique');
        });

        Schema::create('marks', function (Blueprint $table) {
            T::base($table, uuid: false);
            T::ref($table, 'paper_id', 'exam_papers', cascade: true);
            T::ref($table, 'student_id', 'students');
            $table->decimal('marks', 6, 2)->nullable();
            $table->boolean('is_absent')->default(false);
            $table->string('remarks', 150)->nullable();
            $table->string('entered_by', 36)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'paper_id', 'student_id']);
            $table->index(['tenant_id', 'student_id']);
        });

        Schema::create('report_cards', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'student_id', 'students');
            T::ref($table, 'session_id', 'academic_sessions');
            T::ref($table, 'section_id', 'sections');
            $table->string('title', 80);
            $table->json('exams');                                 // [{id, name, weight}]
            $table->json('data');                                  // subject rows, snapshot at publishing
            $table->decimal('percentage', 5, 2);
            $table->string('grade', 20)->nullable();
            $table->decimal('gpa', 4, 2)->nullable();
            $table->unsignedSmallInteger('rank')->nullable();
            $table->string('result', 10);                          // pass | fail
            $table->decimal('attendance_percent', 5, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('published_at');
            $table->string('published_by', 36)->nullable();
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'student_id', 'session_id', 'title'], 'report_cards_unique');
            $table->index(['tenant_id', 'section_id', 'title']);
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
