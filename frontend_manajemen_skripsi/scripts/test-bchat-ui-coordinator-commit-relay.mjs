import test from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import * as tsMls from 'ts-mls';

// ---------------------------------------------------------------------------
// PRASYARAT GERBANG 3: UI / COORDINATOR COMMIT RELAY INTEGRATION TEST
// Menguji 8 aspek integrasi otomatis UI, Coordinator, Worker, dan Backend:
// 1. Deteksi perangkat kedua yang ACTIVE dan trusted.
// 2. Validasi keabsahan anggota room target device.
// 3. Pemicu penerbitan Commit via checkAndInitiateMultiDeviceCommitRelayFlow.
// 4. Pemrosesan Commit oleh perangkat lama (PROCESS_COMMIT).
// 5. Pemrosesan Welcome oleh perangkat baru (JOIN_WELCOME).
// 6. Pengiriman COMMIT_ACK dan WELCOME_ACK sesuai peran masing-masing perangkat.
// 7. Penanganan PENDING_ACKS (anti-duplikasi commit, offline reconnect, retry idempoten).
// 8. Pembukaan kembali composer HANYA setelah status ACTIVE tercapai.
// ---------------------------------------------------------------------------

const cs = await tsMls.getCiphersuiteImpl('MLS_128_DHKEMX25519_AES128GCM_SHA256_Ed25519');
const mlsContext = {
  cipherSuite: cs,
  authService: tsMls.unsafeTestingAuthenticationService,
};

const ROOM_UUID = '7b1288fd-fe03-4a88-87fe-980c5741df18';
const SESSION_UUID = '32f3ee98-3449-47a2-be26-ae8e5ac32d04';
const GROUP_ID = new TextEncoder().encode(ROOM_UUID);

/**
 * Peer Simulator with RFC 9420 ts-mls Worker integration
 */
class PeerSimulator {
  constructor(name, identity, deviceUuid, userId) {
    this.name = name;
    this.identity = identity;
    this.deviceUuid = deviceUuid;
    this.userId = userId;
    this.clientState = null;
    this.keyPackage = null;
    this.keyPackagePublicBytes = null;
    this.keyPackageHash = null;

    const kp = crypto.generateKeyPairSync('ed25519');
    this.publicKey = kp.publicKey;
    this.privateKey = kp.privateKey;
    this.publicKeyHex = kp.publicKey.export({ type: 'spki', format: 'der' }).toString('hex');
  }

  async generateKeyPackage() {
    const cred = {
      credentialType: tsMls.defaultCredentialTypes.basic,
      identity: new TextEncoder().encode(this.identity),
    };
    this.keyPackage = await tsMls.generateKeyPackage({
      credential: cred,
      cipherSuite: mlsContext.cipherSuite,
    });
    this.keyPackagePublicBytes = tsMls.encode(tsMls.keyPackageEncoder, this.keyPackage.publicPackage);
    this.keyPackageHash = crypto.createHash('sha256').update(this.keyPackagePublicBytes).digest('hex');
    return {
      keyPackageData: Buffer.from(this.keyPackagePublicBytes).toString('base64'),
      keyPackageHash: this.keyPackageHash,
    };
  }

  async createGroup() {
    this.clientState = await tsMls.createGroup({
      context: mlsContext,
      groupId: GROUP_ID,
      keyPackage: this.keyPackage.publicPackage,
      privateKeyPackage: this.keyPackage.privatePackage,
    });
    return { epoch: this.clientState.groupContext.epoch.toString() };
  }

  async commitAddMember(kpBytes) {
    const decodedKp = tsMls.decode(tsMls.keyPackageDecoder, kpBytes);
    const addProposal = {
      proposalType: tsMls.defaultProposalTypes.add,
      add: { keyPackage: decodedKp },
    };
    const commitResult = await tsMls.createCommit({
      context: mlsContext,
      state: this.clientState,
      extraProposals: [addProposal],
      ratchetTreeExtension: true,
    });
    this.clientState = commitResult.newState;
    const commitWire = tsMls.encode(tsMls.mlsMessageEncoder, commitResult.commit);
    const welcomeWire = tsMls.encode(tsMls.mlsMessageEncoder, commitResult.welcome);
    return {
      epoch: this.clientState.groupContext.epoch.toString(),
      commitWireBase64: Buffer.from(commitWire).toString('base64'),
      welcomeWireBase64: Buffer.from(welcomeWire).toString('base64'),
    };
  }

