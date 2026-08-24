@extends('layouts.base')

@section('content')
<a class="skip-link" href="#top">Skip to content</a>

<div class="progress" aria-hidden="true"><i></i></div>

<div class="aurora" aria-hidden="true">
  <i class="b1"></i><i class="b2"></i><i class="b3"></i><i class="b4"></i>
</div>
<div class="grain" aria-hidden="true"></div>

<!-- ═══ NAV ═══ -->
<header class="nav glass">
  <a class="brand" href="#top">
    <span class="orbit-ring" aria-hidden="true"><i></i></span>
    Odissey
  </a>
  <nav class="nav-links">
    <a href="#platform">Platform</a>
    <a href="#live-tv">Live TV</a>
    <a href="#playback">Playback</a>
    <a href="#pricing">Pricing</a>
  </nav>
  <a class="btn btn-primary btn-sm" href="#pricing">View plans</a>
</header>

<main id="top">

  <!-- ═══ HERO ═══ -->
  <section class="hero">
    <div class="hero-orbit" aria-hidden="true"></div>

    <div class="chip glass c1"><span class="dot"></span><div>4 sources online<small>Local · S3 · WebDAV · IPTV</small></div></div>
    <div class="chip glass c2"><div>Direct play<small>FFmpeg online</small></div></div>
    <div class="chip glass c3"><div>1080p · Captions<small>The Crossing · E6</small></div></div>
    <div class="chip glass c4"><div>EPG synced<small>5 channels · hourly</small></div></div>

    <div class="hero-inner">
      <span class="eyebrow-pill beam"><span class="dot"></span>Your media operating system</span>
      <h1>
        <span class="row">Every source.</span>
        <span class="row">Every screen.</span>
        <span class="row shimmer">One Odissey.</span>
      </h1>
      <p class="hero-sub">A fast, private home for movies, series, music and live TV — built around the media you already control.</p>
      <div class="hero-ctas">
        <a class="btn btn-primary" href="#platform">Explore Odissey</a>
        <a class="btn btn-ghost" href="#pricing">Self-host for free</a>
      </div>
      <p class="hero-note">Self-hosted <b>$0</b> · Hosted <em>from $10/mo · soon</em></p>
    </div>
  </section>

  <!-- ═══ MARQUEE ═══ -->
  <div class="marquee" aria-hidden="true">
    <div class="marquee-track">
      <span>Local files <b>✦</b> S3 compatible <b>✦</b> WebDAV <b>✦</b> IPTV <b>✦</b> Movies <b>✦</b> Series <b>✦</b> Music <b>✦</b> Live TV <b>✦</b> Direct play <b>✦</b> Captions <b>✦</b></span>
      <span>Local files <b>✦</b> S3 compatible <b>✦</b> WebDAV <b>✦</b> IPTV <b>✦</b> Movies <b>✦</b> Series <b>✦</b> Music <b>✦</b> Live TV <b>✦</b> Direct play <b>✦</b> Captions <b>✦</b></span>
    </div>
  </div>

  <!-- ═══ PLATFORM · sticky stack ═══ -->
  <section id="platform">
    <div class="shell">
      <div class="sec-head reveal">
        <span class="eyebrow">One system, no silos</span>
        <h2>Your collection should feel <em>intentional.</em></h2>
        <p>Four ideas, stacked. Scroll — each one takes the stage while the last quietly steps back.</p>
      </div>

      <div class="stack">
        <article class="stack-card glass" style="--i:1">
          <div>
            <div class="card-num">01 / CATALOG</div>
            <h3>Unified catalog</h3>
            <p>Every source becomes one considered library. Local disks, S3-compatible storage, WebDAV and IPTV — cataloged together, enriched automatically.</p>
            <div class="card-tags"><span>Local</span><span>S3 compatible</span><span>WebDAV</span><span>IPTV</span></div>
          </div>
          <div class="visual">
            <div class="constellation">
              <div class="orbit-path"></div>
              <img class="core orbit-ring" alt="" src="/favicon.svg" style="width:64px;border-radius:14px;-webkit-mask:none;mask:none;background:none;animation:none">
              <div class="planet"><b>Local</b></div>
              <div class="planet"><b>S3</b></div>
              <div class="planet"><b>WebDAV</b></div>
              <div class="planet"><b>IPTV</b></div>
            </div>
          </div>
        </article>

        <article class="stack-card glass" style="--i:2">
          <div>
            <div class="card-num">02 / PROFILES</div>
            <h3>Multi-user</h3>
            <p>Your progress stays yours. Individual profiles with personal continue-watching, favorites and history — per household member.</p>
            <div class="card-tags"><span>Per-user progress</span><span>Favorites</span><span>History</span></div>
          </div>
          <div class="visual">
            <div class="profiles">
              <div class="profile"><div class="ring" style="--p:72;--c:var(--cyan)"><i>A</i></div><b>72%</b><span>Alex</span></div>
              <div class="profile"><div class="ring" style="--p:41;--c:var(--accent)"><i>J</i></div><b>41%</b><span>June</span></div>
              <div class="profile"><div class="ring" style="--p:88;--c:var(--violet)"><i>K</i></div><b>88%</b><span>Kai</span></div>
            </div>
          </div>
        </article>

        <article class="stack-card glass" style="--i:3">
          <div>
            <div class="card-num">03 / METADATA</div>
            <h3>Metadata</h3>
            <p>Artwork and context, automatically. Posters, episodes, captions and rich detail arrive without you lifting a finger.</p>
            <div class="card-tags"><span>Artwork</span><span>Episodes</span><span>Captions</span></div>
          </div>
          <div class="visual">
            <div class="meta-cards">
              <div class="meta-card"><b>Quiet Current</b><i></i></div>
              <div class="meta-card"><b>The Crossing</b><i></i></div>
              <div class="meta-card"><b>Afterlight</b><i></i></div>
            </div>
          </div>
        </article>

        <article class="stack-card glass" style="--i:4">
          <div>
            <div class="card-num">04 / SCREENS</div>
            <h3>Every screen</h3>
            <p>Designed for touch, keyboard and the living room. One interface that feels native on the couch, the desk and the commute.</p>
            <div class="card-tags"><span>Web</span><span>Mobile</span><span>TV</span></div>
          </div>
          <div class="visual">
            <div class="screens">
              <div class="tv"><div class="scan"></div></div>
              <div class="phone"><div class="scan"></div></div>
              <div class="laptop"><div class="scan"></div></div>
            </div>
          </div>
        </article>
      </div>
    </div>
  </section>

  <!-- ═══ LIVE TV ═══ -->
  <section id="live-tv">
    <div class="shell">
      <div class="sec-head reveal">
        <span class="eyebrow">Live TV, made legible</span>
        <h2>Tonight is already <em>organized.</em></h2>
      </div>
      <div class="epg glass reveal">
        <div class="epg-times"><span>Channels</span><span>20:00</span><span>20:30</span><span>21:00</span><span>21:30</span></div>
        <div class="nowline" aria-hidden="true"></div>
        <div class="epg-row"><div class="ch"><i>NW</i>News Wire</div>
          <div class="epg-track"><span class="epg-block" style="--x:0%;--w:31%">Evening Wire</span><span class="epg-block" style="--x:33%;--w:31%">Headlines</span><span class="epg-block" style="--x:66%;--w:32%">World Brief</span></div></div>
        <div class="epg-row"><div class="ch"><i>S1</i>Screen One</div>
          <div class="epg-track"><span class="epg-block live" style="--x:0%;--w:48%">The Crossing · Live</span><span class="epg-block" style="--x:50%;--w:48%">Afterlight</span></div></div>
        <div class="epg-row"><div class="ch"><i>SL</i>Sportline</div>
          <div class="epg-track"><span class="epg-block" style="--x:0%;--w:23%">Pre-game</span><span class="epg-block" style="--x:25%;--w:56%">Match Night</span><span class="epg-block" style="--x:83%;--w:15%">Recap</span></div></div>
        <div class="epg-row"><div class="ch"><i>FQ</i>Frequency</div>
          <div class="epg-track"><span class="epg-block" style="--x:0%;--w:40%">Night Set</span><span class="epg-block" style="--x:42%;--w:40%">Deep Cuts</span></div></div>
        <div class="epg-row"><div class="ch"><i>DW</i>Deep World</div>
          <div class="epg-track"><span class="epg-block" style="--x:0%;--w:65%">Blue Abyss · Documentary</span><span class="epg-block" style="--x:67%;--w:31%">Field Notes</span></div></div>
        <div class="epg-stats">
          <span><b>1 click</b> guide to playback</span>
          <span><b>2 hours</b> next-up context</span>
          <span>Hourly EPG refresh</span>
          <span>Groups, logos &amp; favorites</span>
        </div>
      </div>
    </div>
  </section>

  <!-- ═══ PLAYBACK ═══ -->
  <section id="playback">
    <div class="shell">
      <div class="playback-grid">
        <div class="reveal">
          <span class="eyebrow">Playback that understands the screen</span>
          <h2>Direct when possible. Converted only when needed.</h2>
          <div class="mini-feats">
            <div><b>Ambient</b>Lighting that matches the scene</div>
            <div><b>Captions</b>Ready on every track</div>
            <div><b>Private</b>Nothing leaves your network</div>
            <div><b>Living room</b>Remote &amp; keyboard controls</div>
          </div>
        </div>
        <div class="player-wrap reveal">
          <div class="ambient" aria-hidden="true"></div>
          <div class="player glass">
            <div class="player-screen">
              <button class="play-btn" aria-label="Play"></button>
              <div class="caption">— We keep moving. —</div>
              <div class="timeline"><i></i></div>
            </div>
            <div class="player-meta">
              <div class="title">The Crossing <small>Season 1 · Episode 6 · 42% watched</small></div>
              <div class="pills"><span class="hot">Direct play</span><span>1920×1080</span><span>8.4 Mbps</span><span>50 FPS</span><span>H.264</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ═══ ARCHITECTURE ═══ -->
  <section>
    <div class="shell">
      <div class="sec-head reveal">
        <span class="eyebrow">Self-contained by design</span>
        <h2>One image. Your infrastructure. <em>No media lock-in.</em></h2>
      </div>
      <div class="flow reveal">
        <div class="flow-node glass"><b>Sources</b><span>Local · S3 · WebDAV · IPTV</span></div>
        <div class="flow-arrow"></div>
        <div class="flow-node glass core beam"><b>Odissey</b><span>Catalog · profiles · playback</span></div>
        <div class="flow-arrow"></div>
        <div class="flow-node glass"><b>Your screens</b><span>Web · mobile · TV</span></div>
      </div>
      <div class="privacy-line reveal">
        <span>No uploads</span><span>No advertising</span><span>No analytics</span><span>Encrypted credentials</span>
      </div>
    </div>
  </section>

  <!-- ═══ PRICING ═══ -->
  <section id="pricing">
    <div class="shell">
      <div class="sec-head reveal">
        <span class="eyebrow">Choose who runs the server</span>
        <h2>Free to self-host. Effortless hosting is <em>coming.</em></h2>
      </div>
      <div class="plans">
        <article class="plan glass reveal">
          <span class="badge ok">Available</span>
          <h3>Self-hosted</h3>
          <div class="price">$0 <small>forever</small></div>
          <ul>
            <li>Full feature set — nothing held back</li>
            <li>One Docker image, your hardware</li>
            <li>Every source type included</li>
            <li>You run it, you own it</li>
          </ul>
          <a class="btn btn-ghost" href="#top">Self-host for free</a>
        </article>
        <article class="plan glass featured beam reveal">
          <span class="badge soon">Coming soon</span>
          <h3>Odissey Hosted</h3>
          <div class="price">$10 <small>/ month · from</small></div>
          <ul>
            <li>No server administration</li>
            <li>Managed upgrades</li>
            <li>Encrypted backups</li>
            <li>Full feature set, run for you</li>
          </ul>
          <a class="btn btn-primary" href="#faq">Compare plans</a>
        </article>
      </div>
    </div>
  </section>

  <!-- ═══ FAQ ═══ -->
  <section id="faq">
    <div class="shell">
      <div class="sec-head reveal">
        <span class="eyebrow">Questions</span>
        <h2>Fair things to <em>ask.</em></h2>
      </div>
      <div class="faq-list reveal">
        <details open>
          <summary>Does Odissey provide movies or television channels?</summary>
          <p>No. Odissey is software for media you are already authorized to use. It provides no content, no IPTV service, and no subscriptions to third-party libraries.</p>
        </details>
        <details>
          <summary>Where does my media stay?</summary>
          <p>Exactly where it is today — your disks, your S3-compatible storage, your WebDAV shares. Odissey catalogs and streams from your sources; nothing is uploaded.</p>
        </details>
        <details>
          <summary>What is included in the free version?</summary>
          <p>Everything. The self-hosted edition is the full product — catalog, multi-user profiles, metadata, live TV, playback — at $0 forever.</p>
        </details>
        <details>
          <summary>When will Odissey Hosted launch?</summary>
          <p>Hosted is in active development and will open from $10/month. The self-hosted edition remains free and fully featured either way.</p>
        </details>
        <details>
          <summary>Can household members have separate profiles?</summary>
          <p>Yes. Each person gets their own profile with independent progress, favorites and history.</p>
        </details>
      </div>
    </div>
  </section>

  <!-- ═══ FINAL CTA ═══ -->
  <section class="cta">
    <div class="cta-bg" aria-hidden="true"></div>
    <div class="cta-inner">
      <span class="eyebrow reveal">The next thing to watch is already yours</span>
      <h2 class="reveal">Give it a better home.</h2>
      <a class="btn btn-primary reveal" href="#pricing">Compare plans</a>
    </div>
  </section>

</main>

<section class="shell" id="subscribe">
  <div class="sec-head">
    <span class="kicker">03 · SIGNALS?</span>
    <h2>Get launch <em>updates.</em></h2>
    <p>Odissey Hosted opens soon from $10/month. Leave an address and we will ping you once — no newsletters, no noise.</p>
  </div>
  <div id="subscribe-target">
@include('partials.subscribe')
  </div>
</section>

<footer>
  <div class="footer-inner">
    <a class="brand" href="#top"><span class="orbit-ring" aria-hidden="true"><i></i></span>Odissey</a>
    <p>A private media home for sources you are authorized to use. Odissey provides no media or IPTV service.</p>
    <p class="fine">© 2026 Odissey</p>
  </div>
</footer>
@endsection
