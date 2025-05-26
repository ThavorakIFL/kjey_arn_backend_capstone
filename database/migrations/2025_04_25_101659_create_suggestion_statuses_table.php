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
        Schema::create('suggestion_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        DB::table('suggestion_statuses')->insert([
            ['status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'accepted', 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'rejected', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggestion_statuses');
    }
};
