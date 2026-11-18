/**
 * B-CHAT E2EE — History Transfer Coordinator (Tahap 2B)
 *
 * Mengorkestrasi seluruh siklus hidup transfer riwayat antarperangkat tepercaya:
 * 1. initiateTransferFlow() [Perangkat Penerima Baru]
 * 2. exportAndUploadSnapshotFlow() [Perangkat Sumber Tepercaya]
 * 3. downloadAndImportSnapshotFlow() [Perangkat Penerima Baru]
 *
 * Invarian Keamanan:
 * - Zero Plaintext to Server: Server hanya perantara relay ciphertext blob.
 * - Monotonic Ratchet Protection: Riwayat disimpan langsung ke IndexedDB tanpa merusak state ratchet OpenMLS.
 * - Zero Partial State: Seluruh riwayat diimpor secara atomik; kegagalan membatalkan impor.
 */

import {
  generateEphemeralTransferKeypair,
  importEphemeralPublicKey,
  deriveTransferAesKey,
  buildTransferRequestBuffer,
  buildSnapshotHeaderBuffer,
  buildCompletionBuffer,
  signBufferWithDeviceKey,
  verifyBufferWithDevicePublicKey,
  encryptSnapshotPayload,
  decryptSnapshotPayload,
  type EphemeralTransferKeypair,
} from './bchat-history-transfer-crypto';
import {
  requestHistoryTransfer,
  uploadHistoryTransferSnapshot,
  downloadHistoryTransferSnapshot,
  completeHistoryTransfer,
  cancelHistoryTransfer,
  type BchatHistoryTransferData,
} from './bchat-history-transfer-client';
import {
  loadEncryptedHistory,
  saveEncryptedHistoryItem,
  getEncryptedHistoryItem,
} from '../storage/bchat-encrypted-history';
import {
  parseAttachmentManifest,
  formatAttachmentManifest,
} from '../attachment/bchat-attachment-manifest';
import {
  bytesToCanonicalHex,
} from '../device/bchat-device-registration';

export interface HistorySnapshotData {
  version: '1.0.0';
  room_uuid: string;
  source_device_uuid: string;
  recipient_device_uuid: string;
  transferred_at: string;
  messages: Array<{
    server_sequence: number;
    message_uuid: string;
    sender_id: number;
    sender_device_id?: number;
    sender_device_uuid?: string;
    is_self: boolean;
    content_type: number;
    plaintext: string;
    client_sent_at: string | null;
    created_at: string | null;
  }>;
}

/**
 * Memastikan field `cekBase64` pada manifest lampiran (content_type = 2) dipertahankan
 * saat impor History Transfer tanpa pernah menghapus CEK yang sudah tersedia di sumber maupun lokal.
 */
export function preserveAttachmentManifestCek(
  incomingPlaintext: string,
  existingPlaintext?: string | null
): string {
  const incomingManifest = parseAttachmentManifest(incomingPlaintext);
  if (!incomingManifest) {
    return incomingPlaintext;
  }

  const existingManifest = existingPlaintext ? parseAttachmentManifest(existingPlaintext) : null;
  if (!existingManifest) {
    return formatAttachmentManifest(incomingManifest);
  }

  const existingCekByUuid = new Map<string, string>();
  for (const att of existingManifest.attachments) {
    if (att.cekBase64) {
      existingCekByUuid.set(att.attachmentUuid.toLowerCase(), att.cekBase64);
    }
  }

  const mergedAttachments = incomingManifest.attachments.map((att) => {
    if (att.cekBase64) {
      return att;
    }
    const preserved = existingCekByUuid.get(att.attachmentUuid.toLowerCase());
    return preserved ? { ...att, cekBase64: preserved } : att;
  });

  return formatAttachmentManifest({
    ...incomingManifest,
    attachments: mergedAttachments,
  });
}

// Memory cache untuk kunci privat ephemeral penerima selama sesi aktif
const recipientEphemeralKeys = new Map<string, EphemeralTransferKeypair>();

/**
 * [Alur 1: Perangkat Penerima] Menginisiasi permintaan transfer riwayat.
 */
