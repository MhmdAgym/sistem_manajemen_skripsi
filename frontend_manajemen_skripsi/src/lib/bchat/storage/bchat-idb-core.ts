/**
 * B-CHAT E2EE — IndexedDB Core Foundation Engine
 *
 * Mengelola siklus hidup koneksi database 'unuha_bchat_secure_storage':
 * - Pembukaan database singleton berbasis Promise.
 * - Penanganan upgrade schema deklaratif (5 object store) pada onupgradeneeded.
 * - Penanganan event onblocked dan onversionchange untuk multi-tab safety.
 * - Helper transaksi atomik readonly dan readwrite dengan pemenuhan syarat oncomplete.
 * - Klasifikasi error terstruktur: QuotaExceededError, BlockedError, TransactionError.
 */

import {
  BCAT_DB_NAME,
  BCAT_DB_VERSION,
  BCAT_STORES,
  BCAT_STORE_CONFIGS,
  type BchatStoreName,
} from './bchat-idb-schema';

// =============================================================================
// Hierarki Kelas Error Terstruktur
// =============================================================================

export class BchatStorageError extends Error {
  public readonly code: string;
  public readonly originalError?: unknown;

  constructor(message: string, code = 'STORAGE_ERROR', originalError?: unknown) {
    super(message);
    this.name = 'BchatStorageError';
    this.code = code;
    this.originalError = originalError;
  }
}

export class BchatQuotaExceededError extends BchatStorageError {
  constructor(message = 'Penyimpanan lokal peramban melebihi batas kuota (QuotaExceededError).', originalError?: unknown) {
    super(message, 'QUOTA_EXCEEDED', originalError);
    this.name = 'BchatQuotaExceededError';
  }
}

export class BchatDatabaseBlockedError extends BchatStorageError {
  constructor(message = 'Pembukaan atau upgrade IndexedDB terblokir oleh koneksi tab lain (blocked).', originalError?: unknown) {
    super(message, 'DATABASE_BLOCKED', originalError);
    this.name = 'BchatDatabaseBlockedError';
  }
}

export class BchatTransactionError extends BchatStorageError {
  constructor(message: string, originalError?: unknown) {
    super(message, 'TRANSACTION_ERROR', originalError);
    this.name = 'BchatTransactionError';
  }
}

// =============================================================================
// State Internal Manajemen Koneksi
// =============================================================================

let activeDbInstance: IDBDatabase | null = null;
let pendingOpenPromise: Promise<IDBDatabase> | null = null;

/**
 * Mengambil IDBFactory dari context window (Main Thread) atau self (Worker Thread)
 */
function getIndexedDbFactory(): IDBFactory {
  if (typeof window !== 'undefined' && window.indexedDB) {
    return window.indexedDB;
  }
  if (typeof self !== 'undefined' && self.indexedDB) {
    return self.indexedDB;
  }
  throw new BchatStorageError(
    'IndexedDB tidak didukung pada lingkungan runtime saat ini (SSR atau non-browser context).',
    'IDB_NOT_SUPPORTED'
  );
}

/**
 * Membuka koneksi ke IndexedDB 'unuha_bchat_secure_storage' (Version: 1).
 * Menggunakan mekanisme caching koneksi agar tidak membuka ulang tanpa kebutuhan.
 */
