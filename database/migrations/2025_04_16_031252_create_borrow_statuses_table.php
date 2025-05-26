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
        Schema::create('borrow_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->timestamps();
        });

        DB::table('borrow_statuses')->insert([
            ['status' => 'Pending', 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'Approved', 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'Rejected', 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'InProgress', 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'Completed', 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'Cancelled', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrow_statuses');
    }
};
