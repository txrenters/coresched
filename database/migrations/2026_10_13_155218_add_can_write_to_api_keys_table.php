<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a key may write through the API (put a host on a rota, save
     * their hours). Off by default: every key issued so far was read-only,
     * and a key that only shows hours should stay unable to change them.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->boolean('can_write')->default(false)->after('webhook_scope');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('can_write');
        });
    }
};
