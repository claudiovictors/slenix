<!DOCTYPE html>
<html lang="{{ config('app.locale') }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="shortcut icon" href="/logo.svg" type="image/x-icon">
  <title>Welcome to {{ config('app.name') }}</title>
  
  <style>
     /* ==========================================================================
       1. ENVIRONMENT VARIABLES (THEME SYSTEM)
       ========================================================================== */

    /* Total Black Theme (Absolute Black / AMOLED) - Default */
    :root {
      --bg-body: #000000;            /* Absolute Black */
      --bg-container: #0a0a0c;       /* Extremely dark graphite for the panel */
      --bg-sidebar: #111113;         /* Slightly highlighted sidebar */
      --border-color: #1f1f23;       /* Subtle borders to preserve the look */
      --text-main: #f4f4f5;
      --text-muted: #a1a1aa;
      --text-inverse: #ffffff;
      --accent: #ff2d55;
      --accent-hover: #e62b4f;
      --accent-alpha: rgba(255, 45, 85, 0.1);
      --shadow: rgba(0, 0, 0, 0.9);  /* Denser shadow for the black background */
      --stroke-alpha: rgba(255, 255, 255, 0.04);
    }

    /* Light Theme (kept for smooth toggling) */
    [data-theme="light"] {
      --bg-body: #f4f4f5;
      --bg-container: #ffffff;
      --bg-sidebar: #f4f4f5;
      --border-color: #e4e4e7;
      --text-main: #18181b;
      --text-muted: #71717a;
      --text-inverse: #18181b;
      --accent: #ff2d55;
      --accent-hover: #e62b4f;
      --accent-alpha: rgba(255, 45, 85, 0.08);
      --shadow: rgba(0, 0, 0, 0.08);
      --stroke-alpha: rgba(0, 0, 0, 0.05);
    }

     /* ==========================================================================
       2. RESET STYLES & GENERAL SETTINGS
       ========================================================================== */
    * {
      margin: 0;
      padding: 0;
      text-decoration: none;
      box-sizing: border-box;
      font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    }

    body {
      background: var(--bg-body);
      color: var(--text-main);
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      position: relative;
      padding: 20px;
      transition: background 0.3s ease, color 0.3s ease;
    }

     /* ==========================================================================
       3. TOP NAVIGATION (TOP BAR)
       ========================================================================== */
    .top-nav {
      position: absolute;
      top: 24px;
      right: 24px;
      display: flex;
      gap: 20px;
      align-items: center;
      z-index: 10;
    }

    /* Theme Toggle (interactive button) */
    .theme-toggle {
      background: none;
      border: 1px solid var(--border-color);
      color: var(--text-muted);
      cursor: pointer;
      padding: 8px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
    }

    .theme-toggle:hover {
      color: var(--text-main);
      background: var(--accent-alpha);
      border-color: var(--accent);
    }

    .theme-toggle svg {
      width: 20px;
      height: 20px;
      fill: currentColor;
    }

    /* Simple Link (Login) with sliding underline effect */
    .nav-link {
      color: var(--text-muted);
      font-weight: 500;
      font-size: 0.95rem;
      position: relative;
      padding: 4px 0;
      transition: color 0.2s ease;
    }

    .nav-link:hover {
      color: var(--text-main);
    }

    .nav-link::after {
      content: '';
      position: absolute;
      width: 100%;
      transform: scaleX(0);
      height: 2px;
      bottom: 0;
      left: 0;
      background-color: var(--accent);
      transform-origin: bottom right;
      transition: transform 0.25s ease-out;
    }

    .nav-link:hover::after {
      transform: scaleX(1);
      transform-origin: bottom left;
    }

    /* Highlighted Navigation Button (Register) */
    .nav-btn {
      background: var(--accent-alpha);
      color: var(--accent);
      border: 1px solid rgba(255, 45, 85, 0.3);
      padding: 8px 18px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 0.95rem;
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .nav-btn:hover {
      background: var(--accent);
      color: #fff;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(255, 45, 85, 0.3);
    }

     /* ==========================================================================
       4. MAIN CONTAINER STRUCTURE
       ========================================================================== */
    .container {
      display: flex;
      background: var(--bg-container);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      overflow: hidden;
      max-width: 950px;
      width: 100%;
      box-shadow: 0 20px 40px var(--shadow);
      transition: background 0.3s ease, border 0.3s ease, box-shadow 0.3s ease;
    }

     /* ==========================================================================
       5. LEFT CONTENT (TEXT AND DOCUMENTATION)
       ========================================================================== */
    .left {
      flex: 1.2;
      padding: 48px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .left h1 {
      font-size: 2.2rem;
      font-weight: 700;
      margin-bottom: 12px;
      color: var(--text-main);
      letter-spacing: -0.5px;
    }

    .left p {
      margin-bottom: 28px;
      color: var(--text-muted);
      line-height: 1.6;
      font-size: 1.05rem;
    }

    .left ul {
      list-style: none;
      margin-bottom: 32px;
    }

    .left ul li {
      margin: 16px 0;
      display: flex;
      align-items: center;
    }

    /* Dynamic list links (subtle sliding micro-interaction) */
    .left ul li a {
      color: var(--accent);
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      transition: all 0.2s ease;
    }

    .left ul li a:hover {
      color: var(--accent-hover);
      transform: translateX(4px);
    }

    /* Primary Call-to-Action Button (Deploy Now) */
    .btn {
      background: var(--accent);
      color: #fff;
      border: none;
      padding: 14px 32px;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      width: fit-content;
      box-shadow: 0 4px 14px rgba(255, 45, 85, 0.35);
    }

    .btn:hover {
      background: var(--accent-hover);
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(255, 45, 85, 0.45);
    }

     /* ==========================================================================
       6. RIGHT CONTENT (BRANDING AND LOGO)
       ========================================================================== */
    .right {
      flex: 1;
      background: var(--bg-sidebar);
      border-left: 1px solid var(--border-color);
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      position: relative;
      padding: 40px;
      transition: background 0.3s ease, border-left 0.3s ease;
    }

    .logo-container {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 16px;
    }

    /* Logo image with floating effect and dynamic glow */
    .logo-img {
      width: 120px;
      height: auto;
      filter: drop-shadow(0 0 20px rgba(255, 45, 85, 0.35));
      animation: float 4s ease-in-out infinite;
    }

    .logo-text {
      font-size: 1.8rem;
      font-weight: 700;
      color: var(--text-inverse);
      letter-spacing: 1px;
    }

    /* App version as a watermark in the background */
    .right h2 {
      font-size: 4rem;
      font-weight: 800;
      color: transparent;
      position: absolute;
      bottom: 16px;
      right: 20px;
      user-select: none;
      -webkit-text-stroke: 1px var(--stroke-alpha);
    }

    /* Inline icons for list items */
    .svg-icon {
      width: 20px;
      height: 20px;
      vertical-align: middle;
      margin-right: 12px;
      fill: var(--accent);
    }

    /* Floating animation for the logo */
    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-8px); }
    }

     /* ==========================================================================
       7. RESPONSIVENESS RULES (MEDIA QUERIES)
       ========================================================================== */
    @media (max-width: 768px) {
      body {
        padding-top: 90px;
      }
      .container {
        flex-direction: column-reverse;
      }
      .right {
        border-left: none;
        border-bottom: 1px solid var(--border-color);
        padding: 60px 20px;
      }
      .left {
        padding: 36px 24px;
      }
      .top-nav {
        top: 20px;
        right: 20px;
      }
    }
  </style>
