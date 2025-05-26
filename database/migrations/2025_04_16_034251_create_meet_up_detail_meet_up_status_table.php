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
        Schema::create('meet_up_detail_meet_up_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_up_detail_id')->constrained('meet_up_details');
            $table->foreignId('meet_up_status_id')->constrained('meet_up_statuses');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meet_up_detail_meet_up_status');
    }
};
