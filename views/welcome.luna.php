<!DOCTYPE html>
<html lang="pt-AO">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="/logo.svg" type="image/x-icon">
    <title>Welcome Slenix</title>
    <link rel="stylesheet" href="{{ CSS_TEMPLATE }}">
</head>

<body>
    <div class="terminal">
        <div class="terminal-header">
            <div class="terminal-buttons">
                <div class="btn-circle btn-close"></div>
                <div class="btn-circle btn-minimize"></div>
                <div class="btn-circle btn-maximize"></div>
            </div>
            <div class="terminal-title">slenix — framework info</div>
        </div>

        <div class="terminal-body">
            <div class="prompt-line">
                <span class="prompt">$</span>
                <span class="command">welcome {{ env('APP_NAME') }}</span>
            </div>

<div class="logo-art">
 _____ _            _     
/ ____| |          (_)    
| (___| | ___ _ __  ___  __
\___ \| |/ _ \ '_ \| \ \/ /
____) | |  __/ | | | |>  < 
|_____/|_|\___|_| |_|_/_/\_\</div>

                    <div class="info-section">
                        <div class="info-line">
                            <span class="info-label">Framework:</span>
                            <span class="info-value">Slenix MVC <span class="version-badge">v{{ env('APP_VERSION')
                                    }}</span></span>
                        </div>
                        <div class="info-line">
                            <span class="info-label">Language:</span>
                            <span class="info-value">PHP 8.0+</span>
                        </div>
                        <div class="info-line">
                            <span class="info-label">Architecture: </span>
                            <span class="info-value">Model-View-Controller</span>
                        </div>
                        <div class="info-line">
                            <span class="info-label">Status: </span>
                            <span class="status-indicator">●</span>
                            <span class="info-value">Ready for development</span>
                        </div>
                    </div>

                    <div class="prompt-line" style="margin-top: 24px;">
                        <span class="prompt">$</span>
                        <span class="command"></span>
                        <span class="cursor"></span>
                    </div>
            </div>
        </div>
</body>

</html>