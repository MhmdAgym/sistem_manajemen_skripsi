<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sempro', function (Blueprint $table) {
            $table->id('id_sempro');

            $table->foreignId('id_skripsi')
                  ->constrained('skripsi', 'id_skripsi')
                  ->cascadeOnDelete();

            $table->date('tanggal_sempro');

            $table->enum('status_sempro', [
                'lulus',
                'tidak lulus'
            ]);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sempro');
    }
};