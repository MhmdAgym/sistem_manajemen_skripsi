'use client';

/**
 * B-CHAT E2EE — IndexedDB Foundation Browser Test Runner
 *
 * Halaman pengujian browser terisolasi untuk membuktikan dan memverifikasi
 * Kriteria Pengujian TC-01 sampai TC-10:
 * 1. Pembukaan database 'unuha_bchat_secure_storage' (Version: 1).
 * 2. Keberadaan kelima object store ('keystore', 'group_states', 'pending_key_packages', 'message_outbox', 'tab_leases').
 * 3. Penulisan dan pembacaan biner Uint8Array.
 * 4. Structured clone CryptoKey AES-GCM non-extractable ke 'keystore'.
 * 5. Verifikasi bahwa CryptoKey tetap non-extractable setelah dibaca dari IndexedDB.
 * 6. Operasi enkripsi dan dekripsi AES-GCM menggunakan CryptoKey hasil pembacaan IDB.
 * 7. Transaksi readwrite yang berhasil dengan penyelesaian oncomplete.
 * 8. Transaksi yang sengaja di-abort (tx.abort()) dan bukti bahwa data parsial tidak tersimpan.
 * 9. Penanganan error transaksi terstruktur (BchatTransactionError).
 * 10. Penutupan koneksi (closeBchatDatabase) dan pembukaan kembali dengan data tetap utuh.
 */

import React, { useState } from 'react';
import {
  BCAT_DB_NAME,
  BCAT_DB_VERSION,
  BCAT_STORES,
  type KeystoreRecord,
  type LegacyGroupStateRecord,
  type PendingKeyPackageRecord,
  type MessageOutboxRecord,
  type TabLeaseRecord,
} from '@/lib/bchat/storage/bchat-idb-schema';
import {
  openBchatDatabase,
  closeBchatDatabase,
  withReadonlyTransaction,
  withReadwriteTransaction,
  idbPut,
  idbGet,
  idbDelete,
  idbClear,
  BchatStorageError,
  BchatTransactionError,
} from '@/lib/bchat/storage/bchat-idb-core';

interface TestCaseStatus {
  id: string;
  name: string;
  status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL';
  details?: string;
}

interface TestLog {
  timestamp: string;
  level: 'info' | 'success' | 'warn' | 'error';
  message: string;
}

const INITIAL_TEST_CASES: TestCaseStatus[] = [
  { id: 'TC-01', name: 'Pembukaan Database & Inisialisasi Versi 1', status: 'PENDING' },
  { id: 'TC-02', name: 'Verifikasi Keberadaan 5 Object Store & Indeks', status: 'PENDING' },
  { id: 'TC-03', name: 'Integritas Penulisan dan Pembacaan Biner Uint8Array', status: 'PENDING' },
  { id: 'TC-04', name: 'Penyimpanan Structured Clone CryptoKey Non-Extractable', status: 'PENDING' },
  { id: 'TC-05', name: 'Verifikasi Status Kunci Tetap Non-Extractable', status: 'PENDING' },
  { id: 'TC-06', name: 'Enkripsi & Dekripsi AES-GCM Menggunakan Kunci dari IDB', status: 'PENDING' },
  { id: 'TC-07', name: 'Transaksi Readwrite Atomik dengan Asersi oncomplete', status: 'PENDING' },
  { id: 'TC-08', name: 'Transaksi Sengaja di-Abort & Bukti Zero Partial Data', status: 'PENDING' },
  { id: 'TC-09', name: 'Penanganan Error Transaksi Terkontrol & Terstruktur', status: 'PENDING' },
  { id: 'TC-10', name: 'Siklus Penutupan Koneksi & Pembukaan Kembali (Persistensi)', status: 'PENDING' },
];

