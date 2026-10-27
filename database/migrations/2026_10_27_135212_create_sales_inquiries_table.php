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
        /*
         * "Contact sales" submissions from the public pricing page, kept so a
         * super admin can work them from the admin console (they are emailed
         * to the sales inbox as well). Visitor-supplied, so personal data:
         * only super admins can read them.
         */
        Schema::create('sales_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('company');
            $table->string('team_size');
            $table->string('plan')->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('new')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_inquiries');
    }
};
