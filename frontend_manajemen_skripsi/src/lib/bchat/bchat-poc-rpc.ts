/**
 * B-CHAT E2EE — Worker RPC Client Bridge
 *
 * Mengelola komunikasi asinkron berbasis Promise terhadap Dedicated Web Worker:
 * - Menangani korelasi requestId unik (UUIDv4).
 * - Menjalankan pemeriksaan audit denylist pada setiap paket data yang diterima dari worker.
 * - Menangani timeout permintaan dan terminasi worker secara bersih.
 */

import {
  type WorkerRpcAction,
  type WorkerRpcRequest,
  type WorkerRpcResponse,
  type RestoreGroupStatePayload,
  type RestoreGroupStateResult,
  type WorkerRestoreStatus,
  type RetryOutboxMessagePayload,
  type RetryOutboxMessageResult,
  type UpdateOutboxStatusPayload,
  type UpdateOutboxStatusResult,
  type ClaimOutboxMessagePayload,
  type ClaimOutboxMessageResult,
  type GetRoomOutboxPayload,
  type GetRoomOutboxResult,
  type EncryptMessagePayload,
  type EncryptMessageResult,
  type DecryptMessagePayload,
  type DecryptMessageResult,
  type DeriveAttachmentKeyPayload,
  type DeriveAttachmentKeyResult,
  type FencingRpcInput,
  auditRpcResponseSecurity,
} from './bchat-poc-types';

export type {
  RestoreGroupStatePayload,
  RestoreGroupStateResult,
  WorkerRestoreStatus,
  RetryOutboxMessagePayload,
  RetryOutboxMessageResult,
  UpdateOutboxStatusPayload,
  UpdateOutboxStatusResult,
  ClaimOutboxMessagePayload,
  ClaimOutboxMessageResult,
  GetRoomOutboxPayload,
  GetRoomOutboxResult,
  EncryptMessagePayload,
  EncryptMessageResult,
  DecryptMessagePayload,
  DecryptMessageResult,
  DeriveAttachmentKeyPayload,
  DeriveAttachmentKeyResult,
  FencingRpcInput,
};

interface PendingRequest {
  resolve: (value: any) => void;
  reject: (reason: Error) => void;
  timer: ReturnType<typeof setTimeout>;
  action: WorkerRpcAction;
}

export class BchatWorkerClient {
  private worker: Worker;
  private pendingRequests = new Map<string, PendingRequest>();
  private isTerminated = false;

  constructor(worker: Worker) {
    this.worker = worker;
    this.worker.onmessage = this.handleMessage.bind(this);
    this.worker.onerror = this.handleError.bind(this);
    this.worker.onmessageerror = this.handleMessageError.bind(this);
  }

