<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Every event type was set to 0 on 2026-10-01, which let a slot two
        // minutes away be booked. Fifteen minutes is now the floor.
        DB::table('event_types')
            ->where('minimum_notice_minutes', '<', 15)
            ->update(['minimum_notice_minutes' => 15]);

        Schema::table('event_types', function (Blueprint $table) {
            $table->unsignedInteger('minimum_notice_minutes')->default(15)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->unsignedInteger('minimum_notice_minutes')->default(0)->change();
        });
    }
};
