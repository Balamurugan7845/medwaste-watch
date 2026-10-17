/**
 * MedWaste Watch — Centralized API Client
 * Wraps Fetch API, manages CSRF headers, credential cookies, and error handling.
 */
class ApiClient {
    static csrfToken = null;

    static setCsrfToken(token) {
        this.csrfToken = token;
    }

    static getCsrfToken() {
        return this.csrfToken;
    }

    static async request(endpoint, options = {}) {
        const url = endpoint.startsWith('http') ? endpoint : `${AppConfig.API_BASE}${endpoint}`;

        const headers = {
            'Accept': 'application/json',
            ...(options.headers || {})
        };

        // Attach CSRF token on mutation requests
        const method = (options.method || 'GET').toUpperCase();
        if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && this.csrfToken) {
            headers['X-CSRF-Token'] = this.csrfToken;
        }

        // Set Content-Type for JSON payloads if body is provided and not FormData
        if (options.body && !(options.body instanceof FormData) && !headers['Content-Type']) {
            headers['Content-Type'] = 'application/json';
            if (typeof options.body === 'object') {
                options.body = JSON.stringify(options.body);
            }
        }

        const config = {
            ...options,
            method,
            headers,
            credentials: 'include' // Send and receive session cookies
        };

        try {
            const response = await fetch(url, config);

            // Handle unauthorized redirection
            if (response.status === 401) {
                if (!window.location.pathname.endsWith('login.html')) {
                    sessionStorage.setItem('medwaste_redirect', window.location.pathname + window.location.search);
                    window.location.href = '/login.html';
                }
            }

            // Handle CSV or file downloads
            const contentType = response.headers.get('Content-Type') || '';
            if (contentType.includes('text/csv')) {
                return await response.blob();
            }

            const data = await response.json();

            // Refresh CSRF token if returned in response
            if (data && data.data && data.data.csrf_token) {
                this.setCsrfToken(data.data.csrf_token);
            }

            if (!response.ok || data.success === false) {
                const errorMsg = data.message || `Request failed with status ${response.status}`;
                const err = new Error(errorMsg);
                err.status = response.status;
                err.errors = data.errors || null;
                throw err;
            }

            return data;
        } catch (err) {
            console.error(`[API Error] ${method} ${endpoint}:`, err);
            throw err;
        }
    }

    static get(endpoint, params = {}) {
        const query = new URLSearchParams();
        Object.entries(params).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') {
                query.append(k, v);
            }
        });
        const qs = query.toString();
        const url = qs ? `${endpoint}?${qs}` : endpoint;
        return this.request(url, { method: 'GET' });
    }

    static post(endpoint, body = {}) {
        return this.request(endpoint, { method: 'POST', body });
    }

    static put(endpoint, body = {}) {
        return this.request(endpoint, { method: 'PUT', body });
    }

    static patch(endpoint, body = {}) {
        return this.request(endpoint, { method: 'PATCH', body });
    }

    static delete(endpoint) {
        return this.request(endpoint, { method: 'DELETE' });
    }
}

window.API = ApiClient;
