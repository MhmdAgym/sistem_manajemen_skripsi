<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dosen_penguji_skripsi', function (Blueprint $table) {
    $table->id('id_penguji_skripsi');

    // Kolom & Relasi Dosen
    $table->unsignedBigInteger('id_dosen');
    $table->foreign('id_dosen')
          ->references('id_dosen') // ganti 'id' jika di tabel dosen primary key-nya 'id'
          ->on('dosen')
          ->cascadeOnDelete();

    // Kolom & Relasi Ujian Skripsi
    $table->unsignedBigInteger('id_ujian_skripsi');
    $table->foreign('id_ujian_skripsi')
          ->references('id_ujian_skripsi')
          ->on('ujian_skripsi')
          ->cascadeOnDelete();

    $table->enum('peran', ['ketua', 'anggota']);
    $table->timestamps(); // tambahkan ini jika menggunakan timestamp

    // Unique Constraint
    $table->unique(['id_dosen', 'id_ujian_skripsi']);
});
    }

    public function down(): void
    {
        Schema::dropIfExists('dosen_penguji_skripsi');
    }
};