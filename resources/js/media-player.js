import Hls from 'hls.js/light';

const players = new WeakMap();

function announce(player, message) {
    const target = player.querySelector('[data-player-message]');

    if (target) {
        target.textContent = message;
    }
}

function initializePlayer(player) {
    if (players.has(player)) {
        return;
    }

    const video = player.querySelector('video');
    const sourceUrl = player.dataset.sourceUrl;
    const sourceType = player.dataset.sourceType;

    if (!video || !sourceUrl) {
        return;
    }

    let disposed = false;
    let hls = null;
    let sequence = Number(player.dataset.progressSequence || 0);
    let lastHeartbeatAt = 0;
    let resumeApplied = false;

    const reconcileHeartbeat = async (response) => {
        let payload = null;

        try {
            payload = await response.json();
        } catch {
            // A non-JSON error response is handled by the status check below.
        }

        if (!response.ok) {
            throw new Error(`Progress heartbeat failed with status ${response.status}.`);
        }

        const serverSequence = Number(payload?.sequence);

        if (Number.isSafeInteger(serverSequence) && serverSequence >= 0) {
            sequence = Math.max(sequence, serverSequence);
            player.dataset.progressSequence = String(sequence);
        }
    };

    const sendHeartbeat = (completed = false, { silent = false } = {}) => {
        if (!Number.isFinite(video.currentTime) || !player.dataset.progressUrl) {
            return;
        }

        sequence += 1;
        player.dataset.progressSequence = String(sequence);

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        const durationMs = Number.isFinite(video.duration)
            ? Math.max(1, Math.round(video.duration * 1000))
            : null;

        fetch(player.dataset.progressUrl, {
            method: 'PUT',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            },
            body: JSON.stringify({
                sequence,
                position_ms: Math.max(0, Math.round(video.currentTime * 1000)),
                duration_ms: durationMs,
                completed,
            }),
        })
            .then(reconcileHeartbeat)
            .catch(() => {
                if (!disposed && !silent) {
                    announce(player, 'Playback continues, but progress could not be saved.');
                }
            });
    };

    const applyResumePosition = () => {
        if (resumeApplied || !Number.isFinite(video.duration)) {
            return;
        }

        resumeApplied = true;

        const resumeSeconds = Number(player.dataset.resumeSeconds || 0);

        if (
            Number.isFinite(resumeSeconds)
            && resumeSeconds > 0
            && resumeSeconds < Math.max(0, video.duration - 5)
        ) {
            video.currentTime = resumeSeconds;
        }
    };

    const handleTimeUpdate = () => {
        const now = Date.now();

        if (now - lastHeartbeatAt >= 10_000) {
            lastHeartbeatAt = now;
            sendHeartbeat(false);
        }
    };
    const handlePause = () => sendHeartbeat(false);
    const handleEnded = () => sendHeartbeat(true);
    const handlePageHide = () => sendHeartbeat(video.ended, { silent: true });

    const dispose = () => {
        if (disposed) {
            return;
        }

        sendHeartbeat(video.ended, { silent: true });
        disposed = true;

        video.removeEventListener('loadedmetadata', applyResumePosition);
        video.removeEventListener('timeupdate', handleTimeUpdate);
        video.removeEventListener('pause', handlePause);
        video.removeEventListener('ended', handleEnded);
        window.removeEventListener('pagehide', handlePageHide);

        hls?.destroy();
        hls = null;

        video.pause();
        video.removeAttribute('src');
        video.load();
        players.delete(player);
    };

    players.set(player, { dispose });

    if (sourceType === 'hls' && !video.canPlayType('application/vnd.apple.mpegurl')) {
        if (!Hls.isSupported()) {
            announce(player, 'This browser cannot play HLS streams.');
            return;
        }

        hls = new Hls({
            enableWorker: true,
            xhrSetup: (xhr) => {
                xhr.withCredentials = true;
            },
        });

        hls.loadSource(sourceUrl);
        hls.attachMedia(video);
        hls.on(Hls.Events.ERROR, (_event, data) => {
            if (data.fatal) {
                announce(player, 'Playback stopped because the HLS stream became unavailable.');
            }
        });
    } else {
        video.src = sourceUrl;
    }

    if (video.readyState >= 1) {
        applyResumePosition();
    }

    if (!resumeApplied) {
        video.addEventListener('loadedmetadata', applyResumePosition);
    }

    video.addEventListener('timeupdate', handleTimeUpdate);
    video.addEventListener('pause', handlePause);
    video.addEventListener('ended', handleEnded);
    window.addEventListener('pagehide', handlePageHide);
}

function initializePlayers(root = document) {
    if (root.matches?.('[data-media-player]')) {
        initializePlayer(root);
    }

    root.querySelectorAll?.('[data-media-player]').forEach(initializePlayer);
}

function disposePlayers(root) {
    if (root.matches?.('[data-media-player]')) {
        players.get(root)?.dispose();
    }

    root.querySelectorAll?.('[data-media-player]').forEach((player) => {
        players.get(player)?.dispose();
    });
}

document.addEventListener('DOMContentLoaded', () => initializePlayers());
document.addEventListener('htmx:afterSwap', (event) => initializePlayers(event.detail.target));
document.addEventListener('htmx:beforeCleanupElement', (event) => disposePlayers(event.detail.elt));
