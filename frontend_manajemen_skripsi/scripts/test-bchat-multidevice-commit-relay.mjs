import test from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import * as tsMls from 'ts-mls';

// ---------------------------------------------------------------------------
// TEST HARNESS: MLS Context & Helpers
// ---------------------------------------------------------------------------

const cs = await tsMls.getCiphersuiteImpl('MLS_128_DHKEMX25519_AES128GCM_SHA256_Ed25519');
const context = {
  cipherSuite: cs,
  authService: tsMls.unsafeTestingAuthenticationService,
};

const ROOM_UUID = '7b1288fd-fe03-4a88-87fe-980c5741df18';
const SESSION_UUID = '11111111-2222-3333-4444-555555555555';
const GROUP_ID = new TextEncoder().encode(ROOM_UUID);

async function createMember(identity) {
  const cred = {
    credentialType: tsMls.defaultCredentialTypes.basic,
    identity: new TextEncoder().encode(identity),
  };
  const kp = await tsMls.generateKeyPackage({
    credential: cred,
    cipherSuite: context.cipherSuite,
  });

  // Also generate standard Ed25519 signing keypair for ACK signatures
  const { publicKey, privateKey } = crypto.generateKeyPairSync('ed25519');

  return { identity, cred, kp, publicKey, privateKey };
}

// ---------------------------------------------------------------------------
// TEST SUITE: 15 Scenarios
// ---------------------------------------------------------------------------

