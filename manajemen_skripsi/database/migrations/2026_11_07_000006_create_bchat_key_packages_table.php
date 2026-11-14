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
        Schema::create('bchat_key_packages', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id', 'fk_keypkg_user')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->char('key_package_hash', 64)->unique()->comment('Hash SHA-256 data biner KeyPackage');
            $table->rawColumn('key_package_data', 'longblob')->comment('Paket biner KeyPackage MLS bertanda tangan digital CA Ed25519');
            $table->boolean('is_consumed')->default(false)->comment('1 jika sudah digunakan untuk Add proposal, 0 jika masih tersedia');
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'is_consumed'], 'idx_keypkg_available');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_key_packages');
    }
};

