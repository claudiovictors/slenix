<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'SF Mono', 'Monaco', 'Inconsolata', 'Roboto Mono', 'Source Code Pro', monospace;
    background: #0d1117;
    color: #f0f6fc;
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.terminal {
    background: #161b22;
    border: 1px solid #30363d;
    border-radius: 12px;
    width: 90%;
    max-width: 800px;
    box-shadow: 0 16px 70px rgba(0, 0, 0, 0.5);
}

.terminal-header {
    background: #21262d;
    padding: 12px 16px;
    border-radius: 12px 12px 0 0;
    border-bottom: 1px solid #30363d;
    display: flex;
    align-items: center;
    gap: 8px;
}

.terminal-buttons {
    display: flex;
    gap: 6px;
}

.btn-circle {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: none;
}

.btn-close {
    background: #ff5f57;
}

.btn-minimize {
    background: #ffbd2e;
}

.btn-maximize {
    background: #28ca42;
}

.terminal-title {
    color: #8b949e;
    font-size: 13px;
    margin-left: 12px;
}

.terminal-body {
    padding: 24px;
    min-height: 400px;
}

.prompt-line {
    display: flex;
    align-items: center;
    margin-bottom: 8px;
}

.prompt {
    color: #7c3aed;
    margin-right: 8px;
    font-weight: 600;
}

.command {
    color: #f0f6fc;
}

.logo-art {
    color: #7c3aed;
    font-size: 14px;
    line-height: 1.2;
    margin: 15px 0;
    white-space: pre;
}

.info-section {
    margin: 20px 0;
}

.info-line {
    display: flex;
    margin-bottom: 4px;
}

.info-label {
    color: #58a6ff;
    min-width: 120px;
}

.info-value {
    color: #f0f6fc;
}

.commands-section {
    margin-top: 24px;
    padding-top: 16px;
    border-top: 1px solid #30363d;
}

.command-item {
    display: flex;
    margin-bottom: 8px;
    align-items: center;
}

.command-name {
    color: #f85149;
    min-width: 140px;
    font-weight: 600;
}

.command-desc {
    color: #8b949e;
}

.status-indicator {
    color: #3fb950;
    margin-right: 8px;
}

.cursor {
    background: #f0f6fc;
    animation: blink 1s infinite;
    width: 8px;
    height: 18px;
    display: inline-block;
    margin-left: 4px;
}

@keyframes blink {

    0%,
    50% {
        opacity: 1;
    }

    51%,
    100% {
        opacity: 0;
    }
}

.version-badge {
    background: #238636;
    color: #f0f6fc;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

@media (max-width: 768px) {
    .terminal {
        width: 95%;
        margin: 20px;
    }

    .terminal-body {
        padding: 16px;
    }

    .logo-art {
        font-size: 12px;
    }

    .command-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }

    .command-name {
        min-width: auto;
    }
}
</style>