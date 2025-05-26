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
        DB::table('return_statuses')->insert([
            ['status' => 'Pending', 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'PendingAccepted', 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'Accepted', 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'Rejected', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_statuses');
    }
};
