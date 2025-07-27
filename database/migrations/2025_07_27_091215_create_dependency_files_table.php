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
        Schema::create('dependency_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dependency_upload_id')->constrained()->onDelete('cascade');
            $table->string('ci_upload_id')->nullable();
            $table->string('filename');
            $table->integer('vulnerabilities_found')->default(0);
            $table->string('path');
            $table->tinyInteger('progress')->default(0);
            $table->json('raw_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dependency_files');
    }
};