export async function openBchatDatabase(): Promise<IDBDatabase> {
  // 1. Jika instance aktif sudah ada dan masih terbuka, gunakan langsung
  if (activeDbInstance) {
    try {
      // Periksa apakah koneksi masih hidup dengan mengecek objectStoreNames
      if (activeDbInstance.objectStoreNames.length > 0) {
        return activeDbInstance;
      }
    } catch {
      // Jika terjadi error pada instance, reset
      activeDbInstance = null;
    }
  }

  // 2. Jika proses pembukaan sedang berlangsung (concurrent caller), return promise yang sama
  if (pendingOpenPromise) {
    return pendingOpenPromise;
  }

  const factory = getIndexedDbFactory();

  pendingOpenPromise = new Promise<IDBDatabase>((resolve, reject) => {
    let isSettled = false;

    const request = factory.open(BCAT_DB_NAME, BCAT_DB_VERSION);

    // Penanganan event onupgradeneeded: Membentuk 5 Object Store
    request.onupgradeneeded = (event: IDBVersionChangeEvent) => {
      const db = request.result;
      const oldVersion = event.oldVersion;
      const newVersion = event.newVersion;

      // Iterasi seluruh konfigurasi store wajib
      for (const storeName of Object.values(BCAT_STORES)) {
        const config = BCAT_STORE_CONFIGS[storeName];

        // Buat object store hanya jika belum ada
        if (!db.objectStoreNames.contains(config.name)) {
          const store = db.createObjectStore(config.name, {
            keyPath: config.keyPath,
            autoIncrement: config.autoIncrement || false,
          });

          // Daftarkan seluruh indeks
          for (const index of config.indexes) {
            store.createIndex(index.name, index.keyPath, index.options);
          }
        }
      }
    };

    // Penanganan event onblocked: Tab lain menahan versi lama
    request.onblocked = (event: Event) => {
      const blockedErr = new BchatDatabaseBlockedError(
        'Database B-CHAT terblokir (onblocked). Tab lain sedang memegang koneksi versi sebelumnya.'
      );
      if (!isSettled) {
        isSettled = true;
        pendingOpenPromise = null;
        reject(blockedErr);
      }
    };

    // Penanganan event onsuccess: Koneksi berhasil dibuka
    request.onsuccess = () => {
      if (isSettled) return;
      isSettled = true;
      pendingOpenPromise = null;

      const db = request.result;
      activeDbInstance = db;

      // Tangani event onversionchange jika tab lain memicu upgrade di kemudian hari
      db.onversionchange = () => {
        // Tutup koneksi segera agar tab upgrade tidak terblokir
        db.close();
        if (activeDbInstance === db) {
          activeDbInstance = null;
        }
      };

      // Tangani jika koneksi ditutup tak terduga
      db.onclose = () => {
        if (activeDbInstance === db) {
          activeDbInstance = null;
        }
      };

      resolve(db);
    };

    // Penanganan event onerror
    request.onerror = () => {
      if (isSettled) return;
      isSettled = true;
      pendingOpenPromise = null;

      const err = request.error;
      if (err?.name === 'QuotaExceededError') {
        reject(new BchatQuotaExceededError(err.message, err));
      } else {
        reject(
          new BchatStorageError(
            `Gagal membuka database IndexedDB '${BCAT_DB_NAME}': ${err?.message || 'Unknown error'}`,
            'IDB_OPEN_FAILED',
            err
          )
        );
      }
    };
  });

  return pendingOpenPromise;
}

/**
 * Menutup koneksi database yang sedang aktif secara aman
 */
export function closeBchatDatabase(): void {
  if (activeDbInstance) {
    try {
      activeDbInstance.close();
    } catch {
      // Abaikan jika sudah tertutup
    }
    activeDbInstance = null;
  }
  pendingOpenPromise = null;
}

/**
 * Helper dasar transaksi atomik IndexedDB.
 *
 * KRITERIA KEAMANAN MUTLAK:
 * - Operasi hanya dianggap SUKSES jika transaction.oncomplete terpanggil.
 * - Keberhasilan put() atau add() tidak dianggap sukses jika transaksi abort.
 * - Jika tx.abort() atau tx.onerror terjadi, Promise direject dengan error asli.
 * - Dilarang menyisipkan operasi async non-IDB di tengah eksekusi transaksi.
 */
