'use client';

/**
 * B-CHAT E2EE — History Transfer Modal Component (Tahap 2B)
 *
 * Komponen dialog interaktif pemindahan riwayat obrolan terenkripsi antarperangkat tepercaya:
 * 1. Mode Permintaan (Perangkat Penerima):
 *    - Menampilkan daftar perangkat tepercaya aktif milik akun yang dapat menjadi sumber riwayat.
 *    - Menjalankan initiateTransferFlow() untuk meminta transfer ber-signature Ed25519.
 *    - Memantau kesiapan snapshot dan menjalankan downloadAndImportSnapshotFlow() saat READY.
 *    - Mengimpor riwayat secara atomik ke IndexedDB lokal menggunakan KEK perangkat lokal.
 * 2. Mode Persetujuan (Perangkat Sumber):
 *    - Menampilkan notifikasi & konfirmasi saat terdeteksi permintaan transfer masuk.
 *    - Membaca riwayat lokal dari IndexedDB, mengenkripsi snapshot via ECDH HKDF AES-256-GCM,
 *      dan mengunggah ciphertext ke server melalui exportAndUploadSnapshotFlow().
 *    - Menolak/membatalkan ekspor tanpa persetujuan eksplisit pengguna.
 */

import React, { useState, useEffect, useRef, useCallback } from 'react';
import {
  History,
  Smartphone,
  Laptop,
  Tablet,
  ShieldCheck,
  ShieldAlert,
  AlertTriangle,
  CheckCircle2,
  Loader2,
  RefreshCw,
  X,
  ArrowRight,
  Lock,
  Clock,
  Check,
} from 'lucide-react';
import { getDeviceStatus, BchatApiError, parseBchatApiError } from '@/lib/bchat/api/bchat-api-client';
import type { BchatActiveDeviceInfo } from '@/lib/bchat/api/bchat-api-types';
import { getActiveDeviceIdentityKey, findActiveLocalDevice } from '@/lib/bchat/device/bchat-device-registration';
import {
  initiateTransferFlow,
  exportAndUploadSnapshotFlow,
  downloadAndImportSnapshotFlow,
} from '@/lib/bchat/history/bchat-history-transfer-coordinator';
import {
  fetchHistoryTransferDetail,
  cancelHistoryTransfer,
  type BchatHistoryTransferData,
} from '@/lib/bchat/history/bchat-history-transfer-client';

export interface BchatHistoryTransferModalProps {
  isOpen: boolean;
  onClose: () => void;
  roomUuid: string;
  currentDeviceUuid: string;
  userId: number;
  incomingTransfer?: BchatHistoryTransferData | null;
  onIncomingHandled?: () => void;
  onTransferSuccess?: (importedCount: number) => void;
}

type RecipientStep = 'SELECT_SOURCE' | 'WAITING_APPROVAL' | 'IMPORTING' | 'COMPLETED';

function getDeviceIcon(name: string) {
  const lower = (name || '').toLowerCase();
  if (
    lower.includes('phone') ||
    lower.includes('hp') ||
    lower.includes('android') ||
    lower.includes('iphone') ||
    lower.includes('mobile') ||
    lower.includes('smartphone')
  ) {
    return Smartphone;
  }
  if (lower.includes('tablet') || lower.includes('ipad')) {
    return Tablet;
  }
  return Laptop;
}

