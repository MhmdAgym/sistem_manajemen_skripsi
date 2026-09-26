<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skripsi', function (Blueprint $table) {
            $table->id('id_skripsi');

            $table->foreignId('id_mahasiswa')
                  ->constrained('mahasiswa', 'id_mahasiswa')
                  ->onDelete('cascade');

            $table->string('judul');

            $table->string('status_judul')->default('diajukan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skripsi');
    }
};
