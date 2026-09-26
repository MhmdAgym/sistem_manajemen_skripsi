<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bimbingan', function (Blueprint $table) {
            $table->id('id_bimbingan');

            $table->foreignId('id_skripsi')
                  ->constrained('skripsi', 'id_skripsi')
                  ->onDelete('cascade');

            $table->date('tanggal');
            $table->text('catatan');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bimbingan');
    }
};