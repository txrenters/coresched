<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A workflow that only texts has no email to give a subject or body.
        Schema::table('automations', function (Blueprint $table) {
            $table->string('subject')->nullable()->change();
            $table->text('body')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('automations')->whereNull('subject')->update(['subject' => '']);
        DB::table('automations')->whereNull('body')->update(['body' => '']);

        Schema::table('automations', function (Blueprint $table) {
            $table->string('subject')->nullable(false)->change();
            $table->text('body')->nullable(false)->change();
        });
    }
};
