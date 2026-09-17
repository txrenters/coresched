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
        // Event types picked to show on a team's public page. Separate from
        // event_types.group_id, which decides who HOSTS rather than where it is
        // listed, so any event type can appear on any number of team pages.
        Schema::create('group_page_event_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'event_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_page_event_types');
    }
};
