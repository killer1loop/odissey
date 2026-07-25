//
import htmx from 'htmx.org';

window.htmx = htmx;

htmx.config.allowEval = false;
htmx.config.allowScriptTags = false;
htmx.config.selfRequestsOnly = true;

document.addEventListener('htmx:configRequest', (event) => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    if (csrfToken) {
        event.detail.headers['X-CSRF-TOKEN'] = csrfToken;
    }
});

document.addEventListener('htmx:beforeRequest', () => {
    document.documentElement.dataset.loading = 'true';
});

document.addEventListener('htmx:afterRequest', () => {
    delete document.documentElement.dataset.loading;
});

document.addEventListener('htmx:responseError', () => {
    const announcer = document.querySelector('[data-request-announcer]');

    if (announcer) {
        announcer.textContent = 'The request could not be completed. Please try again.';
    }
});
