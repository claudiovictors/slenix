<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Página não encontrada</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f9fafb;
            color: #1f2937;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        
        .background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }
        
        .shape {
            position: absolute;
            border-radius: 50%;
            opacity: 0.05;
        }
        
        .shape:nth-child(1) {
            background-color: #3b82f6;
            width: 400px;
            height: 400px;
            top: -200px;
            right: -100px;
        }
        
        .shape:nth-child(2) {
            background-color: #6366f1;
            width: 300px;
            height: 300px;
            bottom: -100px;
            left: -100px;
        }
        
        .container {
            text-align: center;
            padding: 3rem;
            max-width: 500px;
            border-radius: 16px;
        }
        
        .error-code {
            font-size: 8rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 1rem;
            background: linear-gradient(90deg, #3b82f6, #6366f1);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -0.05em;
        }
        
        .not-found {
            font-size: 2rem;
            margin-bottom: 1rem;
            font-weight: 600;
            color: #111827;
        }
        
        .icon {
            margin-bottom: 1.5rem;
        }
        
        .line {
            width: 60px;
            height: 4px;
            background: linear-gradient(90deg, #3b82f6, #6366f1);
            margin: 0 auto 1.5rem;
            border-radius: 2px;
        }
        
        @media (max-width: 640px) {
            .container {
                padding: 2rem;
                margin: 0 1rem;
            }
            
            .error-code {
                font-size: 6rem;
            }
            
            .not-found {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="background">
        <div class="shape"></div>
        <div class="shape"></div>
    </div>
    
    <div class="container">
        <div class="error-code">404</div>
        <div class="line"></div>
        <h1 class="not-found">Page not Found</h1>
    </div>
</body>
</html>