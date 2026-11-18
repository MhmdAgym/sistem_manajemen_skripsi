<?php

namespace Tests\Unit;

use App\Models\BchatKeyPackage;
use App\Models\BchatRegistrationNonce;
use App\Models\BchatStepUpToken;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class BchatPhase5b1SchemaAndRelationsTest extends TestCase
{
    /**
     * Helper untuk memuat migration anonymous class dari file.
     */
    protected function loadMigration(string $fileName)
    {
        $path = base_path('database/migrations/' . $fileName);
        $this->assertFileExists($path);
        return require $path;
    }

    /*
    |--------------------------------------------------------------------------
    | PENGUJIAN MIGRATION 000016: bchat_user_devices
    |--------------------------------------------------------------------------
    */

    public function test_migration_000016_blueprint_structure_and_constraints(): void
    {
        $migration = $this->loadMigration('2026_11_07_000016_create_bchat_user_devices_table.php');
        $this->assertIsObject($migration);

        $reflection = new \ReflectionMethod($migration, 'up');
        $this->assertTrue($reflection->isPublic());

        // Verifikasi kode migration memuat kolom & constraint yang ditentukan
        $fileContent = file_get_contents(base_path('database/migrations/2026_11_07_000016_create_bchat_user_devices_table.php'));

        // 1. Foreign Key ke users(id) restrictOnDelete & cascadeOnUpdate
        $this->assertStringContainsString("\$table->foreign('user_id', 'fk_device_user')", $fileContent);
        $this->assertStringContainsString("->references('id')", $fileContent);
        $this->assertStringContainsString("->on('users')", $fileContent);
        $this->assertStringContainsString("->restrictOnDelete()", $fileContent);
        $this->assertStringContainsString("->cascadeOnUpdate()", $fileContent);

        // 2. Kolom Identitas & Kriptografi
        $this->assertStringContainsString("\$table->char('device_uuid', 36)", $fileContent);
        $this->assertStringContainsString("\$table->string('device_name', 100)", $fileContent);
        $this->assertStringContainsString("\$table->unsignedInteger('key_version')->default(1)", $fileContent);
        $this->assertStringContainsString("\$table->char('public_key', 64)->unique('uk_device_public_key')", $fileContent);
        $this->assertStringContainsString("\$table->char('public_key_fingerprint', 64)", $fileContent);
        $this->assertStringContainsString("\$table->enum('status', ['ACTIVE', 'REVOKED', 'EXPIRED'])", $fileContent);
        $this->assertStringContainsString("->default('ACTIVE')", $fileContent);

        // 3. Virtual Column & Unique Slot untuk Single Active Device
        $this->assertStringContainsString("CASE WHEN `status` = 'ACTIVE' THEN CONCAT('user_', `user_id`) ELSE NULL END", $fileContent);
        $this->assertStringContainsString("\$table->unique('active_user_slot', 'uk_device_active_user_slot')", $fileContent);

        // 4. Unique Constraint User + Device UUID + Key Version
        $this->assertStringContainsString("\$table->unique(['user_id', 'device_uuid', 'key_version'], 'uk_device_user_uuid_version')", $fileContent);

        // 5. Indeks Pendukung
        $this->assertStringContainsString("\$table->index(['user_id', 'status'], 'idx_device_user_status')", $fileContent);
        $this->assertStringContainsString("\$table->index('device_uuid', 'idx_device_uuid')", $fileContent);
        $this->assertStringContainsString("\$table->index('public_key_fingerprint', 'idx_device_pubkey_fingerprint')", $fileContent);
    }

    /*
    |--------------------------------------------------------------------------
    | PENGUJIAN MIGRATION 000017: bchat_registration_nonces
    |--------------------------------------------------------------------------
    */

    public function test_migration_000017_blueprint_structure_and_constraints(): void
    {
        $migration = $this->loadMigration('2026_11_07_000017_create_bchat_registration_nonces_table.php');
        $this->assertIsObject($migration);

        $fileContent = file_get_contents(base_path('database/migrations/2026_11_07_000017_create_bchat_registration_nonces_table.php'));

        // 1. Foreign Key ke users(id) restrictOnDelete
        $this->assertStringContainsString("\$table->foreign('user_id', 'fk_regnonce_user')", $fileContent);
        $this->assertStringContainsString("->references('id')", $fileContent);
        $this->assertStringContainsString("->on('users')", $fileContent);
        $this->assertStringContainsString("->restrictOnDelete()", $fileContent);
        $this->assertStringContainsString("->cascadeOnUpdate()", $fileContent);

        // 2. Kolom-kolom PoP 121B & Entropi
        $this->assertStringContainsString("\$table->char('device_uuid', 36)", $fileContent);
        $this->assertStringContainsString("\$table->char('nonce_uuid', 36)->unique('uk_regnonce_uuid')", $fileContent);
        $this->assertStringContainsString("\$table->char('nonce_value', 64)->unique('uk_regnonce_value')", $fileContent);
        $this->assertStringContainsString("\$table->char('public_key_fingerprint', 64)", $fileContent);
        $this->assertStringContainsString("\$table->enum('status', ['ISSUED', 'CONSUMED', 'EXPIRED'])", $fileContent);
        $this->assertStringContainsString("->default('ISSUED')", $fileContent);
        $this->assertStringContainsString("\$table->unsignedBigInteger('issued_at')", $fileContent);
        $this->assertStringContainsString("\$table->timestamp('expires_at')", $fileContent);
        $this->assertStringContainsString("\$table->timestamp('consumed_at')->nullable()", $fileContent);

        // 3. Indeks
        $this->assertStringContainsString("\$table->index(['user_id', 'status', 'expires_at'], 'idx_regnonce_lookup')", $fileContent);
        $this->assertStringContainsString("\$table->index(['device_uuid', 'status'], 'idx_regnonce_device')", $fileContent);
    }

    /*
    |--------------------------------------------------------------------------
    | PENGUJIAN MIGRATION 000018: bchat_step_up_tokens
    |--------------------------------------------------------------------------
    */

    public function test_migration_000018_blueprint_structure_and_constraints(): void
    {
        $migration = $this->loadMigration('2026_11_07_000018_create_bchat_step_up_tokens_table.php');
        $this->assertIsObject($migration);

        $fileContent = file_get_contents(base_path('database/migrations/2026_11_07_000018_create_bchat_step_up_tokens_table.php'));

        // 1. Foreign Key ke users(id) restrictOnDelete
        $this->assertStringContainsString("\$table->foreign('user_id', 'fk_stepup_user')", $fileContent);
        $this->assertStringContainsString("->references('id')", $fileContent);
        $this->assertStringContainsString("->on('users')", $fileContent);
        $this->assertStringContainsString("->restrictOnDelete()", $fileContent);
        $this->assertStringContainsString("->cascadeOnUpdate()", $fileContent);

        // 2. Kolom Token, Device Binding & Audit
        $this->assertStringContainsString("\$table->char('device_uuid', 36)", $fileContent);
        $this->assertStringContainsString("\$table->char('token_hash', 64)->unique('uk_stepup_token_hash')", $fileContent);
        $this->assertStringContainsString("\$table->string('action_scope', 50)->default('DEVICE_REGISTRATION')", $fileContent);
        $this->assertStringContainsString("\$table->boolean('is_consumed')->default(false)", $fileContent);
        $this->assertStringContainsString("\$table->timestamp('expires_at')->comment('Tenggat waktu berlakunya otorisasi step-up (TTL 5 menit)')", $fileContent);
        $this->assertStringContainsString("\$table->timestamp('consumed_at')->nullable()", $fileContent);

        // 3. Indeks Komposit Terikat Device
        $this->assertStringContainsString("\$table->index(['user_id', 'device_uuid', 'action_scope', 'is_consumed', 'expires_at'], 'idx_stepup_verify')", $fileContent);
    }

    /*
    |--------------------------------------------------------------------------
    | PENGUJIAN MIGRATION 000019: add_device_binding
    |--------------------------------------------------------------------------
    */

    public function test_migration_000019_alter_tables_binding_constraints(): void
    {
        $migration = $this->loadMigration('2026_11_07_000019_add_device_binding_to_key_packages_and_challenges_table.php');
        $this->assertIsObject($migration);

        $fileContent = file_get_contents(base_path('database/migrations/2026_11_07_000019_add_device_binding_to_key_packages_and_challenges_table.php'));

        // 1. Binding bchat_key_packages -> bchat_user_devices
        $this->assertStringContainsString("\$table->unsignedBigInteger('device_id')->after('user_id')", $fileContent);
        $this->assertStringContainsString("\$table->foreign('device_id', 'fk_keypkg_device')", $fileContent);
        $this->assertStringContainsString("->references('id')", $fileContent);
        $this->assertStringContainsString("->on('bchat_user_devices')", $fileContent);
        $this->assertStringContainsString("->restrictOnDelete()", $fileContent);
        $this->assertStringContainsString("->cascadeOnUpdate()", $fileContent);
        $this->assertStringContainsString("\$table->index(['device_id', 'is_consumed'], 'idx_keypkg_device_available')", $fileContent);

        // 2. Binding bchat_welcome_challenges -> bchat_user_devices
        $this->assertStringContainsString("\$table->unsignedBigInteger('recipient_device_id')->after('recipient_user_id')", $fileContent);
        $this->assertStringContainsString("\$table->foreign('recipient_device_id', 'fk_challenge_recipient_device')", $fileContent);
        $this->assertStringContainsString("->references('id')", $fileContent);
        $this->assertStringContainsString("->on('bchat_user_devices')", $fileContent);
        $this->assertStringContainsString("->restrictOnDelete()", $fileContent);
        $this->assertStringContainsString("->cascadeOnUpdate()", $fileContent);
        $this->assertStringContainsString("\$table->index(['recipient_device_id', 'operation_status'], 'idx_challenge_recipient_device')", $fileContent);

        // 3. Down drop foreign keys and columns
        $this->assertStringContainsString("\$table->dropForeign('fk_challenge_recipient_device')", $fileContent);
        $this->assertStringContainsString("\$table->dropColumn('recipient_device_id')", $fileContent);
        $this->assertStringContainsString("\$table->dropForeign('fk_keypkg_device')", $fileContent);
        $this->assertStringContainsString("\$table->dropColumn('device_id')", $fileContent);
    }

    /*
    |--------------------------------------------------------------------------
    | PENGUJIAN MODEL: BchatUserDevice
    |--------------------------------------------------------------------------
    */

    public function test_bchat_user_device_model_configuration_and_relations(): void
    {
        $device = new BchatUserDevice();

        // 1. Table & Primary Key
        $this->assertEquals('bchat_user_devices', $device->getTable());
        $this->assertEquals('id', $device->getKeyName());

        // 2. Mass-assignment Fillable (active_user_slot dilindungi)
        $expectedFillable = [
            'user_id',
            'device_uuid',
            'device_name',
            'key_version',
            'public_key',
            'public_key_fingerprint',
            'status',
            'is_trusted',
            'trusted_at',
            'trusted_by_device_id',
            'revoked_at',
            'revocation_reason',
            'last_active_at',
        ];
        $this->assertEquals($expectedFillable, $device->getFillable());
        $this->assertNotContains('active_user_slot', $device->getFillable());

        // 3. Type Casts
        $casts = $device->getCasts();
        $this->assertEquals('integer', $casts['user_id']);
        $this->assertEquals('integer', $casts['key_version']);
        $this->assertEquals('datetime', $casts['revoked_at']);
        $this->assertEquals('datetime', $casts['last_active_at']);

        // 4. Relasi BelongsTo User
        $userRel = $device->user();
        $this->assertInstanceOf(BelongsTo::class, $userRel);
        $this->assertEquals('user_id', $userRel->getForeignKeyName());
        $this->assertEquals('id', $userRel->getOwnerKeyName());
        $this->assertInstanceOf(User::class, $userRel->getRelated());

        // 5. Relasi HasMany KeyPackages
        $kpRel = $device->keyPackages();
        $this->assertInstanceOf(HasMany::class, $kpRel);
        $this->assertEquals('device_id', $kpRel->getForeignKeyName());
        $this->assertEquals('id', $kpRel->getLocalKeyName());
        $this->assertInstanceOf(BchatKeyPackage::class, $kpRel->getRelated());

        // 6. Relasi HasMany WelcomeChallenges
        $wcRel = $device->welcomeChallenges();
        $this->assertInstanceOf(HasMany::class, $wcRel);
        $this->assertEquals('recipient_device_id', $wcRel->getForeignKeyName());
        $this->assertEquals('id', $wcRel->getLocalKeyName());
        $this->assertInstanceOf(BchatWelcomeChallenge::class, $wcRel->getRelated());

        // 7. Helper isActive & scopeExpired
        $device->status = 'ACTIVE';
        $this->assertTrue($device->isActive());
        $device->status = 'REVOKED';
        $this->assertFalse($device->isActive());
        $device->status = 'EXPIRED';
        $this->assertFalse($device->isActive());
        $this->assertTrue(method_exists($device, 'scopeExpired'));
    }

    /*
    |--------------------------------------------------------------------------
    | PENGUJIAN MODEL: BchatRegistrationNonce
    |--------------------------------------------------------------------------
    */

    public function test_bchat_registration_nonce_model_configuration_and_relations(): void
    {
        $nonce = new BchatRegistrationNonce();

        $this->assertEquals('bchat_registration_nonces', $nonce->getTable());
        $this->assertEquals('id', $nonce->getKeyName());

        $expectedFillable = [
            'user_id',
            'device_uuid',
            'nonce_uuid',
            'nonce_value',
            'public_key_fingerprint',
            'status',
            'issued_at',
            'expires_at',
            'consumed_at',
        ];
        $this->assertEquals($expectedFillable, $nonce->getFillable());

        $casts = $nonce->getCasts();
        $this->assertEquals('integer', $casts['user_id']);
        $this->assertEquals('integer', $casts['issued_at']);
        $this->assertEquals('datetime', $casts['expires_at']);
        $this->assertEquals('datetime', $casts['consumed_at']);

        // Relasi BelongsTo User
        $userRel = $nonce->user();
        $this->assertInstanceOf(BelongsTo::class, $userRel);
        $this->assertEquals('user_id', $userRel->getForeignKeyName());
        $this->assertEquals('id', $userRel->getOwnerKeyName());
    }

    /*
    |--------------------------------------------------------------------------
    | PENGUJIAN MODEL: BchatStepUpToken
    |--------------------------------------------------------------------------
    */

    public function test_bchat_step_up_token_model_configuration_and_relations(): void
    {
        $token = new BchatStepUpToken();

        // 1. Konstanta TTL kanonis (5 menit)
        $this->assertEquals(5, BchatStepUpToken::TTL_MINUTES);

        $this->assertEquals('bchat_step_up_tokens', $token->getTable());
        $this->assertEquals('id', $token->getKeyName());

        $expectedFillable = [
            'user_id',
            'device_uuid',
            'token_hash',
            'action_scope',
            'is_consumed',
            'expires_at',
            'consumed_at',
            'ip_address',
            'user_agent',
        ];
        $this->assertEquals($expectedFillable, $token->getFillable());

        $casts = $token->getCasts();
        $this->assertEquals('integer', $casts['user_id']);
        $this->assertEquals('boolean', $casts['is_consumed']);
        $this->assertEquals('datetime', $casts['expires_at']);
        $this->assertEquals('datetime', $casts['consumed_at']);

        // Relasi BelongsTo User
        $userRel = $token->user();
        $this->assertInstanceOf(BelongsTo::class, $userRel);
        $this->assertEquals('user_id', $userRel->getForeignKeyName());
        $this->assertEquals('id', $userRel->getOwnerKeyName());
    }

    /*
    |--------------------------------------------------------------------------
    | PENGUJIAN MODEL DIPERBARUI: BchatKeyPackage
    |--------------------------------------------------------------------------
    */

    public function test_bchat_key_package_model_device_relation_and_fillable(): void
    {
        $kp = new BchatKeyPackage();

        $this->assertContains('device_id', $kp->getFillable());

        $casts = $kp->getCasts();
        $this->assertEquals('integer', $casts['device_id']);
        $this->assertEquals('integer', $casts['user_id']);
        $this->assertEquals('boolean', $casts['is_consumed']);

        // Relasi BelongsTo Device
        $deviceRel = $kp->device();
        $this->assertInstanceOf(BelongsTo::class, $deviceRel);
        $this->assertEquals('device_id', $deviceRel->getForeignKeyName());
        $this->assertEquals('id', $deviceRel->getOwnerKeyName());
        $this->assertInstanceOf(BchatUserDevice::class, $deviceRel->getRelated());
    }

    /*
    |--------------------------------------------------------------------------
    | PENGUJIAN MODEL DIPERBARUI: BchatWelcomeChallenge
    |--------------------------------------------------------------------------
    */

    public function test_bchat_welcome_challenge_model_recipient_device_relation_and_fillable(): void
    {
        $wc = new BchatWelcomeChallenge();

        $this->assertContains('recipient_device_id', $wc->getFillable());

        $casts = $wc->getCasts();
        $this->assertEquals('integer', $casts['recipient_device_id']);
        $this->assertEquals('integer', $casts['recipient_user_id']);
        $this->assertEquals('integer', $casts['room_id']);

        // Relasi BelongsTo RecipientDevice
        $deviceRel = $wc->recipientDevice();
        $this->assertInstanceOf(BelongsTo::class, $deviceRel);
        $this->assertEquals('recipient_device_id', $deviceRel->getForeignKeyName());
        $this->assertEquals('id', $deviceRel->getOwnerKeyName());
        $this->assertInstanceOf(BchatUserDevice::class, $deviceRel->getRelated());
    }
}
