<?php

use App\Core\Tenancy\TenantSchema as T;
use App\Core\Tenancy\TenantTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Homework, certificate templates and issued certificates; Sanctum tokens for the mobile app. */
return new class extends Migration
{
    public const TENANT_TABLES = ['homework', 'homework_submissions', 'certificate_templates', 'certificates'];

    public function up(): void
    {
        Schema::create('homework', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'section_id', 'sections');
            T::ref($table, 'subject_id', 'subjects');
            $table->string('title', 150);
            $table->text('details')->nullable();
            $table->date('assigned_on');
            $table->date('due_on');
            T::ref($table, 'assigned_by', 'staff_members', nullable: true);
            $table->string('attachment_path', 255)->nullable();
            $table->string('status', 10)->default('published');    // published | cancelled
            $table->timestamps();
            T::key($table);
            $table->index(['tenant_id', 'section_id', 'due_on']);
        });

        Schema::create('homework_submissions', function (Blueprint $table) {
            T::base($table, uuid: false);
            T::ref($table, 'homework_id', 'homework', cascade: true);
            T::ref($table, 'student_id', 'students');
            $table->string('status', 10);                          // submitted | late | checked | redo
            $table->text('content')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('checked_by', 36)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('grade', 10)->nullable();
            $table->string('remarks', 200)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'homework_id', 'student_id']);
        });

        Schema::create('certificate_templates', function (Blueprint $table) {
            T::base($table);
            $table->string('type', 20);
            $table->string('name', 120);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            T::key($table);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('certificates', function (Blueprint $table) {
            T::base($table);
            $table->string('number', 30);
            T::ref($table, 'template_id', 'certificate_templates');
            T::ref($table, 'student_id', 'students');
            $table->string('type', 20);
            $table->date('issued_on');
            $table->string('purpose', 200)->nullable();
            $table->json('data');
            $table->text('body');
            $table->string('status', 10)->default('issued');      // issued | cancelled
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->string('issued_by', 36)->nullable();
            $table->timestamps();
            // At most one valid transfer certificate per student.
            $table->string('active_tc_student_id', 36)->nullable()->storedAs("CASE WHEN type = 'transfer' AND status = 'issued' THEN CAST(student_id AS CHAR(36)) END");
            T::key($table);
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'active_tc_student_id'], 'certificates_one_tc');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        foreach (self::TENANT_TABLES as $t) {
            TenantTriggers::create($t);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            TenantTriggers::drop($t);
            Schema::dropIfExists($t);
        }
    }
};
