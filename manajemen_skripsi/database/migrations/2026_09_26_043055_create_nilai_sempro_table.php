<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nilai_sempro', function (Blueprint $table) {
            $table->id('id_nilai_sempro');

            $table->foreignId('id_sempro')
                  ->constrained('sempro', 'id_sempro')
                  ->cascadeOnDelete();

            $table->date('tanggal_ujian');

            $table->enum('status_ujian', [
                'lulus',
                'tidak lulus'
            ]);

            $table->foreignId('id_penguji_sempro')
                  ->constrained('penguji_sempro', 'id_penguji_sempro')
                  ->cascadeOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nilai_sempro');
    }
};