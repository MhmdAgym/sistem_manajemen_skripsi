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
        Schema::create('bchat_crl_revocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->char('revoked_credential_uuid', 36)->unique()->comment('UUID kredensial identitas yang dicabut oleh CA');
            $table->string('reason', 255)->comment('Alasan pencabutan kredensial identitas');
            $table->timestamp('revoked_at')->useCurrent();

            // ON DELETE RESTRICT menjamin rekaman pencabutan kredensial bersifat abadi
            $table->foreign('user_id', 'fk_crl_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['user_id', 'revoked_at'], 'idx_crl_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_crl_revocations');
    }
};

