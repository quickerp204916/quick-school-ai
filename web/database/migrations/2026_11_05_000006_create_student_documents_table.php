<?php

use App\Core\Tenancy\TenantSchema as T;
use App\Core\Tenancy\TenantTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded admission documents and photos. A file belongs to an application while the family applies,
 * and to the student as well once admitted, so the record keeps every document from the first enquiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_documents', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'application_id', 'admission_applications', nullable: true, cascade: true);
            T::ref($table, 'student_id', 'students', nullable: true, cascade: true);
            $table->string('type', 40);                    // config('academic.admission.documents') key; "photo" is the student photo
            $table->string('original_name', 191);
            $table->string('path', 300);                   // on the private uploads disk, under tenants/{tenant_id}/
            $table->string('mime', 100);
            $table->unsignedInteger('size');               // bytes
            $table->char('sha256', 64);
            $table->string('uploaded_by', 36)->nullable();
            $table->timestamp('verified_at')->nullable();  // original seen by the office
            $table->string('verified_by', 36)->nullable();
            $table->timestamps();
            T::key($table);
            $table->index(['tenant_id', 'application_id', 'type']);
            $table->index(['tenant_id', 'student_id', 'type']);
        });
        TenantTriggers::create('student_documents');
    }

    public function down(): void
    {
        TenantTriggers::drop('student_documents');
        Schema::dropIfExists('student_documents');
    }
};
