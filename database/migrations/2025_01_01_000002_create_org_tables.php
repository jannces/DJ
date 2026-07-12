<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->foreignId('head_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('salary_grade', 10)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('employee_no')->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('gender')->nullable();          // male | female
            $table->string('civil_status')->nullable();    // single | married | widowed | separated
            $table->date('birth_date')->nullable();
            $table->string('contact_no', 30)->nullable();
            $table->string('address')->nullable();          // residence (calamity-leave validation)
            $table->decimal('salary', 12, 2)->default(0);   // monthly salary (CSC Form 6 field 5)
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('employment_status')->default('permanent'); // permanent | casual | contractual | coterminous
            $table->date('date_hired')->nullable();
            $table->string('signature_path')->nullable();
            $table->boolean('is_solo_parent')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
    }
};
