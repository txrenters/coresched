<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This once opted every user in to the default holidays. Holidays are now
     * the organization's (see move_holidays_from_users_to_organizations), so
     * a fresh database has nothing to do here.
     */
    public function up(): void
    {
        //
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
