const tvUserAgent = /SMART-TV|Tizen|Web0S|NetCast|HbbTV|AFT/i;
const tvMediaQuery = window.matchMedia?.('(pointer: coarse) and (min-width: 900px)');

function initializeChannelIcons(root = document) {
    const icons = [];

    if (root.matches?.('[data-channel-icon]')) {
        icons.push(root);
    }

    root.querySelectorAll?.('[data-channel-icon]').forEach((icon) => icons.push(icon));

    icons.forEach((icon) => {
        const image = icon.querySelector('img');

        if (!image || image.dataset.fallbackBound === 'true') {
            return;
        }

        image.dataset.fallbackBound = 'true';
        const showFallback = () => {
            image.hidden = true;
            icon.dataset.iconUnavailable = 'true';
        };

        image.addEventListener('error', showFallback, { once: true });

        if (image.complete && image.naturalWidth === 0) {
            showFallback();
        }
    });
}

function isTvNavigation() {
    return tvUserAgent.test(navigator.userAgent) || Boolean(tvMediaQuery?.matches);
}

function isTypingTarget(target) {
    return target instanceof HTMLElement
        && (target.matches('input, textarea, select') || target.isContentEditable);
}

function visibleFocusableElements() {
    return [...document.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), '
        + 'textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    )].filter((element) => {
        if (!(element instanceof HTMLElement) || element.closest('[hidden], [aria-hidden="true"]')) {
            return false;
        }

        const style = window.getComputedStyle(element);
        if (style.visibility === 'hidden' || style.display === 'none') {
            return false;
        }

        const rect = element.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) {
            return false;
        }

        // Cache the rect for this navigation pass so scoring does not force
        // a second full measurement sweep over hundreds of EPG nodes.
        element.__tvRect = rect;

        return true;
    });
}

function directionalCandidate(active, direction, candidates) {
    const activeRect = active.__tvRect ?? active.getBoundingClientRect();
    const originX = activeRect.left + activeRect.width / 2;
    const originY = activeRect.top + activeRect.height / 2;

    return candidates
        .filter((candidate) => candidate !== active)
        .map((candidate) => {
            const rect = candidate.__tvRect ?? candidate.getBoundingClientRect();
            const deltaX = rect.left + rect.width / 2 - originX;
            const deltaY = rect.top + rect.height / 2 - originY;
            const primary = direction === 'left' ? -deltaX
                : direction === 'right' ? deltaX
                    : direction === 'up' ? -deltaY : deltaY;
            const cross = direction === 'left' || direction === 'right' ? deltaY : deltaX;

            return {
                candidate,
                primary,
                score: primary + Math.abs(cross) * 0.35,
            };
        })
        .filter(({ primary }) => primary > 2)
        .sort((left, right) => left.score - right.score)[0]?.candidate;
}

function handleTvKeydown(event) {
    if (!isTvNavigation() || event.defaultPrevented || isTypingTarget(event.target)) {
        return;
    }

    const direction = {
        ArrowLeft: 'left',
        ArrowRight: 'right',
        ArrowUp: 'up',
        ArrowDown: 'down',
    }[event.key];

    if (!direction || document.querySelector('[data-iptv-player]')) {
        return;
    }

    const candidates = visibleFocusableElements();
    const active = document.activeElement;

    if (!(active instanceof HTMLElement) || active === document.body || active === document.documentElement) {
        const initial = candidates.find((candidate) => candidate.matches('[aria-current="page"], [aria-current="true"]'))
            ?? candidates[0];
        initial?.focus();
        event.preventDefault();

        return;
    }

    const next = directionalCandidate(active, direction, candidates);

    if (next) {
        next.focus({ preventScroll: true });
        // Instant scrolling keeps remote-control navigation responsive on
        // low-end TV hardware where smooth scrolls lag visibly.
        next.scrollIntoView({
            behavior: 'instant',
            block: 'nearest',
            inline: 'nearest',
        });
        event.preventDefault();
    }
}

function initialize() {
    initializeChannelIcons();

    if (isTvNavigation()) {
        document.documentElement.dataset.tvNavigation = 'true';
    }
}

document.addEventListener('DOMContentLoaded', initialize);
document.addEventListener('keydown', handleTvKeydown);
document.addEventListener('htmx:afterSwap', (event) => initializeChannelIcons(event.detail.elt));
tvMediaQuery?.addEventListener?.('change', initialize);
