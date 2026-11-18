/**
 * B-CHAT E2EE Protocol Layer — API Client (I-01)
 *
 * Implements typed API methods communicating with Laravel 11 backend:
 * - Device Registration & Step-up Authentication
 * - KeyPackage Batch Upload & Partner Fetch
 * - Room Discovery & Handshake Metadata
 * - Welcome Publication & Welcome ACK
 *
 * Security & Reliability Policies:
 * - Uses existing Sanctum session cookie credentials via `@/lib/axios`.
 * - Zero automatic retries on state-mutating requests.
 * - Zero leakage of passwords, private keys, or tokens in logs/errors.
 * - Standardized error transformation via `BchatApiError`.
 */

import axios from '@/lib/axios'
import type {
    BchatSuccessResponse,
    BchatStepUpRequest,
    BchatStepUpResponseData,
    BchatRegistrationNonceRequest,
    BchatRegistrationNonceResponseData,
    BchatDeviceRegisterRequest,
    BchatDeviceRegisterResponseData,
    BchatDeviceRegisterWithReplacementRequest,
    BchatDeviceRevokeResponseData,
    BchatDeviceStatusQuery,
    BchatDeviceStatusData,
    BchatKeyPackageUploadRequest,
    BchatKeyPackageUploadResponseData,
    BchatRoomSummary,
    BchatRoomDetail,
    BchatPartnerKeyPackageResponseData,
    BchatWelcomePublishRequest,
    BchatWelcomePublishResponseData,
    BchatWelcomeChallengeResponseData,
    BchatWelcomeAckRequest,
    BchatWelcomeAckResponseData,
    BchatSendMessageRequest,
    BchatMessageData,
    BchatFetchMessagesQuery,
} from './bchat-api-types'

// -----------------------------------------------------------------------------
// STANDARDIZED B-CHAT API ERROR
// -----------------------------------------------------------------------------

export class BchatApiError extends Error {
    readonly isBchatApiError = true
    readonly status: number
    readonly errorCode?: string
    readonly errors?: Record<string, string[]>

    constructor(
        message: string,
        status = 0,
        errorCode?: string,
        errors?: Record<string, string[]>
    ) {
        super(message)
        this.name = 'BchatApiError'
        this.status = status
        this.errorCode = errorCode
        this.errors = errors

        // Restore prototype chain for instanceof checks across transpile targets
        Object.setPrototypeOf(this, BchatApiError.prototype)
    }
}

/**
 * Transforms an Axios or unknown error into a structured `BchatApiError`.
 * Prevents leaking raw request payloads containing passwords or cryptographic material.
 */
export function parseBchatApiError(error: unknown): BchatApiError {
    if (error instanceof BchatApiError) {
        return error
    }

    if (error && typeof error === 'object' && 'response' in error) {
        const axiosErr = error as {
            response?: {
                status?: number
                data?: {
                    status?: string
                    message?: string
                    error_code?: string
                    errors?: Record<string, string[]>
                }
            }
            message?: string
        }

        const httpStatus = axiosErr.response?.status ?? 0
        const resData = axiosErr.response?.data
        const message =
            resData?.message ||
            axiosErr.message ||
            'Terjadi kesalahan pada permintaan API B-CHAT.'
        const errorCode = resData?.error_code
        const errors = resData?.errors

        return new BchatApiError(message, httpStatus, errorCode, errors)
    }

    if (error instanceof Error) {
        return new BchatApiError(error.message, 0)
    }

    return new BchatApiError('Terjadi kesalahan jaringan atau sistem internal.', 0)
}

// -----------------------------------------------------------------------------
// API CLIENT IMPLEMENTATION
// -----------------------------------------------------------------------------

/**
 * 1. Step-up password re-authentication for device registration.
 * Endpoint: POST /api/bchat/devices/step-up
 */
