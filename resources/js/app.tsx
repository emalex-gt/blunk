import '../css/app.css';
import './bootstrap';

import { setCsrfToken } from '@/bootstrap';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'BlunkStock';

function syncPageSessionState(props: Record<string, unknown>) {
    const csrfToken = props.csrf_token;

    if (typeof csrfToken === 'string' && csrfToken.length > 0) {
        setCsrfToken(csrfToken);
    }

    window.clearSessionExpired?.();
}

router.on('success', (event) => {
    syncPageSessionState(event.detail.page.props);
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        syncPageSessionState(props.initialPage.props);
        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
