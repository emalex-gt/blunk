import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

function csrfMeta(): HTMLMetaElement | null {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
}

function setCsrfToken(token: string) {
    const meta = csrfMeta();

    if (meta) {
        meta.content = token;
    }

    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
}

const initialCsrfToken = csrfMeta()?.content;

if (initialCsrfToken) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = initialCsrfToken;
}

window.dispatchSessionExpired = (detail = {}) => {
    window.dispatchEvent(new CustomEvent('blunk:session-expired', { detail }));
};

window.clearSessionExpired = () => {
    window.dispatchEvent(new CustomEvent('blunk:session-restored'));
};

window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error?.response?.status === 419) {
            window.dispatchSessionExpired?.({
                isPos: window.location.pathname.includes('/sales/create'),
                source: 'axios',
            });
        }

        return Promise.reject(error);
    },
);

const originalFetch = window.fetch.bind(window);

function isKeepAliveRequest(input: RequestInfo | URL): boolean {
    const value = typeof input === 'string'
        ? input
        : input instanceof URL
            ? input.href
            : input.url;

    return new URL(value, window.location.origin).pathname.endsWith('/session/keep-alive');
}

window.fetch = async (...args) => {
    const response = await originalFetch(...args);

    if (response.status === 419 && !isKeepAliveRequest(args[0])) {
        window.dispatchSessionExpired?.({
            isPos: window.location.pathname.includes('/sales/create'),
            source: 'fetch',
        });
    }

    return response;
};

export { setCsrfToken };
