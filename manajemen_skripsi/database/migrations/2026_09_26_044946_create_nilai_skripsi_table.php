<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nilai_skripsi', function (Blueprint $table) {
            $table->id('id_nilai');

            $table->foreignId('id_ujian_skripsi')
                  ->constrained('ujian_skripsi', 'id_ujian_skripsi')
                  ->cascadeOnDelete();

            $table->foreignId('id_dosen')
                  ->constrained('dosen', 'id_dosen')
                  ->cascadeOnDelete();

            $table->decimal('nilai', 5, 2);

            $table->text('catatan')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nilai_skripsi');
    }
};