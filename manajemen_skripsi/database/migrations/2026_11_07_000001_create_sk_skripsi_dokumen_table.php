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
        Schema::create('sk_skripsi_dokumen', function (Blueprint $table) {
            $table->id();

            // Relasi ke skripsi dan mahasiswa (PK kustom eksisting)
            $table->unsignedBigInteger('skripsi_id')->comment('FK ke skripsi induk');
            $table->foreign('skripsi_id', 'fk_skdok_skripsi')
                ->references('id_skripsi')
                ->on('skripsi')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('mahasiswa_id')->comment('FK ke mahasiswa pengunggah');
            $table->foreign('mahasiswa_id', 'fk_skdok_mahasiswa')
                ->references('id_mahasiswa')
                ->on('mahasiswa')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            // Versi dan hierarki dokumen
            $table->unsignedInteger('versi_ke')->default(1)->comment('Nomor urut versi berkas (1, 2, 3...)');
            $table->boolean('is_terkini')->default(true)->comment('1 jika dokumen versi aktif terkini, 0 jika arsip revisi');
            $table->unsignedBigInteger('induk_dokumen_id')->nullable()->comment('Self-ref FK ke dokumen versi sebelumnya yang diperbaiki');

            // Metadata fisik SK dari tata usaha fakultas
            $table->string('nomor_sk', 100)->comment('Nomor resmi SK fisik dari tata usaha fakultas');
            $table->date('tanggal_sk')->comment('Tanggal penetapan resmi yang tertera pada lembar SK');
            $table->string('file_path', 255)->comment('Path relatif storage privat (sk_skripsi/{hash}.pdf)');
            $table->char('file_hash_sha256', 64)->comment('SHA-256 checksum integritas fisik berkas upload at-rest');
            $table->unsignedBigInteger('file_size_bytes')->comment('Ukuran fisik berkas PDF dalam byte (maks 10.000.000 byte)');

            // Status verifikasi administratif
            $table->enum('status_dokumen', [
                'MENUNGGU_PEMERIKSAAN',
                'PERLU_PERBAIKAN',
                'DITOLAK',
                'TERVERIFIKASI',
                'DIBATALKAN',
            ])->default('MENUNGGU_PEMERIKSAAN');

            $table->unsignedBigInteger('admin_id')->nullable()->comment('FK ke users(id) Admin pemeriksa berkas');
            $table->foreign('admin_id', 'fk_skdok_admin')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->timestamp('diperiksa_at')->nullable()->comment('Waktu verifikasi administratif oleh Admin');
            $table->text('catatan_admin')->nullable()->comment('Catatan koreksi jika status PERLU_PERBAIKAN atau DITOLAK');

            // Usulan dosen pembimbing saat upload mahasiswa
            $table->unsignedBigInteger('dosen_1_usulan_id')->nullable()->comment('Usulan Pembimbing 1 yang dipilih mahasiswa saat upload');
            $table->foreign('dosen_1_usulan_id', 'fk_skdok_dosen1')
                ->references('id_dosen')
                ->on('dosen')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('dosen_2_usulan_id')->nullable()->comment('Usulan Pembimbing 2 yang dipilih mahasiswa saat upload');
            $table->foreign('dosen_2_usulan_id', 'fk_skdok_dosen2')
                ->references('id_dosen')
                ->on('dosen')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            // Generated Virtual Column (Multi-NULL Safe Concurrency Control)
            $table->string('terkini_slot', 20)->virtualAs(
                "CASE WHEN `is_terkini` = 1 THEN 'CURRENT' ELSE NULL END"
            )->nullable()->comment('Slot penjamin tepat 1 dokumen berstatus terkini per skripsi');

            $table->timestamps();

            // Target Composite Key untuk penugasan dan self-reference
            $table->unique(['id', 'skripsi_id'], 'uk_dokumen_id_skripsi');

            // Self-referencing Composite FK
            $table->foreign(['induk_dokumen_id', 'skripsi_id'], 'fk_skdok_induk_composite')
                ->references(['id', 'skripsi_id'])
                ->on('sk_skripsi_dokumen')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            // Constraints Integritas & Indeks
            $table->unique(['skripsi_id', 'versi_ke'], 'uk_skripsi_versi');
            $table->unique(['skripsi_id', 'terkini_slot'], 'uk_skripsi_dokumen_terkini');
            $table->index(['skripsi_id', 'status_dokumen', 'is_terkini'], 'idx_skdok_verifikasi');
            $table->index('file_hash_sha256', 'idx_skdok_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sk_skripsi_dokumen');
    }
};

