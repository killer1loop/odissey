import htmx from 'htmx.org';
import './epg-guide';
import './iptv-player';
import './media-player';
import './tv-navigation';

window.htmx = htmx;

htmx.config.allowEval = false;
htmx.config.allowScriptTags = false;
htmx.config.selfRequestsOnly = true;

let visibleRequests = 0;

function synchronizePageBodyClass() {
    const pageBodyClass = document.querySelector('[data-page-body-class]');

    if (! pageBodyClass) {
        return;
    }

    document.body.className = pageBodyClass.dataset.pageBodyClass?.trim() || '';
}

function isBackgroundRequest(event) {
    return Boolean(event.detail.elt?.closest?.('[data-background-request]'));
}

function conditionalForms(root, selector) {
    const forms = [];

    if (root.matches?.(selector)) {
        forms.push(root);
    }

    root.querySelectorAll?.(selector).forEach((form) => forms.push(form));

    return forms;
}

function initializeConditionalForm(root, {
    formSelector,
    selectSelector,
    sectionSelector,
    sectionAttribute,
}) {
    conditionalForms(root, formSelector).forEach((form) => {
        const select = form.querySelector(selectSelector);
        const sections = [...form.querySelectorAll(sectionSelector)];

        if (! select || sections.length === 0) {
            return;
        }

        const synchronize = () => {
            sections.forEach((section) => {
                const active = section.getAttribute(sectionAttribute) === select.value;

                section.hidden = ! active;
                section.setAttribute('aria-hidden', active ? 'false' : 'true');
                section.querySelectorAll('input, select, textarea, button').forEach((control) => {
                    control.disabled = ! active;
                });
            });
        };

        if (select.dataset.conditionalFormBound !== 'true') {
            select.dataset.conditionalFormBound = 'true';
            select.addEventListener('change', synchronize);
        }

        synchronize();
    });
}

function initializeConditionalForms(root = document) {
    initializeConditionalForm(root, {
        formSelector: '[data-source-config]',
        selectSelector: '[data-source-type]',
        sectionSelector: '[data-source-fields]',
        sectionAttribute: 'data-source-fields',
    });
    initializeConditionalForm(root, {
        formSelector: '[data-provider-config]',
        selectSelector: '[data-provider-type]',
        sectionSelector: '[data-provider-fields]',
        sectionAttribute: 'data-provider-fields',
    });
}

function mediaArtworkImages(root = document) {
    const images = [];

    if (root.matches?.('[data-media-artwork]')) {
        images.push(root);
    }

    root.querySelectorAll?.('[data-media-artwork]').forEach((image) => images.push(image));

    return images;
}

function showMediaArtworkFallback(image) {
    image.hidden = true;
    const fallback = image.parentElement?.querySelector('[data-media-artwork-fallback]');

    if (fallback) {
        fallback.hidden = false;
    }
}

function initializeMediaArtwork(root = document) {
    mediaArtworkImages(root).forEach((image) => {
        if (image.dataset.mediaArtworkBound !== 'true') {
            image.dataset.mediaArtworkBound = 'true';
            image.addEventListener('error', () => showMediaArtworkFallback(image));
        }

        if (image.complete && image.naturalWidth === 0) {
            showMediaArtworkFallback(image);
        }
    });
}

function mobileMenus(root = document) {
    const menus = [];

    if (root.matches?.('.mobile-menu')) {
        menus.push(root);
    }

    root.querySelectorAll?.('.mobile-menu').forEach((menu) => menus.push(menu));

    return menus;
}

function synchronizeMobileMenu(menu) {
    const summary = menu.querySelector('summary');
    const open = menu.open;

    summary?.setAttribute('aria-expanded', open ? 'true' : 'false');
    summary?.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
    document.documentElement.classList.toggle(
        'mobile-menu-open',
        Boolean(document.querySelector('.mobile-menu[open]')),
    );
}

function closeMobileMenu(menu, { restoreFocus = false } = {}) {
    if (! menu?.open) {
        return;
    }

    menu.open = false;
    synchronizeMobileMenu(menu);

    if (restoreFocus) {
        menu.querySelector('summary')?.focus();
    }
}

function initializeMobileMenus(root = document) {
    mobileMenus(root).forEach((menu) => {
        if (menu.dataset.mobileMenuBound === 'true') {
            synchronizeMobileMenu(menu);

            return;
        }

        menu.dataset.mobileMenuBound = 'true';
        menu.addEventListener('toggle', () => synchronizeMobileMenu(menu));
        menu.addEventListener('click', (event) => {
            if (event.target === menu) {
                closeMobileMenu(menu, { restoreFocus: true });
            } else if (event.target.closest?.('a, form button')) {
                closeMobileMenu(menu);
            }
        });
        synchronizeMobileMenu(menu);
    });

    if (! document.querySelector('.mobile-menu[open]')) {
        document.documentElement.classList.remove('mobile-menu-open');
    }
}

document.addEventListener('pointerdown', (event) => {
    const menu = document.querySelector('.mobile-menu[open]');

    if (menu && ! menu.contains(event.target)) {
        closeMobileMenu(menu, { restoreFocus: true });
    }
});

document.addEventListener('keydown', (event) => {
    const menu = document.querySelector('.mobile-menu[open]');

    if (! menu) {
        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        closeMobileMenu(menu, { restoreFocus: true });

        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const focusable = [...menu.querySelectorAll('summary, a[href], button:not([disabled])')]
        .filter((element) => element.getClientRects().length > 0);
    const first = focusable[0];
    const last = focusable.at(-1);

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
    } else if (! event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
    }
});

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

document.addEventListener('DOMContentLoaded', () => {
    synchronizePageBodyClass();
    initializeConditionalForms();
    initializeMediaArtwork();
    initializeMobileMenus();
});
document.addEventListener('htmx:afterSwap', (event) => {
    synchronizePageBodyClass();
    initializeConditionalForms(event.detail.elt);
    initializeMediaArtwork(event.detail.elt);
    initializeMobileMenus(event.detail.elt);
});

document.addEventListener('htmx:responseError', () => {
    const announcer = document.querySelector('[data-request-announcer]');

    if (announcer) {
        announcer.textContent = 'The request could not be completed. Please try again.';
    }
});
