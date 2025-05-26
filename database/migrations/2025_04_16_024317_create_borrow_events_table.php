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
        Schema::create('borrow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->constrained('users');
            $table->foreignId('lender_id')->constrained('users');
            $table->foreignId('book_id')->constrained('books');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrow_events');
    }
};
