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
        Schema::create('pengajuan_dospem', function (Blueprint $table) {
            $table->id();

            // Relasi ke tabel skripsi (id_skripsi custom PK)
            $table->unsignedBigInteger('skripsi_id');
            $table->foreign('skripsi_id')
                ->references('id_skripsi')
                ->on('skripsi')
                ->cascadeOnDelete();

            // Relasi ke tabel mahasiswa (id_mahasiswa custom PK)
            $table->unsignedBigInteger('mahasiswa_id');
            $table->foreign('mahasiswa_id')
                ->references('id_mahasiswa')
                ->on('mahasiswa')
                ->cascadeOnDelete();

            // Tahap pengajuan: proposal (Tahap 4B) atau skripsi (Tahap berikutnya)
            $table->enum('tahap', ['proposal', 'skripsi'])->default('proposal');

            // Status alur penetapan
            $table->enum('status', [
                'menunggu_penetapan',
                'ditetapkan',
                'ditolak',
            ])->default('menunggu_penetapan');

            // Dosen pembimbing yang ditetapkan oleh Admin (nullable saat baru diajukan)
            $table->unsignedBigInteger('dosen_id')->nullable();
            $table->foreign('dosen_id')
                ->references('id_dosen')
                ->on('dosen')
                ->restrictOnDelete();

            $table->text('catatan_mahasiswa')->nullable();
            $table->text('catatan_admin')->nullable();

            // Generated Virtual Column (MySQL 8.0.30) untuk pembatasan pengajuan aktif (menunggu_penetapan)
            // Bernilai tahap jika status = 'menunggu_penetapan', bernilai NULL jika status 'ditetapkan' atau 'ditolak'.
            // Di MySQL UNIQUE index, nilai NULL tidak dianggap duplikat sehingga pengajuan ulang setelah ditolak tetap diizinkan.
            $table->string('active_tahap', 20)->virtualAs(
                "CASE WHEN `status` = 'menunggu_penetapan' THEN `tahap` ELSE NULL END"
            )->nullable();

            $table->timestamps();

            // Unique index untuk menjamin maksimal 1 pengajuan berstatus 'menunggu_penetapan' per skripsi per tahap
            $table->unique(['skripsi_id', 'active_tahap'], 'uk_skripsi_active_tahap');

            // Indeks pendukung performa query antrean & filter
            $table->index(['tahap', 'status']);
            $table->index('skripsi_id');
            $table->index('mahasiswa_id');
            $table->index('dosen_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pengajuan_dospem');
    }
};
