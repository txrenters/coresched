<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The areas a host takes meetings for, as opaque keys the application that
 * sends invitees here also uses (TEXRA's zone slugs today). A booking link
 * that names a zone is offered only to the hosts listing it, so a renter in
 * one part of town is booked with an agent who works there. Per organization
 * because the API key that writes them is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('zone', 100);
            $table->timestamps();

            $table->unique(['team_id', 'user_id', 'zone']);
            $table->index(['team_id', 'zone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_zones');
    }
};
