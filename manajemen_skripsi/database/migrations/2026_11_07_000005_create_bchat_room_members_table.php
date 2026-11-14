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
        Schema::create('bchat_room_members', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('room_id');
            $table->foreign('room_id', 'fk_member_room')
                ->references('id')
                ->on('bchat_rooms')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id', 'fk_member_user')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->enum('role', ['mahasiswa', 'dosen_pembimbing']);
            $table->boolean('is_initiator')->default(false)->comment('1 jika inisiator pembuat MLS Group (bebas ACK), 0 jika penerima Welcome');
            $table->boolean('is_active')->default(false)->comment('1 jika anggota aktif dan diizinkan kirim pesan; diubah 1 setelah ACK sah');
            $table->unsignedBigInteger('joined_at_epoch')->default(0)->comment('Epoch saat anggota resmi bergabung (uint64)');
            $table->timestamp('welcome_ack_at')->nullable()->comment('Waktu verifikasi tanda tangan digital Welcome ACK oleh server');

            $table->timestamps();

            $table->unique(['room_id', 'user_id'], 'uk_room_member');
            $table->index(['user_id', 'is_active'], 'idx_member_auth');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_room_members');
    }
};

