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
        // 1. Tabel bchat_commits: Menyimpan transaksi Commit MLS terenkripsi untuk relay antarperangkat
        Schema::create('bchat_commits', function (Blueprint $table) {
            $table->id();

            $table->char('commit_uuid', 36)->unique('uk_commit_uuid')->comment('UUID v4 unik transaksi commit');

            $table->unsignedBigInteger('room_id')->comment('FK ke bchat_rooms(id)');
            $table->foreign('room_id', 'fk_commit_room')
                ->references('id')
                ->on('bchat_rooms')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('session_id')->comment('FK ke bchat_room_sessions(id)');
            $table->foreign('session_id', 'fk_commit_session')
                ->references('id')
                ->on('bchat_room_sessions')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('sender_user_id')->comment('FK ke users(id) pembuat commit');
            $table->foreign('sender_user_id', 'fk_commit_sender_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('sender_device_id')->comment('FK ke bchat_user_devices(id)');
            $table->foreign('sender_device_id', 'fk_commit_sender_device')
                ->references('id')
                ->on('bchat_user_devices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('from_epoch')->comment('Epoch asal grup MLS sebelum commit diaplikasikan');
            $table->unsignedBigInteger('to_epoch')->comment('Epoch target grup MLS setelah commit diaplikasikan');

            $table->binary('commit_message')->comment('Wireformat TLS biner RFC 9420 MLS Commit');
            $table->char('commit_hash', 64)->unique('uk_commit_hash')->comment('Hash SHA-256 wireformat commit untuk anti-replay & idempotensi');

            $table->unsignedBigInteger('added_device_id')->nullable()->comment('FK ke bchat_user_devices(id) jika commit menambah anggota');
            $table->foreign('added_device_id', 'fk_commit_added_device')
                ->references('id')
                ->on('bchat_user_devices')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('key_package_id')->nullable()->comment('FK ke bchat_key_packages(id) yang dikonsumsi commit');
            $table->foreign('key_package_id', 'fk_commit_key_package')
                ->references('id')
                ->on('bchat_key_packages')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->char('welcome_operation_uuid', 36)->nullable()->comment('UUID operasi welcome terkait jika commit menghasilkan welcome');

            $table->enum('status', ['PENDING_ACKS', 'ACTIVE', 'ABORTED', 'SUPERSEDED'])
                ->default('PENDING_ACKS')
                ->comment('Status daur hidup transisi epoch dua fase');

            $table->timestamp('transition_completed_at')->nullable()->comment('Waktu seluruh ACK lengkap dan epoch room dimajukan');

            $table->timestamps();

            // Constraint: Dalam satu room, transisi dari from_epoch ke to_epoch wajib unik (deterministik monotonik)
            $table->unique(['room_id', 'from_epoch', 'to_epoch'], 'uk_room_epoch_transition');
            $table->index(['room_id', 'status', 'to_epoch'], 'idx_commit_room_lookup');
        });

        // 2. Tabel bchat_commit_acks: Menyimpan bukti ACK kriptografis Ed25519 per perangkat
        Schema::create('bchat_commit_acks', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('commit_id')->comment('FK ke bchat_commits(id)');
            $table->foreign('commit_id', 'fk_commit_ack_commit')
                ->references('id')
                ->on('bchat_commits')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('device_id')->comment('FK ke bchat_user_devices(id)');
            $table->foreign('device_id', 'fk_commit_ack_device')
                ->references('id')
                ->on('bchat_user_devices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('user_id')->comment('FK ke users(id)');
            $table->foreign('user_id', 'fk_commit_ack_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->enum('ack_type', ['COMMIT_ACK', 'WELCOME_ACK'])
                ->default('COMMIT_ACK')
                ->comment('Tipe ACK: COMMIT_ACK untuk anggota lama, WELCOME_ACK untuk anggota baru');

            $table->unsignedBigInteger('acked_epoch')->comment('Epoch yang di-ACK');
            $table->string('signature', 255)->comment('Ed25519 signature hex atas payload kanonikal');
            $table->string('canonical_payload', 500)->nullable()->comment('String kanonikal yang ditandatangani');
            $table->timestamp('acked_at')->comment('Waktu ACK');

            $table->timestamps();

            // Constraint: Satu perangkat hanya boleh mencatat 1 ACK per commit (idempotent)
            $table->unique(['commit_id', 'device_id'], 'uk_commit_device_ack');
            $table->index(['device_id', 'acked_epoch'], 'idx_device_acked_epoch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_commit_acks');
        Schema::dropIfExists('bchat_commits');
    }
};