export function BchatHistoryTransferModal({
  isOpen,
  onClose,
  roomUuid,
  currentDeviceUuid,
  userId,
  incomingTransfer,
  onIncomingHandled,
  onTransferSuccess,
}: BchatHistoryTransferModalProps) {
  // Mode Operasi: 'INCOMING_APPROVAL' (Sumber) vs 'REQUEST' (Penerima)
  const isIncomingMode = Boolean(incomingTransfer);

  // State Perangkat Penerima
  const [recipientStep, setRecipientStep] = useState<RecipientStep>('SELECT_SOURCE');
  const [activeTrustedDevices, setActiveTrustedDevices] = useState<BchatActiveDeviceInfo[]>([]);
  const [selectedSourceDevice, setSelectedSourceDevice] = useState<BchatActiveDeviceInfo | null>(null);
  const [isLoadingDevices, setIsLoadingDevices] = useState<boolean>(true);
  const [activeTransfer, setActiveTransfer] = useState<BchatHistoryTransferData | null>(null);
  const [importedMessageCount, setImportedMessageCount] = useState<number>(0);

  // State Perangkat Sumber
  const [isExporting, setIsExporting] = useState<boolean>(false);
  const [exportSuccessMsg, setExportSuccessMsg] = useState<string | null>(null);

  // Shared State
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [secondsRemaining, setSecondsRemaining] = useState<number>(600);
  const [isCancelling, setIsCancelling] = useState<boolean>(false);
  const [isClosing, setIsClosing] = useState<boolean>(false);

  const pollIntervalRef = useRef<NodeJS.Timeout | null>(null);
  const countdownIntervalRef = useRef<NodeJS.Timeout | null>(null);

  const clearTimers = useCallback(() => {
    if (pollIntervalRef.current) {
      clearInterval(pollIntervalRef.current);
      pollIntervalRef.current = null;
    }
    if (countdownIntervalRef.current) {
      clearInterval(countdownIntervalRef.current);
      countdownIntervalRef.current = null;
    }
  }, []);

  useEffect(() => {
    return () => clearTimers();
  }, [clearTimers]);

  // Muat daftar perangkat tepercaya lain saat modal dibuka dalam mode Request
  useEffect(() => {
    if (!isOpen || isIncomingMode) return;

    // Pertahankan status dan transfer aktif bila sedang dalam alur persetujuan/impor
    if (activeTransfer && (recipientStep === 'WAITING_APPROVAL' || recipientStep === 'IMPORTING')) {
      return;
    }

    let isMounted = true;
    setIsLoadingDevices(true);
    setErrorMsg(null);
    setRecipientStep('SELECT_SOURCE');
    setSelectedSourceDevice(null);
    setActiveTransfer(null);

    getDeviceStatus()
      .then((res) => {
        if (!isMounted) return;
        const devices = res.data.active_devices || [];
        // Filter hanya perangkat ACTIVE, TRUSTED, dan bukan perangkat saat ini
        const eligible = devices.filter(
          (d) =>
            d.device_uuid !== currentDeviceUuid &&
            d.status === 'ACTIVE' &&
            d.is_trusted === true
        );
        setActiveTrustedDevices(eligible);
        if (eligible.length > 0) {
          setSelectedSourceDevice(eligible[0]);
        }
      })
      .catch((err) => {
        if (!isMounted) return;
        const parsed = parseBchatApiError(err);
        setErrorMsg(parsed.message || 'Gagal memuat daftar perangkat tepercaya.');
      })
      .finally(() => {
        if (isMounted) setIsLoadingDevices(false);
      });

    return () => {
      isMounted = false;
    };
  }, [isOpen, isIncomingMode, currentDeviceUuid, activeTransfer, recipientStep]);

  // Ambil private key Ed25519 lokal perangkat ini dari Keystore IndexedDB
  const getLocalPrivateKey = async (): Promise<CryptoKey> => {
    let keyRecord = await getActiveDeviceIdentityKey(currentDeviceUuid);
    if (!keyRecord || !keyRecord.crypto_key) {
      const local = await findActiveLocalDevice();
      if (local && local.deviceUuid === currentDeviceUuid) {
        keyRecord = local.record;
      }
    }
    if (!keyRecord || !keyRecord.crypto_key) {
      throw new Error('Kunci privat identitas perangkat tidak ditemukan di penyimpanan lokal.');
    }
    return keyRecord.crypto_key;
  };

  // ---------------------------------------------------------------------------
  // ALUR 1: PERANGKAT PENERIMA (REQUEST & IMPORT)
  // ---------------------------------------------------------------------------

  const handleStartRequestTransfer = async () => {
    if (!selectedSourceDevice) {
      setErrorMsg('Harap pilih perangkat sumber terlebih dahulu.');
      return;
    }

    setErrorMsg(null);
    setIsLoadingDevices(true);

    try {
      const privateKey = await getLocalPrivateKey();
      const { transfer } = await initiateTransferFlow(
        userId,
        roomUuid,
        currentDeviceUuid,
        privateKey,
        selectedSourceDevice.device_uuid
      );

      setActiveTransfer(transfer);
      setRecipientStep('WAITING_APPROVAL');
      setSecondsRemaining(600);

      // Hitung mundur masa berlaku (10 menit)
      countdownIntervalRef.current = setInterval(() => {
        setSecondsRemaining((prev) => {
          if (prev <= 1) {
            clearTimers();
            setErrorMsg('Permintaan transfer telah kedaluwarsa.');
            return 0;
          }
          return prev - 1;
        });
      }, 1000);

      // Polling status transfer ke backend
      pollIntervalRef.current = setInterval(async () => {
        try {
          const detailRes = await fetchHistoryTransferDetail(
            transfer.transfer_uuid,
            currentDeviceUuid
          );
          const currentStatus = detailRes.data.status;

          if (currentStatus === 'READY') {
            clearTimers();
            setRecipientStep('IMPORTING');
            await handleExecuteImport(transfer.transfer_uuid, selectedSourceDevice.public_key);
          } else if (currentStatus === 'CANCELLED') {
            clearTimers();
            setErrorMsg('Permintaan transfer ditolak atau dibatalkan oleh perangkat sumber.');
            setRecipientStep('SELECT_SOURCE');
          } else if (currentStatus === 'EXPIRED') {
            clearTimers();
            setErrorMsg('Sesi transfer telah kedaluwarsa.');
            setRecipientStep('SELECT_SOURCE');
          }
        } catch (_pollErr) {
          // Abaikan kesalahan koneksi sesaat saat polling
        }
      }, 2500);
    } catch (err: unknown) {
      const parsed = parseBchatApiError(err);
      if (parsed.errorCode === 'TRANSFER_ALREADY_PENDING' || parsed.status === 409) {
        setErrorMsg(
          parsed.message ||
            'Masih terdapat sesi transfer riwayat yang aktif untuk ruang obrolan ini. Harap tunggu hingga sesi sebelumnya selesai atau kedaluwarsa.'
        );
      } else {
        setErrorMsg(parsed.message || 'Gagal mengirim permintaan transfer.');
      }
    } finally {
      setIsLoadingDevices(false);
    }
  };

  const handleExecuteImport = async (transferUuid: string, sourcePubKeyHex?: string) => {
    try {
      const privateKey = await getLocalPrivateKey();
      const res = await downloadAndImportSnapshotFlow(
        userId,
        transferUuid,
        currentDeviceUuid,
        privateKey,
        sourcePubKeyHex
      );

      setImportedMessageCount(res.importedCount);
      setRecipientStep('COMPLETED');
      if (onTransferSuccess) {
        onTransferSuccess(res.importedCount);
      }
    } catch (err: unknown) {
      const parsed = parseBchatApiError(err);
      setErrorMsg(parsed.message || 'Gagal mendekripsi atau mengimpor riwayat.');
      setRecipientStep('SELECT_SOURCE');
    }
  };

  const handleCancelRequest = async () => {
    if (!activeTransfer || isCancelling) {
      clearTimers();
      setRecipientStep('SELECT_SOURCE');
      setActiveTransfer(null);
      return;
    }

    setIsCancelling(true);
    setErrorMsg(null);
    try {
      await cancelHistoryTransfer(
        activeTransfer.transfer_uuid,
        'Dibatalkan oleh perangkat penerima',
        currentDeviceUuid
      );
      clearTimers();
      setActiveTransfer(null);
      setRecipientStep('SELECT_SOURCE');
    } catch (err: unknown) {
      const parsed = parseBchatApiError(err);
      if (parsed.status === 404 || parsed.errorCode === 'TRANSFER_NOT_FOUND') {
        clearTimers();
        setActiveTransfer(null);
        setRecipientStep('SELECT_SOURCE');
        return;
      }
      setErrorMsg(
        `Gagal membatalkan transfer: ${parsed.message || 'Kesalahan jaringan'}. Sesi transfer masih aktif di server.`
      );
    } finally {
      setIsCancelling(false);
    }
  };

  // ---------------------------------------------------------------------------
  // ALUR 2: PERANGKAT SUMBER (APPROVAL & EXPORT)
  // ---------------------------------------------------------------------------

  const handleApproveAndExport = async () => {
    if (!incomingTransfer || isExporting) return;

    setIsExporting(true);
    setErrorMsg(null);
    setExportSuccessMsg(null);

    try {
      const privateKey = await getLocalPrivateKey();
      await exportAndUploadSnapshotFlow(
        userId,
        incomingTransfer,
        currentDeviceUuid,
        privateKey
      );

      setExportSuccessMsg('Snapshot riwayat berhasil dienkripsi dan diunggah ke server!');
      setTimeout(() => {
        if (onIncomingHandled) onIncomingHandled();
        onClose();
      }, 2000);
    } catch (err: unknown) {
      const parsed = parseBchatApiError(err);
      setErrorMsg(parsed.message || 'Gagal mengekspor riwayat obrolan.');
    } finally {
      setIsExporting(false);
    }
  };

  const handleRejectIncoming = async () => {
    if (!incomingTransfer || isExporting) return;
    setIsExporting(true);
    setErrorMsg(null);
    try {
      await cancelHistoryTransfer(
        incomingTransfer.transfer_uuid,
        'Ditolak oleh pengguna perangkat sumber',
        currentDeviceUuid
      );
      if (onIncomingHandled) onIncomingHandled();
      onClose();
    } catch (err: unknown) {
      const parsed = parseBchatApiError(err);
      if (parsed.status === 404 || parsed.errorCode === 'TRANSFER_NOT_FOUND') {
        if (onIncomingHandled) onIncomingHandled();
        onClose();
        return;
      }
      setErrorMsg(`Gagal menolak transfer: ${parsed.message || 'Kesalahan jaringan'}. Silakan coba lagi.`);
    } finally {
      setIsExporting(false);
    }
  };

  // Penanganan penutupan modal dengan pembatalan transfer aktif jika masih menunggu persetujuan
  const handleRequestClose = async () => {
    if (isExporting || isClosing || isCancelling) return;

    // Jika sedang dalam tahap menunggu persetujuan dan ada transfer aktif milik perangkat ini
    if (
      recipientStep === 'WAITING_APPROVAL' &&
      activeTransfer &&
      activeTransfer.recipient_device_uuid === currentDeviceUuid
    ) {
      setIsClosing(true);
      setErrorMsg(null);
      try {
        await cancelHistoryTransfer(
          activeTransfer.transfer_uuid,
          'Dibatalkan karena dialog ditutup oleh pengguna',
          currentDeviceUuid
        );
        clearTimers();
        setActiveTransfer(null);
        setRecipientStep('SELECT_SOURCE');
        setIsClosing(false);
        onClose();
      } catch (err: unknown) {
        setIsClosing(false);
        const parsed = parseBchatApiError(err);
        if (parsed.status === 404 || parsed.errorCode === 'TRANSFER_NOT_FOUND') {
          clearTimers();
          setActiveTransfer(null);
          setRecipientStep('SELECT_SOURCE');
          onClose();
          return;
        }
        setErrorMsg(
          `Gagal membatalkan transfer di server: ${parsed.message || 'Kesalahan jaringan'}. Sesi transfer masih aktif. Harap batalkan permintaan sebelum menutup.`
        );
      }
      return;
    }

    clearTimers();
    onClose();
  };

  if (!isOpen) return null;

  // Format detik ke MM:SS
  const formatTime = (secs: number) => {
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return `${m}:${s < 10 ? '0' : ''}${s}`;
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs font-sans animate-in fade-in duration-200">
      <div className="bg-white rounded-3xl border border-slate-200/80 shadow-2xl max-w-lg w-full overflow-hidden flex flex-col max-h-[90vh]">
        {/* Header Modal */}
        <div className="p-5 sm:p-6 border-b border-slate-100 flex items-start justify-between gap-4">
          <div className="flex items-center gap-3">
            <div
              className={`w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 shadow-2xs ${
                isIncomingMode
                  ? 'bg-amber-50 border border-amber-200 text-amber-600'
                  : 'bg-emerald-50 border border-emerald-200 text-emerald-600'
              }`}
            >
              {isIncomingMode ? (
                <ShieldAlert className="w-5 h-5 text-amber-600" />
              ) : (
                <History className="w-5 h-5 text-emerald-600" />
              )}
            </div>
            <div>
              <h2 className="font-bold text-base text-slate-900">
                {isIncomingMode
                  ? 'Permintaan Transfer Riwayat Masuk'
                  : 'Sinkronisasi Riwayat Chat E2EE'}
              </h2>
              <p className="text-xs text-slate-500 mt-0.5">
                {isIncomingMode
                  ? 'Perangkat lain meminta transfer riwayat terenkripsi'
                  : 'Pindahkan riwayat dari perangkat tepercaya Anda yang lain'}
              </p>
            </div>
          </div>
          <button
            onClick={handleRequestClose}
            disabled={isExporting || isClosing || isCancelling}
            className="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-xl transition cursor-pointer disabled:opacity-50"
            title="Tutup dialog"
          >
            {isClosing ? <Loader2 className="w-5 h-5 animate-spin" /> : <X className="w-5 h-5" />}
          </button>
        </div>

        {/* Konten Modal */}
        <div className="p-5 sm:p-6 space-y-4 overflow-y-auto">
          {/* Pesan Kesalahan */}
          {errorMsg && (
            <div className="p-3.5 bg-rose-50 border border-rose-200 rounded-2xl flex items-start gap-2.5 text-xs text-rose-700 animate-in fade-in">
              <AlertTriangle className="w-4 h-4 shrink-0 text-rose-600 mt-0.5" />
              <p className="flex-1 leading-relaxed">{errorMsg}</p>
            </div>
          )}

          {/* ================================================================= */}
          {/* MODE 1: PERANGKAT SUMBER (PERSETUJUAN & EKSPOR)                     */}
          {/* ================================================================= */}
          {isIncomingMode && incomingTransfer && (
            <div className="space-y-4">
              <div className="p-4 bg-amber-50/70 border border-amber-200/80 rounded-2xl space-y-2">
                <div className="flex items-center gap-2 text-xs font-bold text-amber-900">
                  <ShieldCheck className="w-4 h-4 text-amber-600" />
                  <span>Konfirmasi Otorisasi Pengguna Diperlukan</span>
                </div>
                <p className="text-xs text-amber-800 leading-relaxed">
                  Perangkat baru Anda meminta salinan riwayat chat lokal yang terenkripsi. Demi
                  keamanan E2EE, riwayat <strong>tidak akan pernah</strong> diekspor tanpa
                  persetujuan Anda.
                </p>
              </div>

              <div className="p-4 bg-slate-50 border border-slate-200/80 rounded-2xl space-y-2.5 text-xs">
                <div className="flex items-center justify-between text-slate-500">
                  <span>ID Perangkat Pemohon:</span>
                  <span className="font-mono text-slate-700 font-semibold">
                    {incomingTransfer.recipient_device_uuid.substring(0, 8)}...
                    {incomingTransfer.recipient_device_uuid.substring(28)}
                  </span>
                </div>
                <div className="flex items-center justify-between text-slate-500">
                  <span>ID Sesi Transfer:</span>
                  <span className="font-mono text-slate-700">
                    {incomingTransfer.transfer_uuid.substring(0, 12)}...
                  </span>
                </div>
                <div className="flex items-center justify-between text-slate-500">
                  <span>Protokol Enkripsi:</span>
                  <span className="font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200 text-[10px]">
                    {incomingTransfer.key_exchange_algorithm} (HKDF-AES-GCM)
                  </span>
                </div>
              </div>

              {exportSuccessMsg ? (
                <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center gap-3 text-xs text-emerald-700 font-semibold">
                  <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />
                  <span>{exportSuccessMsg}</span>
                </div>
              ) : (
                <div className="flex items-center gap-3 pt-2">
                  <button
                    onClick={handleRejectIncoming}
                    disabled={isExporting}
                    className="flex-1 py-2.5 px-4 bg-white hover:bg-slate-100 text-slate-700 border border-slate-300 rounded-xl text-xs font-semibold transition cursor-pointer"
                  >
                    Tolak Permintaan
                  </button>
                  <button
                    onClick={handleApproveAndExport}
                    disabled={isExporting}
                    className="flex-1 py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition flex items-center justify-center gap-2 shadow-sm cursor-pointer disabled:opacity-50"
                  >
                    {isExporting ? (
                      <>
                        <Loader2 className="w-4 h-4 animate-spin" />
                        <span>Mengekspor Riwayat...</span>
                      </>
                    ) : (
                      <>
                        <ShieldCheck className="w-4 h-4" />
                        <span>Setujui & Kirim Riwayat</span>
                      </>
                    )}
                  </button>
                </div>
              )}
            </div>
          )}

          {/* ================================================================= */}
          {/* MODE 2: PERANGKAT PENERIMA (PILIH SUMBER & IMPORT)                 */}
          {/* ================================================================= */}
          {!isIncomingMode && (
            <>
              {/* STEP 1: PILIH PERANGKAT SUMBER */}
              {recipientStep === 'SELECT_SOURCE' && (
                <div className="space-y-4">
                  <div className="text-xs text-slate-600 leading-relaxed">
                    Pilih perangkat tepercaya aktif Anda yang menyimpan riwayat obrolan ruang ini
                    sebagai sumber transfer:
                  </div>

                  {isLoadingDevices ? (
                    <div className="p-8 text-center space-y-2">
                      <Loader2 className="w-6 h-6 animate-spin text-emerald-600 mx-auto" />
                      <p className="text-xs text-slate-500">Memeriksa perangkat tepercaya aktif...</p>
                    </div>
                  ) : activeTrustedDevices.length === 0 ? (
                    <div className="p-4 bg-amber-50 border border-amber-200 rounded-2xl space-y-2 text-xs text-amber-800">
                      <div className="flex items-center gap-2 font-bold text-amber-900">
                        <AlertTriangle className="w-4 h-4 text-amber-600" />
                        <span>Tidak Ada Perangkat Sumber yang Tersedia</span>
                      </div>
                      <p className="leading-relaxed">
                        Tidak ditemukan perangkat aktif lain berstatus tepercaya (TRUSTED). Pastikan
                        perangkat Anda yang lain (misal: laptop atau ponsel lama) sudah terdaftar
                        dan aktif.
                      </p>
                    </div>
                  ) : (
                    <div className="space-y-2">
                      {activeTrustedDevices.map((dev) => {
                        const isSelected =
                          selectedSourceDevice?.device_uuid === dev.device_uuid;
                        const Icon = getDeviceIcon(dev.device_name);

                        return (
                          <div
                            key={dev.device_uuid}
                            onClick={() => setSelectedSourceDevice(dev)}
                            className={`p-3.5 rounded-2xl border transition flex items-center justify-between cursor-pointer ${
                              isSelected
                                ? 'bg-emerald-50/70 border-emerald-400 shadow-2xs'
                                : 'bg-slate-50 hover:bg-slate-100/80 border-slate-200/80'
                            }`}
                          >
                            <div className="flex items-center gap-3">
                              <div
                                className={`w-9 h-9 rounded-xl flex items-center justify-center shrink-0 ${
                                  isSelected
                                    ? 'bg-emerald-600 text-white'
                                    : 'bg-white border border-slate-200 text-slate-600'
                                }`}
                              >
                                <Icon className="w-4 h-4" />
                              </div>
                              <div>
                                <div className="flex items-center gap-2">
                                  <span className="font-bold text-xs text-slate-900">
                                    {dev.device_name}
                                  </span>
                                  <span className="inline-flex items-center px-1.5 py-0.2 rounded-full text-[9px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                    Tepercaya
                                  </span>
                                </div>
                                <p className="text-[10px] text-slate-400 font-mono mt-0.5">
                                  ID: {dev.device_uuid.substring(0, 8)}...
                                </p>
                              </div>
                            </div>
                            <div className="shrink-0">
                              <div
                                className={`w-5 h-5 rounded-full border flex items-center justify-center ${
                                  isSelected
                                    ? 'border-emerald-600 bg-emerald-600 text-white'
                                    : 'border-slate-300 bg-white'
                                }`}
                              >
                                {isSelected && <Check className="w-3 h-3 stroke-[3]" />}
                              </div>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )}

                  <div className="pt-2">
                    <button
                      onClick={handleStartRequestTransfer}
                      disabled={!selectedSourceDevice || isLoadingDevices}
                      className="w-full py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition flex items-center justify-center gap-2 shadow-sm cursor-pointer disabled:opacity-50"
                    >
                      <ArrowRight className="w-4 h-4" />
                      <span>Kirim Permintaan Transfer Riwayat</span>
                    </button>
                  </div>
                </div>
              )}

              {/* STEP 2: MENUNGGU PERSETUJUAN PERANGKAT SUMBER */}
              {recipientStep === 'WAITING_APPROVAL' && (
                <div className="p-6 text-center space-y-4">
                  <div className="w-14 h-14 rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center mx-auto text-emerald-600">
                    <Loader2 className="w-7 h-7 animate-spin" />
                  </div>
                  <div>
                    <h3 className="font-bold text-sm text-slate-900">
                      Menunggu Konfirmasi di Perangkat Sumber...
                    </h3>
                    <p className="text-xs text-slate-500 mt-1 max-w-sm mx-auto">
                      Buka perangkat{' '}
                      <strong>{selectedSourceDevice?.device_name || 'sumber Anda'}</strong> dan
                      setujui permintaan transfer yang muncul pada layar obrolan.
                    </p>
                  </div>

                  <div className="inline-flex items-center gap-1.5 px-3 py-1 bg-slate-100 rounded-full text-xs font-mono text-slate-600">
                    <Clock className="w-3.5 h-3.5 text-slate-500" />
                    <span>Masa berlaku: {formatTime(secondsRemaining)}</span>
                  </div>

                  <div className="pt-2">
                    <button
                      onClick={handleCancelRequest}
                      disabled={isCancelling || isClosing}
                      className="px-4 py-2 text-xs font-semibold text-rose-600 hover:bg-rose-50 rounded-xl transition cursor-pointer border border-rose-200 disabled:opacity-50 inline-flex items-center gap-1.5"
                    >
                      {isCancelling && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                      <span>{isCancelling ? 'Membatalkan...' : 'Batalkan Permintaan'}</span>
                    </button>
                  </div>
                </div>
              )}

              {/* STEP 3: MENGUNDUH & MENDEKRIPSI */}
              {recipientStep === 'IMPORTING' && (
                <div className="p-6 text-center space-y-3">
                  <div className="w-12 h-12 rounded-full bg-teal-50 border border-teal-200 flex items-center justify-center mx-auto text-teal-600">
                    <RefreshCw className="w-6 h-6 animate-spin" />
                  </div>
                  <h3 className="font-bold text-sm text-slate-900">
                    Mengimpor Riwayat Terenkripsi...
                  </h3>
                  <p className="text-xs text-slate-500">
                    Memverifikasi tanda tangan digital sumber, mendekripsi snapshot dengan kunci
                    transfer AES-256-GCM, dan menyimpan ke IndexedDB lokal.
                  </p>
                </div>
              )}

              {/* STEP 4: SELESAI */}
              {recipientStep === 'COMPLETED' && (
                <div className="p-6 text-center space-y-4">
                  <div className="w-14 h-14 rounded-full bg-emerald-100 border border-emerald-300 flex items-center justify-center mx-auto text-emerald-700 shadow-2xs">
                    <CheckCircle2 className="w-8 h-8" />
                  </div>
                  <div>
                    <h3 className="font-bold text-base text-slate-900">
                      Riwayat Obrolan Berhasil Disinkronkan!
                    </h3>
                    <p className="text-xs text-slate-500 mt-1">
                      Sebanyak <strong>{importedMessageCount} pesan</strong> telah berhasil
                      didekripsi dan disimpan secara aman ke dalam IndexedDB perangkat ini.
                    </p>
                  </div>

                  <div className="pt-2">
                    <button
                      onClick={() => {
                        clearTimers();
                        onClose();
                      }}
                      className="w-full py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition shadow-sm cursor-pointer"
                    >
                      Buka Obrolan
                    </button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
