import Hls from 'hls.js/light';

const players = new WeakMap();

function text(container, selector, value) {
    const target = container.querySelector(selector);

    if (target) {
        target.textContent = value;
    }
}

function formatBitrate(bitsPerSecond) {
    if (!Number.isFinite(bitsPerSecond) || bitsPerSecond <= 0) {
        return 'Adaptive';
    }

    return bitsPerSecond >= 1_000_000
        ? `${(bitsPerSecond / 1_000_000).toFixed(1)} Mbps`
        : `${Math.round(bitsPerSecond / 1_000)} Kbps`;
}

function initialize(container) {
    if (players.has(container)) {
        return;
    }

    const video = container.querySelector('video');
    const manifestUrl = container.dataset.manifestUrl;
    const playButton = container.querySelector('[data-player-play]');
    const muteButton = container.querySelector('[data-player-mute]');
    const volume = container.querySelector('[data-player-volume]');
    const fullscreenButton = container.querySelector('[data-player-fullscreen]');
    const health = container.querySelector('[data-stream-health]');

    if (!video || !manifestUrl) {
        return;
    }

    let hls = null;
    let disposed = false;

    const setStatus = (state, message) => {
        container.dataset.playerState = state;
        text(container, '[data-iptv-player-status]', message);

        if (health) {
            health.dataset.health = state;
            health.textContent = {
                connecting: 'Connecting',
                ready: 'Ready',
                healthy: 'Healthy',
                buffering: 'Buffering',
                recovering: 'Recovering',
                unavailable: 'Unavailable',
                paused: 'Paused',
            }[state] ?? state;
        }
    };

    const updateResolution = () => {
        const level = hls?.levels?.[hls.currentLevel];
        const width = Number(level?.width || video.videoWidth);
        const height = Number(level?.height || video.videoHeight);

        text(
            container,
            '[data-stream-resolution]',
            width > 0 && height > 0 ? `${width} × ${height}` : 'Adaptive',
        );
    };

    const updateBitrate = () => {
        const level = hls?.levels?.[hls.currentLevel];
        text(container, '[data-stream-bitrate]', formatBitrate(Number(level?.bitrate)));
    };

    const updateControls = () => {
        container.dataset.playing = video.paused ? 'false' : 'true';
        container.dataset.muted = video.muted || video.volume === 0 ? 'true' : 'false';

        if (playButton) {
            playButton.setAttribute('aria-label', video.paused ? 'Play live stream' : 'Pause live stream');
        }

        if (muteButton) {
            muteButton.setAttribute('aria-label', video.muted ? 'Unmute' : 'Mute');
        }

        if (volume && document.activeElement !== volume) {
            volume.value = video.muted ? '0' : String(video.volume);
        }
    };

    const play = () => {
        video.play().catch(() => {
            setStatus('ready', 'Press play to start the live stream.');
        });
    };

    const handlePlaying = () => {
        updateControls();
        updateResolution();
        updateBitrate();
        setStatus('healthy', 'Live stream is playing.');
    };
    const handlePause = () => {
        updateControls();
        setStatus('paused', 'Live stream paused.');
    };
    const handleWaiting = () => setStatus('buffering', 'Buffering live stream…');
    const handleLoadedMetadata = () => {
        updateResolution();
        updateControls();
    };
    const handleVideoError = () => setStatus('unavailable', 'The live stream became unavailable.');
    const handleFullscreenChange = () => {
        const active = document.fullscreenElement === container;
        fullscreenButton?.setAttribute('aria-label', active ? 'Exit full screen' : 'Enter full screen');
    };

    const handlePlayClick = () => {
        if (video.paused) {
            play();
        } else {
            video.pause();
        }
    };
    const handleMuteClick = () => {
        video.muted = !video.muted;
        updateControls();
    };
    const handleVolumeInput = (event) => {
        video.volume = Number(event.currentTarget.value);
        video.muted = video.volume === 0;
        updateControls();
    };
    const handleFullscreenClick = () => {
        if (document.fullscreenElement === container) {
            document.exitFullscreen?.();
        } else {
            container.requestFullscreen?.();
        }
    };

    playButton?.addEventListener('click', handlePlayClick);
    muteButton?.addEventListener('click', handleMuteClick);
    volume?.addEventListener('input', handleVolumeInput);
    fullscreenButton?.addEventListener('click', handleFullscreenClick);
    video.addEventListener('playing', handlePlaying);
    video.addEventListener('pause', handlePause);
    video.addEventListener('waiting', handleWaiting);
    video.addEventListener('stalled', handleWaiting);
    video.addEventListener('loadedmetadata', handleLoadedMetadata);
    video.addEventListener('resize', updateResolution);
    video.addEventListener('error', handleVideoError);
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    updateControls();
    setStatus('connecting', 'Connecting to live stream…');

    if (!Hls.isSupported() && video.canPlayType('application/vnd.apple.mpegurl')) {
        const handleCanPlay = () => {
            setStatus('ready', 'Live stream ready.');
            text(container, '[data-stream-bitrate]', 'Adaptive');
            play();
        };

        video.addEventListener('canplay', handleCanPlay, { once: true });
        video.src = manifestUrl;
    } else if (!Hls.isSupported()) {
        setStatus('unavailable', 'This browser cannot play this live HLS stream.');
    } else {
        hls = new Hls({
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
            setStatus('ready', 'Live stream ready.');
            updateBitrate();
            play();
        });
        hls.on(Hls.Events.LEVEL_SWITCHED, () => {
            updateResolution();
            updateBitrate();
        });
        hls.on(Hls.Events.FRAG_BUFFERED, () => {
            if (!video.paused) {
                setStatus('healthy', 'Live stream is playing.');
            }
        });
        hls.on(Hls.Events.ERROR, (_event, data) => {
            if (data.fatal) {
                setStatus('unavailable', 'The live stream became unavailable.');
            } else if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
                setStatus('recovering', 'Recovering the live stream…');
            }
        });
    }

    const dispose = () => {
        if (disposed) {
            return;
        }

        disposed = true;
        playButton?.removeEventListener('click', handlePlayClick);
        muteButton?.removeEventListener('click', handleMuteClick);
        volume?.removeEventListener('input', handleVolumeInput);
        fullscreenButton?.removeEventListener('click', handleFullscreenClick);
        video.removeEventListener('playing', handlePlaying);
        video.removeEventListener('pause', handlePause);
        video.removeEventListener('waiting', handleWaiting);
        video.removeEventListener('stalled', handleWaiting);
        video.removeEventListener('loadedmetadata', handleLoadedMetadata);
        video.removeEventListener('resize', updateResolution);
        video.removeEventListener('error', handleVideoError);
        document.removeEventListener('fullscreenchange', handleFullscreenChange);
        hls?.destroy();
        video.pause();
        video.removeAttribute('src');
        video.load();
        players.delete(container);
    };

    players.set(container, { dispose });
}

function initializeAll(root = document) {
    if (root.matches?.('[data-iptv-player]')) {
        initialize(root);
    }

    root.querySelectorAll?.('[data-iptv-player]').forEach(initialize);
}

function disposeAll(root = document) {
    if (root.matches?.('[data-iptv-player]')) {
        players.get(root)?.dispose();
    }

    root.querySelectorAll?.('[data-iptv-player]').forEach((container) => {
        players.get(container)?.dispose();
    });
}

document.addEventListener('DOMContentLoaded', () => initializeAll());
document.addEventListener('htmx:afterSwap', (event) => initializeAll(event.detail.elt));
document.addEventListener('htmx:beforeCleanupElement', (event) => disposeAll(event.detail.elt));
window.addEventListener('pagehide', () => disposeAll());
window.addEventListener('pageshow', () => initializeAll());
