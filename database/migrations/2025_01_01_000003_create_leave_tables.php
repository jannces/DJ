<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();               // VL, SL, ML, PL, SPL, ...
            $table->string('name');
            $table->string('category')->default('regular'); // regular | special | monetization | terminal
            $table->decimal('max_days', 6, 1)->nullable();  // null = unlimited by type rule
            $table->boolean('deductible')->default(false);
            $table->string('credit_source')->nullable();    // vacation | sick | null
            $table->unsignedTinyInteger('requires_medical_after_days')->nullable();
            $table->unsignedSmallInteger('filing_deadline_days')->default(0); // file N days before start
            $table->boolean('deadline_is_hard')->default(false);              // false => warning + HR override
            $table->json('detail_schema')->nullable();      // dynamic "Details of Leave" fields
            $table->json('required_documents')->nullable(); // document rules (incl. conditional)
            $table->json('approval_flow')->nullable();      // ordered steps: department_head, hr, mayor
            $table->boolean('annual_reset')->default(false);
            $table->boolean('expires')->default(false);
            $table->boolean('is_custom')->default(false);
            $table->boolean('active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->string('scope')->default('national'); // national | local
            $table->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('date_filed');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('working_days', 5, 1);
            $table->json('details')->nullable();           // details-of-leave answers
            $table->text('purpose')->nullable();
            $table->boolean('commutation')->default(false);
            $table->string('status')->default('pending');  // pending|dept_review|hr_review|final_review|approved|rejected|returned|cancelled
            $table->unsignedTinyInteger('current_step')->default(0);
            $table->boolean('is_late_filing')->default(false);
            $table->string('late_filing_reason')->nullable();
            $table->json('filing_warnings')->nullable();   // deadline warnings shown at submission
            $table->boolean('hr_override')->default(false);
            $table->string('hr_override_reason')->nullable();
            $table->decimal('days_with_pay', 5, 1)->nullable();
            $table->decimal('days_without_pay', 5, 1)->nullable();
            $table->string('disapproval_reason')->nullable();
            // Frozen CSC Form 6 header snapshot at filing time
            $table->string('office_snapshot')->nullable();
            $table->string('position_snapshot')->nullable();
            $table->decimal('salary_snapshot', 12, 2)->nullable();
            $table->string('applicant_signature')->nullable(); // typed-name signature snapshot
            $table->timestamp('decided_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['leave_type_id', 'start_date']);
            $table->index(['status', 'current_step']);
        });

        Schema::create('leave_request_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->string('type');                        // medical_certificate | solo_parent_id | ...
            $table->string('original_name');
            $table->string('path');
            $table->string('hash', 64);
            $table->unsignedInteger('size');
            $table->string('mime', 100);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step_no');
            $table->string('role_slug');                   // department_head | hr | mayor
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->default('pending');  // pending|approved|rejected|returned|certified
            $table->text('comments')->nullable();
            $table->decimal('days_with_pay', 5, 1)->nullable();
            $table->decimal('days_without_pay', 5, 1)->nullable();
            $table->json('certified_balances')->nullable(); // HR certification snapshot (VL/SL/balance)
            $table->string('signature')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
            $table->index(['leave_request_id', 'step_no']);
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('earned', 8, 3)->default(0);
            $table->decimal('used', 8, 3)->default(0);
            $table->decimal('balance', 8, 3)->default(0);
            $table->string('last_accrued_period', 7)->nullable(); // YYYY-MM idempotency guard
            $table->timestamps();
            $table->unique(['user_id', 'leave_type_id']);
        });

        Schema::create('leave_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entry_type'); // accrual|deduction|adjustment|monetization|reversal
            $table->decimal('days', 8, 3);
            $table->decimal('balance_after', 8, 3);
            $table->string('period', 7)->nullable();
            $table->string('remarks')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'leave_type_id']);
            $table->unique(['user_id', 'leave_type_id', 'entry_type', 'period'], 'uq_accrual_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_history');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('leave_request_documents');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('leave_types');
    }
};
