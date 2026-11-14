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
        Schema::create('bchat_attachment_chunks', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('attachment_id');
            $table->foreign('attachment_id', 'fk_chunk_attach')
                ->references('id')
                ->on('bchat_attachments')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedInteger('chunk_index')->comment('Indeks urutan chunk (zero-based: 0, 1, 2...)');
            $table->unsignedInteger('payload_length')->comment('Panjang biner chunk Li + 28 Byte (12 Nonce + Li + 16 Tag)');
            $table->string('storage_path', 255)->comment('Path relatif storage privat Laravel (bchat_attachments/{prefix}/{hash}.bin)');
            $table->char('storage_hash_sha256', 64)->comment('Checksum SHA-256 potongan chunk biner tersimpan');

            $table->timestamps();

            $table->unique(['attachment_id', 'chunk_index'], 'uk_attach_chunk_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_attachment_chunks');
    }
};

