/**
 * B-CHAT E2EE Protocol Layer — Real-Time Reverb WebSocket Listener (I-04B.5 / I-04B.5S)
 *
 * Mengimplementasikan subscriber event siaran WebSocket Reverb pada saluran privat
 * `bchat.rooms.{roomUuid}` untuk mendengarkan sinyal tipis (thin signal) `.message.sent`
 * dan merekonsiliasi pesan baru melalui REST API (Single Source of Truth) via Coordinator.
 *
 * Invarian Protokol & Keamanan:
 * 1. Thin Signal: Event WebSocket HANYA membawa metadata non-sensitif (room_uuid, server_sequence, message_uuid, sender_id, created_at).
 *    DILARANG mengharapkan atau membawa ciphertext, plaintext, atau material kriptografi pada WebSocket payload.
 * 2. REST as Single Source of Truth: Penerimaan sinyal WebSocket BUKAN bukti bahwa pesan sudah tersimpan atau valid;
 *    klien WAJIB memicu pengambilan ciphertext via REST API (`fetchMessages`).
 * 3. Coordinator Delegation: Seluruh pemrosesan pesan masuk, verifikasi SHA-256, dan operasi dekripsi Worker
 *    didelegasikan secara ketat ke `bchat-message-coordinator.ts`. Subscriber DILARANG memanggil Worker RPC `DECRYPT_MESSAGE` secara langsung.
 * 4. Deduplication & Ratchet Protection: Sinyal duplikat atau sinyal pesan yang sudah diproses diabaikan secara ketat
 *    untuk mencegah double-fetch dan mencegah kesalahan ratchet MLS ("Desired gen in the past").
 * 5. Self-Message Bypassing: Pesan yang dikirim oleh diri sendiri (sender_id === currentUserId) ditandai dan dilewati
 *    dari alur dekripsi Worker karena kunci ratchet pengirim telah dimusnahkan saat enkripsi.
 * 6. Error Isolation & Monotonic Cursor: Kegagalan dekripsi satu pesan tidak memajukan kursor sequence dan tidak memproses
 *    pesan berikutnya dalam batch demi mencegah ketidakkonsistenan ratchet MLS.
 * 7. Persistent Cursor & Reload Safety: Kursor pemrosesan dipersistenkan secara atomik bersama GroupState di IndexedDB,
 *    sehingga reload halaman (F5) memulihkan kursor yang tepat tanpa memicu re-dekripsi pesan masa lalu.
 */

import { getEcho } from '@/lib/echo';
import type {
  BchatWorkerClient,
  FencingRpcInput,
} from '@/lib/bchat/bchat-poc-rpc';
import {
  reconcileInboundMessages,
  getGroupStateCursor,
  updateGroupStateCursor,
  type DecryptedMessageItem,
  type BchatInboundErrorCategory,
} from '@/lib/bchat/message/bchat-message-coordinator';

// -----------------------------------------------------------------------------
// SIGNAL TYPES & CONTRACTS
// -----------------------------------------------------------------------------

export interface BchatMessageSentSignal {
  room_uuid: string;
  server_sequence: number;
  message_uuid: string;
  sender_id: number;
  created_at: string;
}

export type SubscriptionState =
  | 'IDLE'
  | 'CONNECTING'
  | 'SUBSCRIBED'
  | 'ERROR'
  | 'UNSUBSCRIBED';

export interface BchatRealtimeSubscriberOptions {
  roomUuid: string;
  currentUserId: number;
  deviceUuid: string;
  workerRpc: BchatWorkerClient;
  fencing: FencingRpcInput;
  initialSequence?: number;
  batchLimit?: number;
  onSubscriptionStatusChange?: (status: SubscriptionState, detail?: string) => void;
  onSignalReceived?: (signal: BchatMessageSentSignal, isDuplicate: boolean) => void;
  onMessageDecrypted?: (message: DecryptedMessageItem) => void;
  onReconciled?: (reconciledCount: number, latestSequence: number) => void;
  onRecoveryRequired?: (failedSequence: number, error: string, category?: BchatInboundErrorCategory) => void;
  onError?: (error: Error) => void;
}

