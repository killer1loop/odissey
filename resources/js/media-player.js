import Hls from 'hls.js/light';

const players = new WeakMap();

function text(player, selector, value) {
    const target = player.querySelector(selector);

    if (target) {
        target.textContent = value;
    }
}

function formatTime(value) {
    if (!Number.isFinite(value) || value < 0) {
        return '0:00';
    }

    const seconds = Math.floor(value);
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainder = seconds % 60;

    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`
        : `${minutes}:${String(remainder).padStart(2, '0')}`;
}

function formatRemaining(value) {
    if (!Number.isFinite(value) || value <= 0) {
        return '0m remaining';
    }

    const minutes = Math.ceil(value / 60);

    if (minutes < 60) {
        return `${minutes}m remaining`;
    }

    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;

    return `${hours}h${remainder > 0 ? ` ${remainder}m` : ''} remaining`;
}

function initializePlayer(player) {
    if (players.has(player)) {
        return;
    }

    const video = player.querySelector('[data-media-video], video, audio');
    const sourceUrl = video?.dataset.sourceUrl || player.dataset.sourceUrl;
    const sourceType = video?.dataset.sourceType || player.dataset.sourceType;

    if (!video || !sourceUrl) {
        return;
    }

    const playButton = player.querySelector('[data-player-play]');
    const muteButton = player.querySelector('[data-player-mute]');
    const volume = player.querySelector('[data-player-volume]');
    const seek = player.querySelector('[data-player-seek]');
    const fullscreenButton = player.querySelector('[data-player-fullscreen]');
    const captionButton = player.querySelector('[data-player-captions]');
    const railToggle = player.querySelector('[data-player-rail-toggle]');
    const railClose = player.querySelector('[data-player-rail-close]');
    const historyItems = [...player.querySelectorAll('[data-player-history-item]')];
    const navigationStatus = player.querySelector('[data-player-navigation-status]');
    const ambientCanvas = player.querySelector('[data-player-ambient]');
    const health = player.querySelector('[data-stream-health]');
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)');

    let disposed = false;
    let hls = null;
    let sequence = Number(player.dataset.progressSequence || 0);
    let lastHeartbeatAt = 0;
    let resumeApplied = false;
    let ambientTimer = null;
    let ambientUnavailable = false;
    let activeCaptionIndex = -1;

    const announce = (message) => {
        text(player, '[data-player-message]', message);
    };
    const setStatus = (state, message) => {
        player.dataset.playerState = state;
        announce(message);

        if (health) {
            health.dataset.health = state;
            health.textContent = {
                connecting: 'Loading',
                ready: 'Ready',
                healthy: 'Playing',
                buffering: 'Buffering',
                unavailable: 'Unavailable',
                paused: 'Paused',
            }[state] ?? state;
        }
    };
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
            const context = ambientCanvas.getContext('2d', { alpha: false });

            if (!context) {
                ambientUnavailable = true;

                return;
            }

            context.drawImage(video, 0, 0, ambientCanvas.width, ambientCanvas.height);
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

    const updateResolution = () => {
        const level = hls?.levels?.[hls.currentLevel];
        const width = Number(level?.width || video.videoWidth);
        const height = Number(level?.height || video.videoHeight);

        text(
            player,
            '[data-stream-resolution]',
            width > 0 && height > 0 ? `${width} × ${height}` : '—',
        );

        const bitrate = Number(level?.bitrate);

        if (bitrate > 0) {
            text(
                player,
                '[data-stream-bitrate]',
                bitrate >= 1_000_000
                    ? `${(bitrate / 1_000_000).toFixed(1)} Mbps`
                    : `${Math.round(bitrate / 1_000)} Kbps`,
            );
        }
    };
    const updateControls = () => {
        player.dataset.playing = video.paused ? 'false' : 'true';
        player.dataset.muted = video.muted || video.volume === 0 ? 'true' : 'false';

        playButton?.setAttribute('aria-label', video.paused ? 'Play' : 'Pause');
        muteButton?.setAttribute('aria-label', video.muted ? 'Unmute' : 'Mute');

        if (volume && document.activeElement !== volume) {
            volume.value = video.muted ? '0' : String(video.volume);
        }
    };
    const updateHistoryProgress = () => {
        const current = player.querySelector('[data-history-current]');

        if (!current || !Number.isFinite(video.duration) || video.duration <= 0) {
            return;
        }

        const percent = Math.min(100, Math.max(0, (video.currentTime / video.duration) * 100));
        const track = current.querySelector('[role="progressbar"]');
        const fill = current.querySelector('[data-history-progress]');

        track?.setAttribute('aria-valuenow', String(Math.round(percent)));

        if (fill) {
            fill.style.setProperty('--history-progress', `${percent}%`);
        }

        text(
            current,
            '[data-history-time]',
            `${Math.round(percent)}% watched · ${formatRemaining(video.duration - video.currentTime)}`,
        );
    };
    const updateTimeline = () => {
        text(player, '[data-player-elapsed]', formatTime(video.currentTime));
        text(
            player,
            '[data-player-remaining]',
            `−${formatTime(Math.max(0, video.duration - video.currentTime))}`,
        );

        if (seek && document.activeElement !== seek && Number.isFinite(video.duration) && video.duration > 0) {
            seek.value = String(Math.round((video.currentTime / video.duration) * 1000));
        }

        updateHistoryProgress();
    };
    const reconcileHeartbeat = async (response) => {
        let payload = null;

        try {
            payload = await response.json();
        } catch {
            // A non-JSON response is handled by the status check below.
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

        window.fetch(player.dataset.progressUrl, {
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
                    announce('Playback continues, but progress could not be saved.');
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

        updateTimeline();
    };
    const play = () => {
        video.play().catch(() => {
            setStatus('ready', 'Press play to start.');
        });
    };
    const handlePlayClick = () => {
        if (video.paused) {
            play();
        } else {
            video.pause();
        }
    };
    const handlePlaying = () => {
        updateControls();
        updateResolution();
        startAmbientLighting();
        setStatus('healthy', 'Video is playing.');
    };
    const handlePause = () => {
        stopAmbientLighting();
        updateControls();
        sendHeartbeat(false);

        if (!video.ended) {
            setStatus('paused', 'Playback paused.');
        }
    };
    const handleWaiting = () => setStatus('buffering', 'Buffering video…');
    const handleEnded = () => {
        stopAmbientLighting();
        updateControls();
        updateTimeline();
        sendHeartbeat(true);
        setStatus('paused', 'Playback finished.');
    };
    const handleTimeUpdate = () => {
        updateTimeline();

        const now = Date.now();

        if (now - lastHeartbeatAt >= 10_000) {
            lastHeartbeatAt = now;
            sendHeartbeat(false);
        }
    };
    const handleLoadedMetadata = () => {
        applyResumePosition();
        updateResolution();
        updateControls();
        updateTimeline();
        setStatus('ready', 'Video ready.');
    };
    const handleCanPlay = () => {
        if (video.paused) {
            setStatus('ready', 'Video ready.');
        }
    };
    const handleVideoError = () => {
        stopAmbientLighting();
        setStatus('unavailable', 'This video could not be played.');
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
    const handleSeekInput = (event) => {
        if (!Number.isFinite(video.duration) || video.duration <= 0) {
            return;
        }

        video.currentTime = (Number(event.currentTarget.value) / 1000) * video.duration;
        updateTimeline();
    };
    const handleSkipClick = (event) => {
        const delta = Number(event.currentTarget.dataset.playerSkip);

        if (!Number.isFinite(delta)) {
            return;
        }

        video.currentTime = Math.min(
            Number.isFinite(video.duration) ? video.duration : Number.MAX_SAFE_INTEGER,
            Math.max(0, video.currentTime + delta),
        );
        updateTimeline();
    };
    const toggleCaptions = () => {
        const tracks = [...video.textTracks];

        if (tracks.length === 0) {
            announce('No caption tracks are available.');

            return;
        }

        activeCaptionIndex = (activeCaptionIndex + 1) % (tracks.length + 1);
        tracks.forEach((track, index) => {
            track.mode = activeCaptionIndex === index ? 'showing' : 'disabled';
        });

        const activeTrack = tracks[activeCaptionIndex];
        const label = activeTrack?.label || activeTrack?.language || 'CC';
        text(player, '[data-player-caption-label]', activeTrack ? label.slice(0, 8) : 'CC');
        captionButton?.setAttribute(
            'aria-label',
            activeTrack ? `Captions on: ${label}. Select next track` : 'Turn captions on',
        );
        player.dataset.captions = activeTrack ? 'true' : 'false';
    };
    const isFullscreen = () => document.fullscreenElement === player
        || document.webkitFullscreenElement === player
        || video.webkitDisplayingFullscreen === true;
    const handleFullscreenChange = () => {
        const active = isFullscreen();
        player.dataset.fullscreen = active ? 'true' : 'false';
        fullscreenButton?.setAttribute('aria-label', active ? 'Exit full screen' : 'Enter full screen');
    };
    const toggleFullscreen = async () => {
        try {
            if (isFullscreen()) {
                if (document.fullscreenElement || document.webkitFullscreenElement) {
                    await (document.exitFullscreen ?? document.webkitExitFullscreen)?.call(document);
                } else {
                    video.webkitExitFullscreen?.();
                }
            } else if (player.requestFullscreen || player.webkitRequestFullscreen) {
                await (player.requestFullscreen ?? player.webkitRequestFullscreen).call(player);
            } else if (video.webkitEnterFullscreen) {
                video.webkitEnterFullscreen();
            } else {
                announce('Full screen is not supported by this browser.');
            }
        } catch {
            announce('Full screen could not be opened.');
        }
    };
    const setRailOpen = (open, focusRail = false) => {
        player.dataset.railOpen = open ? 'true' : 'false';
        railToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open && focusRail) {
            (player.querySelector('[data-history-current]') ?? historyItems[0])?.focus();
        }
    };
    const handleRailToggle = () => {
        setRailOpen(player.dataset.railOpen !== 'true', true);
    };
    const handleRailClose = () => {
        setRailOpen(false);
        railToggle?.focus();
    };
    const moveHistoryFocus = (delta) => {
        if (historyItems.length === 0) {
            return;
        }

        const currentIndex = historyItems.indexOf(document.activeElement);
        const nextIndex = currentIndex < 0
            ? (delta > 0 ? 0 : historyItems.length - 1)
            : (currentIndex + delta + historyItems.length) % historyItems.length;
        historyItems[nextIndex]?.focus();
    };
    const announceNavigation = (message) => {
        if (navigationStatus) {
            navigationStatus.textContent = message;
        }
    };
    const handleKeydown = (event) => {
        const target = event.target;
        const isTyping = target instanceof HTMLElement
            && (target.matches('input:not([type="range"]), textarea, select') || target.isContentEditable);

        if (event.altKey || event.ctrlKey || event.metaKey || event.repeat || isTyping) {
            return;
        }

        const key = String(event.key ?? '').toLowerCase();
        const isBack = event.key === 'BrowserBack' || Number(event.keyCode) === 10009;
        const isPlayPause = event.key === 'MediaPlayPause' || Number(event.keyCode) === 10252;

        if (key === 'f' || event.code === 'KeyF') {
            event.preventDefault();
            event.stopPropagation();
            toggleFullscreen();
        } else if (event.key === 'Escape' || isBack) {
            if (isFullscreen()) {
                event.preventDefault();
                (document.exitFullscreen ?? document.webkitExitFullscreen)?.call(document);
            } else if (player.dataset.railOpen === 'true') {
                event.preventDefault();
                setRailOpen(false);
                player.focus();
            }
        } else if (event.key === 'ArrowRight' && player.dataset.railOpen !== 'true') {
            event.preventDefault();
            setRailOpen(true, true);
        } else if (event.key === 'ArrowLeft' && player.dataset.railOpen === 'true') {
            event.preventDefault();
            setRailOpen(false);
            player.focus();
        } else if (event.key === 'ArrowUp' && player.dataset.railOpen === 'true') {
            event.preventDefault();
            moveHistoryFocus(-1);
        } else if (event.key === 'ArrowDown' && player.dataset.railOpen === 'true') {
            event.preventDefault();
            moveHistoryFocus(1);
        } else if (isPlayPause || event.key === ' ') {
            event.preventDefault();
            handlePlayClick();
            announceNavigation(video.paused ? 'Playback paused.' : 'Playback started.');
        }
    };
    const handlePageHide = () => sendHeartbeat(video.ended, { silent: true });
    const skipButtons = [...player.querySelectorAll('[data-player-skip]')];

    playButton?.addEventListener('click', handlePlayClick);
    muteButton?.addEventListener('click', handleMuteClick);
    volume?.addEventListener('input', handleVolumeInput);
    seek?.addEventListener('input', handleSeekInput);
    fullscreenButton?.addEventListener('click', toggleFullscreen);
    captionButton?.addEventListener('click', toggleCaptions);
    railToggle?.addEventListener('click', handleRailToggle);
    railClose?.addEventListener('click', handleRailClose);
    skipButtons.forEach((button) => button.addEventListener('click', handleSkipClick));
    document.addEventListener('keydown', handleKeydown, true);
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    document.addEventListener('webkitfullscreenchange', handleFullscreenChange);
    document.addEventListener('visibilitychange', handleVisibilityChange);
    reducedMotion?.addEventListener?.('change', handleMotionPreferenceChange);
    video.addEventListener('playing', handlePlaying);
    video.addEventListener('pause', handlePause);
    video.addEventListener('waiting', handleWaiting);
    video.addEventListener('stalled', handleWaiting);
    video.addEventListener('ended', handleEnded);
    video.addEventListener('timeupdate', handleTimeUpdate);
    video.addEventListener('loadedmetadata', handleLoadedMetadata);
    video.addEventListener('canplay', handleCanPlay);
    video.addEventListener('resize', updateResolution);
    video.addEventListener('error', handleVideoError);
    window.addEventListener('pagehide', handlePageHide);

    const dispose = () => {
        if (disposed) {
            return;
        }

        sendHeartbeat(video.ended, { silent: true });
        disposed = true;
        playButton?.removeEventListener('click', handlePlayClick);
        muteButton?.removeEventListener('click', handleMuteClick);
        volume?.removeEventListener('input', handleVolumeInput);
        seek?.removeEventListener('input', handleSeekInput);
        fullscreenButton?.removeEventListener('click', toggleFullscreen);
        captionButton?.removeEventListener('click', toggleCaptions);
        railToggle?.removeEventListener('click', handleRailToggle);
        railClose?.removeEventListener('click', handleRailClose);
        skipButtons.forEach((button) => button.removeEventListener('click', handleSkipClick));
        document.removeEventListener('keydown', handleKeydown, true);
        document.removeEventListener('fullscreenchange', handleFullscreenChange);
        document.removeEventListener('webkitfullscreenchange', handleFullscreenChange);
        document.removeEventListener('visibilitychange', handleVisibilityChange);
        reducedMotion?.removeEventListener?.('change', handleMotionPreferenceChange);
        video.removeEventListener('playing', handlePlaying);
        video.removeEventListener('pause', handlePause);
        video.removeEventListener('waiting', handleWaiting);
        video.removeEventListener('stalled', handleWaiting);
        video.removeEventListener('ended', handleEnded);
        video.removeEventListener('timeupdate', handleTimeUpdate);
        video.removeEventListener('loadedmetadata', handleLoadedMetadata);
        video.removeEventListener('canplay', handleCanPlay);
        video.removeEventListener('resize', updateResolution);
        video.removeEventListener('error', handleVideoError);
        window.removeEventListener('pagehide', handlePageHide);
        stopAmbientLighting();
        hls?.destroy();
        hls = null;
        video.pause();
        video.removeAttribute('src');
        video.load();
        players.delete(player);
    };

    players.set(player, { dispose });
    updateControls();
    handleFullscreenChange();
    setStatus('connecting', 'Preparing playback…');

    if (sourceType === 'hls' && !video.canPlayType('application/vnd.apple.mpegurl')) {
        if (!Hls.isSupported()) {
            setStatus('unavailable', 'This browser cannot play HLS streams.');

            return;
        }

        hls = new Hls({
            enableWorker: true,
            startPosition: 0,
            xhrSetup: (xhr) => {
                xhr.withCredentials = true;
            },
        });
        hls.loadSource(sourceUrl);
        hls.attachMedia(video);
        hls.on(Hls.Events.MANIFEST_PARSED, () => {
            updateResolution();
            setStatus('ready', 'Video ready.');
        });
        hls.on(Hls.Events.LEVEL_SWITCHED, updateResolution);
        hls.on(Hls.Events.ERROR, (_event, data) => {
            if (data.fatal) {
                setStatus('unavailable', 'Playback stopped because the HLS stream became unavailable.');
            }
        });
    } else {
        video.src = sourceUrl;
    }

    if (video.readyState >= 1) {
        handleLoadedMetadata();
    }
}

function playerFor(root) {
    if (root.matches?.('[data-media-player]')) {
        return root;
    }

    return root.closest?.('[data-media-player]') ?? null;
}

function initializePlayers(root = document) {
    const parentPlayer = playerFor(root);

    if (parentPlayer) {
        initializePlayer(parentPlayer);
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
document.addEventListener('htmx:afterSwap', (event) => {
    initializePlayers(event.detail.elt);
});
document.addEventListener('htmx:beforeCleanupElement', (event) => disposePlayers(event.detail.elt));
window.addEventListener('pageshow', () => initializePlayers());
