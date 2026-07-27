const programSelector = '[data-epg-program]';
let activeProgram = null;
let tooltip = null;

function ensureTooltip() {
    if (tooltip?.isConnected) {
        return tooltip;
    }

    tooltip = document.createElement('div');
    tooltip.id = 'epg-program-tooltip';
    tooltip.className = 'epg-program-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.hidden = true;
    document.body.append(tooltip);

    return tooltip;
}

function hideTooltip() {
    activeProgram?.removeAttribute('aria-describedby');
    activeProgram = null;

    if (tooltip) {
        tooltip.hidden = true;
    }
}

function positionTooltip(program) {
    const panel = ensureTooltip();
    const target = program.getBoundingClientRect();
    const panelRect = panel.getBoundingClientRect();
    const edge = 12;
    const gap = 8;
    let left = target.left;
    let top = target.bottom + gap;

    if (left + panelRect.width > window.innerWidth - edge) {
        left = window.innerWidth - panelRect.width - edge;
    }

    left = Math.max(edge, left);

    if (top + panelRect.height > window.innerHeight - edge) {
        top = target.top - panelRect.height - gap;
    }

    panel.style.left = `${left}px`;
    panel.style.top = `${Math.max(edge, top)}px`;
}

function showTooltip(program) {
    if (!(program instanceof HTMLElement)) {
        return;
    }

    const panel = ensureTooltip();
    const title = document.createElement('strong');
    const metadata = document.createElement('span');
    const description = document.createElement('p');

    title.textContent = program.dataset.epgTitle || 'Programme information';
    metadata.textContent = [
        program.dataset.epgChannel,
        program.dataset.epgTime,
    ].filter(Boolean).join(' · ');
    description.textContent = program.dataset.epgDescription
        || 'No programme description is available.';
    panel.replaceChildren(title, metadata, description);
    panel.hidden = false;

    activeProgram?.removeAttribute('aria-describedby');
    activeProgram = program;
    activeProgram.setAttribute('aria-describedby', panel.id);
    positionTooltip(program);
}

document.addEventListener('pointerover', (event) => {
    const program = event.target.closest?.(programSelector);

    if (program && program !== activeProgram) {
        showTooltip(program);
    }
});

document.addEventListener('pointerout', (event) => {
    if (
        activeProgram
        && !activeProgram.contains(event.relatedTarget)
    ) {
        hideTooltip();
    }
});

document.addEventListener('focusin', (event) => {
    const program = event.target.closest?.(programSelector);

    if (program) {
        showTooltip(program);
    }
});

document.addEventListener('focusout', (event) => {
    if (
        activeProgram
        && !activeProgram.contains(event.relatedTarget)
    ) {
        hideTooltip();
    }
});

document.addEventListener('scroll', hideTooltip, true);
window.addEventListener('resize', hideTooltip);
window.addEventListener('pagehide', hideTooltip);
