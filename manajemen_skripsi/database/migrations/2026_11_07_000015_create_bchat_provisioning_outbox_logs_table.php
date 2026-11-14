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
        Schema::create('bchat_provisioning_outbox_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('outbox_id')
                ->comment('FK ke bchat_provisioning_outbox induk');
            $table->foreign('outbox_id', 'fk_outbox_log_induk')
                ->references('id')
                ->on('bchat_provisioning_outbox')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('user_id')
                ->comment('FK ke users(id) aktor administrator yang memicu replay');
            $table->foreign('user_id', 'fk_outbox_log_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->string('aktor_nama', 150)
                ->comment('Snapshot nama administrator saat tindakan dilakukan');

            $table->string('aktor_role', 50)
                ->comment('Snapshot role administrator');

            $table->string('alasan_replay', 500)
                ->comment('Justifikasi administratif perbaikan akar masalah sebelum memicu replay');

            $table->unsignedTinyInteger('snapshot_attempts_sebelum')
                ->comment('Nilai attempts terakhir sebelum di-reset menjadi 0');

            $table->string('snapshot_error_terakhir', 1000)->nullable()
                ->comment('Snapshot pesan error terakhir sebelum replay');

            $table->dateTime('created_at', 6)
                ->comment('Waktu UTC pencatatan log audit replay dilakukan');

            $table->index(['outbox_id', 'created_at'], 'idx_outbox_log_history');
            $table->index(['user_id', 'created_at'], 'idx_outbox_log_actor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_provisioning_outbox_logs');
    }
};

