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
        Schema::table('pengajuan_judul', function (Blueprint $table) {
            $table->unsignedBigInteger('skripsi_id')->nullable()->after('mahasiswa_id');
            $table->foreign('skripsi_id')
                ->references('id_skripsi')
                ->on('skripsi')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pengajuan_judul', function (Blueprint $table) {
            $table->dropForeign(['skripsi_id']);
            $table->dropColumn('skripsi_id');
        });
    }
};
