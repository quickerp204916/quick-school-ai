<?php

use App\Core\Tenancy\TenantSchema as T;
use App\Core\Tenancy\TenantTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Intelligence Engine: settings, versioned prompts, conversations with usage, knowledge sources (the vectors live in
 * the AI service's PostgreSQL + pgvector), document extractions, and proposals awaiting a person's approval.
 */
return new class extends Migration
{
    public const TENANT_TABLES = ['ai_settings', 'ai_prompts', 'ai_conversations', 'ai_messages', 'ai_action_requests', 'ai_documents', 'ai_knowledge_sources', 'ai_call_logs'];

    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            T::base($table);
            $table->boolean('enabled')->default(true);
            $table->json('models')->nullable();               // agent => {provider, model}
            $table->json('agents_enabled')->nullable();       // list of agents; null = all
            $table->string('language', 5)->default('en');
            $table->timestamps();
            $table->unique('tenant_id');
        });

        Schema::create('ai_prompts', function (Blueprint $table) {
            T::base($table);
            $table->string('agent', 20);
            $table->unsignedInteger('version');
            $table->text('prompt');
            $table->boolean('is_active')->default(false);
            $table->string('note', 200)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['tenant_id', 'agent', 'version']);
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('agent', 20);
            $table->string('title', 150)->nullable();
            $table->string('language', 5)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            T::base($table);
            T::ref($table, 'conversation_id', 'ai_conversations', cascade: true);
            $table->string('role', 10);                       // user | assistant | error
            $table->text('content');
            $table->json('tool_calls')->nullable();
            $table->string('provider', 10)->nullable();
            $table->string('model', 40)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('credits')->default(0);
            $table->unsignedInteger('prompt_version')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_action_requests', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('type', 40);                       // accounting.voucher | fees.refund | fees.waiver | payroll.approve
            $table->string('summary', 300);
            $table->json('payload');
            $table->string('permission', 40);                 // what the approver must be allowed to do
            $table->string('status', 10)->default('pending'); // pending | approved | rejected | failed
            $table->foreignUuid('requested_by')->constrained('users');   // the user whose chat proposed it
            $table->string('conversation_id', 36)->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('result', 300)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('ai_documents', function (Blueprint $table) {
            T::base($table);
            T::key($table);
            $table->string('doc_type', 30);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->string('status', 10)->default('pending'); // pending | extracted | failed | reviewed
            $table->json('extracted')->nullable();            // {fields, missing, warnings}
            $table->string('error', 300)->nullable();
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('ai_knowledge_sources', function (Blueprint $table) {
            T::base($table);
            $table->string('title', 150);
            $table->text('content');
            $table->json('roles')->nullable();                // who may retrieve it; null = every role
            $table->unsignedInteger('chunks')->default(0);
            $table->timestamp('indexed_at')->nullable();
            $table->string('error', 300)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // Calls made by the voice assistant (or logged by staff after a call): who, why, what happened, what next.
        Schema::create('ai_call_logs', function (Blueprint $table) {
            T::base($table);
            $table->string('purpose', 20);                    // fee_reminder | admission_follow_up | other
            T::ref($table, 'student_id', 'students', nullable: true);
            T::ref($table, 'application_id', 'admission_applications', nullable: true);
            $table->string('phone', 15);
            $table->string('outcome', 20);                    // connected | no_answer | busy | wrong_number | promised_to_pay | callback | not_interested
            $table->string('notes', 500)->nullable();
            $table->date('next_action_on')->nullable();
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->string('conversation_id', 36)->nullable();
            $table->foreignUuid('logged_by')->nullable()->constrained('users');
            $table->timestamps();
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