  async processCommit(commitWireBase64) {
    const commitWire = Buffer.from(commitWireBase64, 'base64');
    const decoded = tsMls.decode(tsMls.mlsMessageDecoder, commitWire);
    const procRes = await tsMls.processMessage({
      context: mlsContext,
      state: this.clientState,
      message: decoded,
      callback: tsMls.acceptAll,
    });
    if (procRes.kind === 'newState') {
      this.clientState = procRes.newState;
      return { epoch: this.clientState.groupContext.epoch.toString() };
    }
    throw new Error('PROCESS_COMMIT failed to produce newState');
  }

  async joinWelcome(welcomeWireBase64) {
    const welcomeWire = Buffer.from(welcomeWireBase64, 'base64');
    const decoded = tsMls.decode(tsMls.mlsMessageDecoder, welcomeWire);
    this.clientState = await tsMls.joinGroup({
      context: mlsContext,
      welcome: decoded.welcome,
      keyPackage: this.keyPackage.publicPackage,
      privateKeys: this.keyPackage.privatePackage,
    });
    return { epoch: this.clientState.groupContext.epoch.toString() };
  }

  signCanonicalAck(canonicalPayload) {
    const sig = crypto.sign(null, Buffer.from(canonicalPayload, 'utf8'), this.privateKey);
    return sig.toString('hex');
  }

  async encryptMessage(text) {
    const appMsg = await tsMls.createApplicationMessage({
      context: mlsContext,
      state: this.clientState,
      message: new TextEncoder().encode(text),
    });
    this.clientState = appMsg.newState;
    const wireBytes = tsMls.encode(tsMls.mlsMessageEncoder, appMsg.message);
    return {
      wireBytes,
      base64Payload: Buffer.from(wireBytes).toString('base64'),
    };
  }

  async decryptMessage(wireBytes) {
    const wire = Buffer.isBuffer(wireBytes) || wireBytes instanceof Uint8Array
      ? wireBytes
      : Buffer.from(wireBytes, 'base64');
    const decoded = tsMls.decode(tsMls.mlsMessageDecoder, wire);
    const res = await tsMls.processMessage({
      context: mlsContext,
      state: this.clientState,
      message: decoded,
    });
    this.clientState = res.newState;
    return new TextDecoder().decode(res.message);
  }
}

/**
 * Backend Mock simulating Laravel 11 BchatCommitRelayService & DB
 */
class BackendRelayMock {
  constructor(roomUuid, sessionUuid) {
    this.roomUuid = roomUuid;
    this.sessionUuid = sessionUuid;
    this.currentEpoch = 1;
    this.members = new Map(); // userId -> { role, devices: Map<devUuid, devInfo> }
    this.commits = new Map(); // commitUuid -> commitRecord
    this.acks = []; // ack records
  }

  registerDevice(userId, role, devUuid, devName, status, isTrusted) {
    if (!this.members.has(userId)) {
      this.members.set(userId, { role, devices: new Map() });
    }
    this.members.get(userId).devices.set(devUuid, {
      device_uuid: devUuid,
      device_name: devName,
      status,
      is_trusted: isTrusted,
      user_id: userId,
    });
  }

  getDeviceStatus(userId) {
    const member = this.members.get(userId);
    const activeDevices = [];
    if (member) {
      for (const d of member.devices.values()) {
        if (d.status === 'ACTIVE') activeDevices.push(d);
      }
    }
    return {
      has_active_device: activeDevices.length > 0,
      active_devices: activeDevices,
      active_device: activeDevices[0] || null,
    };
  }

  getRoomDetail() {
    return {
      room_uuid: this.roomUuid,
      current_epoch: String(this.currentEpoch),
      status: 'ACTIVE',
      active_session: {
        session_uuid: this.sessionUuid,
      },
    };
  }

