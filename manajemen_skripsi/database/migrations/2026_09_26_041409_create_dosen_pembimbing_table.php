<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dosen_pembimbing', function (Blueprint $table) {
            $table->id('id_pembimbing');

            $table->foreignId('id_skripsi')
                  ->constrained('skripsi', 'id_skripsi')
                  ->onDelete('cascade');

            $table->foreignId('id_dosen')
                  ->constrained('dosen', 'id_dosen')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dosen_pembimbing');
    }
};
