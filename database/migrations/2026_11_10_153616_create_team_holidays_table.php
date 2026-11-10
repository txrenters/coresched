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
            $table->string('holiday_country', 2)->nullable()->after('timezone');
        });

        Schema::create('team_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('holiday_key');
            $table->timestamps();

            $table->unique(['team_id', 'holiday_key']);
        });

        Schema::create('team_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->index(['team_id', 'ends_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_closures');
        Schema::dropIfExists('team_holidays');

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('holiday_country');
        });
    }
};
