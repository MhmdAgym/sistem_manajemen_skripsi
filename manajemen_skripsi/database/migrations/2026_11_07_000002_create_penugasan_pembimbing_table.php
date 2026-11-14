<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('penugasan_pembimbing', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('skripsi_id')->comment('FK ke skripsi induk');
            $table->foreign('skripsi_id', 'fk_penugasan_skripsi')
                ->references('id_skripsi')
                ->on('skripsi')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->enum('tahap', ['proposal', 'skripsi'])->comment('Tahap bimbingan akademik');
            $table->unsignedTinyInteger('pembimbing_ke')->comment('Ordinal: 1 (Proposal / Pembimbing Utama), 2 (Pembimbing Pendamping)');

            $table->unsignedBigInteger('dosen_id')->comment('FK ke dosen yang ditugaskan');
            $table->foreign('dosen_id', 'fk_penugasan_dosen')
                ->references('id_dosen')
                ->on('dosen')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('sk_dokumen_id')->nullable()->comment('FK ke sk_skripsi_dokumen; WAJIB NULL jika proposal, WAJIB TERISI jika skripsi');
            $table->unsignedBigInteger('pengajuan_dospem_id')->nullable()->comment('FK ke pengajuan_dospem (khusus tahap proposal)');
            $table->foreign('pengajuan_dospem_id', 'fk_penugasan_pengajuan')
                ->references('id')
                ->on('pengajuan_dospem')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->enum('status_penugasan', ['AKTIF', 'DIGANTI', 'DIBATALKAN'])->default('AKTIF');
            $table->date('tanggal_mulai')->comment('Tanggal dimulainya penugasan pembimbing');
            $table->date('tanggal_selesai')->nullable()->comment('Tanggal berakhirnya penugasan (jika diganti/dibatalkan/lulus)');

            $table->unsignedBigInteger('digantikan_oleh_id')->nullable()->comment('Self-ref FK ke penugasan baru yang menggantikan posisi ini');
            $table->foreign('digantikan_oleh_id', 'fk_penugasan_silsilah')
                ->references('id')
                ->on('penugasan_pembimbing')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            // Composite FK: Menjamin dokumen SK yang dirujuk benar-benar milik skripsi yang sama
            $table->foreign(['sk_dokumen_id', 'skripsi_id'], 'fk_penugasan_sk_composite')
                ->references(['id', 'skripsi_id'])
                ->on('sk_skripsi_dokumen')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            // Generated Virtual Column (Multi-NULL Safe Concurrency Control)
            $table->string('active_slot', 50)->virtualAs(
                "CASE WHEN `status_penugasan` = 'AKTIF' THEN CONCAT(`tahap`, '_', `pembimbing_ke`) ELSE NULL END"
            )->nullable()->comment('Slot penjamin tepat 1 dosen aktif per kombinasi tahap & nomor pembimbing');

            $table->timestamps();

            // Indeks Unik Slot Aktif & Pendukung Performa
            $table->unique(['skripsi_id', 'active_slot'], 'uk_skripsi_active_slot');
            $table->index(['dosen_id', 'status_penugasan'], 'idx_penugasan_dosen_beban');
            $table->index(['skripsi_id', 'tahap', 'status_penugasan'], 'idx_penugasan_lookup');
        });

        // Penegakan CHECK Constraints Terbuka (MySQL 8.0.30 Enforced)
        DB::statement("ALTER TABLE `penugasan_pembimbing` ADD CONSTRAINT `chk_penugasan_tahap_sk_bifurcation` CHECK (
            (`tahap` = 'proposal' AND `sk_dokumen_id` IS NULL AND `pembimbing_ke` = 1)
            OR
            (`tahap` = 'skripsi' AND `sk_dokumen_id` IS NOT NULL AND `pembimbing_ke` IN (1, 2))
        )");

        DB::statement("ALTER TABLE `penugasan_pembimbing` ADD CONSTRAINT `chk_penugasan_pembimbing_ke_domain` CHECK (`pembimbing_ke` IN (1, 2))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penugasan_pembimbing');
    }
};