</head>
<body>

  <nav class="top-nav">
    <button class="theme-toggle" id="themeToggle" title="Toggle theme">
      <svg id="themeIcon" viewBox="0 0 24 24">
      </svg>
    </button>
    
    @if (Router::has('login'))
      <a href="{{ route('login') }}" class="nav-link">Login</a>
    @endif
    
    @if (Router::has('register'))
      <a href="{{ route('register') }}" class="nav-btn">Register</a>
    @endif
  </nav>

  <div class="container">
    
    <div class="left">
      <h1>Slenix PHP</h1>
      <p>The {{ config('app.name') }} has an incredibly rich ecosystem. We suggest starting with the links below.</p>
      
      <ul>
        <li>
          <svg class="svg-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg> 
          <a href="https://slenix.vercel.app/" target="_blank">Read the Documentation</a>
        </li>
        <li>
          <svg class="svg-icon" viewBox="0 0 24 24"><path d="M10 16.5l6-4.5-6-4.5v9zM12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg> 
          <a href="https://github.com/claudiovictors/slenix" target="_blank">Visit our GitHub</a>
        </li>
      </ul>
      
      <a href="https://github.com/claudiovictors/slenix" target="_blank" class="btn">Deploy now</a>
    </div>

    <div class="right">
      <div class="logo-container">
        <img src="{{ asset('logo.svg') }}" alt="Logo {{ config('app.name') }}" class="logo-img">
        <div class="logo-text">{{ config('app.name') }}</div>
      </div>
      <h2>{{ config('app.version') }}</h2>
    </div>

  </div>


  <script>
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');
    
    const moonIcon = `<path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.389 5.389 0 0 1-4.4 2.26 5.403 5.403 0 0 1-5.3-5.3c0-1.97 1.06-3.69 2.64-4.64C13.41 3.05 12.71 3 12 3z"/>`;
    const sunIcon = `<path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zM5.99 4.58a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41L5.99 4.58zm12.37 12.37a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41l-1.06-1.06zm-12.37 1.41a.996.996 0 0 0 1.41 0c.39-.39.39-1.03 0-1.41l-1.06-1.06a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06zM18.36 5.64a.996.996 0 0 0 1.41-1.41l-1.06-1.06a.996.996 0 0 0-1.41 1.41l1.06 1.06z"/>`;

    // Load preference or set default to Total Black ('dark')
    const savedTheme = localStorage.getItem('theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    themeIcon.innerHTML = savedTheme === 'light' ? moonIcon : sunIcon;

    themeToggle.addEventListener('click', () => {
      const currentTheme = document.documentElement.getAttribute('data-theme');
      let newTheme = 'dark';
      
      if (currentTheme === 'dark') {
        newTheme = 'light';
        themeIcon.innerHTML = moonIcon;
      } else {
        newTheme = 'dark';
        themeIcon.innerHTML = sunIcon;
      }
      
      document.documentElement.setAttribute('data-theme', newTheme);
      localStorage.setItem('theme', newTheme);
    });
  </script>
</body>
</html>