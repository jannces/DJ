<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A real signature on CSC Form No. 6, instead of a typed name.
 *
 * Box 6 of the form ends in "(Signature of Applicant)" and box 7 in the
 * certifying officer's. Until now both printed a typed name, which is a
 * statement that somebody typed something -- it carries none of what a
 * signature is for, and an application printed from this system could not be
 * filed alongside one signed by hand.
 *
 * `employee_profiles.signature_path` already existed and had never been
 * written to: the column was scaffolded when the profile was built and the
 * feature was never finished. It is reused rather than replaced.
 *
 * Two ideas here, and they are separate on purpose:
 *
 *  1. The person HAS a signature. It lives on their profile, is uploaded once,
 *     and can be replaced when it needs to be.
 *
 *  2. An APPLICATION carries the signature it was filed with. That is
 *     snapshotted onto the leave request, exactly as office_snapshot and
 *     position_snapshot already are, so a form reprinted in 2030 shows what
 *     was signed in 2026 rather than whatever the person has uploaded since.
 *     Replacing your signature must not silently re-sign applications already
 *     filed and decided.
 *
 * The hash is SHA-256 of the file's bytes, recorded at upload and copied with
 * the snapshot. It is not a cryptographic signature and does not pretend to
 * be: it is there so that a file swapped on disk -- the one attack that a
 * private folder does not stop, because the LGU's own administrators can
 * reach it -- does not match its record and can be shown not to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            // 64 hex characters, so the column is sized for exactly that.
            $table->string('signature_hash', 64)->nullable()->after('signature_path');
            $table->timestamp('signature_uploaded_at')->nullable()->after('signature_hash');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('applicant_signature_path')->nullable()->after('applicant_signature');
            $table->string('applicant_signature_hash', 64)->nullable()->after('applicant_signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn(['signature_hash', 'signature_uploaded_at']);
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['applicant_signature_path', 'applicant_signature_hash']);
        });
    }
};
