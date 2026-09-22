<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keys our other applications use to reach the API.
     *
     * Only a hash of each key is kept, so the table is worth nothing to
     * somebody who reads it. The prefix is stored in the clear purely to find
     * the row: a request carries it, and the rest is verified against the hash.
     */
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('prefix', 16)->unique();
            $table->string('token_hash');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
