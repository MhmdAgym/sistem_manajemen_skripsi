<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penguji_sempro', function (Blueprint $table) {
            $table->id('id_penguji_sempro');

            $table->foreignId('id_dosen')
                  ->constrained('dosen', 'id_dosen')
                  ->cascadeOnDelete();

            $table->foreignId('id_sempro')
                  ->constrained('sempro', 'id_sempro')
                  ->cascadeOnDelete();

            $table->enum('peran', [
                'penguji 1',
                'penguji 2'
            ]);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penguji_sempro');
    }
};