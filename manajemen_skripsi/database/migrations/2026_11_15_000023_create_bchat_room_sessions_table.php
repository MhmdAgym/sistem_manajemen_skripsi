<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Buat tabel bchat_room_sessions
        Schema::create('bchat_room_sessions', function (Blueprint $table) {
            $table->id();

            $table->char('session_uuid', 36)->unique()->comment('UUID v4 sesi kriptografi MLS');

            $table->unsignedBigInteger('room_id')->comment('FK ke bchat_rooms(id)');
            $table->foreign('room_id', 'fk_session_room')
                ->references('id')
                ->on('bchat_rooms')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedInteger('generation_number')->default(1)->comment('Nomor generasi sesi MLS (1, 2, 3...) per ruang akademik');
            $table->enum('status', ['PENDING', 'ACTIVE', 'ARCHIVED', 'FAILED'])
                ->default('PENDING')
                ->comment('Status siklus hidup sesi kriptografi MLS');

            $table->char('group_id_hash', 64)->nullable()->comment('SHA-256 hash dari MLS GroupContext.group_id');

            $table->unsignedBigInteger('start_epoch')->default(1)->comment('Epoch awal sesi MLS ini');
            $table->unsignedBigInteger('end_epoch')->nullable()->comment('Epoch akhir sesi MLS saat diarsipkan');

            $table->unsignedBigInteger('start_sequence')->default(1)->comment('Nomor urut pesan server_sequence pertama pada sesi ini');
            $table->unsignedBigInteger('end_sequence')->nullable()->comment('Nomor urut pesan server_sequence terakhir pada sesi ini');
            $table->unsignedBigInteger('current_sequence')->default(0)->comment('Nomor urut pesan server_sequence terakhir yang diproses sesi ini');

            $table->unsignedBigInteger('created_by_user_id')->nullable()->comment('FK ke users(id) pembuat inisiasi sesi');
            $table->foreign('created_by_user_id', 'fk_session_creator')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->timestamp('activated_at')->nullable()->comment('Waktu sesi aktif setelah Welcome ACK berhasil');
            $table->timestamp('archived_at')->nullable()->comment('Waktu sesi diarsipkan karena pembuatan generasi berikutnya');
            $table->timestamp('failed_at')->nullable()->comment('Waktu sesi ditandai gagal');
            $table->string('failure_reason', 255)->nullable()->comment('Alasan teknis kegagalan sesi jika status FAILED');

            // Virtual column untuk concurrency control: maksimal 1 sesi ACTIVE per room_id
            $table->string('active_room_slot', 64)->virtualAs(
                "CASE WHEN `status` = 'ACTIVE' THEN CAST(`room_id` AS CHAR) ELSE NULL END"
            )->nullable()->comment('Slot penjamin maksimal 1 sesi ACTIVE per room');

            $table->timestamps();

            // Constraints & Indeks
            $table->unique(['room_id', 'generation_number'], 'uk_room_generation');
            $table->unique('active_room_slot', 'uk_session_active_room_slot');
            $table->index(['room_id', 'status'], 'idx_session_room_status');
        });

        // 2. Tambah kolom session_id ke bchat_messages, bchat_attachments, dan bchat_welcome_challenges
        Schema::table('bchat_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('session_id')->nullable()->after('room_id')->comment('FK ke bchat_room_sessions(id)');
        });

        Schema::table('bchat_attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('session_id')->nullable()->after('room_id')->comment('FK ke bchat_room_sessions(id)');
        });

        Schema::table('bchat_welcome_challenges', function (Blueprint $table) {
            $table->unsignedBigInteger('session_id')->nullable()->after('room_id')->comment('FK ke bchat_room_sessions(id)');
        });

        // 3. Backfill deterministik untuk room yang sudah ada (misal Room 1 dengan 10 pesan & 4 lampiran)
        $existingRooms = DB::table('bchat_rooms')->get();
        foreach ($existingRooms as $room) {
            $now = now();
            $sessionStatus = match ($room->status) {
                'ACTIVE' => 'ACTIVE',
                'PENDING_WELCOME_ACK' => 'PENDING',
                default => 'ARCHIVED',
            };

            $sessionId = DB::table('bchat_room_sessions')->insertGetId([
                'session_uuid'       => (string) Str::uuid(),
                'room_id'            => $room->id,
                'generation_number'  => 1,
                'status'             => $sessionStatus,
                'group_id_hash'      => $room->group_id_hash ?? null,
                'start_epoch'        => 1,
                'end_epoch'          => ($sessionStatus === 'ACTIVE' || $sessionStatus === 'PENDING') ? null : $room->current_epoch,
                'start_sequence'     => 1,
                'end_sequence'       => ($sessionStatus === 'ACTIVE' || $sessionStatus === 'PENDING') ? null : $room->current_sequence,
                'current_sequence'   => $room->current_sequence ?? 0,
                'created_by_user_id' => null,
                'activated_at'       => $sessionStatus === 'ACTIVE' ? ($room->updated_at ?? $now) : null,
                'archived_at'        => $sessionStatus === 'ARCHIVED' ? ($room->updated_at ?? $now) : null,
                'created_at'         => $room->created_at ?? $now,
                'updated_at'         => $room->updated_at ?? $now,
            ]);

            DB::table('bchat_messages')
                ->where('room_id', $room->id)
                ->whereNull('session_id')
                ->update(['session_id' => $sessionId]);

            DB::table('bchat_attachments')
                ->where('room_id', $room->id)
                ->whereNull('session_id')
                ->update(['session_id' => $sessionId]);

            DB::table('bchat_welcome_challenges')
                ->where('room_id', $room->id)
                ->whereNull('session_id')
                ->update(['session_id' => $sessionId]);
        }

        // 4. Pasang FK dan indeks pada relasi tabel turunan
        Schema::table('bchat_messages', function (Blueprint $table) {
            $table->foreign('session_id', 'fk_msg_session')
                ->references('id')
                ->on('bchat_room_sessions')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->index(['session_id', 'server_sequence'], 'idx_msg_session_sequence');
        });

        Schema::table('bchat_attachments', function (Blueprint $table) {
            $table->foreign('session_id', 'fk_attach_session')
                ->references('id')
                ->on('bchat_room_sessions')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->index(['session_id', 'status'], 'idx_attach_session_status');
        });

        Schema::table('bchat_welcome_challenges', function (Blueprint $table) {
            $table->foreign('session_id', 'fk_challenge_session')
                ->references('id')
                ->on('bchat_room_sessions')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->index(['session_id', 'operation_status'], 'idx_challenge_session_op');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('bchat_welcome_challenges') && Schema::hasColumn('bchat_welcome_challenges', 'session_id')) {
            Schema::table('bchat_welcome_challenges', function (Blueprint $table) {
                $table->dropForeign('fk_challenge_session');
                $table->dropIndex('idx_challenge_session_op');
                $table->dropColumn('session_id');
            });
        }

        if (Schema::hasTable('bchat_attachments') && Schema::hasColumn('bchat_attachments', 'session_id')) {
            Schema::table('bchat_attachments', function (Blueprint $table) {
                $table->dropForeign('fk_attach_session');
                $table->dropIndex('idx_attach_session_status');
                $table->dropColumn('session_id');
            });
        }

        if (Schema::hasTable('bchat_messages') && Schema::hasColumn('bchat_messages', 'session_id')) {
            Schema::table('bchat_messages', function (Blueprint $table) {
                $table->dropForeign('fk_msg_session');
                $table->dropIndex('idx_msg_session_sequence');
                $table->dropColumn('session_id');
            });
        }

        Schema::dropIfExists('bchat_room_sessions');
    }
};

