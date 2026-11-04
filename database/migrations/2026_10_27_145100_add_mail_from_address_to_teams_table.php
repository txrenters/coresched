<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            /*
             * The address this organization's booking mail is sent from, when
             * a super admin has given it its own (see Team::bookingMailFrom()).
             * Null means the platform's MAIL_FROM_ADDRESS. Only an address in
             * mail.org_senders is ever used.
             */
            $table->string('mail_from_address')->nullable()->after('plan_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('mail_from_address');
        });
    }
};
