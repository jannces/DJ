<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->string('purpose')->default('login'); // login | password_reset | sensitive_action
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose', 'expires_at']);
        });

        Schema::create('failed_logins', function (Blueprint $table) {
            $table->id();
            $table->string('identifier');                 // email/username attempted
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45);
            $table->text('user_agent')->nullable();
            $table->string('reason');                     // invalid_password | unknown_user | blocked | otp_failed
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['ip', 'occurred_at']);
            $table->index(['identifier', 'occurred_at']);
        });

        Schema::create('blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique();
            $table->string('reason');
            $table->string('source')->default('auto');    // auto | manual
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();  // null = permanent
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['ip', 'active']);
        });

        Schema::create('authorized_devices', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->string('hostname');
            $table->string('mac_address', 17)->nullable();
            $table->string('description')->nullable();
            $table->string('status')->default('active');  // active | inactive
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('intrusion_logs', function (Blueprint $table) {
            $table->id();
            $table->string('category');                   // sqli|xss|traversal|csrf|rate|auth_fail|device|privilege|other
            $table->string('severity')->default('medium');// low|medium|high|critical
            $table->string('route')->nullable();
            $table->string('method', 10)->nullable();
            $table->text('payload_excerpt')->nullable();
            $table->string('matched_rule')->nullable();
            $table->string('ip', 45);
            $table->text('user_agent')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('handled')->default(false);
            $table->timestamps();
            $table->index(['created_at', 'severity']);
            $table->index(['ip', 'created_at']);
            $table->index('category');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role_snapshot')->nullable();
            $table->string('action');                     // created|updated|deleted|login|logout|approved|...
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 10);
            $table->string('path');
            $table->string('route_name')->nullable();
            $table->string('ip', 45);
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');    // string|int|bool|json
            $table->string('group')->default('general');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('archives', function (Blueprint $table) {
            $table->id();
            $table->string('archivable_type');
            $table->unsignedBigInteger('archivable_id');
            $table->json('snapshot');
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->index(['archivable_type', 'archivable_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('archives');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('intrusion_logs');
        Schema::dropIfExists('authorized_devices');
        Schema::dropIfExists('blocked_ips');
        Schema::dropIfExists('failed_logins');
        Schema::dropIfExists('otp_codes');
    }
};
