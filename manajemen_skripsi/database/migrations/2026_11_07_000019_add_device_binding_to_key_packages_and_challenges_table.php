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
        Schema::table('bchat_key_packages', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->after('user_id')->comment('FK ke bchat_user_devices(id) perangkat penerbit');
            $table->foreign('device_id', 'fk_keypkg_device')
                ->references('id')
                ->on('bchat_user_devices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['device_id', 'is_consumed'], 'idx_keypkg_device_available');
        });

        Schema::table('bchat_welcome_challenges', function (Blueprint $table) {
            $table->unsignedBigInteger('recipient_device_id')->after('recipient_user_id')->comment('FK ke bchat_user_devices(id) perangkat penerima target');
            $table->foreign('recipient_device_id', 'fk_challenge_recipient_device')
                ->references('id')
                ->on('bchat_user_devices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['recipient_device_id', 'operation_status'], 'idx_challenge_recipient_device');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bchat_welcome_challenges', function (Blueprint $table) {
            $table->dropForeign('fk_challenge_recipient_device');
            $table->dropIndex('idx_challenge_recipient_device');
            $table->dropColumn('recipient_device_id');
        });

        Schema::table('bchat_key_packages', function (Blueprint $table) {
            $table->dropForeign('fk_keypkg_device');
            $table->dropIndex('idx_keypkg_device_available');
            $table->dropColumn('device_id');
        });
    }
};

