<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Accepting an invitation now requires a verified address. Nothing ever
     * asked anyone to verify before, so every existing account is treated as
     * verified rather than locking people out of invitations they are owed.
     * From here on only changing the email clears it.
     */
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    /**
     * Reverse the migrations.
     *
     * Which rows were unverified before is not recorded, so there is nothing
     * to put back.
     */
    public function down(): void
    {
        //
    }
};
