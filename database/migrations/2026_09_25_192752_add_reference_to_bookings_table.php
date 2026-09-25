<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `reference` is what the system that sent the invitee here asked us to
 * remember, carried in the booking link as `ref` and echoed back in every
 * webhook about the booking. Today that is TEXRA's lead token; the column
 * is deliberately vendor-neutral (see .ai/rules/calendly.md on why no
 * vendor columns belong on domain tables).
 *
 * `webhook_sequence` counts the events reported for the booking, so the
 * receiver can tell a late retry of an older event from the newest one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('reference', 100)->nullable()->index()->after('notes');
            $table->unsignedInteger('webhook_sequence')->default(0)->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['reference', 'webhook_sequence']);
        });
    }
};