export async function stepUpDevice(
    payload: BchatStepUpRequest
): Promise<BchatSuccessResponse<BchatStepUpResponseData>> {
    try {
        const res = await axios.post<BchatSuccessResponse<BchatStepUpResponseData>>(
            '/api/bchat/devices/step-up',
            payload
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

/**
 * 2. Issue a single-use registration challenge nonce.
 * Endpoint: POST /api/bchat/devices/registration-nonce
 */
export async function issueRegistrationNonce(
    payload: BchatRegistrationNonceRequest
): Promise<BchatSuccessResponse<BchatRegistrationNonceResponseData>> {
    try {
        const res = await axios.post<
            BchatSuccessResponse<BchatRegistrationNonceResponseData>
        >(
            '/api/bchat/devices/registration-nonce',
            payload
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

/**
 * 3. Register a new device with Proof-of-Possession (PoP) signature.
 * Supports Replacement Protection fields (confirm_replacement, expected_active_device_uuid).
 * Endpoint: POST /api/bchat/devices/register
 */
export async function registerDevice(
    payload: BchatDeviceRegisterRequest | BchatDeviceRegisterWithReplacementRequest
): Promise<BchatSuccessResponse<BchatDeviceRegisterResponseData>> {
    try {
        const res = await axios.post<
            BchatSuccessResponse<BchatDeviceRegisterResponseData>
        >(
            '/api/bchat/devices/register',
            payload
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

/**
 * 3b. Get active device status for the authenticated user.
 * Optionally pass device_uuid to reconcile a staged key.
 * Endpoint: GET /api/bchat/devices/status
 */
export async function getDeviceStatus(
    query?: BchatDeviceStatusQuery
): Promise<BchatSuccessResponse<BchatDeviceStatusData>> {
    try {
        const res = await axios.get<BchatSuccessResponse<BchatDeviceStatusData>>(
            '/api/bchat/devices/status',
            { params: query }
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

/**
 * Endpoint: POST /api/bchat/devices/{deviceUuid}/revoke
 * Mencabut status aktif satu perangkat spesifik milik pengguna dengan verifikasi kata sandi akun (Tahap 1B).
 */
export async function revokeDevice(
    deviceUuid: string,
    options?: { reason?: string; password?: string; stepUpToken?: string } | string
): Promise<BchatSuccessResponse<BchatDeviceRevokeResponseData>> {
    try {
        const payload = typeof options === 'string'
            ? { reason: options }
            : {
                reason: options?.reason,
                password: options?.password,
                step_up_token: options?.stepUpToken,
            }
        const res = await axios.post<BchatSuccessResponse<BchatDeviceRevokeResponseData>>(
            `/api/bchat/devices/${encodeURIComponent(deviceUuid)}/revoke`,
            payload
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

/**
 * 4. Upload a batch of MLS KeyPackages for the active device.
 * Endpoint: POST /api/bchat/key-packages
 */
export async function uploadKeyPackages(
    payload: BchatKeyPackageUploadRequest
): Promise<BchatSuccessResponse<BchatKeyPackageUploadResponseData>> {
    try {
        const res = await axios.post<
            BchatSuccessResponse<BchatKeyPackageUploadResponseData>
        >(
            '/api/bchat/key-packages',
            payload
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

/**
 * 5. Discover authorized pairwise chat rooms.
 * Endpoint: GET /api/bchat/rooms
 */
export async function fetchRooms(): Promise<
    BchatSuccessResponse<BchatRoomSummary[]>
> {
    try {
        const res = await axios.get<BchatSuccessResponse<BchatRoomSummary[]>>(
            '/api/bchat/rooms'
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

/**
 * 6. Get room detail metadata and handshake context.
 * Endpoint: GET /api/bchat/rooms/{roomUuid}
 */
export async function fetchRoomDetail(
    roomUuid: string
): Promise<BchatSuccessResponse<BchatRoomDetail>> {
    try {
        const res = await axios.get<BchatSuccessResponse<BchatRoomDetail>>(
            `/api/bchat/rooms/${encodeURIComponent(roomUuid)}`
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

/**
 * 7. Fetch partner's oldest unconsumed KeyPackage.
 * Endpoint: GET /api/bchat/rooms/{roomUuid}/partner-key-package
 */
export async function fetchPartnerKeyPackage(
    roomUuid: string
): Promise<BchatSuccessResponse<BchatPartnerKeyPackageResponseData>> {
    try {
        const res = await axios.get<
            BchatSuccessResponse<BchatPartnerKeyPackageResponseData>
        >(
            `/api/bchat/rooms/${encodeURIComponent(roomUuid)}/partner-key-package`
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

/**
 * 8. Publish MLS Welcome message (initiator).
 * Endpoint: POST /api/bchat/rooms/{roomUuid}/welcome
 */
export async function publishWelcome(
    roomUuid: string,
    payload: BchatWelcomePublishRequest
): Promise<BchatSuccessResponse<BchatWelcomePublishResponseData>> {
    try {
        const res = await axios.post<
            BchatSuccessResponse<BchatWelcomePublishResponseData>
        >(
            `/api/bchat/rooms/${encodeURIComponent(roomUuid)}/welcome`,
            payload
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

/**
 * 9. Fetch active Welcome challenge or rotate in-place if expired.
 * Endpoint: POST /api/bchat/rooms/{roomUuid}/welcome-challenge
 */
export async function fetchOrRotateWelcomeChallenge(
    roomUuid: string
): Promise<BchatSuccessResponse<BchatWelcomeChallengeResponseData>> {
    try {
        const res = await axios.post<
            BchatSuccessResponse<BchatWelcomeChallengeResponseData>
        >(
            `/api/bchat/rooms/${encodeURIComponent(roomUuid)}/welcome-challenge`,
            {}
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

/**
 * 10. Submit Canonical Welcome ACK signature for room activation.
 * Endpoint: POST /api/bchat/rooms/{roomUuid}/welcome-ack
 */
export async function submitWelcomeAck(
    roomUuid: string,
    payload: BchatWelcomeAckRequest
): Promise<BchatSuccessResponse<BchatWelcomeAckResponseData>> {
    try {
        const res = await axios.post<
            BchatSuccessResponse<BchatWelcomeAckResponseData>
        >(
            `/api/bchat/rooms/${encodeURIComponent(roomUuid)}/welcome-ack`,
            payload
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

/**
 * 11. Send E2EE Application Message (Ciphertext)
 * Endpoint: POST /api/bchat/rooms/{roomUuid}/messages
 */
export async function sendMessage(
    roomUuid: string,
    payload: BchatSendMessageRequest
): Promise<BchatSuccessResponse<BchatMessageData>> {
    try {
        const res = await axios.post<BchatSuccessResponse<BchatMessageData>>(
            `/api/bchat/rooms/${encodeURIComponent(roomUuid)}/messages`,
            payload
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

/**
 * 12. Fetch E2EE Application Messages (Ciphertext) with cursor pagination
 * Endpoint: GET /api/bchat/rooms/{roomUuid}/messages
 */
export async function fetchMessages(
    roomUuid: string,
    query?: BchatFetchMessagesQuery
): Promise<BchatSuccessResponse<BchatMessageData[]>> {
    try {
        const res = await axios.get<BchatSuccessResponse<BchatMessageData[]>>(
            `/api/bchat/rooms/${encodeURIComponent(roomUuid)}/messages`,
            {
                params: query,
            }
        )
        return res.data
    } catch (err) {
        throw parseBchatApiError(err)
    }
}

// -----------------------------------------------------------------------------
// UNIFIED CLIENT EXPORT
// -----------------------------------------------------------------------------

export const bchatApiClient = {
    stepUpDevice,
    issueRegistrationNonce,
    registerDevice,
    getDeviceStatus,
    revokeDevice,
    uploadKeyPackages,
    fetchRooms,
    fetchRoomDetail,
    fetchPartnerKeyPackage,
    publishWelcome,
    fetchOrRotateWelcomeChallenge,
    submitWelcomeAck,
    sendMessage,
    fetchMessages,
}

export default bchatApiClient

