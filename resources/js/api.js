// One small wrapper around fetch for every call to our own API. It adds the owner's key, and
// turns any failure into an ApiError that carries a message safe to show on the page.

export class ApiError extends Error {
    constructor(status, message, errors = {}) {
        super(message);
        this.status = status;
        // Field-level messages from validation, for example { 'items.0.name': ['The name is required.'] }
        this.errors = errors;
    }

    /** Every validation message as one flat list. */
    get messages() {
        const all = Object.values(this.errors).flat();
        return all.length ? all : [this.message];
    }
}

export async function api(path, { method = 'GET', token, json, form } = {}) {
    const headers = { Accept: 'application/json' };
    if (token) headers.Authorization = `Bearer ${token}`;

    let body;
    if (json !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(json);
    } else if (form) {
        body = form; // the browser sets the multipart boundary itself
    }

    let response;
    try {
        response = await fetch(`/api${path}`, { method, headers, body });
    } catch {
        throw new ApiError(0, 'Cannot reach the server. Check your connection and try again.');
    }

    if (response.status === 204) return null;

    const data = await response.json().catch(() => null);
    if (!response.ok) {
        throw new ApiError(response.status, data?.message ?? 'Something went wrong. Please try again.', data?.errors ?? {});
    }

    return data;
}
