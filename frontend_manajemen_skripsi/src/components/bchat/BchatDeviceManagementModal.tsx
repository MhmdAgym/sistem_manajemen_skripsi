'use client';

/**
 * B-CHAT E2EE — Device Management Modal (Tahap 1B)
 *
 * Komponen modal manajemen multi-perangkat bagi mahasiswa dan dosen:
 * - Menampilkan daftar seluruh perangkat aktif milik akun pengguna.
 * - Mengidentifikasi "Perangkat Ini" berdasarkan UUID lokal.
 * - Mencegah pencabutan tidak sengaja terhadap perangkat yang sedang aktif digunakan.
 * - Memfasilitasi pencabutan (revocation) perangkat lain dengan otorisasi kata sandi (step-up).
 * - Menampilkan riwayat perangkat yang telah dicabut (REVOKED).
 * - Menangani status loading, pesan sukses, dan penanganan kesalahan otentikasi.
 */

import React, { useState, useEffect, useCallback } from 'react';
import {
  Laptop,
  Smartphone,
  Tablet,
  ShieldCheck,
  ShieldAlert,
  AlertTriangle,
  CheckCircle2,
  Trash2,
  Lock,
  Eye,
  EyeOff,
  Loader2,
  RefreshCw,
  X,
  History,
  ChevronDown,
  ChevronUp,
  Info,
  KeyRound,
  Check,
  QrCode,
  Clock,
} from 'lucide-react';
import { useAuth } from '@/hooks/auth';
import {
  getDeviceStatus,
  revokeDevice,
  BchatApiError,
} from '@/lib/bchat/api/bchat-api-client';
import type {
  BchatActiveDeviceInfo,
  BchatDeviceStatusData,
  BchatPairingLookupData,
} from '@/lib/bchat/api/bchat-api-types';
import {
  lookupPairingByCode,
  approvePairingFlow,
  rejectPairing,
} from '@/lib/bchat/device/bchat-pairing-client';

export interface BchatDeviceManagementModalProps {
  isOpen: boolean;
  onClose: () => void;
  currentDeviceUuid: string | null;
  onDeviceRevoked?: (revokedUuid: string) => void;
  onRequestHistoryTransfer?: (sourceDevice: BchatActiveDeviceInfo) => void;
  onDevicesChanged?: () => void;
}

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

function formatIndonesianDate(dateStr?: string | null): string {
  if (!dateStr) return '-';
  try {
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) return '-';
    return d.toLocaleString('id-ID', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return '-';
  }
}

