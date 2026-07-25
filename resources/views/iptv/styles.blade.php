<style>
    .iptv-page { padding: clamp(1.25rem, 4vw, 4rem); }
    .iptv-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem; }
    .iptv-header h1 { margin: 0; font-size: clamp(2rem, 5vw, 3.6rem); letter-spacing: -.055em; }
    .iptv-header p { margin: .55rem 0 0; color: var(--muted); line-height: 1.6; }
    .iptv-notice { margin: 0 0 1rem; padding: .9rem 1rem; border: 1px solid rgba(96,214,165,.3); border-radius: .7rem; background: rgba(96,214,165,.08); color: var(--success); }
    .iptv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: .9rem; }
    .iptv-card { min-width: 0; padding: 1rem; border: 1px solid var(--line); border-radius: .85rem; background: var(--panel); }
    .iptv-card h2, .iptv-card h3, .iptv-card p { margin: 0; }
    .iptv-card p { color: var(--muted); font-size: .78rem; line-height: 1.55; }
    .iptv-card-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: 1rem; }
    .iptv-card-actions form { margin: 0; }
    .iptv-meta { display: flex; flex-wrap: wrap; gap: .45rem; margin: .75rem 0; color: var(--soft); font-size: .72rem; }
    .iptv-filter { display: grid; grid-template-columns: minmax(180px, 1fr) minmax(160px, 260px) auto; gap: .7rem; margin-bottom: 1rem; }
    .iptv-input { width: 100%; min-height: 44px; padding: .7rem .8rem; border: 1px solid var(--line); border-radius: .65rem; background: rgba(255,255,255,.04); color: var(--text); }
    .iptv-label { display: grid; gap: .4rem; color: var(--muted); font-size: .78rem; font-weight: 700; }
    .iptv-form { display: grid; max-width: 720px; gap: 1rem; padding: 1.25rem; border: 1px solid var(--line); border-radius: 1rem; background: var(--panel); }
    .iptv-check { display: flex; align-items: flex-start; gap: .65rem; color: var(--muted); line-height: 1.5; }
    .iptv-check input { margin-top: .3rem; }
    .iptv-error { color: #ff9f9f; font-size: .74rem; }
    .iptv-channel-head { display: flex; align-items: center; gap: .75rem; }
    .iptv-channel-mark { display: grid; width: 48px; height: 48px; flex: 0 0 auto; place-items: center; border-radius: .7rem; background: rgba(242,164,65,.12); color: var(--accent-bright); font-weight: 850; }
    .iptv-guide { margin-top: .8rem; padding-top: .8rem; border-top: 1px solid var(--line); }
    .iptv-guide strong, .iptv-guide span { display: block; }
    .iptv-guide span { margin-top: .2rem; color: var(--soft); font-size: .7rem; }
    .iptv-favorite { width: 42px; min-height: 42px; border: 1px solid var(--line); border-radius: 50%; background: transparent; color: var(--accent-bright); cursor: pointer; }
    .iptv-player { max-width: 1100px; overflow: hidden; border: 1px solid var(--line); border-radius: 1rem; background: #000; }
    .iptv-player video { display: block; width: 100%; aspect-ratio: 16/9; background: #000; }
    .iptv-player-status { margin: 0; padding: .7rem 1rem; background: var(--panel-strong); color: var(--muted); font-size: .78rem; }
    .iptv-empty { padding: 2rem; border: 1px dashed var(--line-strong); border-radius: 1rem; color: var(--muted); text-align: center; }
    @media (max-width: 720px) {
        .iptv-header { align-items: flex-start; flex-direction: column; }
        .iptv-filter { grid-template-columns: 1fr; }
    }
</style>
