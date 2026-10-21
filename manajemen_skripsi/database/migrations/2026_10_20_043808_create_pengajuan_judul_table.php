<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_judul', function (Blueprint $table) {
            $table->id();
            // Relasi ke tabel mahasiswa (via id_mahasiswa custom PK)
            $table->unsignedBigInteger('mahasiswa_id');
            $table->foreign('mahasiswa_id')->references('id_mahasiswa')->on('mahasiswa')->cascadeOnDelete();

            // Relasi ke tabel users (Dosen PA yang memverifikasi — via user_id dosen)
            $table->foreignId('dosen_pa_id')->nullable()->constrained('users')->nullOnDelete();

            // Data Pengajuan & File
            $table->string('judul');
            $table->text('deskripsi');
            $table->string('nama_file');
            $table->string('ukuran_file');
            $table->string('file_path');

            // Status Verifikasi Dosen PA
            $table->enum('status', [
                'Menunggu Verifikasi Dosen PA',
                'Disetujui Dosen PA',
                'Revisi',
                'Ditolak'
            ])->default('Menunggu Verifikasi Dosen PA');

            $table->text('catatan_dosen')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_judul');
    }
};