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
        Schema::table('dependency_uploads', function (Blueprint $table) {
            $table->string('ci_upload_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dependency_uploads', function (Blueprint $table) {
            $table->dropColumn('ci_upload_id');
        });
    }
};
