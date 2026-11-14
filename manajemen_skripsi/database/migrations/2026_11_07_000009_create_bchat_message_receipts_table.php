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
        Schema::create('bchat_message_receipts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('message_id');
            $table->foreign('message_id', 'fk_receipt_msg')
                ->references('id')
                ->on('bchat_messages')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id', 'fk_receipt_user')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->enum('status', ['DELIVERED', 'READ']);
            $table->timestamp('status_at')->useCurrent();

            $table->unique(['message_id', 'user_id', 'status'], 'uk_msg_user_status');
            $table->index(['user_id', 'status'], 'idx_receipt_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_message_receipts');
    }
};