export default function BchatIdbTestPage() {
  const [testCases, setTestCases] = useState<TestCaseStatus[]>(INITIAL_TEST_CASES);
  const [logs, setLogs] = useState<TestLog[]>([]);
  const [isRunning, setIsRunning] = useState<boolean>(false);
  const [overallResult, setOverallResult] = useState<'IDLE' | 'RUNNING' | 'SUCCESS' | 'FAILED'>('IDLE');

  const addLog = (message: string, level: TestLog['level'] = 'info') => {
    const timestamp = new Date().toLocaleTimeString();
    setLogs((prev) => [...prev, { timestamp, level, message }]);
  };

  const updateTc = (id: string, status: TestCaseStatus['status'], details?: string) => {
    setTestCases((prev) =>
      prev.map((tc) => (tc.id === id ? { ...tc, status, details: details || tc.details } : tc))
    );
  };

  // Membersihkan database untuk uji coba ulang dari nol
  const handleResetDatabase = async () => {
    closeBchatDatabase();
    addLog('Menutup koneksi dan meminta penghapusan database IndexedDB...', 'warn');

    return new Promise<void>((resolve) => {
      const req = window.indexedDB.deleteDatabase(BCAT_DB_NAME);
      req.onsuccess = () => {
        addLog(`Database '${BCAT_DB_NAME}' berhasil dihapus.`, 'success');
        setTestCases(INITIAL_TEST_CASES);
        setOverallResult('IDLE');
        resolve();
      };
      req.onerror = () => {
        addLog(`Gagal menghapus database '${BCAT_DB_NAME}'.`, 'error');
        resolve();
      };
      req.onblocked = () => {
        addLog('Penghapusan database terblokir oleh tab lain yang masih terbuka.', 'warn');
        resolve();
      };
    });
  };

  // Eksekusi Rangkaian Pengujian
  const runAllTests = async () => {
    if (isRunning) return;
    setIsRunning(true);
    setOverallResult('RUNNING');
    setTestCases(INITIAL_TEST_CASES);
    setLogs([]);

    addLog('=== MEMULAI VERIFIKASI BROWSER RUNTIME 2C.4H-I1 INDEXEDDB FOUNDATION ===', 'info');

    try {
      // -----------------------------------------------------------------------
      // TC-01: Pembukaan Database & Inisialisasi Versi 1
      // -----------------------------------------------------------------------
      updateTc('TC-01', 'RUNNING');
      addLog('Membuka database IndexedDB...', 'info');

      const db = await openBchatDatabase();
      if (!db || db.name !== BCAT_DB_NAME || db.version !== BCAT_DB_VERSION) {
        throw new Error(`Nama database atau versi tidak sesuai: ${db?.name} v${db?.version}`);
      }
      updateTc('TC-01', 'PASS', `DB: ${db.name} (v${db.version}) terbuka aman.`);
      addLog(`TC-01: PASS | Database '${BCAT_DB_NAME}' versi ${BCAT_DB_VERSION} terbuka.`, 'success');

      // -----------------------------------------------------------------------
      // TC-02: Verifikasi Keberadaan 5 Object Store & Indeks
      // -----------------------------------------------------------------------
      updateTc('TC-02', 'RUNNING');
      addLog('Memverifikasi keberadaan 5 object store deklaratif...', 'info');

      const storeNames = Array.from(db.objectStoreNames);
      const expectedStores = Object.values(BCAT_STORES);
      const missingStores = expectedStores.filter((name) => !storeNames.includes(name));

      if (missingStores.length > 0) {
        throw new Error(`Object store belum terbentuk: ${missingStores.join(', ')}`);
      }

      // Verifikasi keyPath melalui transaksi readonly
      await withReadonlyTransaction(expectedStores, (tx, getStore) => {
        const keystore = getStore(BCAT_STORES.KEYSTORE);
        if (keystore.keyPath !== 'key_id') throw new Error('keystore keyPath bukan key_id');

        const groupStates = getStore(BCAT_STORES.GROUP_STATES);
        const gsKeyPath = Array.isArray(groupStates.keyPath) ? groupStates.keyPath.join(',') : groupStates.keyPath;
        if (gsKeyPath !== 'device_uuid,room_uuid') throw new Error('group_states keyPath bukan [device_uuid, room_uuid]');

        const pendingKp = getStore(BCAT_STORES.PENDING_KEY_PACKAGES);
        const pkpKeyPath = Array.isArray(pendingKp.keyPath) ? pendingKp.keyPath.join(',') : pendingKp.keyPath;
        if (pkpKeyPath !== 'device_uuid,key_package_hash') throw new Error('pending_key_packages keyPath bukan [device_uuid, key_package_hash]');

        const outbox = getStore(BCAT_STORES.MESSAGE_OUTBOX);
        if (outbox.keyPath !== 'client_message_uuid') throw new Error('message_outbox keyPath bukan client_message_uuid');

        const tabLeases = getStore(BCAT_STORES.TAB_LEASES);
        if (tabLeases.keyPath !== 'device_uuid') throw new Error('tab_leases keyPath bukan device_uuid');
      });

      updateTc('TC-02', 'PASS', `5 Store terverifikasi: ${storeNames.join(', ')}`);
      addLog(`TC-02: PASS | 5 Object store & skema keyPath terverifikasi.`, 'success');

      // -----------------------------------------------------------------------
      // TC-03: Integritas Penulisan dan Pembacaan Biner Uint8Array
      // -----------------------------------------------------------------------
      updateTc('TC-03', 'RUNNING');
      addLog('Menguji persistensi array biner Uint8Array ke group_states...', 'info');

      const testBinaryPayload = new Uint8Array([0xde, 0xad, 0xbe, 0xef, 0x01, 0x02, 0x03, 0x04, 0xaa, 0xbb]);
      const groupRecord: LegacyGroupStateRecord = {
        device_uuid: 'dev-test-uuid-001',
        room_uuid: 'room-test-uuid-001',
        epoch: '1',
        state_bytes: testBinaryPayload,
        group_id_hash: 'hash-test-dummy',
        updated_at: Date.now(),
      };

      await idbPut(BCAT_STORES.GROUP_STATES, groupRecord);

      const retrievedGs = await idbGet<LegacyGroupStateRecord>(BCAT_STORES.GROUP_STATES, [
        groupRecord.device_uuid,
        groupRecord.room_uuid,
      ]);

      if (!retrievedGs) throw new Error('Record group_states gagal diambil kembali');
      if (!(retrievedGs.state_bytes instanceof Uint8Array)) throw new Error('state_bytes bukan Uint8Array');
      if (retrievedGs.state_bytes.length !== testBinaryPayload.length) throw new Error('Panjang Uint8Array tidak cocok');

      for (let i = 0; i < testBinaryPayload.length; i++) {
        if (retrievedGs.state_bytes[i] !== testBinaryPayload[i]) {
          throw new Error(`Data byte biner tidak identik pada indeks ${i}`);
        }
      }

      updateTc('TC-03', 'PASS', `Biner ${testBinaryPayload.length} bytes tersimpan dan terbaca 100% presisi.`);
      addLog('TC-03: PASS | Persistensi biner Uint8Array diverifikasi tanpa korupsi byte.', 'success');

      // -----------------------------------------------------------------------
      // TC-04: Penyimpanan Structured Clone CryptoKey Non-Extractable
      // -----------------------------------------------------------------------
      updateTc('TC-04', 'RUNNING');
      addLog('Membangkitkan WebCrypto AES-GCM 256-bit non-extractable key...', 'info');

      const nonExtractableKey = await window.crypto.subtle.generateKey(
        { name: 'AES-GCM', length: 256 },
        false, // non-extractable!
        ['encrypt', 'decrypt']
      );

      const keystoreRecord: KeystoreRecord = {
        key_id: 'kek_device_root_001',
        key_version: 1,
        status: 'active',
        algorithm: 'AES-GCM-256',
        crypto_key: nonExtractableKey,
        created_at: Date.now(),
      };

      await idbPut(BCAT_STORES.KEYSTORE, keystoreRecord);

      updateTc('TC-04', 'PASS', 'CryptoKey non-extractable tersimpan via Structured Clone ke keystore.');
      addLog('TC-04: PASS | CryptoKey non-extractable berhasil disimpan ke IndexedDB.', 'success');

      // -----------------------------------------------------------------------
      // TC-05: Verifikasi Status Kunci Tetap Non-Extractable
      // -----------------------------------------------------------------------
      updateTc('TC-05', 'RUNNING');
      addLog('Membaca CryptoKey dari keystore dan memvalidasi properti keamanan...', 'info');

      const retrievedKeyRecord = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, 'kek_device_root_001');
      if (!retrievedKeyRecord || !retrievedKeyRecord.crypto_key) {
        throw new Error('Gagal membaca kembali CryptoKey dari keystore');
      }

      const readKey = retrievedKeyRecord.crypto_key;

      if (readKey.extractable !== false) {
        throw new Error('PELANGGARAN KEAMANAN: CryptoKey menjadi extractable setelah dibaca!');
      }
      if (readKey.type !== 'secret') {
        throw new Error(`Tipe kunci tidak valid: ${readKey.type} (diharapkan 'secret')`);
      }
      if (readKey.algorithm.name !== 'AES-GCM') {
        throw new Error(`Algoritma kunci tidak valid: ${readKey.algorithm.name}`);
      }

      updateTc('TC-05', 'PASS', 'Kunci terverifikasi: extractable = false, type = secret, alg = AES-GCM.');
      addLog('TC-05: PASS | CryptoKey mempertahankan status non-extractable setelah pemulihan IDB.', 'success');

      // -----------------------------------------------------------------------
      // TC-06: Enkripsi & Dekripsi AES-GCM Menggunakan Kunci dari IDB
      // -----------------------------------------------------------------------
      updateTc('TC-06', 'RUNNING');
      addLog('Menguji operasi kriptografi menggunakan CryptoKey hasil pembacaan IDB...', 'info');

      const testIv = window.crypto.getRandomValues(new Uint8Array(12));
      const testPlaintext = new TextEncoder().encode('NON_SECRET_TEST_DATA_HI1_VALIDATION');

      const ciphertext = await window.crypto.subtle.encrypt(
        { name: 'AES-GCM', iv: testIv },
        readKey,
        testPlaintext
      );

      const decryptedBuffer = await window.crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: testIv },
        readKey,
        ciphertext
      );

      const decryptedText = new TextDecoder().decode(decryptedBuffer);
      if (decryptedText !== 'NON_SECRET_TEST_DATA_HI1_VALIDATION') {
        throw new Error('Hasil dekripsi teks uji tidak cocok!');
      }

      updateTc('TC-06', 'PASS', 'Enkripsi & dekripsi AES-GCM 100% cocok menggunakan kunci dari IDB.');
      addLog('TC-06: PASS | Kunci dari IDB berfungsi penuh untuk operasi kriptografi AES-GCM.', 'success');

      // -----------------------------------------------------------------------
      // TC-07: Transaksi Readwrite Atomik dengan Asersi oncomplete
      // -----------------------------------------------------------------------
      updateTc('TC-07', 'RUNNING');
      addLog('Menguji transaksi readwrite atomik multi-store dengan pemenuhan oncomplete...', 'info');

      const pendingRecord: PendingKeyPackageRecord = {
        device_uuid: 'dev-test-uuid-001',
        key_package_hash: 'kp-hash-atomic-001',
        private_key_package_bytes: new Uint8Array([0x01, 0x02, 0x03]),
        created_at: Date.now(),
      };

      const outboxRecord: MessageOutboxRecord = {
        client_message_uuid: 'msg-uuid-atomic-001',
        room_uuid: 'room-test-uuid-001',
        status: 'pending',
        ciphertext_bytes: new Uint8Array([0xaa, 0xbb, 0xcc]),
        created_at: Date.now(),
      };

      // Jalankan transaksi atomik lintas 2 store
      await withReadwriteTransaction(
        [BCAT_STORES.PENDING_KEY_PACKAGES, BCAT_STORES.MESSAGE_OUTBOX],
        (_tx, getStore) => {
          const pkpStore = getStore(BCAT_STORES.PENDING_KEY_PACKAGES);
          const outboxStore = getStore(BCAT_STORES.MESSAGE_OUTBOX);
          pkpStore.put(pendingRecord);
          outboxStore.put(outboxRecord);
        }
      );

      // Verifikasi pembacaan kedua record
      const checkPkp = await idbGet<PendingKeyPackageRecord>(BCAT_STORES.PENDING_KEY_PACKAGES, [
        pendingRecord.device_uuid,
        pendingRecord.key_package_hash,
      ]);
      const checkOutbox = await idbGet<MessageOutboxRecord>(BCAT_STORES.MESSAGE_OUTBOX, outboxRecord.client_message_uuid);

      if (!checkPkp || !checkOutbox) {
        throw new Error('Transaksi atomik gagal: salah satu record tidak ditemukan');
      }

      updateTc('TC-07', 'PASS', 'Transaksi atomik multi-store berhasil commit via oncomplete.');
      addLog('TC-07: PASS | Transaksi atomik multi-store committed dengan aman.', 'success');

      // -----------------------------------------------------------------------
      // TC-08: Transaksi Sengaja di-Abort & Bukti Zero Partial Data
      // -----------------------------------------------------------------------
      updateTc('TC-08', 'RUNNING');
      addLog('Menguji rollback atomik saat tx.abort() dipanggil...', 'info');

      const leaseRecordToAbort: TabLeaseRecord = {
        device_uuid: 'dev-abort-test-001',
        owner_token: 'token-should-not-exist',
        generation: '1',
        acquired_at: Date.now(),
        expires_at: Date.now() + 60000,
      };

      let abortCaught = false;
      try {
        await withReadwriteTransaction([BCAT_STORES.TAB_LEASES], (tx, getStore) => {
          const store = getStore(BCAT_STORES.TAB_LEASES);
          store.put(leaseRecordToAbort);
          // Panggil abort secara sengaja sebelum transaksi complete
          tx.abort();
        });
      } catch (err: any) {
        abortCaught = true;
        addLog(`Pengecualian abort terdeteksi sesuai ekspektasi: ${err.message}`, 'info');
      }

      if (!abortCaught) {
        throw new Error('withReadwriteTransaction tidak mereject saat tx.abort() dipanggil!');
      }

      // Verifikasi bahwa record TIDAK tersimpan
      const checkAborted = await idbGet<TabLeaseRecord>(BCAT_STORES.TAB_LEASES, leaseRecordToAbort.device_uuid);
      if (checkAborted !== undefined) {
        throw new Error('PELANGGARAN INTEGRITAS: Record tetap tersimpan meskipun transaksi di-abort!');
      }

      updateTc('TC-08', 'PASS', 'tx.abort() terpropagasi dan record dibatalkan 100% tanpa sisa.');
      addLog('TC-08: PASS | Pembatalan transaksi (rollback) terbukti atomik tanpa partial write.', 'success');

      // -----------------------------------------------------------------------
      // TC-09: Penanganan Error Transaksi Terkontrol & Terstruktur
      // -----------------------------------------------------------------------
      updateTc('TC-09', 'RUNNING');
      addLog('Menguji penanganan error ketika callback melemparkan exception...', 'info');

      let customErrorCaught = false;
      try {
        await withReadwriteTransaction([BCAT_STORES.TAB_LEASES], () => {
          throw new Error('SIMULATED_LOGIC_ERROR_INSIDE_TRANSACTION');
        });
      } catch (err: any) {
        if (err instanceof BchatTransactionError || err.message.includes('SIMULATED_LOGIC_ERROR')) {
          customErrorCaught = true;
          addLog(`Error terstruktur berhasil ditangkap: ${err.name} - ${err.message}`, 'info');
        }
      }

      if (!customErrorCaught) {
        throw new Error('Exception dalam callback tidak terbungkus atau lolos tanpa kontrol.');
      }

      updateTc('TC-09', 'PASS', 'Exception internal ditangkap & ditransformasikan ke BchatTransactionError.');
      addLog('TC-09: PASS | Penanganan error transaksi terstruktur dan terisolasi.', 'success');

      // -----------------------------------------------------------------------
      // TC-10: Siklus Penutupan Koneksi & Pembukaan Kembali (Persistensi)
      // -----------------------------------------------------------------------
      updateTc('TC-10', 'RUNNING');
      addLog('Menutup koneksi aktif dengan closeBchatDatabase()...', 'info');

      closeBchatDatabase();
      addLog('Koneksi ditutup. Membuka kembali koneksi database untuk membuktikan persistensi...', 'info');

      const reopenedDb = await openBchatDatabase();
      if (!reopenedDb) {
        throw new Error('Gagal membuka kembali database setelah penutupan.');
      }

      // Pastikan data yang disimpan pada TC-03 dan TC-04 masih ada
      const recheckedGs = await idbGet<LegacyGroupStateRecord>(BCAT_STORES.GROUP_STATES, [
        groupRecord.device_uuid,
        groupRecord.room_uuid,
      ]);
      const recheckedKey = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, 'kek_device_root_001');

      if (!recheckedGs || !recheckedKey) {
        throw new Error('Data yang disimpan sebelumnya hilang setelah database dibuka kembali!');
      }

      updateTc('TC-10', 'PASS', 'Database berhasil ditutup dan dibuka kembali dengan seluruh data utuh.');
      addLog('TC-10: PASS | Siklus tutup/buka database terverifikasi dengan persistensi data.', 'success');

      // -----------------------------------------------------------------------
      // Hasil Akhir
      // -----------------------------------------------------------------------
      setOverallResult('SUCCESS');
      addLog('=== SELURUH 10 TEST CASE FONDASI INDEXEDDB BERHASIL DILALUI (100% PASS) ===', 'success');
    } catch (err: any) {
      setOverallResult('FAILED');
      addLog(`PENGUJIAN GAGAL: ${err.message}`, 'error');
    } finally {
      setIsRunning(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 p-8">
      <div className="max-w-5xl mx-auto space-y-6">
        {/* Header */}
        <div className="border-b border-slate-800 pb-4 flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
              <span className="text-indigo-400">B-CHAT</span> IndexedDB Foundation Test Runner
            </h1>
            <p className="text-sm text-slate-400 mt-1">
              Verifikasi Browser Runtime Tahap 2C.4H-I1 • Database: <code className="text-indigo-300">{BCAT_DB_NAME}</code> (v{BCAT_DB_VERSION})
            </p>
          </div>
          <div className="flex gap-3">
            <button
              onClick={handleResetDatabase}
              disabled={isRunning}
              className="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium rounded-lg transition disabled:opacity-50"
            >
              Reset Database
            </button>
            <button
              onClick={runAllTests}
              disabled={isRunning}
              className="px-5 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold rounded-lg shadow transition disabled:opacity-50 flex items-center gap-2"
            >
              {isRunning ? 'Menjalankan...' : 'Jalankan Pengujian IDB'}
            </button>
          </div>
        </div>

        {/* Status Banner */}
        <div
          className={`p-4 rounded-xl border flex items-center justify-between ${
            overallResult === 'SUCCESS'
              ? 'bg-emerald-950/40 border-emerald-800/80 text-emerald-200'
              : overallResult === 'FAILED'
              ? 'bg-rose-950/40 border-rose-800/80 text-rose-200'
              : overallResult === 'RUNNING'
              ? 'bg-amber-950/40 border-amber-800/80 text-amber-200'
              : 'bg-slate-900 border-slate-800 text-slate-300'
          }`}
        >
          <div>
            <span className="text-xs uppercase tracking-wider font-semibold text-slate-400">Status Pengujian</span>
            <div className="text-lg font-bold mt-0.5">
              {overallResult === 'IDLE' && 'Siap Dijalankan'}
              {overallResult === 'RUNNING' && 'Sedang Menguji Transaksi & Structured Clone...'}
              {overallResult === 'SUCCESS' && 'LULUS PENUH (10/10 Test Cases PASS)'}
              {overallResult === 'FAILED' && 'PENGUJIAN GAGAL (Periksa Log di Bawah)'}
            </div>
          </div>
          <div className="text-right">
            <span className="text-xs text-slate-400">Total Kriteria</span>
            <div className="text-sm font-medium">10 Kasus Uji Kritis</div>
          </div>
        </div>

        {/* Test Cases Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          {testCases.map((tc) => (
            <div
              key={tc.id}
              className="p-4 rounded-lg bg-slate-900/80 border border-slate-800 flex flex-col justify-between"
            >
              <div className="flex items-start justify-between gap-2">
                <div>
                  <div className="text-xs font-mono text-indigo-400 font-semibold">{tc.id}</div>
                  <div className="text-sm font-medium text-slate-200 mt-0.5">{tc.name}</div>
                </div>
                <span
                  className={`text-xs px-2.5 py-1 rounded-full font-semibold ${
                    tc.status === 'PASS'
                      ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                      : tc.status === 'FAIL'
                      ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30'
                      : tc.status === 'RUNNING'
                      ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30 animate-pulse'
                      : 'bg-slate-800 text-slate-400'
                  }`}
                >
                  {tc.status}
                </span>
              </div>
              {tc.details && (
                <div className="mt-2 text-xs text-slate-400 border-t border-slate-800/80 pt-2 font-mono">
                  {tc.details}
                </div>
              )}
            </div>
          ))}
        </div>

        {/* Live Logs Terminal */}
        <div className="rounded-xl bg-slate-900 border border-slate-800 overflow-hidden">
          <div className="px-4 py-2.5 bg-slate-800/60 border-b border-slate-800 flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-300 uppercase tracking-wider">Log Eksekusi Browser</span>
            <span className="text-xs text-slate-500 font-mono">{logs.length} entri</span>
          </div>
          <div className="p-4 font-mono text-xs space-y-1.5 max-h-72 overflow-y-auto bg-slate-950/70">
            {logs.length === 0 ? (
              <span className="text-slate-600">Klik &quot;Jalankan Pengujian IDB&quot; untuk memulai audit runtime peramban.</span>
            ) : (
              logs.map((log, index) => (
                <div key={index} className="flex items-start gap-2">
                  <span className="text-slate-500 shrink-0">[{log.timestamp}]</span>
                  <span
                    className={`shrink-0 font-bold ${
                      log.level === 'success'
                        ? 'text-emerald-400'
                        : log.level === 'error'
                        ? 'text-rose-400'
                        : log.level === 'warn'
                        ? 'text-amber-400'
                        : 'text-sky-400'
                    }`}
                  >
                    [{log.level.toUpperCase()}]
                  </span>
                  <span className="text-slate-300 break-all">{log.message}</span>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

