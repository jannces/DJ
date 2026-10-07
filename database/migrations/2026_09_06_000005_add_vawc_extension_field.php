<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The protection-order extension on VAWC leave.
 *
 * A separate migration rather than another entry in 000004, which may already
 * have run on an installation -- a migration that has run does not run again,
 * so adding a field to its list would deliver it nowhere.
 *
 * RA 9262 sec. 43 grants ten days "extendible when the necessity arises as
 * specified in the protection order". A hard ten refused a longer leave a
 * court had already ordered.
 */
return new class extends Migration
{
    private const FIELD = [
        'name' => 'extension_days',
        'label' => 'Additional days specified in a protection order',
        'type' => 'number',
        'required' => false,
    ];

    public function up(): void
    {
        $type = DB::table('leave_types')->where('code', 'VAWC')->first();

        if (! $type) {
            return;
        }

        $schema = json_decode($type->detail_schema ?? '[]', true) ?: [];

        foreach ($schema as $existing) {
            if (($existing['name'] ?? null) === self::FIELD['name']) {
                return;
            }
        }

        $schema[] = self::FIELD;

        DB::table('leave_types')->where('id', $type->id)->update([
            'detail_schema' => json_encode($schema),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $type = DB::table('leave_types')->where('code', 'VAWC')->first();

        if (! $type) {
            return;
        }

        $schema = array_values(array_filter(
            json_decode($type->detail_schema ?? '[]', true) ?: [],
            fn ($f) => ($f['name'] ?? null) !== self::FIELD['name'],
        ));

        DB::table('leave_types')->where('id', $type->id)->update([
            'detail_schema' => json_encode($schema),
            'updated_at' => now(),
        ]);
    }
};
