<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="/logo.svg" type="image/x-icon">
    <title>Slenix PHP Framework</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            background: #000;
            color: #fff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow-x: hidden;
        }

        /* Background pattern */
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                radial-gradient(circle at 25% 25%, #1a1a2e 0%, transparent 50%),
                radial-gradient(circle at 75% 75%, #16213e 0%, transparent 50%);
            opacity: 0.3;
        }

        .container {
            text-align: center;
            z-index: 1;
            max-width: 800px;
            padding: 2rem;
        }

        .logo {
            font-size: 4rem;
            font-weight: 800;
            margin-bottom: 2rem;
            background: linear-gradient(45deg, #8b5cf6, #06b6d4, #10b981);
            background-size: 200% 200%;
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: gradient 3s ease infinite;
            letter-spacing: -0.05em;
        }

        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .subtitle {
            font-size: 1.25rem;
            color: #a1a1aa;
            margin-bottom: 3rem;
            line-height: 1.6;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .steps {
            margin-bottom: 3rem;
            text-align: left;
            display: inline-block;
        }

        .step {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        .step-number {
            background: linear-gradient(45deg, #8b5cf6, #06b6d4);
            color: #000;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
            margin-right: 1rem;
            flex-shrink: 0;
        }

        .step-text {
            color: #e4e4e7;
        }

        .step-code {
            color: #8b5cf6;
            font-family: 'Courier New', monospace;
            background: rgba(139, 92, 246, 0.1);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
        }

        .buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-bottom: 4rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.75rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: 2px solid transparent;
        }

        .btn-primary {
            background: linear-gradient(45deg, #8b5cf6, #06b6d4);
            color: #000;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(139, 92, 246, 0.3);
        }

        .btn-secondary {
            background: transparent;
            color: #e4e4e7;
            border: 2px solid #374151;
        }

        .btn-secondary:hover {
            border-color: #8b5cf6;
            background: rgba(139, 92, 246, 0.1);
        }

        .links {
            display: flex;
            gap: 2rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .link {
            color: #a1a1aa;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            transition: color 0.3s ease;
        }

        .link:hover {
            color: #8b5cf6;
        }

        .icon {
            width: 16px;
            height: 16px;
            opacity: 0.7;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .logo {
                font-size: 3rem;
            }
            
            .subtitle {
                font-size: 1.1rem;
            }
            
            .buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 250px;
                justify-content: center;
            }
            
            .links {
                flex-direction: column;
                align-items: center;
            }
        }

        /* Floating elements animation */
        .floating-elements {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .floating-element {
            position: absolute;
            background: linear-gradient(45deg, rgba(139, 92, 246, 0.1), rgba(6, 182, 212, 0.1));
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        .floating-element:nth-child(1) {
            width: 100px;
            height: 100px;
            top: 20%;
            left: 15%;
            animation-delay: 0s;
        }

        .floating-element:nth-child(2) {
            width: 60px;
            height: 60px;
            top: 60%;
            right: 20%;
            animation-delay: 2s;
        }

        .floating-element:nth-child(3) {
            width: 80px;
            height: 80px;
            bottom: 20%;
            left: 10%;
            animation-delay: 4s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }
    </style>
</head>
<body>
    <div class="floating-elements">
        <div class="floating-element"></div>
        <div class="floating-element"></div>
        <div class="floating-element"></div>
    </div>

    <div class="container">
        <h1 class="logo">SLENIX</h1>
        
        <p class="subtitle">
            O Slenix é um micro-framework MVC elegante, simples e robusta para criar APIs Rest e Aplicações dinâmicas
        </p>

        <div class="steps">
            <div class="step">
                <div class="step-number">1</div>
                <div class="step-text">
                    Comece criando rotas na pasta <span class="step-code">routes/web.php</span>
                </div>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <div class="step-text">
                    Salve e veja suas mudanças instantaneamente.
                </div>
            </div>
        </div>

        <div class="buttons">
            <a href="https://www.github.com/claudiovictors" target="_blank" class="btn btn-primary">
                <span>▲</span>
                Deploy agora
            </a>
            <a href="https://www.github.com/claudiovictors/slenix" target="_blank" class="btn btn-secondary">
                Ler documentação
            </a>
        </div>

        <div class="links">
            <a href="https://www.github.com/claudiovictors/slenix" target="_blank" class="link">
                <svg class="icon" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Aprender
            </a>
            <a href="https://www.github.com/claudiovictors/slenix" target="_blank" class="link">
                <svg class="icon" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/>
                </svg>
                Exemplos
            </a>
            <a href="https://www.github.com/claudiovictors/slenix" target="_blank" class="link">
                <svg class="icon" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M12.586 4.586a2 2 0 112.828 2.828l-3 3a2 2 0 01-2.828 0 1 1 0 00-1.414 1.414 4 4 0 005.656 0l3-3a4 4 0 00-5.656-5.656l-1.5 1.5a1 1 0 101.414 1.414l1.5-1.5zm-5 5a2 2 0 012.828 0 1 1 0 101.414-1.414 4 4 0 00-5.656 0l-3 3a4 4 0 105.656 5.656l1.5-1.5a1 1 0 10-1.414-1.414l-1.5 1.5a2 2 0 11-2.828-2.828l3-3z" clip-rule="evenodd"/>
                </svg>
                Ir para slenix.org →
            </a>
        </div>
    </div>
</body>
</html>