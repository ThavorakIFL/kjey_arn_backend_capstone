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
        Schema::create('borrowing_event_borrow_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrow_event_id')->constrained('borrow_events');
            $table->foreignId('status_id')->constrained('borrow_statuses');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrow_event_borrow_status');
    }
};
