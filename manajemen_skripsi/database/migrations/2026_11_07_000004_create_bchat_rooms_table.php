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
        Schema::create('bchat_rooms', function (Blueprint $table) {
            $table->id();

            $table->char('room_uuid', 36)->unique()->comment('UUID v4 publik room');

            $table->unsignedBigInteger('penugasan_pembimbing_id')->unique()->comment('1-to-1 relasi eksklusif dengan penugasan yang sah');
            $table->foreign('penugasan_pembimbing_id', 'fk_room_penugasan')
                ->references('id')
                ->on('penugasan_pembimbing')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->enum('tahap', ['proposal', 'skripsi']);
            $table->unsignedTinyInteger('pembimbing_ke')->default(1);
            $table->unsignedBigInteger('current_sequence')->default(0)->comment('Counter monotonik server per room (SELECT ... FOR UPDATE)');

            $table->enum('status', [
                'PENDING_OFFICIAL_SK',
                'PENDING_WELCOME_ACK',
                'ACTIVE',
                'PENDING_REKEYING',
                'SUSPENDED',
                'ARCHIVED_READONLY',
                'TERMINATED',
            ])->default('PENDING_WELCOME_ACK');

            $table->unsignedBigInteger('current_epoch')->default(0)->comment('Nomor epoch grup MLS aktif saat ini (uint64 RFC 9420)');
            $table->char('group_id_hash', 64)->nullable()->comment('Hash SHA-256 dari MLS GroupContext.group_id untuk validasi binding');

            $table->timestamps();

            $table->index(['status', 'tahap'], 'idx_room_lifecycle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_rooms');
    }
};

