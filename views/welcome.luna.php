<!DOCTYPE html>
<html lang="{{ config('app.locale') }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="shortcut icon" href="/logo.svg" type="image/x-icon">
  <title>Welcome to {{ config('app.name') }}</title>
  
  <style>
    /* ==========================================================================
       1. CONTRASTE PURO E CORES SÓLIDAS (Estilo Vite / Laravel)
       ========================================================================== */
    :root {
      --bg-body: #0a0a0c;            /* Fundo escuro fosco e limpo */
      --bg-card: #18181b;            /* O Card em tom sólido destacado */
      --bg-sidebar: #202024;         /* Lado direito ligeiramente diferente */
      --border-color: #27272a;       /* Linhas finas mecânicas */
      --text-main: #ffffff;
      --text-muted: #a1a1aa;
      --accent: #ff2d55;             /* A cor oficial do seu projeto */
      --accent-hover: #e62b4f;
      --btn-secondary-bg: #27272a;
      --btn-secondary-hover: #3f3f46;
    }

    [data-theme="light"] {
      --bg-body: #f4f4f5;
      --bg-card: #ffffff;
      --bg-sidebar: #fafafa;
      --border-color: #e4e4e7;
      --text-main: #18181b;
      --text-muted: #71717a;
      --accent: #ff2d55;
      --accent-hover: #e62b4f;
      --btn-secondary-bg: #e4e4e7;
      --btn-secondary-hover: #d4d4d8;
    }

    /* ==========================================================================
       2. RESET COMPLETO
       ========================================================================== */
    * {
      margin: 0;
      padding: 0;
      text-decoration: none;
      box-sizing: border-box;
      font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }

    body {
      background: var(--bg-body);
      color: var(--text-main);
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      padding: 24px;
      transition: background 0.2s ease;
    }

    /* ==========================================================================
       3. TOP BAR (LINKS DE AUTENTICAÇÃO)
       ========================================================================== */
    .top-nav {
      position: absolute;
      top: 24px;
      right: 24px;
      display: flex;
      gap: 16px;
      align-items: center;
      z-index: 10;
    }

    .theme-toggle {
      background: none;
      border: 1px solid var(--border-color);
      color: var(--text-muted);
      cursor: pointer;
      padding: 8px;
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .theme-toggle:hover {
      color: var(--text-main);
      border-color: var(--text-muted);
    }
    .theme-toggle svg { width: 16px; height: 16px; fill: currentColor; }

    .nav-link {
      color: var(--text-muted);
      font-weight: 500;
      font-size: 0.9rem;
    }
    .nav-link:hover { color: var(--text-main); }

    .nav-btn {
      border: 1px solid var(--border-color);
      background: var(--btn-secondary-bg);
      color: var(--text-main);
      padding: 6px 14px;
      border-radius: 6px;
      font-weight: 500;
      font-size: 0.9rem;
    }
    .nav-btn:hover { background: var(--btn-secondary-hover); }

    /* ==========================================================================
       4. O CARD SIMPLES E LINDO (Inspirado em image_00b7e0.png e image_00b7a2.png)
       ========================================================================== */
    .framework-card {
      display: flex;
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: 12px;           /* Cantos suaves identicos aos frameworks */
      overflow: hidden;
      max-width: 900px;
      width: 100%;
    }

    /* Lado Esquerdo: Textos e Ações */
    .card-body {
      flex: 1.2;
      padding: 48px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .card-body h1 {
      font-size: 2.2rem;
      font-weight: 700;
      margin-bottom: 12px;
      color: var(--text-main);
      letter-spacing: -0.5px;
    }

    .card-body p {
      margin-bottom: 28px;
      color: var(--text-muted);
      line-height: 1.6;
      font-size: 1rem;
    }

    /* Lista de links limpa com setinhas baseada no Laravel */
    .framework-links {
      list-style: none;
      margin-bottom: 32px;
    }

    .framework-links li {
      margin: 12px 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .framework-links li span {
      color: var(--text-muted);
      font-size: 0.8rem;
    }

    .framework-links li a {
      color: var(--text-main);
      font-weight: 500;
      font-size: 0.95rem;
    }

    .framework-links li a:hover {
      color: var(--accent);
      text-decoration: underline;
    }

    .btn-main {
      background: var(--accent);
      color: #ffffff;
      border: none;
      padding: 12px 24px;
      border-radius: 6px;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      width: fit-content;
    }

    .btn-main:hover {
      background: var(--accent-hover);
    }

    /* Lado Direito: Área Visual Limpa */
    .card-sidebar {
      flex: 1;
      background: var(--bg-sidebar);
      border-left: 1px solid var(--border-color);
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      padding: 40px;
      position: relative;
    }

    .logo-wrapper {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
    }

    .logo-img {
      width: 80px;
      height: auto;
    }

    .logo-title {
      font-size: 1.3rem;
      font-weight: 600;
      color: var(--text-main);
    }

    /* Versão discreta no rodapé direito */
    .version-tag {
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--text-muted);
      position: absolute;
      bottom: 16px;
      right: 20px;
    }

    /* RESPONSIVIDADE SIMPLES */
    @media (max-width: 768px) {
      body { padding-top: 80px; }
      .framework-card { flex-direction: column-reverse; }
      .card-sidebar { border-left: none; border-bottom: 1px solid var(--border-color); padding: 40px 20px; }
      .card-body { padding: 32px 20px; }
    }
  </style>
</head>
<body>

  <!-- Topbar com as opções de autenticação -->
  <nav class="top-nav">
    <button class="theme-toggle" id="themeBtn" title="Mudar tema">
      <svg id="themeIcon" viewBox="0 0 24 24"></svg>
    </button>
    
    @if (Router::has('login'))
      <a href="{{ route('login') }}" class="nav-link">Log in</a>
    @endif
    
    @if (Router::has('register'))
      <a href="{{ route('register') }}" class="nav-btn">Register</a>
    @endif
  </nav>

  <!-- O Card Padrão de Framework Famoso -->
  <main class="framework-card">
    
    <!-- Parte Textual -->
    <div class="card-body">
      <h1>Slenix PHP</h1>
      <p>The {{ config('app.name') }} has an incredibly rich ecosystem. We suggest starting with the official resources below.</p>
      
      <ul class="framework-links">
        <li>
          <span>➔</span>
          <a href="https://slenix.vercel.app/" target="_blank" rel="noopener">Read the Documentation</a>
        </li>
        <li>
          <span>➔</span>
          <a href="https://github.com/claudiovictors/slenix" target="_blank" rel="noopener">Visit our GitHub Repository</a>
        </li>
      </ul>
      
      <a href="https://github.com/claudiovictors/slenix" target="_blank" rel="noopener" class="btn-main">Deploy now</a>
    </div>

    <!-- Parte Visual do Logo -->
    <div class="card-sidebar">
      <div class="logo-wrapper">
        <img src="{{ asset('logo.svg') }}" alt="Logo" class="logo-img">
        <div class="logo-title">{{ config('app.name') }}</div>
      </div>
      <div class="version-tag">v{{ config('app.version') }}</div>
    </div>

  </main>

  <!-- Script Clean para Alternar Tema -->
  <script>
    const themeBtn = document.getElementById('themeBtn');
    const themeIcon = document.getElementById('themeIcon');
    
    const moon = `<path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.389 5.389 0 0 1-4.4 2.26 5.403 5.403 0 0 1-5.3-5.3c0-1.97 1.06-3.69 2.64-4.64C13.41 3.05 12.71 3 12 3z"/>`;
    const sun = `<path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zM5.99 4.58a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41L5.99 4.58zm12.37 12.37a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41l-1.06-1.06zm-12.37 1.41a.996.996 0 0 0 1.41 0c.39-.39.39-1.03 0-1.41l-1.06-1.06a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06zM18.36 5.64a.996.996 0 0 0 1.41-1.41l-1.06-1.06a.996.996 0 0 0-1.41 1.41l1.06 1.06z"/>`;

    const getMode = () => {
      const saved = localStorage.getItem('theme');
      if (saved) return saved;
      return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    };

    const currentMode = getMode();
    document.documentElement.setAttribute('data-theme', currentMode);
    themeIcon.innerHTML = currentMode === 'light' ? moon : sun;

    themeBtn.addEventListener('click', () => {
      const mode = document.documentElement.getAttribute('data-theme');
      let target = 'dark';
      
      if (mode === 'dark') {
        target = 'light';
        themeIcon.innerHTML = moon;
      } else {
        target = 'dark';
        themeIcon.innerHTML = sun;
      }
      
      document.documentElement.setAttribute('data-theme', target);
      localStorage.setItem('theme', target);
    });
  </script>
</body>
</html>