const UUID_V4_REGEX =
  /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

/**
 * Validasi ketat format dan metadata sinyal WebSocket BchatMessageSent.
 * Mengembalikan objek typed BchatMessageSentSignal jika sah, atau null jika tidak valid.
 */
export function validateMessageSentSignal(data: unknown): BchatMessageSentSignal | null {
  if (!data || typeof data !== 'object') {
    return null;
  }

  const d = data as Record<string, unknown>;

  if (typeof d.room_uuid !== 'string' || !UUID_V4_REGEX.test(d.room_uuid)) {
    return null;
  }

  if (
    typeof d.server_sequence !== 'number' ||
    !Number.isInteger(d.server_sequence) ||
    d.server_sequence <= 0
  ) {
    return null;
  }

  if (typeof d.message_uuid !== 'string' || !UUID_V4_REGEX.test(d.message_uuid)) {
    return null;
  }

  if (
    typeof d.sender_id !== 'number' ||
    !Number.isInteger(d.sender_id) ||
    d.sender_id <= 0
  ) {
    return null;
  }

  if (typeof d.created_at !== 'string' || Number.isNaN(Date.parse(d.created_at))) {
    return null;
  }

  return {
    room_uuid: d.room_uuid.toLowerCase(),
    server_sequence: d.server_sequence,
    message_uuid: d.message_uuid.toLowerCase(),
    sender_id: d.sender_id,
    created_at: d.created_at,
  };
}

// -----------------------------------------------------------------------------
// REAL-TIME SUBSCRIBER CLASS
// -----------------------------------------------------------------------------

export class BchatRealtimeSubscriber {
  private readonly options: BchatRealtimeSubscriberOptions;
  private echoChannel: any = null;
  private latestKnownSequence: number;
  private readonly processedSequences: Set<number> = new Set();
  private isReconciling: boolean = false;
  private hasPendingReconcileRequest: boolean = false;
  private isRecoveryRequired: boolean = false;
  private recoveryErrorDetail: string | null = null;
  private lastFailedSequence: number | null = null;
  private subscriptionState: SubscriptionState = 'IDLE';

  constructor(options: BchatRealtimeSubscriberOptions) {
    this.options = options;
    this.latestKnownSequence = options.initialSequence ?? 0;
    if (this.latestKnownSequence > 0) {
      for (let s = 1; s <= this.latestKnownSequence; s++) {
        this.processedSequences.add(s);
      }
    }
  }

  public getSubscriptionState(): SubscriptionState {
    return this.subscriptionState;
  }

  public getLatestSequence(): number {
    return this.latestKnownSequence;
  }

  public getProcessedSequences(): number[] {
    return Array.from(this.processedSequences).sort((a, b) => a - b);
  }

  /**
   * Menandai sequence tertentu telah selesai diproses oleh klien (misalnya pesan outbound sendiri)
   * agar tidak direkonsiliasi ulang oleh subscriber.
   */
  public markSequenceProcessed(sequence: number): void {
    if (typeof sequence === 'number' && sequence > 0) {
      this.processedSequences.add(sequence);
      this.latestKnownSequence = Math.max(this.latestKnownSequence, sequence);
    }
  }

  /**
   * Menginisialisasi kursor dari IndexedDB GroupState secara asinkron.
   * Digunakan untuk memastikan kursor lokal sinkron dengan state terenkripsi pada disk.
   */
  public async initializeFromPersistence(): Promise<number> {
    try {
      const persistentCursor = await getGroupStateCursor(
        this.options.deviceUuid,
        this.options.roomUuid
      );
      if (persistentCursor > this.latestKnownSequence) {
        this.latestKnownSequence = persistentCursor;
        for (let s = 1; s <= persistentCursor; s++) {
          this.processedSequences.add(s);
        }
      }
      return this.latestKnownSequence;
    } catch (_err) {
      return this.latestKnownSequence;
    }
  }

