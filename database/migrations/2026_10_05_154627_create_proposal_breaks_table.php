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
        Schema::create('proposal_breaks', function (Blueprint $table) {
          $table->id();

        $table->foreignId('attendance_correct_request_id')
            ->constrained('attendance_correct_requests')
            ->onDelete('cascade');

        $table->time('break_in');
        $table->time('break_out');
        $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proposal_breaks');
    }
};
