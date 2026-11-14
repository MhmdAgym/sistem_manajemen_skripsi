/**
 * B-CHAT E2EE — IndexedDB Schema and Storage Contracts
 *
 * Mendefinisikan konstanta nama database, versi skema, konfigurasi lima object store,
 * key paths, indexes, serta tipe record TypeScript untuk penyimpanan aman B-CHAT E2EE.
 *
 * Database Target: unuha_bchat_secure_storage (Version: 1)
 */

export const BCAT_DB_NAME = 'unuha_bchat_secure_storage';
export const BCAT_DB_VERSION = 2;

/**
 * Daftar enam object store wajib untuk fondasi penyimpanan lokal B-CHAT
 */
export const BCAT_STORES = {
  KEYSTORE: 'keystore',
  GROUP_STATES: 'group_states',
  PENDING_KEY_PACKAGES: 'pending_key_packages',
  MESSAGE_OUTBOX: 'message_outbox',
  TAB_LEASES: 'tab_leases',
  MESSAGE_HISTORY: 'message_history',
} as const;

export type BchatStoreName = (typeof BCAT_STORES)[keyof typeof BCAT_STORES];

/**
 * Konfigurasi deklaratif skema object store dan indeks
 */
export interface BchatIndexConfig {
  name: string;
  keyPath: string | string[];
  options?: IDBIndexParameters;
}

export interface BchatStoreConfig {
  name: BchatStoreName;
  keyPath: string | string[];
  autoIncrement?: boolean;
  indexes: BchatIndexConfig[];
}

export const BCAT_STORE_CONFIGS: Record<BchatStoreName, BchatStoreConfig> = {
  [BCAT_STORES.KEYSTORE]: {
    name: BCAT_STORES.KEYSTORE,
    keyPath: 'key_id',
    indexes: [
      { name: 'idx_status', keyPath: 'status', options: { unique: false } },
      { name: 'idx_key_version', keyPath: 'key_version', options: { unique: false } },
    ],
  },
  [BCAT_STORES.GROUP_STATES]: {
    name: BCAT_STORES.GROUP_STATES,
    keyPath: ['device_uuid', 'room_uuid'],
    indexes: [
      { name: 'idx_device_uuid', keyPath: 'device_uuid', options: { unique: false } },
      { name: 'idx_updated_at', keyPath: 'updated_at', options: { unique: false } },
    ],
  },
  [BCAT_STORES.PENDING_KEY_PACKAGES]: {
    name: BCAT_STORES.PENDING_KEY_PACKAGES,
    keyPath: ['device_uuid', 'key_package_hash'],
    indexes: [
      { name: 'idx_device_uuid', keyPath: 'device_uuid', options: { unique: false } },
      { name: 'idx_created_at', keyPath: 'created_at', options: { unique: false } },
    ],
  },
  [BCAT_STORES.MESSAGE_OUTBOX]: {
    name: BCAT_STORES.MESSAGE_OUTBOX,
    keyPath: 'client_message_uuid',
    indexes: [
      { name: 'idx_room_status', keyPath: ['room_uuid', 'status'], options: { unique: false } },
      { name: 'idx_created_at', keyPath: 'created_at', options: { unique: false } },
    ],
  },
  [BCAT_STORES.TAB_LEASES]: {
    name: BCAT_STORES.TAB_LEASES,
    keyPath: 'device_uuid',
    indexes: [
      { name: 'idx_owner_token', keyPath: 'owner_token', options: { unique: false } },
      { name: 'idx_expires_at', keyPath: 'expires_at', options: { unique: false } },
    ],
  },
  [BCAT_STORES.MESSAGE_HISTORY]: {
    name: BCAT_STORES.MESSAGE_HISTORY,
    keyPath: 'id',
    indexes: [
      { name: 'idx_room_device', keyPath: ['room_uuid', 'device_uuid'], options: { unique: false } },
      { name: 'idx_device_room_seq', keyPath: ['device_uuid', 'room_uuid', 'sequence_number'], options: { unique: true } },
      { name: 'idx_created_at', keyPath: 'created_at', options: { unique: false } },
    ],
  },
};

// =============================================================================
// TypeScript Interfaces untuk Record Storage
// =============================================================================

import type { BchatEncryptedEnvelope } from './bchat-storage-envelope';

