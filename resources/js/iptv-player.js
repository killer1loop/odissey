import Hls from 'hls.js/light';

const players = new WeakMap();

function announce(container, message) {
    const status = container.querySelector('[data-iptv-player-status]');

    if (status) {
        status.textContent = message;
    }
}

function initialize(container) {
    if (players.has(container)) {
        return;
    }

    const video = container.querySelector('video');
    const manifestUrl = container.dataset.manifestUrl;

    if (!video || !manifestUrl) {
        return;
    }

    if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = manifestUrl;
        players.set(container, { hls: null });
        return;
    }

    if (!Hls.isSupported()) {
        announce(container, 'This browser cannot play this live HLS stream.');
        return;
    }

    const hls = new Hls({
        enableWorker: true,
        liveSyncDurationCount: 3,
        maxBufferLength: 30,
        xhrSetup(xhr) {
            xhr.withCredentials = true;
        },
    });

    hls.loadSource(manifestUrl);
    hls.attachMedia(video);
    hls.on(Hls.Events.MANIFEST_PARSED, () => {
        announce(container, 'Live stream ready.');
        video.play().catch(() => {
            announce(container, 'Press play to start the live stream.');
        });
    });
    hls.on(Hls.Events.ERROR, (_event, data) => {
        if (data.fatal) {
            announce(container, 'The live stream became unavailable.');
        }
    });

    players.set(container, { hls });
}

function initializeAll(root = document) {
    if (root.matches?.('[data-iptv-player]')) {
        initialize(root);
    }

    root.querySelectorAll?.('[data-iptv-player]').forEach(initialize);
}

function dispose(container) {
    const player = players.get(container);

    if (!player) {
        return;
    }

    const video = container.querySelector('video');

    player.hls?.destroy();

    if (video) {
        video.pause();
        video.removeAttribute('src');
        video.querySelectorAll('source').forEach((source) => source.removeAttribute('src'));
        video.load();
    }

    players.delete(container);
}

function disposeAll(root = document) {
    if (root.matches?.('[data-iptv-player]')) {
        dispose(root);
    }

    root.querySelectorAll?.('[data-iptv-player]').forEach(dispose);
}

document.addEventListener('DOMContentLoaded', () => initializeAll());
document.addEventListener('htmx:afterSwap', (event) => initializeAll(event.detail.elt));
document.addEventListener('htmx:beforeCleanupElement', (event) => disposeAll(event.detail.elt));
window.addEventListener('pagehide', () => disposeAll());
window.addEventListener('pageshow', () => initializeAll());
