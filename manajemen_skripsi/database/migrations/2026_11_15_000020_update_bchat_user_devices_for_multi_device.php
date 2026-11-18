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
        Schema::table('bchat_user_devices', function (Blueprint $table) {
            $table->dropUnique('uk_device_active_user_slot');
            $table->dropColumn('active_user_slot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bchat_user_devices', function (Blueprint $table) {
            $table->string('active_user_slot', 64)->virtualAs(
                "CASE WHEN `status` = 'ACTIVE' THEN CONCAT('user_', `user_id`) ELSE NULL END"
            )->nullable()->comment('Slot penjamin maksimal 1 perangkat aktif per pengguna');

            $table->unique('active_user_slot', 'uk_device_active_user_slot');
        });
    }
};

