import Axios from 'axios'

const backendUrl = process.env.NEXT_PUBLIC_BACKEND_URL

const ALLOWED_TEST_ORIGINS = new Set([
    'http://localhost:8001',
    'http://127.0.0.1:8001',
])

function isTestHarnessActive() {
    if (process.env.NEXT_PUBLIC_IS_TEST_HARNESS === 'true') {
        return true
    }
    if (typeof window !== 'undefined' && window.location.port === '3001') {
        return true
    }
    return false
}

function resolveEffectiveOrigin(url, baseURL) {
    try {
        if (url) {
            if (/^[a-z]+:\/\//i.test(url)) {
                return new URL(url).origin
            }
            if (url.startsWith('//')) {
                return new URL(`http:${url}`).origin
            }
        }
        if (baseURL) {
            if (/^[a-z]+:\/\//i.test(baseURL)) {
                return new URL(baseURL).origin
            }
            if (baseURL.startsWith('//')) {
                return new URL(`http:${baseURL}`).origin
            }
        }
        return null
    } catch {
        return null
    }
}

const axios = Axios.create({
    baseURL: backendUrl,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
    withXSRFToken: true,
})

// Request guard interceptor (active in both SSR and Browser)
axios.interceptors.request.use(
    config => {
        if (isTestHarnessActive()) {
            const effectiveOrigin = resolveEffectiveOrigin(config.url, config.baseURL)

            if (!effectiveOrigin || !ALLOWED_TEST_ORIGINS.has(effectiveOrigin)) {
                const targetDesc = effectiveOrigin || 'EMPTY/INVALID_TARGET'
                const errorMsg = `[SECURITY BLOCKED] Test harness request blocked: Target origin '${targetDesc}' is not in allowed testing origins (http://localhost:8001, http://127.0.0.1:8001). Request URL: '${config.url}', BaseURL: '${config.baseURL}'.`
                console.error(errorMsg)
                return Promise.reject(new Error(errorMsg))
            }
        }
        return config
    },
    error => Promise.reject(error),
)

export default axios

