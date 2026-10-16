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
    Schema::table('mahasiswa', function (Blueprint $table) {
        // Mengubah panjang NIM menjadi maksimal 10 karakter
        $table->string('NIM', 10)->change();

        // Menambahkan kolom profil mahasiswa
        $table->string('no_handphone', 20)->nullable();
        $table->string('jenis_kelamin', 20)->nullable();
        $table->string('fakultas')->nullable();
    });
}

public function down(): void
{
    Schema::table('mahasiswa', function (Blueprint $table) {
        // Menghapus kolom tambahan jika migration di-rollback
        $table->dropColumn([
            'no_handphone',
            'jenis_kelamin',
            'fakultas',
        ]);

        // Mengembalikan panjang kolom NIM
        $table->string('NIM', 20)->change();
    });
}

};
