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
             * Set by a super admin to suspend the organization: its members
             * lose access to it (and cannot sign in at all if it was their
             * only one), its booking pages stop taking bookings and its
             * reminders and workflows stop. Null is active. The reason is for
             * super admins only.
             */
            $table->timestamp('disabled_at')->nullable()->after('mail_from_address');
            $table->text('disabled_reason')->nullable()->after('disabled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['disabled_at', 'disabled_reason']);
        });
    }
};
