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
        Schema::table('borrow_event_borrow_status', function (Blueprint $table) {
            $table->dropForeign(['borrow_event_id']);
            $table->foreign('borrow_event_id')
                ->references('id')
                ->on('borrow_events')
                ->onDelete('cascade');
        });

        Schema::table('meet_up_details', function (Blueprint $table) {
            $table->dropForeign(['borrow_event_id']);
            $table->foreign('borrow_event_id')
                ->references('id')
                ->on('borrow_events')
                ->onDelete('cascade');
        });

        Schema::table('meet_up_detail_meet_up_status', function (Blueprint $table) {
            $table->dropForeign(['meet_up_detail_id']);
            $table->foreign('meet_up_detail_id')
                ->references('id')
                ->on('meet_up_details')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('foreign_keys', function (Blueprint $table) {
            //
        });
    }
};