  /**
   * Mengirimkan aksi RPC typed ke Web Worker dan menunggu respons.
   */
  public sendRequest<T = unknown, R = unknown>(
    action: WorkerRpcAction,
    payload: T,
    timeoutMs = 15000
  ): Promise<R> {
    if (this.isTerminated) {
      return Promise.reject(new Error(`Worker telah diterminasi. Tidak dapat mengirim aksi '${action}'.`));
    }

    const requestId = typeof crypto !== 'undefined' && crypto.randomUUID
      ? crypto.randomUUID()
      : `req_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;

    const request: WorkerRpcRequest<T> = {
      requestId,
      action,
      payload,
    };

    return new Promise<R>((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pendingRequests.delete(requestId);
        reject(
          new Error(
            `RPC TIMEOUT: Permintaan '${action}' [requestId: ${requestId}] melebihi batas waktu ${timeoutMs}ms.`
          )
        );
      }, timeoutMs);

      this.pendingRequests.set(requestId, {
        resolve,
        reject,
        timer,
        action,
      });

      this.worker.postMessage(request);
    });
  }

  /**
   * Menangani respons sukses atau error terkontrol dari Worker.
   */
  private handleMessage(event: MessageEvent<WorkerRpcResponse>): void {
    const res = event.data;
    if (!res || !res.requestId) return;

    const pending = this.pendingRequests.get(res.requestId);
    if (!pending) return;

    clearTimeout(pending.timer);
    this.pendingRequests.delete(res.requestId);

    if (!res.success) {
      const errMsg = res.error?.message || 'Terjadi kesalahan internal pada Worker.';
      pending.reject(new Error(`[${res.error?.code || 'WORKER_ERROR'}] ${errMsg}`));
      return;
    }

    // AUDIT KEAMANAN LOKAL: Pastikan tidak ada kunci privat atau ClientState yang lolos
    const audit = auditRpcResponseSecurity(res);
    if (!audit.safe) {
      pending.reject(
        new Error(`SECURITY AUDIT FAILURE: Paket respons Worker melanggar aturan isolasi: ${audit.reason}`)
      );
      return;
    }

    pending.resolve(res.data);
  }

  /**
   * Menangani error runtime unhandled pada Worker thread.
   */
  private handleError(errorEvent: ErrorEvent): void {
    const message = errorEvent.message || 'Worker mengalami uncaught exception.';
    // Reject seluruh request tertunda
    this.pendingRequests.forEach((pending, requestId) => {
      clearTimeout(pending.timer);
      pending.reject(new Error(`UNHANDLED_WORKER_ERROR pada '${pending.action}': ${message}`));
      this.pendingRequests.delete(requestId);
    });
  }

  /**
   * Menangani error deserialisasi pesan pada batas worker boundary.
   */
  private handleMessageError(): void {
    this.pendingRequests.forEach((pending, requestId) => {
      clearTimeout(pending.timer);
      pending.reject(new Error(`MESSAGE_DESERIALIZATION_ERROR pada '${pending.action}'.`));
      this.pendingRequests.delete(requestId);
    });
  }

  /**
   * Mengirimkan aksi RESTORE_GROUP_STATE ke Dedicated Worker untuk memulihkan GroupState dari IndexedDB.
   */
  public restoreGroupState(
    payload: RestoreGroupStatePayload,
    timeoutMs = 15000
  ): Promise<RestoreGroupStateResult> {
    return this.sendRequest<RestoreGroupStatePayload, RestoreGroupStateResult>(
      'RESTORE_GROUP_STATE',
      payload,
      timeoutMs
    );
  }

  /**
   * Mengambil kembali rekaman ciphertext outbox yang sudah tersimpan untuk pengiriman ulang (retry).
   * Menjamin penggunaan UUID dan ciphertext identik tanpa mengenkripsi ulang MLS.
   */
  public retryOutboxMessage(
    payload: RetryOutboxMessagePayload,
    timeoutMs = 10000
  ): Promise<RetryOutboxMessageResult> {
    return this.sendRequest<RetryOutboxMessagePayload, RetryOutboxMessageResult>(
      'RETRY_OUTBOX_MESSAGE',
      payload,
      timeoutMs
    );
  }

  /**
   * Memperbarui status outbox message (misalnya in_transit, acked, failed).
   */
  public updateOutboxStatus(
    payload: UpdateOutboxStatusPayload,
    timeoutMs = 10000
  ): Promise<UpdateOutboxStatusResult> {
    return this.sendRequest<UpdateOutboxStatusPayload, UpdateOutboxStatusResult>(
      'UPDATE_OUTBOX_STATUS',
      payload,
      timeoutMs
    );
  }

  /**
   * Mengklaim pesan outbox secara atomik (status in_transit) dengan validasi lease fencing dan dependensi.
   */
  public claimOutboxMessage(
    payload: ClaimOutboxMessagePayload,
    timeoutMs = 10000
  ): Promise<ClaimOutboxMessageResult> {
    return this.sendRequest<ClaimOutboxMessagePayload, ClaimOutboxMessageResult>(
      'CLAIM_OUTBOX_MESSAGE',
      payload,
      timeoutMs
    );
  }

  /**
   * Mengambil rekaman antrean outbox untuk suatu room.
   */
  public getRoomOutbox(
    payload: GetRoomOutboxPayload,
    timeoutMs = 10000
  ): Promise<GetRoomOutboxResult> {
    return this.sendRequest<GetRoomOutboxPayload, GetRoomOutboxResult>(
      'GET_ROOM_OUTBOX',
      payload,
      timeoutMs
    );
  }

  /**
   * Mengenkripsi pesan aplikasi MLS dan menyimpannya secara atomik ke outbox IDB.
   */
  public encryptMessage(
    payload: EncryptMessagePayload,
    timeoutMs = 15000
  ): Promise<EncryptMessageResult> {
    return this.sendRequest<EncryptMessagePayload, EncryptMessageResult>(
      'ENCRYPT_MESSAGE',
      payload,
      timeoutMs
    );
  }

  /**
   * Mendekripsi pesan aplikasi MLS dan memperbarui GroupState secara atomik di IDB.
   */
  public decryptMessage(
    payload: DecryptMessagePayload,
    timeoutMs = 15000
  ): Promise<DecryptMessageResult> {
    return this.sendRequest<DecryptMessagePayload, DecryptMessageResult>(
      'DECRYPT_MESSAGE',
      payload,
      timeoutMs
    );
  }

  /**
   * Menurunkan Content Encryption Key (CEK) 256-bit lampiran via MLS Exporter (RFC 9420 §8.5).
   * Konteks eksak 48 byte: RoomUUID (16 B) || MessageUUID (16 B) || AttachmentUUID (16 B).
   */
  public deriveAttachmentKey(
    payload: DeriveAttachmentKeyPayload,
    timeoutMs = 15000
  ): Promise<DeriveAttachmentKeyResult> {
    return this.sendRequest<DeriveAttachmentKeyPayload, DeriveAttachmentKeyResult>(
      'DERIVE_ATTACHMENT_KEY',
      payload,
      timeoutMs
    );
  }

  /**
   * Menghentikan instance Dedicated Worker dan membersihkan seluruh state.
   */
  public terminate(): void {
    if (this.isTerminated) return;
    this.isTerminated = true;

    this.pendingRequests.forEach((pending) => {
      clearTimeout(pending.timer);
      pending.reject(new Error(`Worker diterminasi sebelum permintaan '${pending.action}' selesai.`));
    });
    this.pendingRequests.clear();

    this.worker.terminate();
  }
}

/**
 * Factory helper untuk menginstansiasi client Dedicated Worker PoC di browser.
 */
export function createBchatWorkerClient(): BchatWorkerClient {
  const worker = new Worker(new URL('../../workers/bchat-mls-poc.worker.ts', import.meta.url), {
    type: 'module',
  });
  return new BchatWorkerClient(worker);
}

if (typeof window !== 'undefined') {
  (window as any).BchatWorkerClient = BchatWorkerClient;
  (window as any).createBchatWorkerClient = createBchatWorkerClient;
}