  /**
   * Berlangganan ke saluran privat Reverb `bchat.rooms.{roomUuid}`.
   * Dilengkapi idempotency guard untuk mencegah pendaftaran listener berulang.
   */
  public subscribe(): void {
    if (
      this.subscriptionState === 'SUBSCRIBED' ||
      this.subscriptionState === 'CONNECTING'
    ) {
      return;
    }

    const echo = getEcho();
    if (!echo) {
      this.updateStatus('ERROR', 'Instance Laravel Echo tidak tersedia.');
      this.options.onError?.(new Error('Instance Laravel Echo tidak tersedia.'));
      return;
    }

    this.updateStatus('CONNECTING');
    const channelName = `bchat.rooms.${this.options.roomUuid}`;

    try {
      const channel = echo.private(channelName);
      this.echoChannel = channel;

      channel.subscribed(() => {
        this.updateStatus('SUBSCRIBED');
        // Saat pertama terhubung atau reconnect: rekonsiliasi pesan yang mungkin terlewat
        this.reconcileFromRest().catch((_err) => {
          // Error ditangani melalui onError callback di dalam reconcileFromRest
        });
      });

      channel.error((err: any) => {
        const errMsg = err?.message || JSON.stringify(err) || 'Autentikasi private channel ditolak.';
        this.updateStatus('ERROR', errMsg);
        this.options.onError?.(new Error(`Otorisasi saluran privat gagal: ${errMsg}`));
      });

      // Dengarkan event siaran tipis (Thin Signal) .message.sent
      channel.listen('.message.sent', (rawPayload: unknown) => {
        this.handleSignal(rawPayload);
      });
    } catch (err: any) {
      const errMsg = err?.message || 'Gagal memulai koneksi WebSocket.';
      this.updateStatus('ERROR', errMsg);
      this.options.onError?.(new Error(errMsg));
    }
  }

  /**
   * Melakukan reconnect bersih: membersihkan listener dan saluran lama sebelum berlangganan kembali.
   */
  public reconnect(): void {
    this.unsubscribe();
    this.subscribe();
  }

  /**
   * Menangani sinyal yang masuk dari WebSocket Reverb secara defensif.
   */
  public handleSignal(rawPayload: unknown): void {
    if (this.subscriptionState === 'UNSUBSCRIBED') {
      return;
    }

    const signal = validateMessageSentSignal(rawPayload);
    if (!signal) {
      // Sinyal tidak valid atau rusak: abaikan demi keamanan
      return;
    }

    if (signal.room_uuid !== this.options.roomUuid.toLowerCase()) {
      return;
    }

    // Deteksi duplikasi event:
    const isDuplicate =
      this.processedSequences.has(signal.server_sequence) ||
      signal.server_sequence <= this.latestKnownSequence;

    this.options.onSignalReceived?.(signal, isDuplicate);

    if (isDuplicate) {
      // Event duplikat: cegah double-fetch dan cegah re-enkripsi/dekripsi
      return;
    }

    // Jika sedang dalam status membutuhkan pemulihan permanen, jangan trigger loop rekonsiliasi tak terbatas
    if (this.isRecoveryRequired) {
      return;
    }

    // Jika pesan dikirim oleh diri sendiri: perbarui kursor tanpa memanggil Worker
    if (signal.sender_id === this.options.currentUserId) {
      this.processedSequences.add(signal.server_sequence);
      this.latestKnownSequence = Math.max(this.latestKnownSequence, signal.server_sequence);
      updateGroupStateCursor({
        deviceUuid: this.options.deviceUuid,
        roomUuid: this.options.roomUuid,
        sequence: signal.server_sequence,
        fencing: {
          ownerToken: this.options.fencing.ownerToken,
          generation: this.options.fencing.generation,
        },
      }).catch((_err) => {
        // noop: safe fail
      });
      return;
    }

    // Sinyal sah dari peer: Pemicu untuk rekonsiliasi REST via coordinator
    this.reconcileFromRest().catch((_err) => {
      // Error ditangani melalui onError callback di dalam reconcileFromRest
    });
  }

