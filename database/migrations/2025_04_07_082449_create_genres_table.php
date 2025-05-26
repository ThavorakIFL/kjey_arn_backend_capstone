<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {


        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->string('genre')->unique();
            $table->timestamps();
        });

        // Insert predefined genres
        DB::table('genres')->insert([
            ['genre' => 'Horror', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Romance', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Adventure', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Science Fiction', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Fantasy', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Mystery', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Thriller', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Historical Fiction', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Biography', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Self-Help', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Philosophy', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Poetry', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Young Adult', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Children\'s', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Dystopian', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Non-Fiction', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Memoir', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Crime', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Classic', 'created_at' => now(), 'updated_at' => now()],
            ['genre' => 'Comic Book/Graphic Novel', 'created_at' => now(), 'updated_at' => now()],

        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('genres');
    }
};
