<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a key name the endpoint its application wants booking webhooks at.
     *
     * The signing secret is kept encrypted rather than hashed: it has to be
     * read back to sign every delivery. The scope says whether the key hears
     * of every booking in its organization or only those from its own links.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->string('webhook_url', 2048)->nullable()->after('token_hash');
            $table->text('webhook_secret')->nullable()->after('webhook_url');
            $table->string('webhook_scope')->default('referenced')->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn(['webhook_url', 'webhook_secret', 'webhook_scope']);
        });
    }
};
