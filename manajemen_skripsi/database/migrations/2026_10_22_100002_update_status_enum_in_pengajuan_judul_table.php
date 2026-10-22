<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE pengajuan_judul MODIFY COLUMN status ENUM(
            'Menunggu Verifikasi Dosen PA',
            'Disetujui Dosen PA',
            'Menunggu Persetujuan Kaprodi',
            'Disetujui Kaprodi',
            'Revisi Dosen PA',
            'Ditolak Dosen PA',
            'Revisi Kaprodi',
            'Ditolak Kaprodi',
            'Revisi',
            'Ditolak'
        ) NOT NULL DEFAULT 'Menunggu Verifikasi Dosen PA'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $newStatuses = [
            'Menunggu Persetujuan Kaprodi',
            'Disetujui Kaprodi',
            'Revisi Dosen PA',
            'Ditolak Dosen PA',
            'Revisi Kaprodi',
            'Ditolak Kaprodi',
        ];

        $count = DB::table('pengajuan_judul')->whereIn('status', $newStatuses)->count();

        if ($count > 0) {
            throw new \RuntimeException(
                "Gagal rollback migrasi status ENUM: Terdapat {$count} record dengan status baru pada tabel pengajuan_judul. Lakukan resolusi data sebelum rollback."
            );
        }

        DB::statement("ALTER TABLE pengajuan_judul MODIFY COLUMN status ENUM(
            'Menunggu Verifikasi Dosen PA',
            'Disetujui Dosen PA',
            'Revisi',
            'Ditolak'
        ) NOT NULL DEFAULT 'Menunggu Verifikasi Dosen PA'");
    }
};
