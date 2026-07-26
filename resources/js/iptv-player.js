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
    const railToggle = container.querySelector('[data-player-rail-toggle]');
    const railClose = container.querySelector('[data-player-rail-close]');
    const favoriteForms = [...container.querySelectorAll('[data-favorite-channel]')];
    const navigationStatus = container.querySelector('[data-player-navigation-status]');
    const ambientCanvas = container.querySelector('[data-player-ambient]');
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)');

    if (!video || !manifestUrl) {
        return;
    }

    let hls = null;
    let disposed = false;
    let ambientTimer = null;
    let ambientUnavailable = false;

    const stopAmbientLighting = () => {
        if (ambientTimer !== null) {
            window.clearInterval(ambientTimer);
            ambientTimer = null;
        }
    };
    const drawAmbientFrame = () => {
        if (
            ambientUnavailable
            || !ambientCanvas
            || video.readyState < 2
            || video.videoWidth === 0
            || video.videoHeight === 0
        ) {
            return;
        }

        try {
            const context = ambientCanvas.getContext('2d', {
                alpha: false,
            });

            if (!context) {
                ambientUnavailable = true;

                return;
            }

            context.drawImage(
                video,
                0,
                0,
                ambientCanvas.width,
                ambientCanvas.height,
            );
            ambientCanvas.dataset.ready = 'true';
        } catch {
            ambientUnavailable = true;
            ambientCanvas.hidden = true;
            stopAmbientLighting();
        }
    };
    const startAmbientLighting = () => {
        stopAmbientLighting();

        if (!ambientCanvas || ambientUnavailable || document.hidden || video.paused) {
            return;
        }

        drawAmbientFrame();

        if (!reducedMotion?.matches) {
            ambientTimer = window.setInterval(drawAmbientFrame, 750);
        }
    };
    const handleVisibilityChange = () => {
        if (document.hidden) {
            stopAmbientLighting();
        } else {
            startAmbientLighting();
        }
    };
    const handleMotionPreferenceChange = () => startAmbientLighting();

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
        startAmbientLighting();
        setStatus('healthy', 'Live stream is playing.');
    };
    const handlePause = () => {
        stopAmbientLighting();
        updateControls();
        setStatus('paused', 'Live stream paused.');
    };
    const handleWaiting = () => setStatus('buffering', 'Buffering live stream…');
    const handleLoadedMetadata = () => {
        updateResolution();
        updateControls();
    };
    const handleVideoError = () => {
        stopAmbientLighting();
        setStatus('unavailable', 'The live stream became unavailable.');
    };
    const handleFullscreenChange = () => {
        const active = document.fullscreenElement === container
            || document.webkitFullscreenElement === container;
        container.dataset.fullscreen = active ? 'true' : 'false';
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
    const toggleFullscreen = () => {
        if (document.fullscreenElement === container || document.webkitFullscreenElement === container) {
            (document.exitFullscreen ?? document.webkitExitFullscreen)?.call(document);
        } else {
            (container.requestFullscreen ?? container.webkitRequestFullscreen)?.call(container);
        }
    };
    const setRailOpen = (open, focusRail = false) => {
        container.dataset.railOpen = open ? 'true' : 'false';
        railToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open && focusRail) {
            const activeButton = container.querySelector(
                '[data-favorite-channel] button[aria-current="true"]',
            );
            (activeButton ?? favoriteForms[0]?.querySelector('button'))?.focus();
        }
    };
    const announceNavigation = (message) => {
        if (navigationStatus) {
            navigationStatus.textContent = message;
        }
    };
    const switchFavorite = (delta) => {
        if (favoriteForms.length === 0) {
            setRailOpen(true, true);
            announceNavigation('No favorite channels are available.');

            return;
        }

        const activeId = String(container.dataset.activeChannelId ?? '');
        const currentIndex = favoriteForms.findIndex(
            (form) => form.dataset.channelId === activeId,
        );
        const nextIndex = currentIndex < 0
            ? (delta > 0 ? 0 : favoriteForms.length - 1)
            : (currentIndex + delta + favoriteForms.length) % favoriteForms.length;
        const nextForm = favoriteForms[nextIndex];
        const nextButton = nextForm?.querySelector('button');

        if (!nextForm || !nextButton) {
            return;
        }

        if (nextForm.dataset.channelId === activeId) {
            announceNavigation(`${nextButton.getAttribute('aria-label')?.replace('Switch to ', '') ?? 'Channel'} is already playing.`);

            return;
        }

        announceNavigation(nextButton.getAttribute('aria-label') ?? 'Switching favorite channel.');
        nextForm.requestSubmit(nextButton);
    };
    const handleFullscreenClick = () => toggleFullscreen();
    const handleRailToggle = () => {
        setRailOpen(container.dataset.railOpen !== 'true', true);
    };
    const handleRailClose = () => {
        setRailOpen(false);
        railToggle?.focus();
    };
    const handleKeydown = (event) => {
        const target = event.target;
        const isTyping = target instanceof HTMLElement
            && (target.matches('input, textarea, select') || target.isContentEditable);

        if (event.altKey || event.ctrlKey || event.metaKey || event.repeat || isTyping) {
            return;
        }

        const legacyCode = Number(event.keyCode);
        const isChannelUp = event.key === 'ChannelUp' || legacyCode === 427;
        const isChannelDown = event.key === 'ChannelDown' || legacyCode === 428;
        const isBack = event.key === 'BrowserBack' || legacyCode === 10009;
        const isPlayPause = event.key === 'MediaPlayPause' || legacyCode === 10252;

        if ((event.key ?? '').toLowerCase() === 'f') {
            event.preventDefault();
            toggleFullscreen();
        } else if (event.key === 'Escape' || isBack) {
            const isFullscreen = document.fullscreenElement === container
                || document.webkitFullscreenElement === container;

            if (isFullscreen) {
                event.preventDefault();
                (document.exitFullscreen ?? document.webkitExitFullscreen)?.call(document);
            } else if (container.dataset.railOpen === 'true') {
                event.preventDefault();
                setRailOpen(false);
                container.focus();
            }
        } else if (event.key === 'ArrowUp' || isChannelUp) {
            event.preventDefault();
            switchFavorite(-1);
        } else if (event.key === 'ArrowDown' || isChannelDown) {
            event.preventDefault();
            switchFavorite(1);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            setRailOpen(true, true);
        } else if (event.key === 'ArrowLeft' && container.dataset.railOpen === 'true') {
            event.preventDefault();
            setRailOpen(false);
            container.focus();
        } else if (isPlayPause) {
            event.preventDefault();
            handlePlayClick();
        }
    };

    playButton?.addEventListener('click', handlePlayClick);
    muteButton?.addEventListener('click', handleMuteClick);
    volume?.addEventListener('input', handleVolumeInput);
    fullscreenButton?.addEventListener('click', handleFullscreenClick);
    railToggle?.addEventListener('click', handleRailToggle);
    railClose?.addEventListener('click', handleRailClose);
    document.addEventListener('keydown', handleKeydown);
    video.addEventListener('playing', handlePlaying);
    video.addEventListener('pause', handlePause);
    video.addEventListener('waiting', handleWaiting);
    video.addEventListener('stalled', handleWaiting);
    video.addEventListener('loadedmetadata', handleLoadedMetadata);
    video.addEventListener('resize', updateResolution);
    video.addEventListener('error', handleVideoError);
    document.addEventListener('visibilitychange', handleVisibilityChange);
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    document.addEventListener('webkitfullscreenchange', handleFullscreenChange);
    reducedMotion?.addEventListener?.('change', handleMotionPreferenceChange);
    updateControls();
    handleFullscreenChange();
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
        railToggle?.removeEventListener('click', handleRailToggle);
        railClose?.removeEventListener('click', handleRailClose);
        document.removeEventListener('keydown', handleKeydown);
        video.removeEventListener('playing', handlePlaying);
        video.removeEventListener('pause', handlePause);
        video.removeEventListener('waiting', handleWaiting);
        video.removeEventListener('stalled', handleWaiting);
        video.removeEventListener('loadedmetadata', handleLoadedMetadata);
        video.removeEventListener('resize', updateResolution);
        video.removeEventListener('error', handleVideoError);
        document.removeEventListener('visibilitychange', handleVisibilityChange);
        document.removeEventListener('fullscreenchange', handleFullscreenChange);
        document.removeEventListener('webkitfullscreenchange', handleFullscreenChange);
        reducedMotion?.removeEventListener?.('change', handleMotionPreferenceChange);
        stopAmbientLighting();
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