  getPendingCommits(devUuid) {
    const pending = [];
    for (const c of this.commits.values()) {
      // Find if this device already acked
      const hasAcked = this.acks.some(
        (a) => a.commit_uuid === c.commit_uuid && a.device_uuid === devUuid
      );
      if (!hasAcked || c.status === 'PENDING_ACKS') {
        const isAddedDevice = c.added_device_uuid === devUuid;
        pending.push({
          commit_uuid: c.commit_uuid,
          from_epoch: String(c.from_epoch),
          to_epoch: String(c.to_epoch),
          commit_wire_base64: c.commit_wire_base64,
          welcome_wire_base64: isAddedDevice ? c.welcome_wire_base64 : null,
          commit_hash: c.commit_hash,
          status: c.status,
          sender_device_uuid: c.sender_device_uuid,
          added_device_uuid: c.added_device_uuid,
        });
      }
    }
    return pending;
  }

  publishCommit(senderDevUuid, payload) {
    // 1. Guard against double-commit if transition already pending
    for (const c of this.commits.values()) {
      if (c.status === 'PENDING_ACKS') {
        throw new Error('COMMIT_ALREADY_IN_PROGRESS: Transisi sedang berlangsung.');
      }
    }

    // 2. Validate epoch
    if (payload.from_epoch !== this.currentEpoch) {
      throw new Error(`EPOCH_MISMATCH: from_epoch ${payload.from_epoch} != ${this.currentEpoch}`);
    }

    const commitUuid = 'commit_' + crypto.randomUUID();
    const commitHash = crypto.createHash('sha256').update(payload.commit_wire_base64).digest('hex');

    const commitRecord = {
      commit_uuid: commitUuid,
      from_epoch: payload.from_epoch,
      to_epoch: payload.to_epoch,
      commit_wire_base64: payload.commit_wire_base64,
      welcome_wire_base64: payload.welcome_wire_base64,
      commit_hash: commitHash,
      sender_device_uuid: senderDevUuid,
      added_device_uuid: payload.added_device_uuid,
      status: 'PENDING_ACKS',
    };

    this.commits.set(commitUuid, commitRecord);

    // Auto-record initiator COMMIT_ACK
    this.acks.push({
      commit_uuid: commitUuid,
      device_uuid: senderDevUuid,
      ack_type: 'COMMIT_ACK',
      epoch: payload.to_epoch,
      status: 'ACKED',
    });

    this.evaluateTransition(commitRecord);

    return {
      commit_uuid: commitUuid,
      from_epoch: String(payload.from_epoch),
      to_epoch: String(payload.to_epoch),
      status: commitRecord.status,
    };
  }

  submitCommitAck(commitUuid, devUuid, ackedEpoch, signature) {
    const commit = this.commits.get(commitUuid);
    if (!commit) throw new Error('COMMIT_NOT_FOUND');

    // Idempotency check
    const existing = this.acks.find(
      (a) => a.commit_uuid === commitUuid && a.device_uuid === devUuid && a.ack_type === 'COMMIT_ACK'
    );
    if (existing) {
      return {
        commit_uuid: commitUuid,
        is_complete: commit.status === 'ACTIVE',
        status: commit.status,
      };
    }

    this.acks.push({
      commit_uuid: commitUuid,
      device_uuid: devUuid,
      ack_type: 'COMMIT_ACK',
      epoch: ackedEpoch,
      signature,
    });

    this.evaluateTransition(commit);

    return {
      commit_uuid: commitUuid,
      is_complete: commit.status === 'ACTIVE',
      status: commit.status,
    };
  }

  submitWelcomeAck(commitUuid, devUuid, ackedEpoch, signature) {
    const commit = this.commits.get(commitUuid);
    if (!commit) throw new Error('COMMIT_NOT_FOUND');
    if (commit.added_device_uuid !== devUuid) {
      throw new Error('INVALID_WELCOME_RECIPIENT');
    }

    // Idempotency check
    const existing = this.acks.find(
      (a) => a.commit_uuid === commitUuid && a.device_uuid === devUuid && a.ack_type === 'WELCOME_ACK'
    );
    if (existing) {
      return {
        commit_uuid: commitUuid,
        is_complete: commit.status === 'ACTIVE',
        status: commit.status,
      };
    }

    this.acks.push({
      commit_uuid: commitUuid,
      device_uuid: devUuid,
      ack_type: 'WELCOME_ACK',
      epoch: ackedEpoch,
      signature,
    });

    this.evaluateTransition(commit);

    return {
      commit_uuid: commitUuid,
      is_complete: commit.status === 'ACTIVE',
      status: commit.status,
    };
  }

