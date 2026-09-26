<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ujian_skripsi', function (Blueprint $table) {
            $table->id('id_ujian_skripsi');

            $table->foreignId('id_sempro')
                  ->constrained('sempro', 'id_sempro')
                  ->cascadeOnDelete();

            $table->date('tanggal_ujian');

            $table->enum('status_ujian', [
                'lulus',
                'tidak lulus'
            ]);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ujian_skripsi');
    }
};