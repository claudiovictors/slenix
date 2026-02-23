<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found</title>
    <style>
        :root {
            --bg: #000000;
            --text-main: #ffffff;
            --text-muted: #888888;
            --accent: #667eea;
        }

        body {
            margin: 0;
            padding: 0;
            background-color: var(--bg);
            color: var(--text-main);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            text-align: center;
        }

        .container {
            padding: 20px;
        }

        h1 {
            font-size: 24px;
            font-weight: 600;
            margin: 0;
            display: inline-block;
            border-right: 1px solid rgba(255, 255, 255, 0.3);
            padding: 10px 23px 10px 0;
            margin-right: 20px;
            vertical-align: middle;
        }

        .message-box {
            display: inline-block;
            text-align: left;
            vertical-align: middle;
        }

        h2 {
            font-size: 14px;
            font-weight: 400;
            margin: 0;
            line-height: 24px;
        }

        p {
            font-size: 12px;
            color: var(--text-muted);
            margin: 5px 0 0 0;
        }

        .back-link {
            margin-top: 30px;
            display: block;
            color: var(--accent);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: opacity 0.2s;
        }

        .back-link:hover {
            opacity: 0.8;
            text-decoration: underline;
        }

        /* Estilo sutil para o rodapé */
        .brand {
            position: absolute;
            bottom: 30px;
            font-size: 10px;
            color: #222;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

    <div class="container">
        <h1>404</h1>
        <div class="message-box">
            <h2>This page could not be found.</h2>
            <p>The requested URL was not found on this server.</p>
        </div>
        
        <a href="/" class="back-link">Return to Home Page</a>
    </div>

    <div class="brand">Slenix Framework</div>

</body>
</html>