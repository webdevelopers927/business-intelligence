/**
 * Minimal JSON client for ORBIT's same-origin JSON endpoints (widget data,
 * notifications, import progress). Inertia handles page navigation; this is
 * only for data fetched into an already-rendered page.
 *
 * Response envelope: { data, meta? } on success; { message, errors?, code? } on error.
 */

export class HttpError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match?.[1] ? decodeURIComponent(match[1]) : null;
}

async function request<T>(method: string, url: string, body?: unknown, signal?: AbortSignal): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = xsrfToken();
    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: body === undefined ? null : JSON.stringify(body),
        signal: signal ?? null,
    });

    const payload: unknown = response.headers.get('content-type')?.includes('application/json') ? await response.json() : null;

    if (!response.ok) {
        const err = (payload ?? {}) as { message?: string; errors?: Record<string, string[]> };
        throw new HttpError(response.status, err.message ?? `Request failed (${response.status})`, err.errors ?? {});
    }

    return payload as T;
}

export const http = {
    get: <T>(url: string, signal?: AbortSignal) => request<T>('GET', url, undefined, signal),
    post: <T>(url: string, body: unknown = {}) => request<T>('POST', url, body),
    put: <T>(url: string, body: unknown = {}) => request<T>('PUT', url, body),
};
