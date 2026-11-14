/**
 * B-CHAT E2EE — Encrypted Local Chat History Storage Engine (I-05A)
 *
 * Mengelola persistensi riwayat pesan obrolan terdekripsi secara aman pada IndexedDB:
 * - Plaintext pesan DIENKRIPSI menggunakan Key Encryption Key (KEK) perangkat (AES-256-GCM)
 *   melalui WebCrypto sebelum disimpan ke disk (object store 'message_history').
 * - ZERO Plaintext at-rest: Tidak ada pesan mentah di IndexedDB, localStorage, sessionStorage, atau backend.
 * - Anti-Transplantation: Ciphertext amplop diikat kuat menggunakan AAD biner deterministik
 *   berisi deviceUuid, roomUuid, storeName, dan sequence kanonikal.
 * - Monotonic Ratchet Protection: Riwayat lokal dimuat langsung tanpa memanggil ulang OpenMLS Worker,
 *   sehingga GroupState ratchet tetap terjaga dan tidak memicu "Desired gen in the past".
 * - Resilience: Bertahan terhadap reload (F5), navigasi keluar/masuk halaman, dan logout/login pada profil yang sama.
 */

import {
  BCAT_STORES,
  type EncryptedMessageHistoryRecord,
} from './bchat-idb-schema';
import {
  withReadonlyTransaction,
  withReadwriteTransaction,
  idbPut,
  idbGet,
  BchatStorageError,
} from './bchat-idb-core';
import {
  normalizeCanonicalUuid,
  type BchatAadContext,
} from './bchat-storage-aad';
import {
  encryptPayloadToEnvelope,
  decryptPayloadFromEnvelope,
} from './bchat-crypto-helper';
import type { DecryptedMessageItem } from '../message/bchat-message-coordinator';

// -----------------------------------------------------------------------------
// TIPE & PAYLOAD INTERNAL
// -----------------------------------------------------------------------------

export interface HistoryPayloadData {
  plaintext: string;
  contentType: number;
  senderUserId: number;
  senderDeviceId?: number;
  senderDeviceUuid?: string;
  isSelf: boolean;
  clientSentAt: string | null;
  createdAt: string | null;
}

export interface SaveHistoryItemParams {
  deviceUuid: string;
  roomUuid: string;
  sequenceNumber: number;
  messageUuid: string;
  senderUserId: number;
  senderDeviceId?: number;
  senderDeviceUuid?: string;
  isSelf: boolean;
  plaintext: string;
  contentType?: number;
  clientSentAt?: string | null;
  createdAt?: string | null;
}

// -----------------------------------------------------------------------------
// HELPER BUILD AAD CONTEXT
// -----------------------------------------------------------------------------

function buildMessageHistoryAad(
  canonicalDevice: string,
  canonicalRoom: string,
  sequenceNumber: number
): Omit<BchatAadContext, 'keyId'> {
  return {
    storeName: BCAT_STORES.MESSAGE_HISTORY,
    recordType: 'chat_message',
    deviceUuid: canonicalDevice,
    roomUuid: canonicalRoom,
    recordKey: `${canonicalDevice}:${canonicalRoom}:${sequenceNumber}`,
  };
}

function makeHistoryRecordId(
  canonicalDevice: string,
  canonicalRoom: string,
  sequenceNumber: number
): string {
  return `${canonicalDevice}:${canonicalRoom}:${sequenceNumber}`;
}

// -----------------------------------------------------------------------------
// API PUBLIK PERSISTENSI RIWAYAT
// -----------------------------------------------------------------------------

/**
 * Menyimpan satu item pesan terdekripsi ke dalam penyimpanan terenkripsi IndexedDB.
 */
export async function saveEncryptedHistoryItem(
  params: SaveHistoryItemParams
): Promise<void> {
  const canonicalDevice = normalizeCanonicalUuid(params.deviceUuid, 'deviceUuid');
  const canonicalRoom = normalizeCanonicalUuid(params.roomUuid, 'roomUuid');
  const canonicalMessageUuid = normalizeCanonicalUuid(params.messageUuid, 'messageUuid');
  const seq = params.sequenceNumber;

  if (!Number.isInteger(seq) || seq <= 0) {
    throw new BchatStorageError(
      `Sequence number tidak valid untuk penyimpanan riwayat: ${seq}`,
      'INVALID_SEQUENCE'
    );
  }

  const payload: HistoryPayloadData = {
    plaintext: params.plaintext,
    contentType: params.contentType ?? 1,
    senderUserId: params.senderUserId,
    senderDeviceId: params.senderDeviceId,
    senderDeviceUuid: params.senderDeviceUuid,
    isSelf: params.isSelf,
    clientSentAt: params.clientSentAt ?? null,
    createdAt: params.createdAt ?? new Date().toISOString(),
  };

  const jsonString = JSON.stringify(payload);
  const plaintextBytes = new TextEncoder().encode(jsonString);

  const aadCtx = buildMessageHistoryAad(canonicalDevice, canonicalRoom, seq);
  const envelope = await encryptPayloadToEnvelope(plaintextBytes, aadCtx);

  const record: EncryptedMessageHistoryRecord = {
    id: makeHistoryRecordId(canonicalDevice, canonicalRoom, seq),
    device_uuid: canonicalDevice,
    room_uuid: canonicalRoom,
    sequence_number: seq,
    message_uuid: canonicalMessageUuid,
    sender_user_id: params.senderUserId,
    sender_device_id: params.senderDeviceId,
    sender_device_uuid: params.senderDeviceUuid,
    is_self: params.isSelf,
    envelope,
    created_at: Date.now(),
  };

  await idbPut(BCAT_STORES.MESSAGE_HISTORY, record);
}

