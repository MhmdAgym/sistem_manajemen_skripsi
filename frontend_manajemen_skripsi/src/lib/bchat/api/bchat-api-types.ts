/**
 * B-CHAT E2EE Protocol Layer — API Type Definitions (I-01)
 *
 * Strict TypeScript types for client-to-backend communication:
 * - Device Registration & Step-up Authentication Plane
 * - KeyPackage Deposit & Fetch Plane
 * - Room Discovery & Handshake State Plane
 * - Welcome Publication & Canonical Welcome ACK Handshake Plane
 *
 * Zero usage of `any`. Strictly aligned with Laravel 11 backend schemas.
 */

// -----------------------------------------------------------------------------
// 1. GENERIC API RESPONSE CONTRACTS
// -----------------------------------------------------------------------------

export interface BchatSuccessResponse<T> {
    status: 'success'
    message: string
    data: T
}

export interface BchatErrorResponse {
    status: 'error'
    message: string
    error_code?: string
    errors?: Record<string, string[]>
}

export type BchatApiResponse<T> = BchatSuccessResponse<T> | BchatErrorResponse

// -----------------------------------------------------------------------------
// 2. DEVICE STEP-UP AUTHENTICATION (POST /api/bchat/devices/step-up)
// -----------------------------------------------------------------------------

export interface BchatStepUpRequest {
    password: string
    device_uuid: string
}

export interface BchatStepUpResponseData {
    user_id: string
    step_up_token: string
    device_uuid: string
    action_scope: string
    expires_at: string
}

// -----------------------------------------------------------------------------
// 3. REGISTRATION NONCE ISSUANCE (POST /api/bchat/devices/registration-nonce)
// -----------------------------------------------------------------------------

export interface BchatRegistrationNonceRequest {
    step_up_token: string
    device_uuid: string
    public_key: string // 64-character lowercase hex (32-byte Ed25519)
}

export interface BchatRegistrationNonceResponseData {
    nonce_uuid: string
    registration_nonce: string // 64-character hex CSPRNG nonce
    device_uuid: string
    public_key_fingerprint: string // 64-character hex SHA-256 fingerprint
    issued_at: string
    expires_at: string
}

// -----------------------------------------------------------------------------
// 4. DEVICE REGISTRATION WITH POP (POST /api/bchat/devices/register)
// -----------------------------------------------------------------------------

export interface BchatDeviceRegisterRequest {
    nonce_uuid: string
    pop_payload: string // 242-character hex (121 bytes canonical PoP payload)
    pop_signature: string // 128-character hex (64 bytes detached Ed25519 signature)
    device_name?: string | null
}

export interface BchatDeviceRegisterResponseData {
    device_uuid: string
    device_name: string
    key_version: number
    status: string // e.g. 'ACTIVE'
    public_key: string // 64-character hex
    registered_at: string
    is_replacement: boolean
}

// -----------------------------------------------------------------------------
// 5. KEYPACKAGE DEPOSIT (POST /api/bchat/key-packages)
// -----------------------------------------------------------------------------

export interface BchatKeyPackageUploadItem {
    key_package_hash: string // 64-character lowercase hex (SHA-256)
    key_package_data: string // Canonical Base64-encoded binary KeyPackage (RFC 4648)
}

export interface BchatKeyPackageUploadRequest {
    device_uuid: string
    packages: BchatKeyPackageUploadItem[]
}

export interface BchatKeyPackageUploadResponseData {
    uploaded_count: number
    device_uuid: string
    active_pool_count: number
    remaining_active_packages: number
}

// -----------------------------------------------------------------------------
// 6. ROOM DISCOVERY & SUMMARY (GET /api/bchat/rooms)
// -----------------------------------------------------------------------------

export type BchatRoomStatus =
    | 'PENDING_OFFICIAL_SK'
    | 'PENDING_WELCOME_ACK'
    | 'ACTIVE'
    | 'SUSPENDED'
    | 'TERMINATED'
    | 'ARCHIVED_READONLY'

export type BchatActionType =
    | 'NONE'
    | 'PUBLISH_WELCOME'
    | 'AWAIT_PARTNER_WELCOME'
    | 'FETCH_CHALLENGE_AND_ACK'
    | 'AWAIT_PARTNER_ACK'

export interface BchatActionHint {
    action_required: boolean
    action_type: BchatActionType
}

export interface BchatPartnerSummary {
    name: string
    role: string | null
    has_active_device: boolean
}

export interface BchatAssignmentSummary {
    status_penugasan: string | null
    judul_skripsi: string
}

export interface BchatRoomSummary {
    room_uuid: string
    status: BchatRoomStatus | string
    tahap: string
    pembimbing_ke: number
    current_epoch: string // Formatted as decimal string from backend
    my_role: string | null
    is_active: boolean
    is_initiator: boolean
    partner: BchatPartnerSummary
    assignment: BchatAssignmentSummary
    action_hint: BchatActionHint
    created_at?: string | null
    updated_at?: string | null
}

// -----------------------------------------------------------------------------
// 7. ROOM DETAIL (GET /api/bchat/rooms/{roomUuid})
// -----------------------------------------------------------------------------

export interface BchatMyMembership {
    role: string | null
    is_initiator: boolean
    is_active: boolean
    joined_at_epoch: number
}

export interface BchatPartnerDetail {
    name: string
    role: string | null
    has_active_device: boolean
    is_active: boolean
}

export interface BchatAssignmentDetail {
    status_penugasan: string | null
    judul_skripsi: string
    tanggal_mulai?: string | null
}