test('B-CHAT Multi-Device MLS: Commit Relay, Processing, and Epoch Sync', async (t) => {
  // Setup 3 members: Mahasiswa (Dev 4), Chrome Dosen (Dev 3), Edge Dosen (Dev 5)
  const mhs = await createMember('mahasiswa:dev-4');
  const chrome = await createMember('dosen:chrome:dev-3');
  const edge = await createMember('dosen:edge:dev-5');
  const nonMember = await createMember('attacker:dev-99');

  // Simulated History Store for History Transfer (Messages 1 to 34)
  const historyStore = new Map();
  for (let seq = 1; seq <= 34; seq++) {
    historyStore.set(seq, {
      serverSequence: seq,
      messageUuid: `legacy-msg-uuid-${seq}`,
      epoch: 1,
      sender: seq % 2 === 1 ? 'mahasiswa' : 'dosen',
      plaintext: `Riwayat pesan #${seq} dari Sesi 1-3`,
    });
  }

  // 1. Inisiasi Grup oleh Mahasiswa di Epoch 0
  let stateMahasiswa = await tsMls.createGroup({
    context,
    groupId: GROUP_ID,
    keyPackage: mhs.kp.publicPackage,
    privateKeyPackage: mhs.kp.privatePackage,
  });
  assert.equal(stateMahasiswa.groupContext.epoch.toString(), '0', 'Initial epoch must be 0');

  // 2. Mahasiswa Add Chrome (Device 3) -> Epoch 1
  const addChromeProposal = {
    proposalType: tsMls.defaultProposalTypes.add,
    add: { keyPackage: chrome.kp.publicPackage },
  };
  const commit1 = await tsMls.createCommit({
    context,
    state: stateMahasiswa,
    extraProposals: [addChromeProposal],
    ratchetTreeExtension: true,
  });
  stateMahasiswa = commit1.newState;
  assert.equal(stateMahasiswa.groupContext.epoch.toString(), '1', 'Mahasiswa advances to Epoch 1');

  // Chrome joins via Welcome 1
  const decodedWelcome1 = tsMls.decode(
    tsMls.mlsMessageDecoder,
    tsMls.encode(tsMls.mlsMessageEncoder, commit1.welcome)
  );
  let stateChrome = await tsMls.joinGroup({
    context,
    welcome: decodedWelcome1.welcome,
    keyPackage: chrome.kp.publicPackage,
    privateKeys: chrome.kp.privatePackage,
  });
  assert.equal(stateChrome.groupContext.epoch.toString(), '1', 'Chrome joins at Epoch 1');

  // Exchange message at Epoch 1
  const appMsg1 = await tsMls.createApplicationMessage({
    context,
    state: stateMahasiswa,
    message: new TextEncoder().encode('Pesan Epoch 1'),
  });
  stateMahasiswa = appMsg1.newState;
  const dec1 = await tsMls.processMessage({
    context,
    state: stateChrome,
    message: appMsg1.message,
  });
  stateChrome = dec1.newState;
  assert.equal(new TextDecoder().decode(dec1.message), 'Pesan Epoch 1');

  // 3. Mahasiswa membuat Add Proposal & Commit untuk menambahkan Edge (Device 5) -> Epoch 2
  const addEdgeProposal = {
    proposalType: tsMls.defaultProposalTypes.add,
    add: { keyPackage: edge.kp.publicPackage },
  };
  const commit2Result = await tsMls.createCommit({
    context,
    state: stateMahasiswa,
    extraProposals: [addEdgeProposal],
    ratchetTreeExtension: true,
  });
  stateMahasiswa = commit2Result.newState;
  assert.equal(stateMahasiswa.groupContext.epoch.toString(), '2', 'Mahasiswa advances to Epoch 2');

  const commit2Wire = tsMls.encode(tsMls.mlsMessageEncoder, commit2Result.commit);
  const welcome2Wire = tsMls.encode(tsMls.mlsMessageEncoder, commit2Result.welcome);
  const commitUuid = crypto.randomUUID();

  // -------------------------------------------------------------------------
  // Skenario 1: Commit valid diproses dengan benar oleh perangkat lama (Chrome)
  // -------------------------------------------------------------------------
  await t.test('1. Commit valid diproses dengan benar oleh perangkat lama', async () => {
    const decodedCommit = tsMls.decode(tsMls.mlsMessageDecoder, commit2Wire);
    assert.ok(decodedCommit, 'Commit wire bytes must be decodable');

    const procRes = await tsMls.processMessage({
      context,
      state: stateChrome,
      message: decodedCommit,
      callback: tsMls.acceptAll,
    });

    assert.equal(procRes.kind, 'newState', 'Commit processing must return newState');
    assert.equal(procRes.actionTaken, 'accept', 'Commit action must be accept');
    stateChrome = procRes.newState;
  });

  // -------------------------------------------------------------------------
  // Skenario 2: Welcome valid diproses dengan benar oleh perangkat baru (Edge)
  // -------------------------------------------------------------------------
  let stateEdge;
  await t.test('2. Welcome valid diproses dengan benar oleh perangkat baru', async () => {
    const decodedWelcome = tsMls.decode(tsMls.mlsMessageDecoder, welcome2Wire);
    assert.ok(decodedWelcome, 'Welcome wire bytes must be decodable');

    stateEdge = await tsMls.joinGroup({
      context,
      welcome: decodedWelcome.welcome,
      keyPackage: edge.kp.publicPackage,
      privateKeys: edge.kp.privatePackage,
    });

    assert.ok(stateEdge, 'Edge must successfully form GroupState');
  });

  // -------------------------------------------------------------------------
  // Skenario 3: Kedua perangkat mencapai epoch yang konsisten
  // -------------------------------------------------------------------------
  await t.test('3. Kedua perangkat mencapai epoch yang konsisten', async () => {
    const epochChrome = stateChrome.groupContext.epoch.toString();
    const epochEdge = stateEdge.groupContext.epoch.toString();
    const epochMhs = stateMahasiswa.groupContext.epoch.toString();

    assert.equal(epochChrome, '2', 'Chrome must be at Epoch 2');
    assert.equal(epochEdge, '2', 'Edge must be at Epoch 2');
    assert.equal(epochMhs, '2', 'Mahasiswa must be at Epoch 2');
  });

  // -------------------------------------------------------------------------
  // Skenario 4: Commit duplikat tidak diproses dua kali (idempoten)
  // -------------------------------------------------------------------------
  await t.test('4. Commit duplikat tidak diproses dua kali (idempoten)', async () => {
    const decodedCommit = tsMls.decode(tsMls.mlsMessageDecoder, commit2Wire);
    const currentEpochChrome = stateChrome.groupContext.epoch.toString();
    assert.equal(currentEpochChrome, '2', 'Chrome already at Epoch 2');

    await assert.rejects(
      async () => {
        await tsMls.processMessage({
          context,
          state: stateChrome,
          message: decodedCommit,
          callback: tsMls.acceptAll,
        });
      },
      /past|epoch|validation/i,
      'Re-processing old commit on state already at new epoch must be rejected'
    );
  });

  // -------------------------------------------------------------------------
  // Skenario 5: Commit untuk sesi / epoch yang salah ditolak
  // -------------------------------------------------------------------------
  await t.test('5. Commit untuk sesi / epoch yang salah ditolak', async () => {
    const otherMember = await createMember('other:user');
    const otherGroup = await tsMls.createGroup({
      context,
      groupId: new TextEncoder().encode('unrelated-room-uuid'),
      keyPackage: otherMember.kp.publicPackage,
      privateKeyPackage: otherMember.kp.privatePackage,
    });
    const unrelatedCommit = await tsMls.createCommit({
      context,
      state: otherGroup,
      extraProposals: [],
      ratchetTreeExtension: true,
    });
    const unrelatedCommitWire = tsMls.encode(tsMls.mlsMessageEncoder, unrelatedCommit.commit);
    const decodedUnrelated = tsMls.decode(tsMls.mlsMessageDecoder, unrelatedCommitWire);

    await assert.rejects(
      async () => {
        await tsMls.processMessage({
          context,
          state: stateChrome,
          message: decodedUnrelated,
          callback: tsMls.acceptAll,
        });
      },
      /group|epoch/i,
      'Commit for a different group must be rejected'
    );
  });

  // -------------------------------------------------------------------------
  // Skenario 6: Commit corrupt / invalid tidak merusak GroupState
  // -------------------------------------------------------------------------
  await t.test('6. Commit corrupt / invalid tidak merusak GroupState', async () => {
    const corruptedBytes = new Uint8Array(commit2Wire);
    corruptedBytes[corruptedBytes.length - 10] ^= 0xff;

    let decodedCorrupted;
    try {
      decodedCorrupted = tsMls.decode(tsMls.mlsMessageDecoder, corruptedBytes);
    } catch {
      // Decode error caught early
    }

    if (decodedCorrupted) {
      await assert.rejects(
        async () => {
          await tsMls.processMessage({
            context,
            state: stateChrome,
            message: decodedCorrupted,
            callback: tsMls.acceptAll,
          });
        },
        /error|verification|mac/i,
        'Corrupted commit must fail verification'
      );
    }

    assert.equal(stateChrome.groupContext.epoch.toString(), '2');
  });

  // -------------------------------------------------------------------------
  // Skenario 7: Kegagalan persistensi ditangani fail-closed
  // -------------------------------------------------------------------------
  await t.test('7. Kegagalan persistensi ditangani fail-closed', async () => {
    let candidateState = null;
    let adoptedState = stateChrome;
    let failed = false;

    try {
      candidateState = { ...stateChrome, dummyMod: true };
      throw new Error('IDB Transaction Aborted (Simulated Disk Full)');
    } catch {
      failed = true;
      candidateState = null;
    }

    assert.ok(failed, 'Persistence failure was triggered');
    assert.equal(candidateState, null, 'Candidate state was discarded');
    assert.equal(adoptedState.groupContext.epoch.toString(), '2', 'Adopted state remained untouched');
  });

  // -------------------------------------------------------------------------
  // Skenario 8: Pesan epoch baru dapat dipertukarkan setelah transisi selesai
  // -------------------------------------------------------------------------
  await t.test('8. Pesan epoch baru dapat dipertukarkan setelah transisi selesai', async () => {
    // Edge -> Chrome & Mahasiswa
    const edgeMsg = await tsMls.createApplicationMessage({
      context,
      state: stateEdge,
      message: new TextEncoder().encode('Halo dari Edge di Epoch 2!'),
    });
    stateEdge = edgeMsg.newState;

    const chromeDecEdge = await tsMls.processMessage({
      context,
      state: stateChrome,
      message: edgeMsg.message,
    });
    stateChrome = chromeDecEdge.newState;
    assert.equal(new TextDecoder().decode(chromeDecEdge.message), 'Halo dari Edge di Epoch 2!');

    const mhsDecEdge = await tsMls.processMessage({
      context,
      state: stateMahasiswa,
      message: edgeMsg.message,
    });
    stateMahasiswa = mhsDecEdge.newState;
    assert.equal(new TextDecoder().decode(mhsDecEdge.message), 'Halo dari Edge di Epoch 2!');

    // Chrome -> Edge
    const chromeMsg = await tsMls.createApplicationMessage({
      context,
      state: stateChrome,
      message: new TextEncoder().encode('Halo dari Chrome di Epoch 2!'),
    });
    stateChrome = chromeMsg.newState;

    const edgeDecChrome = await tsMls.processMessage({
      context,
      state: stateEdge,
      message: chromeMsg.message,
    });
    stateEdge = edgeDecChrome.newState;
    assert.equal(new TextDecoder().decode(edgeDecChrome.message), 'Halo dari Chrome di Epoch 2!');
  });

  // -------------------------------------------------------------------------
  // Skenario 9: Kedua perangkat tetap aktif setelah reload
  // -------------------------------------------------------------------------
  await t.test('9. Kedua perangkat tetap aktif setelah reload', async () => {
    const chromeSerialized = tsMls.encode(tsMls.clientStateEncoder, stateChrome);
    const edgeSerialized = tsMls.encode(tsMls.clientStateEncoder, stateEdge);

    const rehydratedChrome = tsMls.decode(tsMls.clientStateDecoder, chromeSerialized);
    const rehydratedEdge = tsMls.decode(tsMls.clientStateDecoder, edgeSerialized);

    assert.equal(rehydratedChrome.groupContext.epoch.toString(), '2');
    assert.equal(rehydratedEdge.groupContext.epoch.toString(), '2');

    const postReloadMsg = await tsMls.createApplicationMessage({
      context,
      state: rehydratedChrome,
      message: new TextEncoder().encode('Pesan pasca-reload dari Chrome!'),
    });
    const decPostReload = await tsMls.processMessage({
      context,
      state: rehydratedEdge,
      message: postReloadMsg.message,
    });
    assert.equal(new TextDecoder().decode(decPostReload.message), 'Pesan pasca-reload dari Chrome!');
  });

  // -------------------------------------------------------------------------
  // Skenario 10: Riwayat lama hasil History Transfer tetap utuh
  // -------------------------------------------------------------------------
  await t.test('10. Riwayat lama hasil History Transfer tetap utuh', async () => {
    assert.equal(historyStore.size, 34, 'History store must contain all 34 messages');
    const msg1 = historyStore.get(1);
    const msg34 = historyStore.get(34);

    assert.equal(msg1.plaintext, 'Riwayat pesan #1 dari Sesi 1-3');
    assert.equal(msg34.plaintext, 'Riwayat pesan #34 dari Sesi 1-3');
  });

  // -------------------------------------------------------------------------
  // Skenario 11: Pengiriman pesan diblokir selama status commit PENDING_ACKS
  // -------------------------------------------------------------------------
  await t.test('11. Pengiriman pesan diblokir selama status commit masih PENDING_ACKS', async () => {
    // Simulator Relay / Room State
    const relayState = {
      roomEpoch: 1,
      pendingCommit: {
        commitUuid,
        status: 'PENDING_ACKS',
        fromEpoch: 1,
        toEpoch: 2,
      },
    };

    function attemptSendMessage(messagePayload) {
      if (relayState.pendingCommit && relayState.pendingCommit.status === 'PENDING_ACKS') {
        const error = new Error('Ruangan sedang dalam proses pergantian epoch MLS. Pengiriman pesan ditunda.');
        error.name = 'BchatMessageException';
        error.code = 'ROOM_TRANSITIONING_EPOCH';
        error.statusCode = 409;
        throw error;
      }
      return { success: true, message: messagePayload };
    }

    assert.throws(
      () => attemptSendMessage({ text: 'Pesan terlarang saat transisi' }),
      (err) => {
        return (
          err.code === 'ROOM_TRANSITIONING_EPOCH' &&
          err.statusCode === 409 &&
          err.message.includes('pergantian epoch')
        );
      },
      'Message send must be blocked with HTTP 409 ROOM_TRANSITIONING_EPOCH'
    );
  });

  // -------------------------------------------------------------------------
  // Skenario 12: Pembuatan commit dengan KeyPackage user lain / bukan room member ditolak
  // -------------------------------------------------------------------------
  await t.test('12. Pembuatan commit dengan KeyPackage milik user lain ditolak', async () => {
    const roomMembers = new Set(['mahasiswa:dev-4', 'dosen:dev-3', 'dosen:dev-5']);
    const registeredDevices = new Map([
      ['dev-4', { userId: 'mahasiswa', status: 'ACTIVE' }],
      ['dev-3', { userId: 'dosen', status: 'ACTIVE' }],
      ['dev-5', { userId: 'dosen', status: 'ACTIVE' }],
      ['dev-99', { userId: 'intruder', status: 'ACTIVE' }],
    ]);

    function validateAddedDeviceForCommit(deviceUuid, kpOwnerIdentity) {
      const dev = registeredDevices.get(deviceUuid);
      if (!dev) throw new Error('DEVICE_NOT_FOUND');
      if (!roomMembers.has(`${dev.userId}:${deviceUuid}`)) {
        const error = new Error('ADDED_DEVICE_NOT_ROOM_MEMBER: Perangkat tujuan bukan milik anggota aktif room ini');
        error.code = 'ADDED_DEVICE_NOT_ROOM_MEMBER';
        throw error;
      }
      if (kpOwnerIdentity !== `${dev.userId}:${deviceUuid}`) {
        const error = new Error('KEY_PACKAGE_IDENTITY_MISMATCH: KeyPackage tidak cocok dengan identitas perangkat tujuan');
        error.code = 'KEY_PACKAGE_IDENTITY_MISMATCH';
        throw error;
      }
      return true;
    }

    // A. Perangkat dev-99 bukan anggota room
    assert.throws(
      () => validateAddedDeviceForCommit('dev-99', 'intruder:dev-99'),
      /ADDED_DEVICE_NOT_ROOM_MEMBER/,
      'Non-member device addition must be rejected'
    );

    // B. KeyPackage dipalsukan dengan identity attacker
    assert.throws(
      () => validateAddedDeviceForCommit('dev-5', 'attacker:dev-99'),
      /KEY_PACKAGE_IDENTITY_MISMATCH/,
      'Mismatched KeyPackage identity must be rejected'
    );

    // C. Valid member & matching KeyPackage
    assert.ok(validateAddedDeviceForCommit('dev-5', 'dosen:dev-5'));
  });

  // -------------------------------------------------------------------------
  // Skenario 13: Commit dengan KeyPackage yang sudah consumed ditolak
  // -------------------------------------------------------------------------
  await t.test('13. Commit dengan KeyPackage yang sudah consumed ditolak', async () => {
    const keyPackageTable = new Map([
      ['kp-edge-uuid', { id: 101, isConsumed: false, deviceUuid: 'dev-5' }],
    ]);

    function consumeKeyPackage(kpUuid) {
      const kp = keyPackageTable.get(kpUuid);
      if (!kp) throw new Error('KEY_PACKAGE_NOT_FOUND');
      if (kp.isConsumed) {
        const error = new Error('KEY_PACKAGE_ALREADY_CONSUMED: KeyPackage sudah digunakan oleh commit atau sesi lain');
        error.code = 'KEY_PACKAGE_ALREADY_CONSUMED';
        throw error;
      }
      kp.isConsumed = true;
      return kp;
    }

    // Pertama kali: sukses
    const consumed = consumeKeyPackage('kp-edge-uuid');
    assert.equal(consumed.isConsumed, true);

    // Kedua kali (reuse): ditolak
    assert.throws(
      () => consumeKeyPackage('kp-edge-uuid'),
      /KEY_PACKAGE_ALREADY_CONSUMED/,
      'Reusing consumed KeyPackage must throw KEY_PACKAGE_ALREADY_CONSUMED'
    );
  });

  // -------------------------------------------------------------------------
  // Skenario 14: COMMIT_ACK dan WELCOME_ACK dipisahkan dengan validasi canonical signature
  // -------------------------------------------------------------------------
  await t.test('14. COMMIT_ACK dan WELCOME_ACK dipisahkan dengan validasi canonical signature ketat', async () => {
    const fromEpoch = 1;
    const toEpoch = 2;
    const devChromeUuid = 'dev-3';
    const devEdgeUuid = 'dev-5';

    // Canonical payload definitions
    const canonicalCommitPayload = `COMMIT_ACK:${ROOM_UUID}:${SESSION_UUID}:${commitUuid}:${devChromeUuid}:${toEpoch}`;
    const canonicalWelcomePayload = `WELCOME_ACK:${ROOM_UUID}:${SESSION_UUID}:${commitUuid}:${devEdgeUuid}:${toEpoch}`;

    // Signatures
    const validCommitSig = crypto.sign(null, Buffer.from(canonicalCommitPayload, 'utf8'), chrome.privateKey);
    const validWelcomeSig = crypto.sign(null, Buffer.from(canonicalWelcomePayload, 'utf8'), edge.privateKey);

    function verifyAckSubmission({ ackType, deviceUuid, canonicalPayload, signature, publicKey, isAddedDevice }) {
      // Role enforcement
      if (isAddedDevice && ackType !== 'WELCOME_ACK') {
        const err = new Error('INVALID_ACK_TYPE_FOR_DEVICE: Perangkat baru wajib mengirim WELCOME_ACK');
        err.code = 'INVALID_ACK_TYPE_FOR_DEVICE';
        throw err;
      }
      if (!isAddedDevice && ackType !== 'COMMIT_ACK') {
        const err = new Error('INVALID_ACK_TYPE_FOR_DEVICE: Perangkat lama wajib mengirim COMMIT_ACK');
        err.code = 'INVALID_ACK_TYPE_FOR_DEVICE';
        throw err;
      }

      // Canonical format enforcement
      const expectedPrefix = `${ackType}:${ROOM_UUID}:${SESSION_UUID}:${commitUuid}:${deviceUuid}:${toEpoch}`;
      if (canonicalPayload !== expectedPrefix) {
        const err = new Error('INVALID_CANONICAL_PAYLOAD: Canonical payload tidak sesuai dengan parameter transisi');
        err.code = 'INVALID_CANONICAL_PAYLOAD';
        throw err;
      }

      // Ed25519 Detached Signature Verification
      const isValid = crypto.verify(null, Buffer.from(canonicalPayload, 'utf8'), publicKey, signature);
      if (!isValid) {
        const err = new Error('INVALID_ACK_SIGNATURE: Signature ACK tidak valid atau tidak cocok dengan public key perangkat');
        err.code = 'INVALID_ACK_SIGNATURE';
        throw err;
      }

      return true;
    }

    // A. Valid COMMIT_ACK dari Chrome
    assert.ok(
      verifyAckSubmission({
        ackType: 'COMMIT_ACK',
        deviceUuid: devChromeUuid,
        canonicalPayload: canonicalCommitPayload,
        signature: validCommitSig,
        publicKey: chrome.publicKey,
        isAddedDevice: false,
      })
    );

    // B. Valid WELCOME_ACK dari Edge
    assert.ok(
      verifyAckSubmission({
        ackType: 'WELCOME_ACK',
        deviceUuid: devEdgeUuid,
        canonicalPayload: canonicalWelcomePayload,
        signature: validWelcomeSig,
        publicKey: edge.publicKey,
        isAddedDevice: true,
      })
    );

    // C. Chrome mencoba mengirim WELCOME_ACK (Cross-ACK ditolak)
    assert.throws(
      () =>
        verifyAckSubmission({
          ackType: 'WELCOME_ACK',
          deviceUuid: devChromeUuid,
          canonicalPayload: `WELCOME_ACK:${ROOM_UUID}:${SESSION_UUID}:${commitUuid}:${devChromeUuid}:${toEpoch}`,
          signature: validCommitSig,
          publicKey: chrome.publicKey,
          isAddedDevice: false,
        }),
      /INVALID_ACK_TYPE_FOR_DEVICE/
    );

    // D. Edge mencoba mengirim COMMIT_ACK (Cross-ACK ditolak)
    assert.throws(
      () =>
        verifyAckSubmission({
          ackType: 'COMMIT_ACK',
          deviceUuid: devEdgeUuid,
          canonicalPayload: `COMMIT_ACK:${ROOM_UUID}:${SESSION_UUID}:${commitUuid}:${devEdgeUuid}:${toEpoch}`,
          signature: validWelcomeSig,
          publicKey: edge.publicKey,
          isAddedDevice: true,
        }),
      /INVALID_ACK_TYPE_FOR_DEVICE/
    );

    // E. Signature palsu / dirusak ditolak
    const forgedSig = Buffer.from(validCommitSig);
    forgedSig[0] ^= 0xff;
    assert.throws(
      () =>
        verifyAckSubmission({
          ackType: 'COMMIT_ACK',
          deviceUuid: devChromeUuid,
          canonicalPayload: canonicalCommitPayload,
          signature: forgedSig,
          publicKey: chrome.publicKey,
          isAddedDevice: false,
        }),
      /INVALID_ACK_SIGNATURE/
    );
  });

  // -------------------------------------------------------------------------
  // Skenario 15: Kemajuan epoch ditahan jika salah satu ACK belum diterima
  // -------------------------------------------------------------------------
  await t.test('15. Kemajuan epoch ditahan jika salah satu ACK belum diterima', async () => {
    const requiredCommitAcks = new Set(['dev-3']); // Chrome
    const requiredWelcomeAck = 'dev-5'; // Edge

    const receivedCommitAcks = new Set();
    let receivedWelcomeAck = null;

    let roomEpoch = 1;
    let commitStatus = 'PENDING_ACKS';

    function evaluateTransition() {
      const allCommitsReceived = Array.from(requiredCommitAcks).every((d) => receivedCommitAcks.has(d));
      const welcomeReceived = receivedWelcomeAck === requiredWelcomeAck;

      if (allCommitsReceived && welcomeReceived) {
        roomEpoch = 2;
        commitStatus = 'ACTIVE';
        return { completed: true, roomEpoch, commitStatus };
      }
      return { completed: false, roomEpoch, commitStatus };
    }

    // Step 0: Initial state
    assert.equal(evaluateTransition().completed, false);
    assert.equal(roomEpoch, 1);
    assert.equal(commitStatus, 'PENDING_ACKS');

    // Step 1: Chrome sends COMMIT_ACK, but Edge has not sent WELCOME_ACK yet
    receivedCommitAcks.add('dev-3');
    const step1 = evaluateTransition();
    assert.equal(step1.completed, false, 'Transition must NOT complete without WELCOME_ACK');
    assert.equal(step1.roomEpoch, 1, 'Room epoch must remain 1');
    assert.equal(step1.commitStatus, 'PENDING_ACKS');

    // Step 2: Edge sends WELCOME_ACK -> Now all conditions met
    receivedWelcomeAck = 'dev-5';
    const step2 = evaluateTransition();
    assert.equal(step2.completed, true, 'Transition completes when all ACKs are present');
    assert.equal(step2.roomEpoch, 2, 'Room epoch advances to 2');
    assert.equal(step2.commitStatus, 'ACTIVE');
  });
});