/**
 * Membaca dan mendekripsi seluruh riwayat pesan untuk ruang obrolan tertentu.
 * Mengembalikan daftar pesan yang terurut secara kronologis (sequence ascending).
 */
export async function loadEncryptedHistory(
  deviceUuid: string,
  roomUuid: string
): Promise<DecryptedMessageItem[]> {
  const canonicalDevice = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');
  const canonicalRoom = normalizeCanonicalUuid(roomUuid, 'roomUuid');

  // Ambil record yang cocok dengan indeks [room_uuid, device_uuid]
  const records = await withReadonlyTransaction([BCAT_STORES.MESSAGE_HISTORY], (_tx, getStore) => {
    return new Promise<EncryptedMessageHistoryRecord[]>((resolve, reject) => {
      const store = getStore(BCAT_STORES.MESSAGE_HISTORY);
      const index = store.index('idx_room_device');
      const range = IDBKeyRange.only([canonicalRoom, canonicalDevice]);
      const req = index.getAll(range);

      req.onsuccess = () => {
        resolve((req.result as EncryptedMessageHistoryRecord[]) || []);
      };
      req.onerror = () => reject(req.error || new Error('Gagal membaca riwayat pesan dari IndexedDB.'));
    });
  });

  if (!records || records.length === 0) {
    return [];
  }

  // Urutkan berdasarkan sequence_number ascending
  records.sort((a, b) => a.sequence_number - b.sequence_number);

  // Dekripsi setiap pesan secara aman dengan isolasi error per pesan
  const items: DecryptedMessageItem[] = [];

  for (const record of records) {
    const seq = record.sequence_number;
    const aadCtx = buildMessageHistoryAad(canonicalDevice, canonicalRoom, seq);

    try {
      const decryptedBytes = await decryptPayloadFromEnvelope(record.envelope, aadCtx);
      const jsonString = new TextDecoder().decode(decryptedBytes);
      const payload = JSON.parse(jsonString) as HistoryPayloadData;

      items.push({
        server_sequence: seq,
        message_uuid: record.message_uuid,
        sender_id: payload.senderUserId,
        epoch: 1,
        generation: seq,
        content_type: payload.contentType ?? 1,
        is_self: payload.isSelf,
        plaintext: payload.plaintext,
        client_sent_at: payload.clientSentAt,
        created_at: payload.createdAt,
      });
    } catch (err: any) {
      // Isolasi error: Jangan biarkan satu amplop rusak menggagalkan seluruh riwayat
      items.push({
        server_sequence: seq,
        message_uuid: record.message_uuid,
        sender_id: record.sender_user_id,
        epoch: 1,
        generation: seq,
        content_type: 1,
        is_self: record.is_self,
        plaintext: '[Pesan riwayat tidak dapat didekripsi]',
        client_sent_at: null,
        created_at: null,
        error: err?.message || 'Decryption failed',
      });
    }
  }

  return items;
}

/**
 * Mengambil satu record riwayat terenkripsi berdasarkan sequence.
 */
export async function getEncryptedHistoryItem(
  deviceUuid: string,
  roomUuid: string,
  sequenceNumber: number
): Promise<DecryptedMessageItem | null> {
  const canonicalDevice = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');
  const canonicalRoom = normalizeCanonicalUuid(roomUuid, 'roomUuid');
  const id = makeHistoryRecordId(canonicalDevice, canonicalRoom, sequenceNumber);

  const record = await idbGet<EncryptedMessageHistoryRecord>(BCAT_STORES.MESSAGE_HISTORY, id);
  if (!record) return null;

  const aadCtx = buildMessageHistoryAad(canonicalDevice, canonicalRoom, sequenceNumber);
  try {
    const decryptedBytes = await decryptPayloadFromEnvelope(record.envelope, aadCtx);
    const payload = JSON.parse(new TextDecoder().decode(decryptedBytes)) as HistoryPayloadData;

    return {
      server_sequence: sequenceNumber,
      message_uuid: record.message_uuid,
      sender_id: payload.senderUserId,
      epoch: 1,
      generation: sequenceNumber,
      content_type: payload.contentType ?? 1,
      is_self: payload.isSelf,
      plaintext: payload.plaintext,
      client_sent_at: payload.clientSentAt,
      created_at: payload.createdAt,
    };
  } catch {
    return null;
  }
}

/**
 * Menghapus seluruh riwayat pesan lokal untuk perangkat tertentu (misal saat reset/deregister).
 */
export async function clearHistoryForDevice(deviceUuid: string): Promise<number> {
  const canonicalDevice = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');

  return withReadwriteTransaction([BCAT_STORES.MESSAGE_HISTORY], (_tx, getStore) => {
    return new Promise<number>((resolve, reject) => {
      const store = getStore(BCAT_STORES.MESSAGE_HISTORY);
      const req = store.getAll();

      req.onsuccess = () => {
        const records = (req.result as EncryptedMessageHistoryRecord[]) || [];
        const toDelete = records.filter((r) => r.device_uuid === canonicalDevice);
        for (const item of toDelete) {
          store.delete(item.id);
        }
        resolve(toDelete.length);
      };
      req.onerror = () => reject(req.error || new Error('Gagal membersihkan riwayat pesan.'));
    });
  });
}