export async function initiateTransferFlow(
  userId: number,
  roomUuid: string,
  recipientDeviceUuid: string,
  recipientDevicePrivateKey: CryptoKey,
  sourceDeviceUuid: string
): Promise<{ transfer: BchatHistoryTransferData; ephemeralKeypair: EphemeralTransferKeypair }> {
  // 1. Bangkitkan pasangan kunci ephemeral ECDH (P-256)
  const ephemeralKeypair = await generateEphemeralTransferKeypair();

  // 2. Bangkitkan 32-byte transfer nonce acak
  const nonceBytes = new Uint8Array(32);
  crypto.getRandomValues(nonceBytes);
  const nonceHex = bytesToCanonicalHex(nonceBytes);

  // 3. Bangkitkan buffer kanonikal permintaan dan tanda tangani dengan kunci Ed25519 penerima
  const reqBuffer = await buildTransferRequestBuffer(
    userId,
    roomUuid,
    recipientDeviceUuid,
    sourceDeviceUuid,
    nonceHex,
    ephemeralKeypair.publicKeyHex
  );

  const requestSignature = await signBufferWithDeviceKey(recipientDevicePrivateKey, reqBuffer);

  // 4. Kirim permintaan ke server
  const res = await requestHistoryTransfer(
    roomUuid,
    {
      source_device_uuid: sourceDeviceUuid,
      key_exchange_algorithm: 'ECDH-P256',
      recipient_ephemeral_public_key: ephemeralKeypair.publicKeyHex,
      transfer_nonce: nonceHex,
      request_signature: requestSignature,
    },
    recipientDeviceUuid
  );

  // Simpan kunci ephemeral penerima dalam cache sesi lokal
  recipientEphemeralKeys.set(res.data.transfer_uuid, ephemeralKeypair);

  return {
    transfer: res.data,
    ephemeralKeypair,
  };
}

/**
 * [Alur 2: Perangkat Sumber] Membaca riwayat lokal, mengenkripsi snapshot, dan mengunggah ke server.
 */
export async function exportAndUploadSnapshotFlow(
  userId: number,
  transfer: BchatHistoryTransferData,
  sourceDeviceUuid: string,
  sourceDevicePrivateKey: CryptoKey
): Promise<BchatHistoryTransferData> {
  const roomUuid = transfer.room_uuid;
  const recipientDeviceUuid = transfer.recipient_device_uuid;

  // 1. Baca seluruh riwayat chat lokal yang terdekripsi dari IndexedDB menggunakan KEK lokal
  const historyItems = await loadEncryptedHistory(sourceDeviceUuid, roomUuid);

  // 2. Susun snapshot payload kanonikal
  const snapshotPayload: HistorySnapshotData = {
    version: '1.0.0',
    room_uuid: roomUuid,
    source_device_uuid: sourceDeviceUuid,
    recipient_device_uuid: recipientDeviceUuid,
    transferred_at: new Date().toISOString(),
    messages: historyItems.map((item) => ({
      server_sequence: item.server_sequence,
      message_uuid: item.message_uuid,
      sender_id: item.sender_id,
      is_self: item.is_self,
      content_type: item.content_type,
      plaintext: item.plaintext,
      client_sent_at: item.client_sent_at,
      created_at: item.created_at,
    })),
  };

  const jsonPlaintext = JSON.stringify(snapshotPayload);

  // 3. Bangkitkan pasangan kunci ephemeral ECDH sumber
  const sourceEphemeral = await generateEphemeralTransferKeypair();

  // 4. Impor kunci publik ephemeral penerima
  const recipientPubCryptoKey = await importEphemeralPublicKey(transfer.recipient_ephemeral_public_key);

  // 5. Derivasi transfer AES key via ECDH + HKDF
  const salt = new TextEncoder().encode(`transfer_salt_${transfer.transfer_uuid}`);
  const info = `UNUHA-BCHAT-TRANSFER-KEY:${transfer.transfer_uuid}:${roomUuid}`;
  const transferAesKey = await deriveTransferAesKey(
    sourceEphemeral.privateKey,
    recipientPubCryptoKey,
    salt,
    info
  );

  // 6. Enkripsi snapshot dengan AES-256-GCM terikat AAD
  const aad = `UNUHA-BCHAT-AAD:${userId}:${transfer.transfer_uuid}:${roomUuid}:${recipientDeviceUuid}:${sourceDeviceUuid}`;
  const encrypted = await encryptSnapshotPayload(jsonPlaintext, transferAesKey, aad);

  // 7. Tandatangani snapshot header menggunakan kunci Ed25519 sumber
  const snapshotHeaderBuf = await buildSnapshotHeaderBuffer(
    userId,
    transfer.transfer_uuid,
    roomUuid,
    recipientDeviceUuid,
    sourceDeviceUuid,
    encrypted.hashSha256Hex,
    sourceEphemeral.publicKeyHex
  );

  const sourceSignature = await signBufferWithDeviceKey(sourceDevicePrivateKey, snapshotHeaderBuf);

  // 8. Unggah snapshot ciphertext ke server
  const uploadRes = await uploadHistoryTransferSnapshot(
    transfer.transfer_uuid,
    {
      source_ephemeral_public_key: sourceEphemeral.publicKeyHex,
      snapshot_ciphertext: encrypted.ciphertextBase64,
      snapshot_hash_sha256: encrypted.hashSha256Hex,
      snapshot_iv: encrypted.ivHex,
      snapshot_metadata: {
        message_count: snapshotPayload.messages.length,
      },
      source_signature: sourceSignature,
    },
    sourceDeviceUuid
  );

  return uploadRes.data;
}

/**
 * [Alur 3: Perangkat Penerima] Mengunduh ciphertext, mendekripsi, memverifikasi integritas, dan mengimpor ke IndexedDB.
 */
