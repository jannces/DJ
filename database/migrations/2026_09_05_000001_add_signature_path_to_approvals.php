<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The signature an officer actually signed a step with.
 *
 * `signature` already held their NAME as it stood on the day, which is what
 * box 7.B of the printed form needs to be able to name whoever headed an
 * office two years ago. This holds the image beside it.
 *
 * It is a snapshot path, not a reference to the officer's profile: replacing a
 * signature deletes the file it replaces, and a form already filed and printed
 * cannot lose the signature it was signed with because somebody uploaded a
 * new one afterwards. Same reasoning as `applicant_signature_path` on the
 * leave request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('signature');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn('signature_path');
        });
    }
};
