<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two questions the circulars require, on a database already carrying data.
 *
 * LeaveTypeSeeder has them, but re-running it would overwrite every field of
 * these types with the seeded version and discard anything HR had adjusted.
 * This adds the two fields to the schema they already have, and only if they
 * are not there.
 *
 *   ML  delivery_type      the ceiling is 105 days for live childbirth and 60
 *                          for miscarriage or emergency termination of
 *                          pregnancy (Sec. 11, CSC MC 5 s.2021). Without the
 *                          answer a miscarriage claim could run to 105.
 *
 *   SEL declaration_date   the leave "may be availed of ... within thirty days
 *                          from the first day of calamity declaration" (CSC MC
 *                          2 s.2012, item 4). The date was never asked for, so
 *                          the window could not be checked by hand either.
 */
return new class extends Migration
{
    /** @var array<string, array{after: ?string, field: array<string, mixed>}> */
    private const FIELDS = [
        'ML' => [
            'after' => null, // first: it decides the ceiling for everything below
            'field' => [
                'name' => 'delivery_type',
                'label' => 'Contingency',
                'type' => 'radio',
                'required' => true,
                'options' => [
                    'live' => 'Live childbirth',
                    'miscarriage' => 'Miscarriage / emergency termination of pregnancy',
                ],
            ],
        ],
        'SEL' => [
            'after' => 'calamity',
            'field' => [
                'name' => 'declaration_date',
                'label' => 'First day of calamity declaration',
                'type' => 'date',
                'required' => true,
            ],
        ],
    ];

    public function up(): void
    {
        foreach (self::FIELDS as $code => $spec) {
            $type = DB::table('leave_types')->where('code', $code)->first();

            if (! $type) {
                continue;
            }

            $schema = json_decode($type->detail_schema ?? '[]', true) ?: [];

            // Already there: this migration has run, or the type was seeded
            // fresh after the seeder was updated.
            foreach ($schema as $existing) {
                if (($existing['name'] ?? null) === $spec['field']['name']) {
                    continue 2;
                }
            }

            $schema = $this->insert($schema, $spec['field'], $spec['after']);

            DB::table('leave_types')->where('id', $type->id)->update([
                'detail_schema' => json_encode($schema),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::FIELDS as $code => $spec) {
            $type = DB::table('leave_types')->where('code', $code)->first();

            if (! $type) {
                continue;
            }

            $schema = array_values(array_filter(
                json_decode($type->detail_schema ?? '[]', true) ?: [],
                fn ($f) => ($f['name'] ?? null) !== $spec['field']['name'],
            ));

            DB::table('leave_types')->where('id', $type->id)->update([
                'detail_schema' => json_encode($schema),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Position matters: these are rendered in order, and a question that
     * decides how the rest are read belongs above them.
     */
    private function insert(array $schema, array $field, ?string $after): array
    {
        if ($after === null) {
            return array_merge([$field], $schema);
        }

        $out = [];

        foreach ($schema as $existing) {
            $out[] = $existing;

            if (($existing['name'] ?? null) === $after) {
                $out[] = $field;
            }
        }

        // The anchor was not found -- append rather than lose the field.
        return in_array($field, $out, true) ? $out : array_merge($out, [$field]);
    }
};
