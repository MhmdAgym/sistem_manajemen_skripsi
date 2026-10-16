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
    Schema::table('dosen', function (Blueprint $table) {
        // Mengubah panjang NIDN menjadi maksimal 10 karakter
        $table->string('NIDN', 10)->change();

        // Menambahkan kolom profil dosen
        $table->string('no_handphone', 20)->nullable();
        $table->string('jenis_kelamin', 20)->nullable();
    });
}

public function down(): void
{
    Schema::table('dosen', function (Blueprint $table) {
        // Menghapus kolom tambahan jika migration di-rollback
        $table->dropColumn([
            'no_handphone',
            'jenis_kelamin',
        ]);

        // Mengembalikan panjang kolom NIDN
        $table->string('NIDN', 20)->change();
    });
}

};
