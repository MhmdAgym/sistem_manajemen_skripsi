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
        Schema::create('penugasan_pembimbing_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('penugasan_pembimbing_id')->comment('FK ke penugasan_pembimbing yang dimutasi');
            $table->foreign('penugasan_pembimbing_id', 'fk_penugasan_log_induk')
                ->references('id')
                ->on('penugasan_pembimbing')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('user_id')->nullable()->comment('FK ke users(id) aktor yang melakukan tindakan');
            $table->foreign('user_id', 'fk_penugasan_log_user')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->string('aktor_nama', 150);
            $table->string('aktor_role', 50)->comment('admin / dekan / kaprodi / sistem');
            $table->enum('tindakan', [
                'PENETAPAN_AWAL',
                'PERGANTIAN_PEMBIMBING',
                'PEMBATALAN_PENUGASAN',
                'PENGAKTIFAN_KEMBALI',
            ]);

            $table->string('status_sebelum', 50)->nullable();
            $table->string('status_sesudah', 50);
            $table->unsignedBigInteger('snapshot_dosen_lama_id')->nullable();
            $table->unsignedBigInteger('snapshot_dosen_baru_id')->nullable();
            $table->text('catatan')->nullable()->comment('Alasan administratif mutasi pembimbing');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['penugasan_pembimbing_id', 'created_at'], 'idx_penugasan_log_history');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penugasan_pembimbing_logs');
    }
};

