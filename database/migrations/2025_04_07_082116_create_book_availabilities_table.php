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
        Schema::create('book_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books', 'book_id')->onDelete('cascade')->unique();
            $table->integer('availability')->default(2); // Default to Available (2)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_availabilities');
    }
};
