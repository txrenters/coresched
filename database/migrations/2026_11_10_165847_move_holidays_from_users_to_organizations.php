<?php

use App\Services\Scheduling\HolidayCalendar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Holidays become the organization's call. Until now nearly everyone was
     * off on every US holiday through their own picks, so each organization
     * that has not chosen yet starts with the same list: nothing that was
     * closed opens up for booking, and an admin switches off what they keep
     * open. The personal picks are then dropped.
     */
    public function up(): void
    {
        $country = config('scheduling.default_holiday_country');
        $keys = blank($country) ? [] : app(HolidayCalendar::class)->forCountry($country)->pluck('key')->all();

        if ($keys !== []) {
            $now = now();

            DB::table('teams')
                ->whereNull('holiday_country')
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                    ->from('team_holidays')
                    ->whereColumn('team_holidays.team_id', 'teams.id'))
                ->orderBy('id')
                ->chunkById(100, function ($teams) use ($country, $keys, $now) {
                    foreach ($teams as $team) {
                        DB::table('teams')->where('id', $team->id)->update(['holiday_country' => $country]);

                        DB::table('team_holidays')->insert(array_map(fn (string $key) => [
                            'team_id' => $team->id,
                            'holiday_key' => $key,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ], $keys));
                    }
                });
        }

        Schema::dropIfExists('user_holidays');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('holiday_country');
        });
    }

    /**
     * Reverse the migrations.
     *
     * The personal picks are gone; the structure comes back empty.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('holiday_country', 2)->nullable()->after('timezone');
        });

        Schema::create('user_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('holiday_key');
            $table->timestamps();

            $table->unique(['user_id', 'holiday_key']);
        });
    }
};
