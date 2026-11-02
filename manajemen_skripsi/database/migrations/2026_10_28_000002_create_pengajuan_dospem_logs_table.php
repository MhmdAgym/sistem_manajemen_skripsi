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
        Schema::create('pengajuan_dospem_logs', function (Blueprint $table) {
            $table->id();

            // Referensi ke pengajuan_dospem induk (restrictOnDelete agar riwayat audit tidak terhapus)
            $table->unsignedBigInteger('pengajuan_dospem_id');
            $table->foreign('pengajuan_dospem_id')
                ->references('id')
                ->on('pengajuan_dospem')
                ->restrictOnDelete();

            // Referensi ke user aktor (nullOnDelete untuk mempertahankan audit log jika akun user dihapus)
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // Snapshot identitas aktor
            $table->string('aktor_nama');
            $table->string('aktor_role', 50);
            $table->string('aktor_nomor_induk', 50)->nullable();

            // Snapshot aksi dan status
            $table->string('tahap', 50)->default('proposal');
            $table->string('tindakan', 50); // submit / tetapkan / tolak
            $table->string('status_sebelum', 50)->nullable();
            $table->string('status_sesudah', 50);
            $table->text('catatan')->nullable();

            // Snapshot dosen pembimbing yang ditetapkan (jika tindakan tetapkan)
            $table->unsignedBigInteger('snapshot_dosen_id')->nullable();
            $table->string('snapshot_dosen_nama')->nullable();

            // Timestamp pencatatan (append-only)
            $table->timestamp('created_at')->useCurrent();

            $table->index('pengajuan_dospem_id');
            $table->index('created_at');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pengajuan_dospem_logs');
    }
};
