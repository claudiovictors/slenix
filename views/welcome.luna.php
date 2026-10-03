<!DOCTYPE html>
<html lang="{{ config('app.locale') }}" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="shortcut icon" href="/logo.svg" type="image/svg+xml">
  <title>Welcome to {{ config('app.name') }}</title>
  <script>
    try {
      var t = localStorage.getItem('theme');
      if (!t) t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', t);
    } catch (e) {}
  </script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css">
  <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
  <button class="theme-btn" id="themeBtn" aria-label="Toggle theme"><i class="bx bx-moon"></i></button>

  <div class="shell">
    <div class="layout">

      <div class="left">
        <div class="brand">
          <img src="{{ asset('logo.svg') }}" alt="">
          <span>{{ config('app.name') }}</span>
        </div>
        <h1>Hello, {{ config('app.name') }}</h1>
        <p>Congratulations! Your app is running. Edit <code>routes/web.php</code> to get started.</p>
      </div>

      <div class="right">
        <a class="pill blue" href="https://slenix.vercel.app/" target="_blank" rel="noopener">
          Explore the Docs <i class="bx bx-link-external"></i>
        </a>
        <a class="pill purple" href="https://github.com/claudiovictors/slenix" target="_blank" rel="noopener">
          GitHub Repository <i class="bx bx-link-external"></i>
        </a>
        <a class="pill pink" href="https://github.com/claudiovictors/slenix/issues" target="_blank" rel="noopener">
          Report an Issue <i class="bx bx-link-external"></i>
        </a>
        <a class="pill red" href="https://vite.dev" target="_blank" rel="noopener">
          Vite Docs <i class="bx bx-link-external"></i>
        </a>

        <div class="socials">
          <a href="https://github.com/claudiovictors/slenix" target="_blank" rel="noopener" aria-label="GitHub">
            <i class="bx bxl-github"></i>
          </a>
          <a href="https://instagram.com/claudio_victor.dev" target="_blank" rel="noopener" aria-label="Instagram">
            <i class="bx bxl-instagram"></i>
          </a>
          <a href="https://linkedin.com/in/claudiovictor" target="_blank" rel="noopener" aria-label="LinkeDin">
            <i class="bx bxl-linkedin"></i>
          </a>
        </div>
      </div>

    </div>
  </div>

  <script>
    (function () {
      var btn = document.getElementById('themeBtn');
      var icon = btn.querySelector('i');
      var root = document.documentElement;
      function paint() {
        icon.className = root.getAttribute('data-theme') === 'dark' ? 'bx bx-sun' : 'bx bx-moon';
      }
      btn.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('theme', next); } catch (e) {}
        paint();
      });
      paint();
    })();
  </script>
</body>
</html>