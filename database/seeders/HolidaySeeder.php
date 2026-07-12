<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

/** Philippine regular holidays (fixed-date set; HR maintains movable ones in the UI). */
class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $year = now()->year;
        foreach ([$year, $year + 1] as $y) {
            $holidays = [
                ["$y-01-01", "New Year's Day"],
                ["$y-04-09", 'Araw ng Kagitingan'],
                ["$y-05-01", 'Labor Day'],
                ["$y-06-12", 'Independence Day'],
                ["$y-08-21", 'Ninoy Aquino Day'],
                ["$y-11-01", "All Saints' Day"],
                ["$y-11-30", 'Bonifacio Day'],
                ["$y-12-08", 'Feast of the Immaculate Conception'],
                ["$y-12-25", 'Christmas Day'],
                ["$y-12-30", 'Rizal Day'],
                ["$y-12-31", "New Year's Eve"],
            ];
            foreach ($holidays as [$date, $name]) {
                Holiday::updateOrCreate(['date' => $date], ['name' => $name, 'scope' => 'national']);
            }
        }
    }
}