export function BchatDeviceManagementModal({
  isOpen,
  onClose,
  currentDeviceUuid,
  onDeviceRevoked,
  onRequestHistoryTransfer,
  onDevicesChanged,
}: BchatDeviceManagementModalProps) {
  // State data perangkat
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [activeDevices, setActiveDevices] = useState<BchatActiveDeviceInfo[]>([]);
  const [revokedDevices, setRevokedDevices] = useState<BchatActiveDeviceInfo[]>([]);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);
  const [showRevokedHistory, setShowRevokedHistory] = useState<boolean>(false);

  // User auth context
  const { user } = useAuth();

  // State dialog penautan (pairing) perangkat baru
  const [isPairingModalOpen, setIsPairingModalOpen] = useState<boolean>(false);
  const [pairingCodeInput, setPairingCodeInput] = useState<string>('');
  const [isLookingUpPairing, setIsLookingUpPairing] = useState<boolean>(false);
  const [lookupError, setLookupError] = useState<string | null>(null);
  const [foundPairing, setFoundPairing] = useState<BchatPairingLookupData | null>(null);
  const [isApprovingPairing, setIsApprovingPairing] = useState<boolean>(false);

  // State dialog konfirmasi pencabutan
  const [targetDevice, setTargetDevice] = useState<BchatActiveDeviceInfo | null>(null);
  const [accountPassword, setAccountPassword] = useState<string>('');
  const [showPassword, setShowPassword] = useState<boolean>(false);
  const [revocationReason, setRevocationReason] = useState<string>('');
  const [isRevoking, setIsRevoking] = useState<boolean>(false);
  const [revokeError, setRevokeError] = useState<string | null>(null);

  // Fetch daftar perangkat aktif & riwayat dari backend
  const loadDevices = useCallback(async () => {
    setIsLoading(true);
    setErrorMsg(null);
    try {
      const res = await getDeviceStatus({
        device_uuid: currentDeviceUuid || undefined,
      });

      const data: BchatDeviceStatusData = res.data;
      if (data.active_devices && Array.isArray(data.active_devices)) {
        setActiveDevices(data.active_devices);
      } else if (data.active_device) {
        setActiveDevices([data.active_device]);
      } else {
        setActiveDevices([]);
      }

      if (data.revoked_devices && Array.isArray(data.revoked_devices)) {
        setRevokedDevices(data.revoked_devices);
      } else {
        setRevokedDevices([]);
      }
    } catch (err) {
      if (err instanceof BchatApiError) {
        setErrorMsg(`Gagal memuat status perangkat: ${err.message}`);
      } else {
        setErrorMsg('Terjadi kesalahan saat memuat daftar perangkat.');
      }
    } finally {
      setIsLoading(false);
    }
  }, [currentDeviceUuid]);

  // Muat data saat modal dibuka
  useEffect(() => {
    if (isOpen) {
      loadDevices();
      setSuccessMsg(null);
      setErrorMsg(null);
      setTargetDevice(null);
      setAccountPassword('');
      setRevokeError(null);
    }
  }, [isOpen, loadDevices]);

  // Buka dialog konfirmasi pencabutan
  const handleInitiateRevoke = (device: BchatActiveDeviceInfo) => {
    if (device.device_uuid === currentDeviceUuid) {
      return; // Perlindungan: Tidak dapat mencabut perangkat yang sedang aktif
    }
    setTargetDevice(device);
    setAccountPassword('');
    setShowPassword(false);
    setRevocationReason('');
    setRevokeError(null);
  };

  // Batalkan pencabutan
  const handleCancelRevoke = () => {
    setTargetDevice(null);
    setAccountPassword('');
    setRevokeError(null);
  };

  // Submit pencabutan perangkat dengan password akun
  const handleConfirmRevoke = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!targetDevice) return;

    if (!accountPassword.trim()) {
      setRevokeError('Kata sandi akun wajib diisi untuk mengonfirmasi pencabutan.');
      return;
    }

    setIsRevoking(true);
    setRevokeError(null);

    try {
      const reason = revocationReason.trim() || 'Dicabut oleh pengguna melalui Manajemen Perangkat';
      await revokeDevice(targetDevice.device_uuid, {
        password: accountPassword,
        reason,
      });

      const revokedName = targetDevice.device_name;
      const revokedUuid = targetDevice.device_uuid;

      // Reset state form konfirmasi
      setTargetDevice(null);
      setAccountPassword('');
      setSuccessMsg(`Akses perangkat "${revokedName}" berhasil dicabut.`);

      // Muat ulang daftar perangkat
      await loadDevices();

      if (onDeviceRevoked) {
        onDeviceRevoked(revokedUuid);
      }
      if (onDevicesChanged) {
        onDevicesChanged();
      }
    } catch (err) {
      if (err instanceof BchatApiError) {
        if (err.errorCode === 'INVALID_CREDENTIALS') {
          setRevokeError('Kata sandi salah. Harap periksa kembali kata sandi akun Anda.');
        } else if (err.errorCode === 'STEP_UP_REQUIRED') {
          setRevokeError('Otorisasi kata sandi akun diperlukan.');
        } else {
          setRevokeError(err.message || 'Gagal mencabut akses perangkat.');
        }
      } else {
        setRevokeError('Terjadi kesalahan koneksi saat mencabut perangkat.');
      }
    } finally {
      setIsRevoking(false);
    }
  };

  // Cari sesi pairing berdasarkan kode pairing (approver device)
  const handleLookupPairing = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!pairingCodeInput.trim()) return;

    setIsLookingUpPairing(true);
    setLookupError(null);
    setFoundPairing(null);

    try {
      const res = await lookupPairingByCode(pairingCodeInput);
      setFoundPairing(res.data);
    } catch (err) {
      if (err instanceof BchatApiError) {
        setLookupError(err.message || 'Kode pairing tidak ditemukan atau sudah kedaluwarsa.');
      } else {
        setLookupError('Terjadi kesalahan saat memeriksa kode pairing.');
      }
    } finally {
      setIsLookingUpPairing(false);
    }
  };

  // Setujui pairing dengan tanda tangan kriptografis Ed25519
  const handleConfirmPairingApproval = async () => {
    if (!foundPairing || !currentDeviceUuid || !user?.id) return;

    setIsApprovingPairing(true);
    setLookupError(null);

    try {
      await approvePairingFlow({
        userId: Number(user.id),
        pairing: foundPairing,
        approverDeviceUuid: currentDeviceUuid,
      });

      const deviceName = foundPairing.target_device_name;
      setSuccessMsg(`Perangkat "${deviceName}" berhasil disetujui sebagai perangkat tepercaya!`);
      setIsPairingModalOpen(false);
      setFoundPairing(null);
      setPairingCodeInput('');

      // Muat ulang daftar perangkat agar status is_trusted langsung ter-update
      await loadDevices();

      if (onDevicesChanged) {
        onDevicesChanged();
      }
    } catch (err) {
      if (err instanceof BchatApiError) {
        setLookupError(err.message || 'Gagal menyetujui pairing perangkat.');
      } else {
        setLookupError('Terjadi kesalahan kriptografis saat menyetujui pairing.');
      }
    } finally {
      setIsApprovingPairing(false);
    }
  };

  // Tolak permintaan pairing
  const handleConfirmPairingRejection = async () => {
    if (!foundPairing || !currentDeviceUuid) return;

    setIsApprovingPairing(true);
    setLookupError(null);

    try {
      await rejectPairing(
        foundPairing.pairing_uuid,
        currentDeviceUuid,
        'Ditolak oleh pengguna via Manajemen Perangkat'
      );

      const deviceName = foundPairing.target_device_name;
      setSuccessMsg(`Permintaan pairing untuk "${deviceName}" telah ditolak.`);
      setIsPairingModalOpen(false);
      setFoundPairing(null);
      setPairingCodeInput('');

      await loadDevices();
    } catch (err) {
      if (err instanceof BchatApiError) {
        setLookupError(err.message || 'Gagal menolak pairing.');
      } else {
        setLookupError('Terjadi kesalahan saat menolak pairing.');
      }
    } finally {
      setIsApprovingPairing(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs animate-in fade-in duration-200"
      role="dialog"
      aria-modal="true"
      aria-labelledby="device-mgmt-modal-title"
    >
      <div className="relative w-full max-w-2xl bg-white rounded-3xl shadow-2xl border border-slate-200/90 overflow-hidden flex flex-col max-h-[90vh]">
        {/* Header Modal */}
        <div className="flex items-center justify-between px-6 py-4.5 border-b border-slate-100 bg-slate-50/70">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-2xl bg-emerald-100/80 text-emerald-700 flex items-center justify-center shadow-xs">
              <ShieldCheck className="w-5 h-5 text-emerald-600" />
            </div>
            <div>
              <h2
                id="device-mgmt-modal-title"
                className="text-base font-bold text-slate-900 leading-tight"
              >
                Manajemen Perangkat B-CHAT
              </h2>
              <p className="text-xs text-slate-500 mt-0.5">
                Kelola perangkat yang memiliki akses End-to-End Encryption (E2EE)
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            disabled={isRevoking}
            className="p-1.5 text-slate-400 hover:text-slate-600 rounded-xl hover:bg-slate-200/60 transition cursor-pointer disabled:opacity-50"
            title="Tutup Modal"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Notifikasi Pesan Sukses / Error */}
        {successMsg && (
          <div className="mx-6 mt-4 p-3 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center gap-2.5 text-xs text-emerald-800 animate-in fade-in">
            <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
            <span className="flex-1 font-medium">{successMsg}</span>
            <button
              onClick={() => setSuccessMsg(null)}
              className="text-emerald-600 hover:text-emerald-800"
            >
              <X className="w-3.5 h-3.5" />
            </button>
          </div>
        )}

        {errorMsg && (
          <div className="mx-6 mt-4 p-3 bg-rose-50 border border-rose-200 rounded-2xl flex items-center gap-2.5 text-xs text-rose-800 animate-in fade-in">
            <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
            <span className="flex-1 font-medium">{errorMsg}</span>
            <button
              onClick={() => setErrorMsg(null)}
              className="text-rose-600 hover:text-rose-800"
            >
              <X className="w-3.5 h-3.5" />
            </button>
          </div>
        )}

        {/* Konten Utama */}
        <div className="flex-1 overflow-y-auto p-6 space-y-6">
          {isLoading ? (
            <div className="flex flex-col items-center justify-center py-12 text-slate-500 space-y-3">
              <Loader2 className="w-8 h-8 animate-spin text-emerald-600" />
              <p className="text-xs font-medium">Memuat status perangkat...</p>
            </div>
          ) : (
            <>
              {/* Seksi Perangkat Aktif */}
              <div>
                <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
                  <div className="flex items-center gap-2">
                    <h3 className="text-xs font-bold uppercase tracking-wider text-slate-500">
                      Perangkat Aktif
                    </h3>
                    <span className="inline-flex items-center justify-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                      {activeDevices.length}
                    </span>
                  </div>
                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => {
                        setIsPairingModalOpen(true);
                        setPairingCodeInput('');
                        setLookupError(null);
                        setFoundPairing(null);
                      }}
                      className="inline-flex items-center gap-1.5 px-3 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 rounded-xl text-xs font-bold transition cursor-pointer shadow-xs"
                      title="Tautkan perangkat baru dengan kode pairing"
                    >
                      <KeyRound className="w-3.5 h-3.5 text-emerald-600" />
                      <span>Tautkan Perangkat Baru</span>
                    </button>
                    <button
                      onClick={loadDevices}
                      disabled={isLoading}
                      className="inline-flex items-center gap-1 text-[11px] font-medium text-slate-500 hover:text-emerald-700 transition"
                      title="Segarkan data"
                    >
                      <RefreshCw className={`w-3.5 h-3.5 ${isLoading ? 'animate-spin' : ''}`} />
                      <span>Segarkan</span>
                    </button>
                  </div>
                </div>

                {activeDevices.length === 0 ? (
                  <div className="p-6 text-center border-2 border-dashed border-slate-200 rounded-2xl text-slate-400 text-xs">
                    Tidak ada perangkat aktif yang ditemukan.
                  </div>
                ) : (
                  <div className="space-y-3">
                    {activeDevices.map((device) => {
                      const isCurrent = device.device_uuid === currentDeviceUuid;
                      const IconComponent = getDeviceIcon(device.device_name);

                      return (
                        <div
                          key={device.device_uuid}
                          className={`p-4 rounded-2xl border transition flex items-center justify-between gap-4 ${
                            isCurrent
                              ? 'bg-emerald-50/50 border-emerald-200 shadow-xs ring-1 ring-emerald-500/10'
                              : 'bg-white border-slate-200 hover:border-slate-300 shadow-xs'
                          }`}
                        >
                          <div className="flex items-center gap-3.5 min-w-0">
                            <div
                              className={`w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 ${
                                isCurrent
                                  ? 'bg-emerald-600 text-white shadow-sm'
                                  : 'bg-slate-100 text-slate-600'
                              }`}
                            >
                              <IconComponent className="w-5 h-5" />
                            </div>

                            <div className="min-w-0">
                              <div className="flex items-center gap-2 flex-wrap">
                                <span className="font-bold text-sm text-slate-900 truncate">
                                  {device.device_name}
                                </span>
                                {isCurrent && (
                                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300/60">
                                    <CheckCircle2 className="w-3 h-3 text-emerald-700" />
                                    Perangkat Ini
                                  </span>
                                )}
                                {device.is_trusted ? (
                                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <ShieldCheck className="w-3 h-3 text-emerald-600" />
                                    Tepercaya
                                  </span>
                                ) : (
                                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                    <Clock className="w-3 h-3 text-amber-600" />
                                    Menunggu Pairing
                                  </span>
                                )}
                              </div>

                              <div className="flex items-center gap-3 text-[11px] text-slate-500 mt-1 flex-wrap">
                                <span>
                                  Terdaftar:{' '}
                                  <strong className="font-semibold text-slate-700">
                                    {formatIndonesianDate(device.registered_at)}
                                  </strong>
                                </span>
                                <span>•</span>
                                <span className="font-mono text-[10px] text-slate-400">
                                  ID: {device.device_uuid.substring(0, 8)}...
                                </span>
                              </div>
                            </div>
                          </div>

                          {/* Tombol Aksi */}
                          <div className="shrink-0">
                            {isCurrent ? (
                              <span
                                className="inline-block text-[11px] text-slate-400 italic px-2 py-1"
                                title="Perangkat yang sedang digunakan tidak dapat dicabut sendiri demi keamanan sesi."
                              >
                                Sesi Saat Ini
                              </span>
                            ) : (
                              <div className="flex items-center gap-2">
                                {device.is_trusted && onRequestHistoryTransfer && (
                                  <button
                                    onClick={() => onRequestHistoryTransfer(device)}
                                    className="inline-flex items-center gap-1 px-2.5 py-1.5 bg-teal-50 hover:bg-teal-100 text-teal-700 border border-teal-200 rounded-xl text-xs font-semibold transition cursor-pointer shadow-xs"
                                    title="Minta transfer riwayat obrolan dari perangkat ini"
                                  >
                                    <History className="w-3.5 h-3.5 text-teal-600" />
                                    <span className="hidden sm:inline">Minta Riwayat</span>
                                  </button>
                                )}
                                <button
                                  onClick={() => handleInitiateRevoke(device)}
                                  className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-rose-50 text-rose-600 hover:text-rose-700 border border-rose-200 hover:border-rose-300 rounded-xl text-xs font-semibold transition cursor-pointer shadow-xs"
                                  title="Cabut akses perangkat ini"
                                >
                                  <Trash2 className="w-3.5 h-3.5" />
                                  <span>Cabut Akses</span>
                                </button>
                              </div>
                            )}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>

              {/* Seksi Riwayat Perangkat Dicabut (Collapsible) */}
              {revokedDevices.length > 0 && (
                <div className="pt-2 border-t border-slate-100">
                  <button
                    onClick={() => setShowRevokedHistory(!showRevokedHistory)}
                    className="w-full flex items-center justify-between py-2 text-xs font-bold text-slate-500 hover:text-slate-800 transition"
                  >
                    <div className="flex items-center gap-2">
                      <History className="w-4 h-4 text-slate-400" />
                      <span>Riwayat Perangkat Dicabut ({revokedDevices.length})</span>
                    </div>
                    {showRevokedHistory ? (
                      <ChevronUp className="w-4 h-4 text-slate-400" />
                    ) : (
                      <ChevronDown className="w-4 h-4 text-slate-400" />
                    )}
                  </button>

                  {showRevokedHistory && (
                    <div className="mt-3 space-y-2.5">
                      {revokedDevices.map((device) => {
                        const IconComponent = getDeviceIcon(device.device_name);
                        return (
                          <div
                            key={device.device_uuid}
                            className="p-3 bg-slate-50 border border-slate-200/80 rounded-2xl flex items-center justify-between text-xs opacity-75"
                          >
                            <div className="flex items-center gap-3">
                              <div className="w-8 h-8 rounded-xl bg-slate-200 text-slate-500 flex items-center justify-center shrink-0">
                                <IconComponent className="w-4 h-4" />
                              </div>
                              <div>
                                <div className="flex items-center gap-2">
                                  <span className="font-semibold text-slate-700 line-through">
                                    {device.device_name}
                                  </span>
                                  <span className="px-1.5 py-0.2 rounded text-[9px] font-bold bg-slate-200 text-slate-600">
                                    DICABUT
                                  </span>
                                </div>
                                <p className="text-[10px] text-slate-400 mt-0.5">
                                  Dicabut pada: {formatIndonesianDate(device.revoked_at)}
                                  {device.revocation_reason && ` (${device.revocation_reason})`}
                                </p>
                              </div>
                            </div>
                            <span className="font-mono text-[9px] text-slate-400">
                              {device.device_uuid.substring(0, 8)}...
                            </span>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              )}

              {/* Informasi Kebijakan Multi-Device */}
              <div className="p-3.5 bg-slate-50 rounded-2xl border border-slate-200/80 flex items-start gap-2.5 text-[11px] text-slate-500">
                <Info className="w-4 h-4 text-slate-400 shrink-0 mt-0.5" />
                <p>
                  Semua perangkat aktif Anda (laptop, ponsel, tablet) memiliki kedudukan setara.
                  Mencabut satu perangkat tidak akan memengaruhi sesi enkripsi pada perangkat lainnya.
                </p>
              </div>
            </>
          )}
        </div>

        {/* Footer Modal */}
        <div className="px-6 py-4 border-t border-slate-100 bg-slate-50/70 flex justify-end">
          <button
            onClick={onClose}
            disabled={isRevoking}
            className="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-xl text-xs font-bold transition cursor-pointer"
          >
            Tutup
          </button>
        </div>

        {/* DIALOG KONFIRMASI PENCABUTAN DENGAN STEP-UP PASSWORD */}
        {targetDevice && (
          <div
            className="absolute inset-0 z-60 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in"
            role="alertdialog"
            aria-labelledby="confirm-revoke-title"
          >
            <div className="w-full max-w-md bg-white rounded-3xl shadow-2xl border border-rose-100 p-6 space-y-4 animate-in zoom-in-95 duration-150">
              <div className="flex items-center gap-3 text-rose-600">
                <div className="w-10 h-10 rounded-2xl bg-rose-100 flex items-center justify-center shrink-0">
                  <ShieldAlert className="w-5 h-5 text-rose-600" />
                </div>
                <div>
                  <h4
                    id="confirm-revoke-title"
                    className="font-bold text-sm text-slate-900 leading-tight"
                  >
                    Konfirmasi Pencabutan Akses
                  </h4>
                  <p className="text-xs text-slate-500 mt-0.5">
                    Tindakan ini memerlukan verifikasi kata sandi akun
                  </p>
                </div>
              </div>

              <div className="p-3.5 bg-rose-50/60 border border-rose-200/80 rounded-2xl text-xs text-rose-800 space-y-1">
                <p>
                  Apakah Anda yakin ingin mencabut akses untuk{' '}
                  <strong className="font-bold">{targetDevice.device_name}</strong>?
                </p>
                <p className="text-[11px] text-rose-700/90">
                  Perangkat ini tidak akan dapat lagi mengirim atau mengunduh pesan dan lampiran B-CHAT.
                </p>
              </div>

              {revokeError && (
                <div className="p-3 bg-rose-50 border border-rose-200 rounded-xl flex items-center gap-2 text-xs text-rose-700">
                  <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
                  <span>{revokeError}</span>
                </div>
              )}

              <form onSubmit={handleConfirmRevoke} className="space-y-3.5">
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1.5">
                    Kata Sandi Akun <span className="text-rose-500">*</span>
                  </label>
                  <div className="relative">
                    <input
                      type={showPassword ? 'text' : 'password'}
                      value={accountPassword}
                      onChange={(e) => setAccountPassword(e.target.value)}
                      placeholder="Masukkan kata sandi Anda..."
                      required
                      autoFocus
                      disabled={isRevoking}
                      className="w-full px-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-hidden focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 pr-10"
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword(!showPassword)}
                      className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                    >
                      {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                    </button>
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-600 mb-1">
                    Alasan Pencabutan (Opsional)
                  </label>
                  <input
                    type="text"
                    value={revocationReason}
                    onChange={(e) => setRevocationReason(e.target.value)}
                    placeholder="Contoh: Perangkat hilang, ganti laptop baru"
                    disabled={isRevoking}
                    className="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-hidden focus:ring-2 focus:ring-slate-400/20 focus:border-slate-400"
                  />
                </div>

                <div className="flex items-center justify-end gap-2 pt-2">
                  <button
                    type="button"
                    onClick={handleCancelRevoke}
                    disabled={isRevoking}
                    className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    disabled={isRevoking || !accountPassword.trim()}
                    className="inline-flex items-center gap-1.5 px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-xl transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed shadow-xs"
                  >
                    {isRevoking ? (
                      <>
                        <Loader2 className="w-3.5 h-3.5 animate-spin" />
                        <span>Mencabut...</span>
                      </>
                    ) : (
                      <>
                        <Trash2 className="w-3.5 h-3.5" />
                        <span>Cabut Akses</span>
                      </>
                    )}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* DIALOG PENAUTAN (PAIRING) PERANGKAT BARU (TAHAP 2A) */}
        {isPairingModalOpen && (
          <div
            className="absolute inset-0 z-60 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in"
            role="dialog"
            aria-labelledby="pairing-modal-title"
          >
            <div className="w-full max-w-lg bg-white rounded-3xl shadow-2xl border border-emerald-100 p-6 space-y-4 animate-in zoom-in-95 duration-150">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-2xl bg-emerald-100 flex items-center justify-center shrink-0">
                    <KeyRound className="w-5 h-5 text-emerald-600" />
                  </div>
                  <div>
                    <h4
                      id="pairing-modal-title"
                      className="font-bold text-sm text-slate-900 leading-tight"
                    >
                      Tautkan Perangkat Baru
                    </h4>
                    <p className="text-xs text-slate-500 mt-0.5">
                      Verifikasi dan berikan status tepercaya pada perangkat baru Anda
                    </p>
                  </div>
                </div>
                <button
                  onClick={() => setIsPairingModalOpen(false)}
                  disabled={isApprovingPairing}
                  className="p-1.5 text-slate-400 hover:text-slate-600 rounded-xl"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              {lookupError && (
                <div className="p-3 bg-rose-50 border border-rose-200 rounded-xl flex items-center gap-2 text-xs text-rose-700">
                  <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
                  <span>{lookupError}</span>
                </div>
              )}

              {!foundPairing ? (
                /* Tahap 1: Masukkan Kode Pairing */
                <form onSubmit={handleLookupPairing} className="space-y-4">
                  <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-2xl text-xs text-slate-600 space-y-1">
                    <p>
                      Masukkan kode pairing 8 karakter yang tertera pada layar perangkat baru Anda (format: <strong>XXXX-XXXX</strong>).
                    </p>
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1.5">
                      Kode Pairing <span className="text-rose-500">*</span>
                    </label>
                    <input
                      type="text"
                      value={pairingCodeInput}
                      onChange={(e) => setPairingCodeInput(e.target.value.toUpperCase())}
                      placeholder="Contoh: PAIR-ABCD"
                      required
                      autoFocus
                      disabled={isLookingUpPairing}
                      className="w-full px-3.5 py-2.5 text-sm font-mono tracking-widest text-center bg-slate-50 border border-slate-200 rounded-xl focus:outline-hidden focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 uppercase"
                    />
                  </div>

                  <div className="flex items-center justify-end gap-2 pt-2">
                    <button
                      type="button"
                      onClick={() => setIsPairingModalOpen(false)}
                      disabled={isLookingUpPairing}
                      className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer"
                    >
                      Batal
                    </button>
                    <button
                      type="submit"
                      disabled={isLookingUpPairing || !pairingCodeInput.trim()}
                      className="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl transition cursor-pointer disabled:opacity-50 shadow-xs"
                    >
                      {isLookingUpPairing ? (
                        <>
                          <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          <span>Memeriksa...</span>
                        </>
                      ) : (
                        <>
                          <span>Periksa Kode</span>
                        </>
                      )}
                    </button>
                  </div>
                </form>
              ) : (
                /* Tahap 2: Tinjau Detail Target & Berikan Persetujuan */
                <div className="space-y-4">
                  <div className="p-4 bg-emerald-50/60 border border-emerald-200 rounded-2xl space-y-3">
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-bold text-emerald-900">
                        {foundPairing.target_device_name}
                      </span>
                      <span className="px-2 py-0.5 text-[10px] font-bold bg-emerald-200 text-emerald-900 rounded-full font-mono">
                        {foundPairing.pairing_code}
                      </span>
                    </div>

                    <div className="text-[11px] text-slate-600 space-y-1">
                      <p>
                        <strong>Device UUID:</strong>{' '}
                        <span className="font-mono">{foundPairing.target_device_uuid}</span>
                      </p>
                      <div>
                        <strong>Fingerprint Kunci Publik (SHA-256):</strong>
                        <div className="font-mono text-[10px] bg-white p-2 rounded-lg border border-emerald-200/80 break-all select-all mt-1">
                          {foundPairing.target_public_key_fingerprint}
                        </div>
                      </div>
                    </div>

                    <div className="flex items-start gap-2 text-[10px] text-amber-700 bg-amber-50 p-2 rounded-xl border border-amber-200">
                      <Info className="w-3.5 h-3.5 shrink-0 mt-0.5" />
                      <span>
                        Pastikan fingerprint kunci di atas persis sama dengan yang ditampilkan pada layar perangkat baru sebelum menyetujui.
                      </span>
                    </div>
                  </div>

                  <div className="flex items-center justify-between pt-2">
                    <button
                      type="button"
                      onClick={handleConfirmPairingRejection}
                      disabled={isApprovingPairing}
                      className="px-3 py-2 bg-white hover:bg-rose-50 text-rose-600 hover:text-rose-700 border border-rose-200 rounded-xl text-xs font-bold transition cursor-pointer disabled:opacity-50"
                    >
                      Tolak
                    </button>

                    <div className="flex items-center gap-2">
                      <button
                        type="button"
                        onClick={() => setFoundPairing(null)}
                        disabled={isApprovingPairing}
                        className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer"
                      >
                        Kembali
                      </button>

                      <button
                        type="button"
                        onClick={handleConfirmPairingApproval}
                        disabled={isApprovingPairing}
                        className="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl transition cursor-pointer shadow-xs disabled:opacity-50"
                      >
                        {isApprovingPairing ? (
                          <>
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                            <span>Menandatangani...</span>
                          </>
                        ) : (
                          <>
                            <ShieldCheck className="w-3.5 h-3.5" />
                            <span>Setujui Perangkat</span>
                          </>
                        )}
                      </button>
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

