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
        Schema::create('return_detail_return_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_detail_id')->constrained('return_details')->onDelete('cascade');
            $table->foreignId('return_status_id')->constrained('return_statuses')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_detail_return_statuses');
    }
};
