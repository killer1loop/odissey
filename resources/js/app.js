import htmx from 'htmx.org';
import './iptv-player';
import './media-player';

window.htmx = htmx;

htmx.config.allowEval = false;
htmx.config.allowScriptTags = false;
htmx.config.selfRequestsOnly = true;

let visibleRequests = 0;

function isBackgroundRequest(event) {
    return Boolean(event.detail.elt?.closest?.('[data-background-request]'));
}

document.addEventListener('htmx:configRequest', (event) => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    if (csrfToken) {
        event.detail.headers['X-CSRF-TOKEN'] = csrfToken;
    }
});

document.addEventListener('htmx:beforeRequest', (event) => {
    if (isBackgroundRequest(event)) {
        return;
    }

    visibleRequests += 1;
    document.documentElement.dataset.loading = 'true';
});

document.addEventListener('htmx:afterRequest', (event) => {
    if (isBackgroundRequest(event)) {
        return;
    }

    visibleRequests = Math.max(0, visibleRequests - 1);

    if (visibleRequests === 0) {
        delete document.documentElement.dataset.loading;
    }
});

document.addEventListener('htmx:responseError', () => {
    const announcer = document.querySelector('[data-request-announcer]');

    if (announcer) {
        announcer.textContent = 'The request could not be completed. Please try again.';
    }
});
