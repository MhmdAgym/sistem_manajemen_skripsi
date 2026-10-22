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
        Schema::create('pengajuan_judul_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pengajuan_judul_id');
            $table->foreign('pengajuan_judul_id')
                ->references('id')
                ->on('pengajuan_judul')
                ->restrictOnDelete();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->string('aktor_nama');
            $table->string('aktor_role', 50);
            $table->string('aktor_nomor_induk', 50)->nullable();
            $table->string('tahap', 50);
            $table->string('tindakan', 50);
            $table->string('status_sebelum', 50)->nullable();
            $table->string('status_sesudah', 50);
            $table->text('catatan')->nullable();
            $table->string('snapshot_judul')->nullable();
            $table->string('snapshot_file_path')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('pengajuan_judul_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pengajuan_judul_logs');
    }
};
