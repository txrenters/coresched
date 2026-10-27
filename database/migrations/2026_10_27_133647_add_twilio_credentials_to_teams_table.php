<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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
             * Each organization texts through its own Twilio account. With no
             * credentials here its workflows cannot send text messages at all;
             * there is no fallback to an installation-wide account. The token
             * is stored encrypted (Team casts it), so it is a text column.
             */
            $table->string('twilio_account_sid')->nullable()->after('sms_from_number');
            $table->text('twilio_auth_token')->nullable()->after('twilio_account_sid');
        });

        /*
         * Before this, every organization texted through the installation's
         * account (TWILIO_SID / TWILIO_AUTH_TOKEN). Every organization that
         * exists now keeps that ability by taking the account over as its
         * own; only organizations created later start without one. Written
         * encrypted the same way the model's cast does.
         */
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');

        if (filled($sid) && filled($token)) {
            DB::table('teams')
                ->update([
                    'twilio_account_sid' => $sid,
                    'twilio_auth_token' => Crypt::encryptString($token),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['twilio_account_sid', 'twilio_auth_token']);
        });
    }
};
