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
        Schema::create('bchat_provisioning_outbox', function (Blueprint $table) {
            $table->id();

            $table->char('event_uuid', 36)->unique('uk_outbox_event_uuid')
                ->comment('UUID v4 unik global untuk idempotensi payload dan korelasi log');

            $table->unsignedBigInteger('penugasan_pembimbing_id')
                ->comment('FK ke penugasan_pembimbing canonical');
            $table->foreign('penugasan_pembimbing_id', 'fk_outbox_penugasan')
                ->references('id')
                ->on('penugasan_pembimbing')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->string('event_type', 50)
                ->comment('Tipe event outbox, e.g. PROPOSAL_ROOM_PROVISION');

            $table->json('payload')
                ->comment('Metadata referensi minimum: event_uuid, penugasan_pembimbing_id, event_type, schema_version');

            $table->enum('status', ['PENDING', 'PROCESSING', 'COMPLETED', 'FAILED'])
                ->default('PENDING')
                ->comment('State lifecycle antrean outbox');

            $table->unsignedTinyInteger('attempts')
                ->default(0)
                ->comment('Pencacah kegagalan yang mengonsumsi kuota retry (maksimal 6)');

            $table->dateTime('next_attempt_at', 6)->nullable()
                ->comment('Jadwal UTC eksekusi berikutnya berdasarkan exponential backoff');

            $table->dateTime('locked_at', 6)->nullable()
                ->comment('Waktu UTC worker mengklaim hak sewa baris ini');

            $table->string('locked_by', 100)->nullable()
                ->comment('Identitas unik worker pemegang lease (hostname:pid:uuid)');

            $table->unsignedBigInteger('generation_token')
                ->default(1)
                ->comment('Fencing token monotonik untuk mem-fence worker lama');

            $table->string('last_error_code', 50)->nullable()
                ->comment('Kode klasifikasi error mesin (e.g. ASSIGNMENT_INACTIVE, LEASE_TIMEOUT)');

            $table->string('last_error', 1000)->nullable()
                ->comment('Ringkasan error tersanitasi UTF-8 multibyte-safe');

            $table->dateTime('failed_at', 6)->nullable()
                ->comment('Waktu UTC event masuk ke status terminal FAILED');

            $table->dateTime('completed_at', 6)->nullable()
                ->comment('Waktu UTC provisioning room dan member berhasil');

            $table->unsignedSmallInteger('replay_count')
                ->default(0)
                ->comment('Akumulator jumlah intervensi replay administratif');

            $table->dateTime('replayed_at', 6)->nullable()
                ->comment('Waktu UTC terakhir replay manual administratif dijalankan');

            // Wajib NOT NULL dengan pengisian UTC eksplisit
            $table->dateTime('created_at', 6)
                ->comment('Waktu UTC baris dibuat dalam transaksi penetapan akademik');
            $table->dateTime('updated_at', 6)
                ->comment('Waktu UTC baris terakhir kali dimutasi');

            // Unique constraint menjamin 1 event kanonis proposal per penugasan
            $table->unique(['penugasan_pembimbing_id', 'event_type'], 'uk_outbox_penugasan_event');

            // Indeks penunjang performa worker dispatch dan reaper
            $table->index(['status', 'next_attempt_at', 'attempts'], 'idx_outbox_dispatch');
            $table->index(['status', 'locked_at'], 'idx_outbox_reaper');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_provisioning_outbox');
    }
};

