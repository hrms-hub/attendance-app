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
    Schema::create('attendance_correct_requests', function (Blueprint $table) {
        $table->id();

        $table->foreignId('attendance_record_id')
            ->constrained('attendance_records')
            ->onDelete('cascade');

        $table->foreignId('user_id')
            ->constrained('users')
            ->onDelete('restrict');

        $table->date('new_date');
        $table->time('new_clock_in');
        $table->time('new_clock_out');
        $table->string('comment', 255);

        $table->string('approval_status', 20)
            ->default('承認待ち');

        $table->date('application_date');

        $table->foreignId('approved_by')
            ->nullable()
            ->constrained('users')
            ->onDelete('set null');

        $table->timestamp('approved_at')->nullable();
        $table->timestamps();

      $table->index(
    ['approval_status', 'application_date'],
    'acr_status_date_idx'
);
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_correct_requests');
    }
};
