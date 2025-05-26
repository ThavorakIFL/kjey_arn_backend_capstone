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
        Schema::create('return_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_details_id')->constrained('return_details');
            $table->foreignId('suggested_by')->constrained('users');
            $table->string('suggested_status')->d_suggefault('pending');
            $table->time('suggested_time');
            $table->string('suggested_location');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_suggestions');
    }
};
