<!DOCTYPE html>
<html lang="pt-AO">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="/logo.svg" type="image/x-icon">
    <title>Welcome Slenix</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        body {
            background-color: #0f0f1a;
            color: #e6e6e6;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .welcome-section {
            position: relative;
            width: 100%;
            max-width: 1200px;
            padding: 3rem 2rem;
            overflow: hidden;
        }

        .glow {
            position: absolute;
            width: 600px;
            height: 600px;
            top: -200px;
            right: -150px;
            background: radial-gradient(circle, rgba(94, 114, 228, 0.2) 0%, rgba(255, 107, 107, 0.15) 40%, rgba(0, 0, 0, 0) 70%);
            filter: blur(90px);
            pointer-events: none;
            z-index: 0;
        }

        .content {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .header {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .logo {
            color: #5e72e4;
            width: 50px;
            height: 50px;
        }

        .title-block {
            flex: 1;
        }

        .title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #5e72e4 20%, #ff6b6b 80%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .subtitle {
            font-size: 1.1rem;
            color: #a0aec0;
            max-width: 600px;
        }

        .version {
            font-size: 0.9rem;
            color: #5e72e4;
            padding: 0.3rem 0.8rem;
            border: 1px solid rgba(94, 114, 228, 0.3);
            border-radius: 20px;
        }

        .mvc-diagram {
            background: rgba(18, 18, 30, 0.7);
            border-radius: 12px;
            padding: 2.5rem;
            border: 1px solid rgba(255, 255, 255, 0.05);
            margin-top: 1rem;
            position: relative;
            height: 300px;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
        }

        .mvc-component {
            position: relative;
            width: 180px;
            height: 180px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            border-radius: 10px;
            transition: all 0.3s ease;
            z-index: 1;
        }

        .mvc-component:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(94, 114, 228, 0.15);
        }

        .mvc-component.model {
            background: rgba(94, 114, 228, 0.1);
            border: 1px solid rgba(94, 114, 228, 0.3);
            margin-right: -30px;
        }

        .mvc-component.view {
            background: rgba(255, 107, 107, 0.1);
            border: 1px solid rgba(255, 107, 107, 0.3);
            z-index: 2;
            transform: scale(1.1);
        }

        .mvc-component.controller {
            background: rgba(45, 206, 137, 0.1);
            border: 1px solid rgba(45, 206, 137, 0.3);
            margin-left: -30px;
        }

        .mvc-icon {
            width: 40px;
            height: 40px;
            margin-bottom: 1rem;
        }

        .mvc-component.model .mvc-icon {
            color: #5e72e4;
        }

        .mvc-component.view .mvc-icon {
            color: #ff6b6b;
        }

        .mvc-component.controller .mvc-icon {
            color: #2dce89;
        }

        .mvc-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .mvc-description {
            font-size: 0.85rem;
            text-align: center;
            color: #a0aec0;
            padding: 0 1rem;
        }

        .connection-lines {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            pointer-events: none;
            z-index: 0;
            opacity: 0.4;
        }

        .code-block {
            background: rgba(18, 18, 30, 0.7);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.05);
            font-family: 'Fira Code', monospace;
            font-size: 0.9rem;
            color: #e6e6e6;
            position: relative;
            overflow: hidden;
        }

        .code-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.7rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .code-title {
            font-weight: 600;
            color: #a0aec0;
        }

        .code-dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .code-dot.red {
            background-color: #ff6b6b;
        }

        .code-dot.yellow {
            background-color: #ffd76b;
        }

        .code-dot.green {
            background-color: #2dce89;
        }

        .code-line {
            line-height: 1.5;
        }

        .code-keyword {
            color: #ff6b6b;
        }

        .code-function {
            color: #5e72e4;
        }

        .code-variable {
            color: #2dce89;
        }

        .code-string {
            color: #ffd76b;
        }

        .code-comment {
            color: #718096;
        }

        .get-started {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #5e72e4 0%, #ff6b6b 100%);
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            margin-top: 1.5rem;
        }

        .get-started:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(94, 114, 228, 0.3);
        }

        .arrow-icon {
            width: 18px;
            height: 18px;
        }

        @media (max-width: 768px) {
            .mvc-diagram {
                height: auto;
                padding: 2rem 1rem;
                flex-direction: column;
                gap: 2rem;
            }

            .mvc-component {
                width: 100%;
                max-width: 220px;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }

            .mvc-component.view {
                transform: scale(1);
                order: -1;
            }
        }
    </style>
</head>

<body>
    <section class="welcome-section">
        <div class="glow"></div>

        <div class="content">
            <div class="header">
                <img src="/logo.svg" alt="Slenix Logo" width="100px">

                <div class="title-block">
                    <h1 class="title"><?= env('APP_NAME') ?></h1>
                    <p class="subtitle">É um micro-framework MVC elegante, simples e robusta para criar APIs Rest e APlicações dinâmicas</p>
                </div>

                <span class="version">v<?= env('APP_VERSION') ?></span>
            </div>

            <div class="mvc-diagram">
                <svg class="connection-lines" viewBox="0 0 800 300" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M200 150H600" stroke="#5e72e4" stroke-width="2" stroke-dasharray="10 5" />
                    <path d="M300 80C300 80 400 20 500 80" stroke="#ff6b6b" stroke-width="2" stroke-dasharray="10 5" />
                    <path d="M300 220C300 220 400 280 500 220" stroke="#2dce89" stroke-width="2" stroke-dasharray="10 5" />
                </svg>

                <div class="mvc-component model">
                    <svg class="mvc-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4 5V19C4 19.5304 4.21071 20.0391 4.58579 20.4142C4.96086 20.7893 5.46957 21 6 21H18C18.5304 21 19.0391 20.7893 19.4142 20.4142C19.7893 20.0391 20 19.5304 20 19V5C20 4.46957 19.7893 3.96086 19.4142 3.58579C19.0391 3.21071 18.5304 3 18 3H6C5.46957 3 4.96086 3.21071 4.58579 3.58579C4.21071 3.96086 4 4.46957 4 5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M9 3V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <h3 class="mvc-title">Model</h3>
                    <p class="mvc-description">Gerencia dados e lógica de negócios da aplicação</p>
                </div>

                <div class="mvc-component view">
                    <svg class="mvc-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M15 12C15 13.6569 13.6569 15 12 15C10.3431 15 9 13.6569 9 12C9 10.3431 10.3431 9 12 9C13.6569 9 15 10.3431 15 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12 5C7.58172 5 4.00001 8.58172 4.00001 13C4.00001 17.4183 7.58172 21 12 21C16.4183 21 20 17.4183 20 13C20 8.58172 16.4183 5 12 5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12 5V3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M4 13H2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12 21V23" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M20 13H22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <h3 class="mvc-title">View</h3>
                    <p class="mvc-description">Renderiza a interface do usuário e apresenta os dados</p>
                </div>

                <div class="mvc-component controller">
                    <svg class="mvc-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M19 12H5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12 19L5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <h3 class="mvc-title">Controller</h3>
                    <p class="mvc-description">Processa requisições e coordena interações</p>
                </div>
            </div>
        </div>
    </section>
</body>

</html>