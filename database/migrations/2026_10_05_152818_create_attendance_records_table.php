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
        Schema::create('attendance_records', function (Blueprint $table) {
        $table->id();

        $table->foreignId('user_id')
            ->constrained('users')
            ->onDelete('restrict');

        $table->date('date');
        $table->time('clock_in');
        $table->time('clock_out')->nullable();
        $table->string('comment', 255)->nullable();
        $table->timestamps();

        $table->unique(['user_id', 'date']);
        $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::dropIfExists('attendance_records');
    }
};