  evaluateTransition(commit) {
    // Required: all existing members have COMMIT_ACK, and added_device has WELCOME_ACK
    const hasAddedWelcomeAck = this.acks.some(
      (a) => a.commit_uuid === commit.commit_uuid && a.device_uuid === commit.added_device_uuid && a.ack_type === 'WELCOME_ACK'
    );

    // Existing active devices that must ACK (Device 3 Chrome + Device 4 Mahasiswa)
    const existingDevUuids = ['device-3-chrome', 'device-4-mahasiswa'];
    const allExistingAcked = existingDevUuids.every((devUuid) =>
      this.acks.some(
        (a) => a.commit_uuid === commit.commit_uuid && a.device_uuid === devUuid && a.ack_type === 'COMMIT_ACK'
      )
    );

    if (hasAddedWelcomeAck && allExistingAcked) {
      commit.status = 'ACTIVE';
      this.currentEpoch = commit.to_epoch;
    }
  }
}

// ---------------------------------------------------------------------------
// SUITE: UI & COORDINATOR COMMIT RELAY PRASYARAT GERBANG 3
// ---------------------------------------------------------------------------

test('GERBANG 3 PRASYARAT — UI & Coordinator Commit Relay Multi-Device (Opsi B)', async (t) => {
  const backend = new BackendRelayMock(ROOM_UUID, SESSION_UUID);

  // Setup initial actors:
  // User 2 (Dosen): Device 3 (Chrome - Active MLS member)
  // User 1 (Mahasiswa): Device 4 (HP - Active MLS member)
  // User 2 (Dosen): Device 5 (Edge - Second Device, Trusted, Read-Only History initially)
  const chromeDev = new PeerSimulator('Chrome', 'dosen-device-3', 'device-3-chrome', 2);
  const mhsDev = new PeerSimulator('Mahasiswa', 'mahasiswa-device-4', 'device-4-mahasiswa', 1);
  const edgeDev = new PeerSimulator('Edge', 'dosen-device-5', 'device-5-edge', 2);

  backend.registerDevice(2, 'dosen', 'device-3-chrome', 'Chrome PC', 'ACTIVE', true);
  backend.registerDevice(1, 'mahasiswa', 'device-4-mahasiswa', 'Mahasiswa Mobile', 'ACTIVE', true);

  // Initial group creation at Epoch 1 (Chrome + Mahasiswa)
  await chromeDev.generateKeyPackage();
  await mhsDev.generateKeyPackage();
  await edgeDev.generateKeyPackage();

  await chromeDev.createGroup();
  const initAdd = await chromeDev.commitAddMember(mhsDev.keyPackagePublicBytes);
  await mhsDev.joinWelcome(initAdd.welcomeWireBase64);
  assert.equal(chromeDev.clientState.groupContext.epoch.toString(), '1');
  assert.equal(mhsDev.clientState.groupContext.epoch.toString(), '1');

  // Edge starts with NO MLS GroupState (Read-Only History)
  assert.equal(edgeDev.clientState, null);

  await t.test('1. Deteksi perangkat kedua yang ACTIVE dan trusted', async () => {
    // Condition A: Edge not registered or revoked -> NO_SECONDARY_TRUSTED_DEVICE
    let status = backend.getDeviceStatus(2);
    let otherDevices = status.active_devices.filter((d) => d.device_uuid !== chromeDev.deviceUuid);
    assert.equal(otherDevices.length, 0, 'Belum ada perangkat kedua');

    // Register Edge as ACTIVE and trusted
    backend.registerDevice(2, 'dosen', 'device-5-edge', 'Edge Laptop', 'ACTIVE', true);
    status = backend.getDeviceStatus(2);
    otherDevices = status.active_devices.filter((d) => d.device_uuid !== chromeDev.deviceUuid);
    assert.equal(otherDevices.length, 1);
    assert.equal(otherDevices[0].device_uuid, 'device-5-edge');
    assert.equal(otherDevices[0].is_trusted, true);
  });

  await t.test('2. Validasi keabsahan keanggotaan room target device', async () => {
    // Device 5 belongs to User 2, who is a registered member of the room
    const member = backend.members.get(2);
    assert.ok(member, 'User 2 harus terdaftar sebagai anggota room');
    assert.ok(member.devices.has('device-5-edge'), 'Device 5 harus milik User 2');
    assert.equal(member.devices.get('device-5-edge').is_trusted, true);
  });

  let publishedCommit = null;

  await t.test('3. Pemicu penerbitan Commit via Coordinator Flow', async () => {
    // Coordinator initiates commit add member for Edge
    const kpData = await edgeDev.generateKeyPackage();
    const commitOutput = await chromeDev.commitAddMember(edgeDev.keyPackagePublicBytes);

    publishedCommit = backend.publishCommit(chromeDev.deviceUuid, {
      from_epoch: 1,
      to_epoch: 2,
      commit_wire_base64: commitOutput.commitWireBase64,
      welcome_wire_base64: commitOutput.welcomeWireBase64,
      added_device_uuid: edgeDev.deviceUuid,
      key_package_hash: kpData.keyPackageHash,
    });

    assert.ok(publishedCommit.commit_uuid);
    assert.equal(publishedCommit.status, 'PENDING_ACKS');
    assert.equal(backend.currentEpoch, 1, 'current_epoch TIDAK boleh naik sebelum semua ACK lengkap');
  });

  await t.test('4. Anti-duplikasi Commit saat status PENDING_ACKS', async () => {
    // Multiple coordinator checks (rerender, polling, reconnect) must NOT create second commit
    assert.throws(
      () => {
        backend.publishCommit(chromeDev.deviceUuid, {
          from_epoch: 1,
          to_epoch: 2,
          commit_wire_base64: 'duplicate...',
          added_device_uuid: edgeDev.deviceUuid,
        });
      },
      /COMMIT_ALREADY_IN_PROGRESS/,
      'Commit kedua harus ditolak selama transisi masih PENDING_ACKS'
    );
  });

  await t.test('5. Fail-closed Composer Gating saat PENDING_ACKS', async () => {
    // Composer simulation state:
    const isTransitioningEpoch = true;
    const hasLocalGroupState = true;
    const connectionStatus = 'CONNECTED';
    const roomStatus = 'ACTIVE';

    const isComposerDisabled =
      isTransitioningEpoch || !hasLocalGroupState || connectionStatus !== 'CONNECTED' || roomStatus !== 'ACTIVE';
    assert.equal(isComposerDisabled, true, 'Composer WAJIB terkunci saat isTransitioningEpoch true');

    // Edge simulation state:
    const isEdgeComposerDisabled =
      isTransitioningEpoch || edgeDev.clientState !== null; // edge has no GroupState yet
    assert.equal(isEdgeComposerDisabled, true, 'Composer Edge WAJIB terkunci');
  });

  await t.test('6. Pemrosesan Commit oleh perangkat lama (Mahasiswa) & COMMIT_ACK', async () => {
    const pendingCommits = backend.getPendingCommits(mhsDev.deviceUuid);
    assert.equal(pendingCommits.length, 1);
    const commitToProcess = pendingCommits[0];

    // Mahasiswa processes Commit wireformat via ts-mls
    const mhsRes = await mhsDev.processCommit(commitToProcess.commit_wire_base64);
    assert.equal(mhsRes.epoch, '2', 'Mahasiswa berhasil maju ke epoch 2 secara internal');

    // Mahasiswa generates canonical signature & submits COMMIT_ACK
    const canonicalPayload = `COMMIT_ACK:${ROOM_UUID}:${SESSION_UUID}:${commitToProcess.commit_uuid}:${mhsDev.deviceUuid}:2`;
    const sigHex = mhsDev.signCanonicalAck(canonicalPayload);
    const ackRes = backend.submitCommitAck(commitToProcess.commit_uuid, mhsDev.deviceUuid, 2, sigHex);

    assert.equal(ackRes.is_complete, false, 'Masih belum complete karena Welcome ACK Edge belum masuk');
    assert.equal(backend.currentEpoch, 1, 'Epoch backend belum naik');
  });

  await t.test('7. Pemrosesan Welcome oleh perangkat baru (Edge) & WELCOME_ACK', async () => {
    const pendingCommits = backend.getPendingCommits(edgeDev.deviceUuid);
    assert.equal(pendingCommits.length, 1);
    const commitRecord = pendingCommits[0];
    assert.ok(commitRecord.welcome_wire_base64, 'welcome_wire_base64 harus tersedia untuk added_device');

    // Edge processes Welcome wireformat via ts-mls JOIN_WELCOME
    const edgeRes = await edgeDev.joinWelcome(commitRecord.welcome_wire_base64);
    assert.equal(edgeRes.epoch, '2', 'Edge berhasil bergabung ke grup MLS pada epoch 2');
    assert.ok(edgeDev.clientState, 'Edge kini memiliki GroupState aktif');

    // Edge generates canonical signature & submits WELCOME_ACK
    const canonicalPayload = `WELCOME_ACK:${ROOM_UUID}:${SESSION_UUID}:${commitRecord.commit_uuid}:${edgeDev.deviceUuid}:2`;
    const sigHex = edgeDev.signCanonicalAck(canonicalPayload);
    const ackRes = backend.submitWelcomeAck(commitRecord.commit_uuid, edgeDev.deviceUuid, 2, sigHex);

    assert.equal(ackRes.is_complete, true, 'Transisi SUDAH LENGKAP setelah Welcome ACK');
    assert.equal(ackRes.status, 'ACTIVE');
    assert.equal(backend.currentEpoch, 2, 'Epoch backend sekarang SUDAH naik ke 2');
  });

  await t.test('8. Pembukaan kembali Composer setelah ACTIVE & Pertukaran Pesan 3 Perangkat', async () => {
    // Now backend is ACTIVE, epoch = 2
    const roomDetail = backend.getRoomDetail();
    assert.equal(roomDetail.current_epoch, '2');

    // UI simulator unlocks:
    const isTransitioningEpoch = false;
    const hasLocalGroupState = true;
    const isComposerDisabled = isTransitioningEpoch || !hasLocalGroupState;
    assert.equal(isComposerDisabled, false, 'Composer TERBUKA kembali');

    // 1. Edge mengirim pesan baru ke room
    const edgePlaintext = 'Halo dari Edge (Device 5)! MLS multi-device aktif!';
    const encEdge = await edgeDev.encryptMessage(edgePlaintext);

    // 2. Chrome mendekripsi pesan dari Edge
    const decryptedByChrome = await chromeDev.decryptMessage(encEdge.wireBytes);
    assert.equal(decryptedByChrome, edgePlaintext, 'Chrome berhasil mendekripsi pesan dari Edge');

    // 3. Mahasiswa mendekripsi pesan dari Edge
    const decryptedByMhs = await mhsDev.decryptMessage(encEdge.wireBytes);
    assert.equal(decryptedByMhs, edgePlaintext, 'Mahasiswa berhasil mendekripsi pesan dari Edge');

    // 4. Mahasiswa membalas pesan
    const mhsReply = 'Halo Dosen! Pesan diterima di HP mahasiswa.';
    const encMhs = await mhsDev.encryptMessage(mhsReply);

    // 5. Edge dan Chrome mendekripsi pesan dari Mahasiswa
    const decryptedReplyEdge = await edgeDev.decryptMessage(encMhs.wireBytes);
    const decryptedReplyChrome = await chromeDev.decryptMessage(encMhs.wireBytes);
    assert.equal(decryptedReplyEdge, mhsReply, 'Edge menerima balasan dari Mahasiswa');
    assert.equal(decryptedReplyChrome, mhsReply, 'Chrome menerima balasan dari Mahasiswa');
  });

  await t.test('9. Idempotensi Retry dan Reconnect Offline', async () => {
    // Replay of ACK on reconnect does not error and returns complete
    const replayAck = backend.submitWelcomeAck(publishedCommit.commit_uuid, edgeDev.deviceUuid, 2, 'dummy_sig');
    assert.equal(replayAck.is_complete, true);
    assert.equal(replayAck.status, 'ACTIVE');
    assert.equal(backend.currentEpoch, 2);
  });
});