export interface BchatHandshakeContext {
    has_active_challenge: boolean
    welcome_operation_uuid: string | null
    epoch: string | null
    expires_at?: string | null
    action_required: boolean
    action_type: BchatActionType
}

export interface BchatRoomCapabilities {
    can_send_messages: boolean
    can_read_messages: boolean
    can_handshake: boolean
}

export interface BchatRoomDetail {
    room_uuid: string
    status: BchatRoomStatus | string
    tahap: string
    pembimbing_ke: number
    current_epoch: string
    group_id_hash: string | null
    my_membership: BchatMyMembership
    partner: BchatPartnerDetail
    assignment: BchatAssignmentDetail
    action_hint: BchatActionHint
    handshake_context: BchatHandshakeContext
    capabilities: BchatRoomCapabilities
    created_at?: string | null
    updated_at?: string | null
}

// -----------------------------------------------------------------------------
// 8. PARTNER KEYPACKAGE FETCH (GET /api/bchat/rooms/{roomUuid}/partner-key-package)
// -----------------------------------------------------------------------------

export interface BchatPartnerKeyPackageResponseData {
    recipient_user_id: string
    recipient_device_uuid: string
    key_package_hash: string // 64-character lowercase hex
    key_package_data: string // Canonical Base64-encoded binary KeyPackage
}

// -----------------------------------------------------------------------------
// 9. WELCOME PUBLICATION (POST /api/bchat/rooms/{roomUuid}/welcome)
// -----------------------------------------------------------------------------

export interface BchatWelcomePublishRequest {
    recipient_device_uuid: string
    key_package_hash: string // 64-character hex
    group_id_hash: string // 64-character hex
    epoch: 1 | '1' // First welcome is strictly epoch 1
    welcome_message: string // Canonical Base64-encoded encrypted MLS Welcome message
}

export interface BchatWelcomePublishResponseData {
    status: string // 'success'
    welcome_operation_uuid: string
    room_uuid: string
    recipient_user_id: string
    recipient_device_uuid: string
    epoch: string // '1'
    challenge_generation: number
    created_at: string
}

// -----------------------------------------------------------------------------
// 10. WELCOME CHALLENGE (POST /api/bchat/rooms/{roomUuid}/welcome-challenge)
// -----------------------------------------------------------------------------

export interface BchatWelcomeChallengeResponseData {
    status: string // 'success'
    welcome_operation_uuid: string
    room_uuid: string
    welcome_message: string // Canonical Base64-encoded encrypted MLS Welcome message
    group_id_hash: string
    epoch: string
    challenge_uuid: string
    challenge_nonce: string // 64-character hex
    challenge_generation: number
    expires_at: string
    ttl_remaining_seconds: number
}

// -----------------------------------------------------------------------------
// 11. WELCOME ACK (POST /api/bchat/rooms/{roomUuid}/welcome-ack)
// -----------------------------------------------------------------------------

export interface BchatWelcomeAckRequest {
    challenge_uuid: string
    signature: string // 88-character canonical Base64 detached Ed25519 signature
}

export interface BchatWelcomeAckResponseData {
    status: string // 'success'
    room_status: string // 'ACTIVE'
    current_epoch: string // '1'
    activated_at: string
    room_uuid: string
    welcome_operation_uuid: string
    challenge_uuid: string
    ack_payload_hash: string
}

// -----------------------------------------------------------------------------
// 12. APPLICATION MESSAGE TRANSPORT (POST & GET /api/bchat/rooms/{roomUuid}/messages)
// -----------------------------------------------------------------------------

export interface BchatSendMessageRequest {
    message_uuid: string // UUID v4 format
    epoch: number // Integer >= 1
    generation: number // Integer >= 0
    content_type: number // 1: TEXT, 2: ATTACHMENT_MANIFEST, 3: CONTROL_RECEIPT
    ciphertext_payload: string // Canonical Base64 encoded binary MLS ciphertext
    payload_hash_sha256: string // 64-character lowercase hex SHA-256 of raw ciphertext
    client_sent_at: string // ISO-8601 date string
}

export interface BchatMessageData {
    server_sequence: number
    message_uuid: string
    sender_id: number
    epoch: number
    generation: number
    content_type: number
    ciphertext_payload: string
    payload_hash_sha256: string
    client_sent_at: string | null
    created_at: string | null
}

export type BchatSendMessageResponse = BchatSuccessResponse<BchatMessageData>

export interface BchatFetchMessagesQuery {
    after_sequence?: number
    limit?: number
}

export type BchatFetchMessagesResponse = BchatSuccessResponse<BchatMessageData[]>

// -----------------------------------------------------------------------------
// 13. DEVICE STATUS (GET /api/bchat/devices/status) — Tahap A
// -----------------------------------------------------------------------------

export interface BchatDeviceStatusQuery {
    device_uuid?: string
}

export interface BchatActiveDeviceInfo {
    device_uuid: string
    device_name: string
    status: string        // 'ACTIVE'
    key_version: number
    registered_at: string
}

export interface BchatQueriedDeviceInfo {
    device_uuid: string
    is_active: boolean    // true jika UUID yang di-query sudah ACTIVE di backend
}

export interface BchatDeviceStatusData {
    has_active_device: boolean
    active_device: BchatActiveDeviceInfo | null
    queried_device: BchatQueriedDeviceInfo | null
}

// -----------------------------------------------------------------------------
// 14. DEVICE REGISTER WITH REPLACEMENT PROTECTION — Tahap A extension
// -----------------------------------------------------------------------------

export interface BchatDeviceRegisterWithReplacementRequest extends BchatDeviceRegisterRequest {
    confirm_replacement?: boolean
    expected_active_device_uuid?: string
}