/**
 * Record untuk Keystore:
 * Menyimpan kunci privat terenkripsi atau instance CryptoKey non-extractable native
 */
export interface KeystoreRecord {
  key_id: string;
  key_version: number;
  status: 'active' | 'staged' | 'revoked' | 'deprecated';
  algorithm: string;
  crypto_key?: CryptoKey;
  encrypted_key_bytes?: Uint8Array;
  iv?: Uint8Array;
  created_at: number;
  metadata?: Record<string, unknown>;
}

/**
 * Record untuk Group States (Produksi Ketat H-D):
 * Menyimpan state MLS terenkripsi (ClientState) per room & device
 */
export interface GroupStateRecord {
  device_uuid: string;
  room_uuid: string;
  storage_schema_version: number;
  serializer_version: number;
  ciphersuite_id: number;
  group_id_hash: string;
  epoch: string;
  state_revision: string;
  previous_revision: string | null;
  key_id: string;
  envelope: BchatEncryptedEnvelope;
  status: 'active' | 'quarantined';
  quarantine_reason?: string;
  created_at: number;
  updated_at: number;
  last_processed_sequence?: number;
  metadata?: Record<string, unknown>;
}

/**
 * Record untuk Group States Legacy (Hanya untuk Kompatibilitas Uji H-I1/H-I2):
 * @deprecated Dilarang digunakan dalam alur persistensi atau pemulihan produksi.
 */
export interface LegacyGroupStateRecord {
  device_uuid: string;
  room_uuid: string;
  epoch: string;
  state_bytes: Uint8Array;
  group_id_hash?: string;
  updated_at: number;
  metadata?: Record<string, unknown>;
}

/**
 * Status siklus hidup publikasi KeyPackage ke backend
 */
export type KeyPackagePublicationStatus =
  | 'local_only'
  | 'uploading'
  | 'published'
  | 'unknown'
  | 'rejected';

/**
 * Metadata terstruktur untuk record Pending KeyPackage
 */
export interface PendingKeyPackageMetadata extends Record<string, unknown> {
  status?: 'pending' | 'consumed';
  publication_status?: KeyPackagePublicationStatus;
  published_at?: number;
  active_pool_count?: number;
  publication_error?: string | null;
  envelope?: BchatEncryptedEnvelope;
  consumed_at?: number;
  room_uuid?: string;
  updated_at?: number;
}

/**
 * Record untuk Pending Key Packages:
 * Menyimpan KeyPackage privat prapendaftaran sebelum Welcome tiba
 */
export interface PendingKeyPackageRecord {
  device_uuid: string;
  key_package_hash: string;
  private_key_package_bytes: Uint8Array;
  public_key_package_bytes?: Uint8Array;
  created_at: number;
  metadata?: PendingKeyPackageMetadata;
}

/**
 * Record untuk Message Outbox:
 * Antrean pesan terenkripsi sebelum ACK / transmisi jaringan sukses
 */
export interface MessageOutboxRecord {
  client_message_uuid: string;
  room_uuid: string;
  status: 'pending' | 'in_transit' | 'acked' | 'failed';
  ciphertext_bytes: Uint8Array;
  created_at: number;
  sent_at?: number;
  retry_count?: number;
  metadata?: Record<string, unknown>;
}

/**
 * Record untuk Tab Leases:
 * Penguncian satu tab aktif per device untuk koordinasi multi-tab.
 * Field generation wajib berupa decimal string kanonikal.
 */
export interface TabLeaseRecord {
  device_uuid: string;
  owner_token: string;
  generation: string;
  acquired_at: number;
  expires_at: number;
}

/**
 * Record untuk Riwayat Pesan Terenkripsi (Encrypted Chat History):
 * Plaintext tersimpan DI DALAM BchatEncryptedEnvelope yang dienkripsi menggunakan KEK (AES-256-GCM).
 * Disk TIDAK PERNAH memuat plaintext mentah.
 */
export interface EncryptedMessageHistoryRecord {
  id: string; // `${device_uuid}:${room_uuid}:${sequence_number}`
  device_uuid: string;
  room_uuid: string;
  sequence_number: number;
  message_uuid: string;
  sender_user_id: number;
  sender_device_id?: number;
  sender_device_uuid?: string;
  is_self: boolean;
  envelope: BchatEncryptedEnvelope;
  created_at: number;
}


