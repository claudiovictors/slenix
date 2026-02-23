<!DOCTYPE html>
<html lang="pt-AO">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ env('APP_NAME') }} - Welcome</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Geist+Mono:wght@300;400;500;600&family=Syne:wght@400;600;700;800&display=swap');

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:        #0a0a0a;
      --surface:   #111111;
      --surface2:  #1a1a1a;
      --border:    #262626;
      --border2:   #333333;
      --red:       #e5484d;
      --red-lt:    #ff6b6f;
      --red-dk:    #c0282d;
      --red-deep:  #7d1a1d;
      --red-dim:   rgba(229, 72, 77, 0.10);
      --red-glow:  rgba(229, 72, 77, 0.32);
      --green:     #3dd68c;
      --text:      #ededed;
      --muted:     #888888;
      --dim:       #555555;
      --mono:      'Geist Mono', 'SFMono-Regular', Menlo, Consolas, monospace;
      --display:   'Syne', sans-serif;
    }

    html, body {
      height: 100%;
      background: var(--bg);
      color: var(--text);
      font-family: var(--mono);
      font-size: 14px;
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
    }

    /* noise */
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
      pointer-events: none;
      z-index: 0;
    }

    /* top accent bar */
    .topbar {
      position: fixed;
      top: 0; left: 0; right: 0;
      height: 2px;
      background: linear-gradient(90deg, var(--red-deep), var(--red), var(--red-lt), var(--red), var(--red-deep));
      background-size: 200% 100%;
      animation: shimmer 3s linear infinite;
      z-index: 100;
      box-shadow: 0 0 28px var(--red-glow);
    }

    @keyframes shimmer {
      0%   { background-position: 200% 0; }
      100% { background-position: -200% 0; }
    }

    /* layout */
    .page {
      position: relative;
      z-index: 1;
      min-height: 100vh;
      display: grid;
      grid-template-rows: auto 1fr auto;
    }

    /* ── HEADER ── */
    .header {
      padding: 1.25rem 2.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid var(--border);
    }

    .wordmark {
      display: flex;
      align-items: center;
      gap: .75rem;
      font-family: var(--display);
      font-weight: 800;
      font-size: 1.05rem;
      letter-spacing: -.02em;
      color: var(--text);
      text-decoration: none;
    }

    /* Inline gem SVG in header */
    .wordmark-gem {
      width: 28px;
      height: 28px;
      flex-shrink: 0;
    }

    .header-meta {
      display: flex;
      align-items: center;
      gap: 1rem;
      font-size: .7rem;
      color: var(--muted);
    }

    .version-badge {
      font-family: var(--mono);
      font-size: .68rem;
      font-weight: 600;
      border: 1px solid var(--border2);
      border-radius: 4px;
      padding: 2px 8px;
      color: var(--muted);
      letter-spacing: .04em;
    }

    .php-badge {
      color: var(--dim);
      font-size: .68rem;
    }

    /* ── MAIN GRID ── */
    .main {
      display: grid;
      grid-template-columns: 1fr 1fr;
      min-height: calc(100vh - 57px - 52px);
    }

    /* ── LEFT PANEL ── */
    .panel-left {
      padding: 4rem 3rem 4rem 3.5rem;
      display: flex;
      flex-direction: column;
      justify-content: center;
      border-right: 1px solid var(--border);
      position: relative;
      overflow: hidden;
    }

    .panel-left::after {
      content: '';
      position: absolute;
      bottom: -1px; right: -1px;
      width: 140px; height: 140px;
      background-image:
        linear-gradient(var(--border) 1px, transparent 1px),
        linear-gradient(90deg, var(--border) 1px, transparent 1px);
      background-size: 20px 20px;
      opacity: .4;
      mask-image: radial-gradient(circle at bottom right, black 30%, transparent 70%);
    }

    .greeting {
      font-size: .7rem;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: .14em;
      color: var(--red);
      margin-bottom: 1.25rem;
      display: flex;
      align-items: center;
      gap: .6rem;
    }

    .greeting::before {
      content: '';
      display: inline-block;
      width: 22px; height: 1px;
      background: var(--red);
    }

    .headline {
      font-family: var(--display);
      font-size: 3rem;
      font-weight: 800;
      line-height: 1.05;
      letter-spacing: -.04em;
      color: var(--text);
      margin-bottom: 1.25rem;
    }

    .headline span { color: var(--red); }

    .description {
      font-size: .8rem;
      color: var(--muted);
      line-height: 1.8;
      max-width: 380px;
      margin-bottom: 2.5rem;
    }

    /* links */
    .links {
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: .5rem;
      margin-bottom: 2.5rem;
    }

    .links li a {
      display: flex;
      align-items: center;
      gap: .75rem;
      padding: .65rem .9rem;
      border: 1px solid var(--border);
      border-radius: 6px;
      background: var(--surface);
      color: var(--text);
      text-decoration: none;
      font-size: .78rem;
      font-weight: 500;
      transition: border-color .15s, background .15s, transform .15s;
    }

    .links li a:hover {
      border-color: var(--red);
      background: var(--red-dim);
      transform: translateX(4px);
    }

    .link-icon {
      width: 30px; height: 30px;
      background: var(--surface2);
      border: 1px solid var(--border2);
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .link-icon svg {
      width: 14px; height: 14px;
      fill: var(--muted);
      transition: fill .15s;
    }

    .links li a:hover .link-icon svg { fill: var(--red); }

    .link-label { display: flex; flex-direction: column; gap: .1rem; }
    .link-title  { color: var(--text); font-size: .78rem; }
    .link-sub    { color: var(--dim);  font-size: .68rem; font-weight: 400; }

    /* CTAs */
    .cta-row {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .btn-primary {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      background: var(--red);
      color: #fff;
      border: none;
      padding: .7rem 1.4rem;
      border-radius: 6px;
      font-family: var(--mono);
      font-size: .78rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      transition: background .15s, box-shadow .15s;
      letter-spacing: .02em;
    }

    .btn-primary:hover {
      background: var(--red-dk);
      box-shadow: 0 0 24px var(--red-glow);
    }

    .btn-ghost {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      background: transparent;
      color: var(--muted);
      border: 1px solid var(--border2);
      padding: .7rem 1.2rem;
      border-radius: 6px;
      font-family: var(--mono);
      font-size: .78rem;
      cursor: pointer;
      text-decoration: none;
      transition: border-color .15s, color .15s;
    }

    .btn-ghost:hover {
      border-color: var(--muted);
      color: var(--text);
    }

    /* ── RIGHT PANEL ── */
    .panel-right {
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      background: var(--surface);
    }

    /* grid bg */
    .panel-right::before {
      content: '';
      position: absolute;
      inset: 0;
      background-image:
        linear-gradient(var(--border) 1px, transparent 1px),
        linear-gradient(90deg, var(--border) 1px, transparent 1px);
      background-size: 40px 40px;
      opacity: .45;
    }

    /* radial glow behind gem */
    .panel-right::after {
      content: '';
      position: absolute;
      top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      width: 340px; height: 340px;
      background: radial-gradient(circle, rgba(229,72,77,.18) 0%, transparent 70%);
      pointer-events: none;
    }

    .brand-center {
      position: relative;
      z-index: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 2rem;
    }

    /* Big gem logo */
    .hero-gem {
      width: 180px;
      height: 180px;
      filter: drop-shadow(0 0 32px rgba(229,72,77,.5));
      animation: float 4s ease-in-out infinite;
    }

    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50%       { transform: translateY(-10px); }
    }

    /* wordmark under gem */
    .hero-wordmark {
      font-family: var(--display);
      font-size: 2.6rem;
      font-weight: 800;
      letter-spacing: -.05em;
      background: linear-gradient(135deg, var(--text) 30%, var(--red-lt) 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      line-height: 1;
    }

    /* terminal box */
    .terminal {
      background: var(--bg);
      border: 1px solid var(--border2);
      border-radius: 8px;
      padding: .8rem 1.2rem;
      font-size: .7rem;
      color: var(--muted);
      min-width: 260px;
      position: relative;
    }

    .terminal::before {
      content: '';
      position: absolute;
      top: -1px; left: 28px; right: 28px;
      height: 1px;
      background: linear-gradient(90deg, transparent, var(--red), transparent);
    }

    .terminal-line {
      display: flex;
      align-items: center;
      gap: .5rem;
      margin: .2rem 0;
    }

    .terminal-prompt { color: var(--red); }
    .terminal-cmd    { color: var(--green); }
    .terminal-val    { color: var(--text); }
    .terminal-dim    { color: var(--dim); }

    /* ghosted version number bottom-right */
    .corner-version {
      position: absolute;
      bottom: 1.5rem; right: 1.75rem;
      font-family: var(--display);
      font-size: 5rem;
      font-weight: 800;
      letter-spacing: -.05em;
      line-height: 1;
      color: transparent;
      -webkit-text-stroke: 1px var(--border2);
      user-select: none;
      pointer-events: none;
      z-index: 1;
    }

    /* ── FOOTER ── */
    .footer {
      border-top: 1px solid var(--border);
      padding: .9rem 2.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: .68rem;
      color: var(--dim);
    }

    .footer a {
      color: var(--dim);
      text-decoration: none;
      transition: color .15s;
    }
    .footer a:hover { color: var(--red); }

    .footer-right {
      display: flex;
      align-items: center;
      gap: 1.25rem;
    }

    .status-dot {
      width: 6px; height: 6px;
      background: var(--green);
      border-radius: 50%;
      display: inline-block;
      margin-right: .35rem;
      box-shadow: 0 0 6px rgba(61,214,140,.7);
      animation: blink 2s ease-in-out infinite;
    }

    @keyframes blink {
      0%, 100% { opacity: 1; }
      50%       { opacity: .4; }
    }

    /* ── RESPONSIVE ── */
    @media (max-width: 768px) {
      .main { grid-template-columns: 1fr; }
      .panel-right { min-height: 280px; border-top: 1px solid var(--border); }
      .panel-left  { padding: 2.5rem 1.5rem; }
      .headline    { font-size: 2.25rem; }
      .hero-gem    { width: 120px; height: 120px; }
      .hero-wordmark { font-size: 2rem; }
      .corner-version { font-size: 3rem; }
      .header      { padding: 1.25rem 1.5rem; }
      .footer      { flex-direction: column; gap: .5rem; text-align: center; }
    }

    /* ── FADE-IN ANIMATIONS ── */
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(16px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    .greeting     { animation: fadeUp .5s ease both; animation-delay: .1s; }
    .headline     { animation: fadeUp .5s ease both; animation-delay: .2s; }
    .description  { animation: fadeUp .5s ease both; animation-delay: .3s; }
    .links        { animation: fadeUp .5s ease both; animation-delay: .4s; }
    .cta-row      { animation: fadeUp .5s ease both; animation-delay: .5s; }
    .brand-center { animation: fadeUp .6s ease both; animation-delay: .2s; }
  </style>
</head>
<body>

<div class="topbar"></div>

<div class="page">

  <!-- ── HEADER ── -->
  <header class="header">
    <a href="/" class="wordmark">
      <!-- Gem mark inline SVG (small) -->
      <svg class="wordmark-gem" viewBox="0 0 56 56" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="wg" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%"  stop-color="#ff6b6f"/>
            <stop offset="45%" stop-color="#e5484d"/>
            <stop offset="100%" stop-color="#7d1a1d"/>
          </linearGradient>
          <linearGradient id="wt" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%"  stop-color="#ffadaf" stop-opacity=".9"/>
            <stop offset="100%" stop-color="#e5484d" stop-opacity="0"/>
          </linearGradient>
          <filter id="wglow">
            <feGaussianBlur stdDeviation="1.5" result="b"/>
            <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
          </filter>
        </defs>
        <g transform="translate(28,28)" filter="url(#wglow)">
          <polygon points="0,-24 17,-17 24,0 17,17 0,24 -17,17 -24,0 -17,-17" fill="url(#wg)"/>
          <polygon points="0,-24 -17,-17 0,-6 17,-17" fill="url(#wt)" opacity=".85"/>
          <polygon points="17,-17 24,0 9,0 0,-6"      fill="#c0282d" opacity=".65"/>
          <polygon points="0,-6 9,0 0,6 -9,0"         fill="#ff6b6f" opacity=".45"/>
          <polygon points="0,6 9,0 17,17 0,24 -17,17 -24,0 -9,0" fill="#7d1a1d" opacity=".22"/>
          <line x1="-8" y1="-19" x2="3" y2="-10" stroke="#fff" stroke-width="1.2" stroke-opacity=".22" stroke-linecap="round"/>
        </g>
      </svg>
      {{ env('APP_NAME') }}
    </a>

    <div class="header-meta">
      <span class="version-badge">v{{ env('APP_VERSION') }}</span>
      <span class="php-badge">PHP <?php echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION; ?></span>
    </div>
  </header>

  <!-- ── MAIN ── -->
  <main class="main">

    <!-- LEFT: copy + links -->
    <section class="panel-left">
      <span class="greeting">Ready to build</span>

      <h1 class="headline">
        Let's get<br><span>started.</span>
      </h1>

      <p class="description">
        {{ env('APP_NAME') }} has an incredibly rich ecosystem.
        We suggest starting with the following resources.
      </p>

      <ul class="links">
        <li>
          <a href="https://github.com/claudiovictors/slenix" target="_blank" rel="noopener">
            <span class="link-icon">
              <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>
            </span>
            <span class="link-label">
              <span class="link-title">Read the Documentation</span>
              <span class="link-sub">github.com/claudiovictors/slenix</span>
            </span>
          </a>
        </li>
        <li>
          <a href="http://instagram.com/slenixphp" target="_blank" rel="noopener">
            <span class="link-icon">
              <svg viewBox="0 0 24 24"><path d="M10 16.5l6-4.5-6-4.5v9zM12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>
            </span>
            <span class="link-label">
              <span class="link-title">Video Tutorials &amp; Screencasts</span>
              <span class="link-sub">instagram.com/slenixphp</span>
            </span>
          </a>
        </li>
        <li>
          <a href="https://github.com/claudiovictors/slenix/issues" target="_blank" rel="noopener">
            <span class="link-icon">
              <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
            </span>
            <span class="link-label">
              <span class="link-title">Report an Issue</span>
              <span class="link-sub">github.com/claudiovictors/slenix/issues</span>
            </span>
          </a>
        </li>
      </ul>

      <div class="cta-row">
        <a href="https://github.com/claudiovictors/slenix" target="_blank" class="btn-primary">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.3 3.44 9.8 8.2 11.38.6.11.82-.26.82-.57v-2c-3.34.72-4.04-1.61-4.04-1.61-.55-1.39-1.34-1.76-1.34-1.76-1.09-.75.08-.73.08-.73 1.21.08 1.84 1.24 1.84 1.24 1.07 1.84 2.81 1.31 3.5 1 .11-.78.42-1.31.76-1.61-2.67-.3-5.47-1.33-5.47-5.93 0-1.31.47-2.38 1.24-3.22-.14-.3-.54-1.52.1-3.18 0 0 1.01-.32 3.3 1.23a11.5 11.5 0 0 1 3-.4c1.02.004 2.04.14 3 .4 2.28-1.55 3.29-1.23 3.29-1.23.64 1.66.24 2.88.12 3.18.77.84 1.23 1.91 1.23 3.22 0 4.61-2.81 5.63-5.48 5.92.43.37.81 1.1.81 2.22v3.29c0 .32.21.69.82.57C20.56 21.8 24 17.3 24 12c0-6.63-5.37-12-12-12z"/></svg>
          Deploy now
        </a>
        <a href="https://github.com/claudiovictors/slenix" target="_blank" class="btn-ghost">
          View source
        </a>
      </div>
    </section>

    <!-- RIGHT: hero gem + terminal -->
    <section class="panel-right">
      <div class="brand-center">

        <!-- Big animated gem -->
        <svg class="hero-gem" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
          <defs>
            <linearGradient id="hg" x1="0%" y1="0%" x2="100%" y2="100%">
              <stop offset="0%"   stop-color="#ff6b6f"/>
              <stop offset="45%"  stop-color="#e5484d"/>
              <stop offset="100%" stop-color="#7d1a1d"/>
            </linearGradient>
            <linearGradient id="ht" x1="0%" y1="0%" x2="100%" y2="100%">
              <stop offset="0%"   stop-color="#ffb3b5" stop-opacity=".92"/>
              <stop offset="100%" stop-color="#e5484d" stop-opacity=".05"/>
            </linearGradient>
            <linearGradient id="hd" x1="0%" y1="0%" x2="100%" y2="100%">
              <stop offset="0%"   stop-color="#c0282d" stop-opacity=".7"/>
              <stop offset="100%" stop-color="#7d1a1d"/>
            </linearGradient>
            <filter id="hglow">
              <feGaussianBlur stdDeviation="5" result="b"/>
              <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
            </filter>
          </defs>
          <g transform="translate(100,100)" filter="url(#hglow)">
            <!-- 8-facet gem -->
            <polygon points="0,-86 61,-61 86,0 61,61 0,86 -61,61 -86,0 -61,-61" fill="url(#hg)"/>
            <!-- top facet (light) -->
            <polygon points="0,-86 -61,-61 0,-22 61,-61" fill="url(#ht)" opacity=".88"/>
            <!-- right facet -->
            <polygon points="61,-61 86,0 32,0 0,-22"     fill="#c0282d" opacity=".62"/>
            <!-- center diamond -->
            <polygon points="0,-22 32,0 0,22 -32,0"      fill="#ff6b6f" opacity=".42"/>
            <!-- left facet -->
            <polygon points="-61,-61 0,-22 -32,0 -86,0"  fill="url(#hd)" opacity=".55"/>
            <!-- bottom -->
            <polygon points="0,22 32,0 61,61 0,86 -61,61 -86,0 -32,0" fill="#7d1a1d" opacity=".24"/>
            <!-- shine streak -->
            <line x1="-26" y1="-68" x2="10" y2="-38" stroke="#fff" stroke-width="2.5" stroke-opacity=".2" stroke-linecap="round"/>
            <!-- secondary shimmer -->
            <circle cx="-40" cy="-48" r="3" fill="#fff" fill-opacity=".1"/>
          </g>
        </svg>

        <!-- wordmark -->
        <div class="hero-wordmark">{{ env('APP_NAME') }}</div>

        <!-- terminal -->
        <div class="terminal">
          <div class="terminal-line">
            <span class="terminal-prompt">$</span>
            <span class="terminal-cmd">php</span>
            <span class="terminal-val">celestial serve</span>
          </div>
          <div class="terminal-line">
            <span class="terminal-dim">→</span>
            <span class="terminal-val">Server running on</span>
            <span class="terminal-cmd">{{ "http://127.0.0.1:8080" }}</span>
          </div>
          <div class="terminal-line">
            <span class="terminal-dim">→</span>
            <span class="terminal-val">Environment:</span>
            <span class="terminal-cmd">{{ env('APP_ENV', 'local') }}</span>
          </div>
          <div class="terminal-line">
            <span class="terminal-dim">→</span>
            <span class="terminal-val">Debug:</span>
            <span class="terminal-cmd">{{ env('APP_DEBUG', 'false') }}</span>
          </div>
        </div>

      </div>

      <!-- ghost version number -->
      <div class="corner-version">{{ env('APP_VERSION') }}</div>
    </section>

  </main>

  <!-- ── FOOTER ── -->
  <footer class="footer">
    <span>
      <span class="status-dot"></span>
      {{ env('APP_NAME') }} Framework &mdash; Desenvolvido com ♥ por
      <a href="https://github.com/claudiovictors" target="_blank">Cláudio Victor</a>
    </span>
    <div class="footer-right">
      <a href="https://github.com/claudiovictors/slenix" target="_blank">GitHub</a>
      <a href="https://github.com/claudiovictors/slenix/issues" target="_blank">Issues</a>
      <a href="http://instagram.com/slenixphp" target="_blank">Instagram</a>
    </div>
  </footer>

</div>
</body>
</html>