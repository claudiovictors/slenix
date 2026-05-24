<!DOCTYPE html>
<html lang="{{ env('APP_LOCALE') }}">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="shortcut icon" href="/logo.svg" type="image/x-icon">
  <title>Slenix - v{{ env('APP_VERSION') }}</title>

  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@500;700;800&display=swap');

    :root {
      --bg: #030303;
      --accent: #FF2D20;
      --card-bg: #0a0a0a;
      --border: #1a1a1a;
      --border-focus: #333333;
      --text-main: #f8fafc;
      --text-dim: #475569;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      background-color: var(--bg);
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      flex-direction: column; /* Altera para alinhar o cabeçalho e o card verticalmente */
      align-items: center;
      justify-content: center;
      padding: 2rem 1rem;
      position: relative;
      overflow-x: hidden;
    }

    /* --- BACKGROUND GRID --- */
    .bg-grid {
      position: fixed;
      inset: 0;
      background-image: 
        linear-gradient(to right, rgba(255, 45, 32, 0.02) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(255, 45, 32, 0.02) 1px, transparent 1px);
      background-size: 50px 50px;
      mask-image: radial-gradient(circle at 50% 50%, black, transparent 80%);
      z-index: -1;
      pointer-events: none;
    }

    /* --- GLOBAL TOP NAV (FORA DO CARD) --- */
    .global-header {
      position: fixed; /* Fixado no topo da página inteira */
      top: 0;
      left: 0;
      width: 100%;
      display: flex;
      justify-content: flex-end; /* Empurra tudo para a direita */
      padding: 1.5rem 2rem;
      z-index: 100;
      pointer-events: none; /* Garante que não quebre cliques na página */
    }

    .top-nav-actions {
      display: flex;
      gap: 0.75rem;
      pointer-events: auto; /* Reativa os cliques apenas nos botões */
    }

    .btn-nav {
      font-family: 'JetBrains Mono', monospace;
      font-size: 12px;
      font-weight: 500;
      text-decoration: none;
      padding: 0.5rem 1rem;
      border-radius: 6px;
      transition: all 0.2s ease;
    }

    .btn-nav.login {
      color: var(--text-main);
      background: transparent;
      border: 1px solid transparent;
    }

    .btn-nav.login:hover {
      border-color: var(--border);
      background: rgba(255, 255, 255, 0.02);
    }

    .btn-nav.register {
      color: var(--bg);
      background: var(--text-main);
      border: 1px solid var(--text-main);
    }

    .btn-nav.register:hover {
      opacity: 0.9;
    }

    /* --- THE MONSTER CARD (WIDTH LONGO) --- */
    .terminal-login-wrapper {
      width: 100%;
      max-width: 900px;
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      z-index: 10;
      margin-top: 2rem; /* Espaço para o header fixo no mobile se necessário */
    }

    /* Lado Esquerdo */
    .auth-side {
      padding: 3.5rem 3rem;
      border-right: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .brand-area {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin-bottom: 2rem;
    }

    .brand-logo {
      width: 28px;
      height: 28px;
      border-radius: 6px;
      border: 1px solid var(--accent);
      padding: 4px;
    }

    .brand-name {
      font-family: 'JetBrains Mono', monospace;
      font-weight: 700;
      font-size: 1.25rem;
      letter-spacing: -0.5px;
    }

    .version-tag {
      font-size: 11px;
      background: rgba(255, 45, 32, 0.1);
      color: var(--accent);
      padding: 2px 6px;
      border-radius: 4px;
      font-weight: 700;
    }

    h1 {
      font-size: 2rem;
      font-weight: 700;
      letter-spacing: -0.8px;
      margin-bottom: 0.5rem;
    }

    .subtitle {
      color: var(--text-dim);
      font-size: 0.9rem;
      margin-bottom: 2.5rem;
    }

    .form-group {
      margin-bottom: 1.25rem;
    }

    .form-group label {
      display: block;
      font-family: 'JetBrains Mono', monospace;
      font-size: 11px;
      color: var(--text-dim);
      margin-bottom: 0.5rem;
      text-transform: uppercase;
    }

    .input-mock {
      width: 100%;
      background: #000;
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 0.75rem 1rem;
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.85rem;
      color: #fff;
      outline: none;
    }

    .btn-action {
      width: 100%;
      background: var(--text-main);
      color: var(--border);
      border: 1px solid var(--border-focus);
      border-radius: 6px;
      padding: 0.85rem;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      margin-top: 1rem;
      transition: all 0.2s ease;
      text-align: center;
      text-decoration: none;
    }

    .btn-action:hover {
      border-color: var(--accent);
      background: rgba(255, 45, 32, 0.02);
    }

    /* Lado Direito */
    .resources-side {
      background: #050505;
      padding: 3.5rem 2.5rem;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 1.25rem;
    }

    .section-title {
      font-family: 'JetBrains Mono', monospace;
      font-size: 11px;
      color: var(--text-dim);
      text-transform: uppercase;
      margin-bottom: 0.5rem;
      letter-spacing: 1px;
    }

    .resource-link {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 1.5rem;
      text-decoration: none;
      color: inherit;
      transition: border-color 0.2s, background 0.2s;
    }

    .resource-link:hover {
      border-color: var(--border-focus);
      background: #0e0e0e;
    }

    .resource-link h3 {
      font-size: 0.95rem;
      font-weight: 600;
      margin-bottom: 0.35rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .resource-link h3::before {
      content: '->';
      color: var(--accent);
      font-family: 'JetBrains Mono', monospace;
    }

    .resource-link p {
      font-size: 0.85rem;
      color: var(--text-dim);
      line-height: 1.4;
    }

    /* --- RESPONSIVIDADE --- */
    @media (max-width: 768px) {
      .global-header {
        position: absolute;
        padding: 1rem;
      }
      .terminal-login-wrapper {
        grid-template-columns: 1fr;
        margin-top: 4rem; /* Evita que o card mobile cole nos botões do topo */
      }
      .auth-side {
        border-right: none;
        border-bottom: 1px solid var(--border);
        padding: 2.5rem 1.5rem;
      }
      .resources-side {
        padding: 2.5rem 1.5rem;
      }
    }
  </style>
</head>

<body>

  <div class="bg-grid"></div>

  <!-- Card Principal Isolado -->
  <main class="terminal-login-wrapper">

    <!-- Lado Esquerdo -->
    <section class="auth-side">
      <div class="brand-area">
        <img class="brand-logo" src="/logo.svg" alt="Slenix" onerror="this.style.removeAttribute('border');">
        <span class="brand-name">slenix_</span>
        <span class="version-tag">v{{ env('APP_VERSION') ?: '2.6' }}</span>
      </div>

      <h1>Welcome to Clarity.</h1>
      <p class="subtitle">A minimalist PHP framework built for execution.</p>

      <div class="form-group">
        <label>Project Environment</label>
        <input type="text" class="input-mock" value="production" readonly>
      </div>

      <div class="form-group">
        <label>App Locale</label>
        <input type="text" class="input-mock" value="environment" readonly>
      </div>

      <a href="/dashboard" class="btn-action">
        Open Application Console
      </a>
    </section>

    <!-- Lado Direito -->
    <section class="resources-side">
      <div class="section-title">// Core Resources</div>

      <a href="https://slenix.vercel.app/" target="_blank" class="resource-link">
        <h3>Documentation</h3>
        <p>Master execution, custom routing, fast query builder architecture, and security layers.</p>
      </a>

      <a href="http://github.com/claudiovictors/slenix" target="_blank" class="resource-link">
        <h3>GitHub Repository</h3>
        <p>Contribute to core components, review upcoming releases, or report structural bugs.</p>
      </a>
    </section>

  </main>

</body>

</html>