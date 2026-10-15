<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `zone` is the area the booking link named, kept so a reschedule or a
 * hand-off stays with a host who covers it and so the webhook can report it
 * back. Vendor-neutral like `reference` beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('zone', 100)->nullable()->index()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('zone');
        });
    }
};
