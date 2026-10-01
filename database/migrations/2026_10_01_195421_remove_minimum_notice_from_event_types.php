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
        // Bookable times come from the hosts' availability alone, so no event
        // type holds back a notice period any more.
        DB::table('event_types')->update(['minimum_notice_minutes' => 0]);

        Schema::table('event_types', function (Blueprint $table) {
            $table->unsignedInteger('minimum_notice_minutes')->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->unsignedInteger('minimum_notice_minutes')->default(240)->change();
        });
    }
};