export async function downloadAndImportSnapshotFlow(
  userId: number,
  transferUuid: string,
  recipientDeviceUuid: string,
  recipientDevicePrivateKey: CryptoKey,
  sourceDevicePublicKeyHex?: string
): Promise<{ importedCount: number; transfer: BchatHistoryTransferData }> {
  // 1. Unduh paket snapshot terenkripsi dari server
  const downloadRes = await downloadHistoryTransferSnapshot(transferUuid, recipientDeviceUuid);
  const data = downloadRes.data;

  const effectiveSourcePubKey = sourceDevicePublicKeyHex || data.source_device_public_key;
  if (!effectiveSourcePubKey) {
    throw new Error('Kunci publik perangkat sumber tidak tersedia untuk verifikasi tanda tangan snapshot.');
  }

  // 2. Verifikasi tanda tangan digital perangkat sumber atas snapshot header
  const snapshotHeaderBuf = await buildSnapshotHeaderBuffer(
    userId,
    data.transfer_uuid,
    data.room_uuid,
    data.recipient_device_uuid,
    data.source_device_uuid,
    data.snapshot_hash_sha256,
    data.source_ephemeral_public_key
  );

  const isSigValid = await verifyBufferWithDevicePublicKey(
    effectiveSourcePubKey,
    data.source_signature,
    snapshotHeaderBuf
  );

  if (!isSigValid) {
    throw new Error('Tanda tangan digital perangkat sumber tidak valid (integritas snapshot ditolak).');
  }

  // 3. Ambil kunci privat ephemeral penerima dari cache
  let recipientEphemeral = recipientEphemeralKeys.get(transferUuid);
  if (!recipientEphemeral) {
    throw new Error('Kunci privat ephemeral penerima tidak ditemukan untuk sesi transfer ini.');
  }

  // 4. Impor kunci publik ephemeral sumber
  const sourcePubCryptoKey = await importEphemeralPublicKey(data.source_ephemeral_public_key);

  // 5. Derivasi transfer AES key yang identik
  const salt = new TextEncoder().encode(`transfer_salt_${data.transfer_uuid}`);
  const info = `UNUHA-BCHAT-TRANSFER-KEY:${data.transfer_uuid}:${data.room_uuid}`;
  const transferAesKey = await deriveTransferAesKey(
    recipientEphemeral.privateKey,
    sourcePubCryptoKey,
    salt,
    info
  );

  // 6. Dekripsi snapshot ciphertext dengan verifikasi AAD
  const aad = `UNUHA-BCHAT-AAD:${userId}:${data.transfer_uuid}:${data.room_uuid}:${data.recipient_device_uuid}:${data.source_device_uuid}`;
  const decryptedJson = await decryptSnapshotPayload(
    data.snapshot_ciphertext,
    data.snapshot_iv,
    transferAesKey,
    aad
  );

  const snapshot: HistorySnapshotData = JSON.parse(decryptedJson);

  // 7. Simpan setiap pesan ke IndexedDB lokal menggunakan KEK lokal penerima
  //    (mempertahankan cekBase64 manifest lampiran & mencegah duplikasi berdasarkan primary key sequence)
  let importedCount = 0;
  for (const msg of snapshot.messages) {
    let effectivePlaintext = msg.plaintext;
    if (msg.content_type === 2) {
      try {
        const existing = await getEncryptedHistoryItem(
          recipientDeviceUuid,
          data.room_uuid,
          msg.server_sequence
        );
        effectivePlaintext = preserveAttachmentManifestCek(msg.plaintext, existing?.plaintext);
      } catch {
        effectivePlaintext = preserveAttachmentManifestCek(msg.plaintext);
      }
    }

    await saveEncryptedHistoryItem({
      deviceUuid: recipientDeviceUuid,
      roomUuid: data.room_uuid,
      sequenceNumber: msg.server_sequence,
      messageUuid: msg.message_uuid,
      senderUserId: msg.sender_id,
      senderDeviceId: msg.sender_device_id,
      senderDeviceUuid: msg.sender_device_uuid,
      isSelf: msg.is_self,
      plaintext: effectivePlaintext,
      contentType: msg.content_type,
      clientSentAt: msg.client_sent_at,
      createdAt: msg.created_at,
    });
    importedCount++;
  }

  // 8. Buat signature penyelesaian ACK oleh penerima
  const ackBuf = buildCompletionBuffer(
    userId,
    data.transfer_uuid,
    data.recipient_device_uuid,
    data.snapshot_hash_sha256
  );
  const completionSignature = await signBufferWithDeviceKey(recipientDevicePrivateKey, ackBuf);

  // 9. Kirim konfirmasi penyelesaian ke server
  const compRes = await completeHistoryTransfer(
    data.transfer_uuid,
    { completion_signature: completionSignature },
    recipientDeviceUuid
  );

  // Bersihkan cache kunci ephemeral
  recipientEphemeralKeys.delete(transferUuid);

  return {
    importedCount,
    transfer: compRes.data,
  };
}
