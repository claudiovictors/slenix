<!DOCTYPE html>
<html lang="pt-br">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="shortcut icon" href="/logo.svg" type="image/x-icon">
  <title>{{ env('APP_NAME') }} PHP Framework</title>

  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap');

    :root {
      --bg: #050505;
      --card: rgba(17, 17, 17, 0.7);
      --border: rgba(255, 255, 255, 0.08);
      --text: #ffffff;
      --muted: #a0a0a0;
      --primary: #e5484d;
      --secondary: #6e2cf2;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      /* Gradiente aprimorado com dois focos de luz */
      background:
        radial-gradient(circle at 15% 15%, rgba(229, 72, 77, 0.15), transparent 35%),
        radial-gradient(circle at 85% 85%, rgba(110, 44, 242, 0.1), transparent 35%),
        var(--bg);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem;
      overflow-x: hidden;
    }

    /* Versão 3D Grande no Fundo */
    .version-3d {
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      font-size: 25vw;
      font-weight: 800;
      color: rgba(255, 255, 255, 0.03);
      z-index: -1;
      pointer-events: none;
      user-select: none;
      text-transform: uppercase;
      letter-spacing: -1rem;
      /* Efeito de Profundidade 3D */
      text-shadow:
        1px 1px 0px rgba(229, 72, 77, 0.1),
        4px 4px 20px rgba(0, 0, 0, 0.5);
      animation: float 6s ease-in-out infinite;
    }

    @keyframes float {

      0%,
      100% {
        transform: translate(-50%, -52%);
      }

      50% {
        transform: translate(-50%, -48%);
      }
    }

    .wrapper {
      width: 100%;
      max-width: 1050px;
      backdrop-filter: blur(5px);
    }

    .top {
      text-align: center;
      margin-bottom: 3.5rem;
    }

    .logo {
      width: 90px;
      margin-bottom: 1.5rem;
      filter: drop-shadow(0 0 25px rgba(229, 72, 77, 0.4));
    }

    .title {
      font-size: 2.8rem;
      font-weight: 800;
      letter-spacing: -1.5px;
      background: linear-gradient(to bottom, #fff, #888);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .subtitle {
      color: var(--muted);
      font-size: 1.1rem;
      margin-top: 0.6rem;
      max-width: 500px;
      margin-left: auto;
      margin-right: auto;
    }

    .grid {
      display: grid;
      grid-template-columns: 1.2fr 1fr;
      gap: 1.5rem;
    }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 1.8rem;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      text-decoration: none;
      color: inherit;
      display: block;
      backdrop-filter: blur(12px);
      position: relative;
      overflow: hidden;
    }

    .card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: linear-gradient(45deg, transparent, rgba(229, 72, 77, 0.05), transparent);
      transform: translateX(-100%);
      transition: 0.5s;
    }

    .card:hover::before {
      transform: translateX(100%);
    }

    .card:hover {
      border-color: var(--primary);
      background: rgba(25, 25, 25, 0.8);
      transform: translateY(-5px) scale(1.01);
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
    }

    .big-card {
      min-height: 350px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      border-width: 1.5px;
    }

    .card-title {
      font-size: 1.2rem;
      font-weight: 700;
      margin-bottom: 0.8rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .card-desc {
      font-size: 0.95rem;
      color: var(--muted);
      line-height: 1.7;
    }

    .arrow {
      margin-top: 1.2rem;
      text-align: right;
      color: var(--primary);
      font-size: 1.5rem;
      font-weight: 300;
    }

    .stack {
      display: flex;
      flex-direction: column;
      gap: 1.2rem;
    }

    .footer {
      text-align: center;
      margin-top: 4rem;
      font-size: 0.85rem;
      color: #444;
      letter-spacing: 1px;
    }

    .footer a {
      color: #777;
      text-decoration: none;
      transition: 0.2s;
      font-weight: 600;
    }

    .footer a:hover {
      color: var(--primary);
    }

    @media (max-width: 900px) {
      .grid {
        grid-template-columns: 1fr;
      }

      .version-3d {
        font-size: 40vw;
        opacity: 0.02;
      }

      .title {
        font-size: 2.2rem;
      }
    }
  </style>
</head>

<body>

  <div class="version-3d">
    {{ env('APP_VERSION') }}
  </div>

  <div class="wrapper">
    <div class="top">
      <img src="/logo.svg" class="logo" alt="Slenix Logo">
      <h1 class="title">{{ env('APP_NAME') }}</h1>
      <p class="subtitle">
        A modern, lightweight PHP framework for building fast and scalable applications.
      </p>
    </div>

    <div class="grid">
      <a href="https://slenix.vercel.app" target="_blank" class="card big-card">
        <div>
          <div class="card-title">
            <span style="color: var(--primary)">#</span> Documentation
          </div>
          <div class="card-desc">
            Explore the full ecosystem of {{ env('APP_NAME') }}.
            From routing and controllers to advanced dependency injection.
            Everything is designed to be intuitive and clean.
          </div>
        </div>
        <div class="arrow">view docs —</div>
      </a>

      <div class="stack">
        <a href="https://github.com/claudiovictors/slenix" target="_blank" class="card">
          <div class="card-title">GitHub Repository</div>
          <div class="card-desc">Open source core. Star us to support the project.</div>
        </a>

        <a href="https://slenix.vercel.app/docs/installation" target="_blank" class="card">
          <div class="card-title">Core Guides</div>
          <div class="card-desc">Master the framework lifecycle and architecture.</div>
        </a>

        <a href="https://slenix.vercel.app/docs/celestial-cli" target="_blank" class="card">
          <div class="card-title">Slenix CLI</div>
          <div class="card-desc">Supercharge your productivity with our command line tool.</div>
        </a>
      </div>
    </div>

    <div class="footer">
      RELEASE V{{ env('APP_VERSION') }} • DESIGNED BY
      <a href="https://github.com/claudiovictors" target="_blank">CLÁUDIO VICTOR</a>
    </div>
  </div>

</body>

</html>