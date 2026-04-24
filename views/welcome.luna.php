<!DOCTYPE html>
<html lang="{{ env('APP_LOCALE') }}">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="shortcut icon" href="/logo.svg" type="image/x-icon">
  <title>Slenix - v{{ env('APP_VERSION') }}</title>

  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&family=JetBrains+Mono:wght@800&display=swap');

    :root {
      --bg: #050505;
      --accent: #FF2D20; 
      --glass: rgba(255, 255, 255, 0.03);
      --border: rgba(255, 255, 255, 0.08);
      --text-main: #e2e8f0;
      --text-dim: #64748b;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'Inter', sans-serif;
      background-color: var(--bg);
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden; /* Importante para o bg 3D não criar scroll */
      position: relative;
    }

    /* --- BACKGROUND VERSION 3D EFFECT --- */
    .bg-version-3d {
      position: fixed;
      inset: 0;
      left: -20%;
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: -2;
      perspective: 1000px;
      overflow: hidden;
      pointer-events: none;
    }

    .version-text-3d {
      font-family: 'JetBrains Mono', monospace;
      font-weight: 800;
      font-size: 20vw; /* Se ainda não aparecer, tente 400px */
      line-height: 1;
      color: rgba(255, 45, 32, 0.03); /* Cor sólida bem clarinha */
      -webkit-text-stroke: 2px rgba(255, 45, 32, 0.15); /* Contorno mais grosso */
      
      /* Transformação fixa para teste inicial */
      transform: rotateX(25deg) rotateY(-20deg);
      opacity: 0.8;
      white-space: nowrap;
      user-select: none;
    }

    @keyframes float3D {
      0%, 100% { transform: rotateX(20deg) rotateY(-30deg) translateZ(-200px) translateY(0px); }
      50% { transform: rotateX(25deg) rotateY(-25deg) translateZ(-150px) translateY(-30px); }
    }

    /* Grid de fundo sutil (sobre o texto 3D) */
    .bg-grid {
      position: fixed;
      inset: 0;
      background-image: radial-gradient(var(--border) 1px, transparent 1px);
      background-size: 50px 50px;
      mask-image: radial-gradient(circle at 50% 50%, black, transparent 90%);
      z-index: -1;
      pointer-events: none;
    }

    /* --- CONTEÚDO PRINCIPAL (Sem alterações) --- */
    .main-container {
      width: 90%;
      max-width: 1200px;
      display: grid;
      grid-template-columns: 1.2fr 0.8fr;
      gap: 2rem;
      position: relative;
      z-index: 10; /* Garante que fique acima do background */
    }

    .hero-section {
      padding: 2rem;
    }

    .badge {
      display: inline-block;
      padding: 4px 12px;
      background: rgba(255, 45, 32, 0.1);
      border: 1px solid var(--accent);
      color: var(--accent);
      font-family: 'JetBrains Mono', monospace;
      font-size: 12px;
      border-radius: 20px;
      margin-bottom: 1.5rem;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    h1 {
      font-size: clamp(3rem, 8vw, 5.5rem);
      font-weight: 800;
      line-height: 0.9;
      margin-bottom: 1.5rem;
      letter-spacing: -3px;
      color: #fff;
    }

    .description {
      font-size: 1.1rem;
      color: var(--text-dim);
      max-width: 500px;
      line-height: 1.6;
      margin-bottom: 3rem;
    }

    .action-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1rem;
    }

    .nav-card {
      background: var(--glass);
      border: 1px solid var(--border);
      padding: 1.5rem;
      border-radius: 12px;
      text-decoration: none;
      color: inherit;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .nav-card:hover {
      border-color: var(--accent);
      background: rgba(255, 45, 32, 0.03);
      transform: translateY(-5px);
      box-shadow: 0 10px 30px -10px rgba(255, 45, 32, 0.2);
    }

    .nav-card h3 {
      font-size: 0.9rem;
      color: var(--accent);
      font-family: 'JetBrains Mono', monospace;
      margin-bottom: 0.5rem;
    }

    .nav-card p {
      font-size: 0.85rem;
      color: var(--text-dim);
    }

    .visual-stack {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .core-sphere {
      width: 280px;
      height: 280px;
      border: 1px solid rgba(255, 45, 32, 0.3);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      background: radial-gradient(circle, rgba(255, 45, 32, 0.05) 0%, transparent 70%);
      animation: pulse 4s infinite ease-in-out;
    }

    .core-sphere img {
      width: 120px;
      height: 120px;
      filter: drop-shadow(0 0 15px rgba(255, 45, 32, 0.5));
    }

    .orbit {
      position: absolute;
      border: 1px solid var(--border);
      border-radius: 50%;
      animation: rotate 25s linear infinite;
    }

    .orbit-1 { width: 400px; height: 400px; }
    .orbit-2 { width: 520px; height: 520px; border-style: dashed; animation-duration: 40s; animation-direction: reverse; }

    @keyframes rotate {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    @keyframes pulse {
      0%, 100% { transform: scale(1); box-shadow: 0 0 40px rgba(255, 45, 32, 0.1); }
      50% { transform: scale(1.05); box-shadow: 0 0 80px rgba(255, 45, 32, 0.2); }
    }

    @media (max-width: 968px) {
      .main-container { grid-template-columns: 1fr; text-align: center; margin-top: 2rem; }
      .description { margin-left: auto; margin-right: auto; }
      .visual-stack { display: none; }
      .action-grid { justify-content: center; }
      .version-text-3d { font-size: 60vw; } /* Aumenta o bg no mobile */
    }
  </style>
</head>

<body>

  <div class="bg-version-3d">
    <div class="version-text-3d">v{{ env('APP_VERSION') ?: 'v2.5' }}</div>
  </div>

  <div class="bg-grid"></div>

  <main class="main-container">
    
    <section class="hero-section">
      <div class="badge">Slenix Core Engine</div>
      <h1>Slenix.</h1>
      <p class="description">
        A minimalist PHP framework engineered for developers who demand peak performance and architectural clarity.
      </p>

      <div class="action-grid">
        <a href="https://slenix.vercel.app/" target="_blank" class="nav-card">
          <h3>// Documentation</h3>
          <p>Learn how to build lightning-fast APIs and web applications.</p>
        </a>

        <a href="http://github.com/claudiovictors/slenix" target="_blank" class="nav-card">
          <h3>// GitHub</h3>
          <p>Explore the source code and contribute to the core project.</p>
        </a>
      </div>
    </section>

    <section class="visual-stack">
      <div class="orbit orbit-1"></div>
      <div class="orbit orbit-2"></div>
      
      <div class="core-sphere">
        <img src="/logo.svg" alt="Slenix Logo">
      </div>
    </section>

  </main>

</body>

</html>