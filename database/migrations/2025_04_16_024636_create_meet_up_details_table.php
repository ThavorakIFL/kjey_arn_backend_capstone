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
        Schema::create('meet_up_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrow_event_id')->constrained('borrow_events');
            $table->date('final_date');
            $table->string('final_time');
            $table->string('final_location');
            $table->boolean('is_finalized')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meet_up_details');
    }
};
