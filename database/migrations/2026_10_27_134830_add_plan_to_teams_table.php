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
             * Set by a super admin in the admin console (App\Enums\TeamPlan).
             * Null means no plan has been recorded yet. A label only: nothing
             * is gated on it. The note is for the operator, never shown to
             * the organization.
             */
            $table->string('plan')->nullable()->after('website_url');
            $table->text('plan_note')->nullable()->after('plan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['plan', 'plan_note']);
        });
    }
};