  /**
   * Mengambil pesan baru dari REST API (Single Source of Truth) dan memproses dekripsi inbound melalui coordinator.
   * Dilengkapi pencegahan race condition konkurensi dan pemutusan infinite loop saat permanent error.
   */
  public async reconcileFromRest(): Promise<number> {
    if (this.subscriptionState === 'UNSUBSCRIBED') {
      return 0;
    }

    if (this.isRecoveryRequired) {
      // Pemulihan permanen dibutuhkan: jangan picu infinite reconcile loop
      return 0;
    }

    if (this.isReconciling) {
      // Hindari konkurensi ganda, tandai request tertunda untuk dieksekusi setelah batch selesai
      this.hasPendingReconcileRequest = true;
      return 0;
    }
    this.isReconciling = true;

    try {
      const reconcileRes = await reconcileInboundMessages({
        roomUuid: this.options.roomUuid,
        deviceUuid: this.options.deviceUuid,
        currentUserId: this.options.currentUserId,
        workerRpc: this.options.workerRpc,
        fencing: this.options.fencing,
        afterSequence: this.latestKnownSequence,
        batchLimit: this.options.batchLimit,
        processedSequences: this.processedSequences,
        onMessageDecrypted: this.options.onMessageDecrypted,
      });

      this.latestKnownSequence = reconcileRes.latestSequence;

      if (reconcileRes.hasErrors) {
        const errorMsg =
          reconcileRes.error ||
          `Rekonsiliasi terhenti pada sequence ${reconcileRes.failedSequence} karena kegagalan pemrosesan.`;

        if (reconcileRes.requiresRecovery) {
          this.isRecoveryRequired = true;
          this.recoveryErrorDetail = errorMsg;
          this.lastFailedSequence = reconcileRes.failedSequence ?? null;
          this.options.onRecoveryRequired?.(
            reconcileRes.failedSequence ?? 0,
            errorMsg,
            reconcileRes.errorCategory
          );
        }

        this.options.onError?.(new Error(errorMsg));
      }

      this.options.onReconciled?.(reconcileRes.reconciledCount, this.latestKnownSequence);
      return reconcileRes.reconciledCount;
    } catch (err: any) {
      const errorObj = err instanceof Error ? err : new Error(String(err));
      this.options.onError?.(errorObj);
      return 0;
    } finally {
      this.isReconciling = false;
      // Jika ada request sinyal yang tiba di tengah rekonsiliasi dan tidak terblokir permanent error,
      // jalankan catch-up untuk request tertunda tersebut
      if (
        this.hasPendingReconcileRequest &&
        !this.isRecoveryRequired &&
        this.subscriptionState === 'SUBSCRIBED'
      ) {
        this.hasPendingReconcileRequest = false;
        this.reconcileFromRest().catch((_err) => {});
      }
    }
  }

  /**
   * Mengatur ulang status pemulihan (misalnya setelah rekonsiliasi manual atau re-onboarding).
   */
  public resetRecoveryState(): void {
    this.isRecoveryRequired = false;
    this.recoveryErrorDetail = null;
    this.lastFailedSequence = null;
    this.hasPendingReconcileRequest = false;
  }

  public getIsRecoveryRequired(): boolean {
    return this.isRecoveryRequired;
  }

  public getRecoveryErrorDetail(): string | null {
    return this.recoveryErrorDetail;
  }

  public getLastFailedSequence(): number | null {
    return this.lastFailedSequence;
  }

  /**
   * Berhenti berlangganan dan membersihkan saluran WebSocket.
   */
  public unsubscribe(): void {
    this.updateStatus('UNSUBSCRIBED');
    if (this.echoChannel) {
      try {
        if (typeof this.echoChannel.stopListening === 'function') {
          this.echoChannel.stopListening('.message.sent');
        }
      } catch (_err) {
        // noop
      }
      const echo = getEcho();
      if (echo) {
        try {
          echo.leave(`bchat.rooms.${this.options.roomUuid}`);
        } catch (_err) {
          // noop: abaikan error pelepasan channel jika koneksi sudah terputus
        }
      }
      this.echoChannel = null;
    }
  }

  private updateStatus(state: SubscriptionState, detail?: string): void {
    this.subscriptionState = state;
    this.options.onSubscriptionStatusChange?.(state, detail);
  }
}
