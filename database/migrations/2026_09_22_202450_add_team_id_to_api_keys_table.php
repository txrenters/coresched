<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scope a key to the organization that issued it.
     *
     * An organization's admins can issue their own keys, so a key has to say
     * which organization it speaks for: without this, any admin could issue
     * one that reads every other organization's booking pages. Null means a
     * system-wide key, which only a super admin can make.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->foreignId('team_id')->nullable()->after('name')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