export async function withTransaction<T>(
  storeNames: BchatStoreName | BchatStoreName[],
  mode: IDBTransactionMode,
  callback: (
    tx: IDBTransaction,
    getStore: (name: BchatStoreName) => IDBObjectStore
  ) => T | Promise<T>
): Promise<T> {
  const db = await openBchatDatabase();
  const stores = Array.isArray(storeNames) ? storeNames : [storeNames];

  return new Promise<T>((resolve, reject) => {
    let tx: IDBTransaction;
    try {
      tx = db.transaction(stores, mode);
    } catch (err: any) {
      if (err.name === 'QuotaExceededError') {
        reject(new BchatQuotaExceededError(err.message, err));
      } else {
        reject(
          new BchatTransactionError(
            `Gagal membuat transaksi [${mode}] pada store [${stores.join(', ')}]: ${err.message}`,
            err
          )
        );
      }
      return;
    }

    const getStore = (name: BchatStoreName): IDBObjectStore => {
      return tx.objectStore(name);
    };

    let callbackResult: any = undefined;
    let callbackError: unknown = null;

    // Transaksi hanya sukses ketika event oncomplete ditembakkan oleh browser
    tx.oncomplete = () => {
      if (callbackError) {
        reject(callbackError);
      } else {
        resolve(callbackResult);
      }
    };

    // Tangani error transaksi
    tx.onerror = (event: Event) => {
      const err = tx.error || (event.target as any)?.error || new Error('IDBTransaction encountered an error');
      if (err.name === 'QuotaExceededError') {
        reject(new BchatQuotaExceededError(err.message, err));
      } else {
        reject(new BchatTransactionError(`Transaksi [${mode}] mengalami error: ${err.message}`, err));
      }
    };

    // Tangani abort transaksi (baik sengaja dipanggil tx.abort() maupun dibatalkan browser)
    tx.onabort = (event: Event) => {
      const err = tx.error || (event.target as any)?.error || new Error('IDBTransaction dibatalkan (aborted)');
      reject(new BchatTransactionError(`Transaksi [${mode}] dibatalkan (aborted): ${err.message}`, err));
    };

    // Eksekusi callback pengguna secara terlindungi
    try {
      const res = callback(tx, getStore);
      if (res instanceof Promise) {
        res
          .then((val) => {
            callbackResult = val;
          })
          .catch((err) => {
            callbackError = err;
            try {
              tx.abort();
            } catch {
              // Jika sudah aborted, abaikan
            }
            reject(new BchatTransactionError(`Callback transaksi melemparkan exception: ${err.message}`, err));
          });
      } else {
        callbackResult = res;
      }
    } catch (err: any) {
      callbackError = err;
      try {
        tx.abort();
      } catch {
        // Jika sudah aborted, abaikan
      }
      reject(new BchatTransactionError(`Eksekusi sinkron callback transaksi gagal: ${err.message}`, err));
    }
  });
}

/**
 * Helper transaksi readonly
 */
export function withReadonlyTransaction<T>(
  storeNames: BchatStoreName | BchatStoreName[],
  callback: (
    tx: IDBTransaction,
    getStore: (name: BchatStoreName) => IDBObjectStore
  ) => T | Promise<T>
): Promise<T> {
  return withTransaction(storeNames, 'readonly', callback);
}

/**
 * Helper transaksi readwrite
 */
export function withReadwriteTransaction<T>(
  storeNames: BchatStoreName | BchatStoreName[],
  callback: (
    tx: IDBTransaction,
    getStore: (name: BchatStoreName) => IDBObjectStore
  ) => T | Promise<T>
): Promise<T> {
  return withTransaction(storeNames, 'readwrite', callback);
}

// =============================================================================
// Helper CRUD Terstandarisasi Berbasis Transaksi Atomik
// =============================================================================

/**
 * Menyimpan record ke object store (hanya selesai saat transaction.oncomplete)
 */
export async function idbPut<T>(storeName: BchatStoreName, value: T): Promise<void> {
  await withReadwriteTransaction([storeName], (_tx, getStore) => {
    const store = getStore(storeName);
    store.put(value);
  });
}

/**
 * Mengambil record berdasarkan keyPath dari object store
 */
export async function idbGet<T>(storeName: BchatStoreName, key: IDBValidKey): Promise<T | undefined> {
  return withReadonlyTransaction([storeName], (_tx, getStore) => {
    return new Promise<T | undefined>((resolve, reject) => {
      const store = getStore(storeName);
      const req = store.get(key);
      req.onsuccess = () => resolve(req.result as T | undefined);
      req.onerror = () => reject(req.error || new Error(`Gagal membaca key '${String(key)}' pada ${storeName}`));
    });
  });
}

/**
 * Menghapus record berdasarkan keyPath dari object store
 */
export async function idbDelete(storeName: BchatStoreName, key: IDBValidKey): Promise<void> {
  await withReadwriteTransaction([storeName], (_tx, getStore) => {
    const store = getStore(storeName);
    store.delete(key);
  });
}

/**
 * Mengambil seluruh record dari object store
 */
export async function idbGetAll<T>(storeName: BchatStoreName): Promise<T[]> {
  return withReadonlyTransaction([storeName], (_tx, getStore) => {
    return new Promise<T[]>((resolve, reject) => {
      const store = getStore(storeName);
      const req = store.getAll();
      req.onsuccess = () => resolve(req.result as T[]);
      req.onerror = () => reject(req.error || new Error(`Gagal membaca semua record dari ${storeName}`));
    });
  });
}

/**
 * Membersihkan seluruh isi record dari object store
 */
export async function idbClear(storeName: BchatStoreName): Promise<void> {
  await withReadwriteTransaction([storeName], (_tx, getStore) => {
    const store = getStore(storeName);
    store.clear();
  });
}

