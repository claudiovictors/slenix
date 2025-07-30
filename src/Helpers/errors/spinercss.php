<style>
:root {
    --bg-color: #f8fafc;
    --surface-color: #ffffff;
    --border-color: #e2e8f0;
    --text-color: #334155;
    --text-muted: #64748b;
    --blue-light: #e0f2fe;
    --blue-lighter: #f0f9ff;
    --blue-border: #bae6fd;
    --blue-text: #0369a1;
    --error-text: #b91c1c;
    --error-light: #fee2e2;
    --header-bg: #f1f5f9;
    --code-bg: #dbeafe;
    --error-line-bg: #fecaca;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    line-height: 1.5;
    color: var(--text-color);
    background-color: var(--bg-color);
    padding: 0;
    margin: 0;
}

.top-bar {
    background-color: #1e293b;
    color: white;
    padding: 0.5rem 1rem;
    display: flex;
    align-items: center;
    font-size: 0.875rem;
}

.top-bar-title {
    font-weight: 500;
}

.error-container {
    max-width: 1200px;
    margin: 2rem auto;
    background: var(--surface-color);
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    overflow: hidden;
    border: 1px solid var(--border-color);
}

.error-header {
    background: var(--header-bg);
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.error-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--text-color);
    margin-bottom: 0.5rem;
}

.error-message {
    color: var(--error-text);
    font-weight: 500;
}

.error-body {
    padding: 1.5rem;
}

.detail-section {
    margin-bottom: 1.5rem;
    border: 1px solid var(--border-color);
    border-radius: 0.375rem;
    overflow: hidden;
}

.detail-section:last-child {
    margin-bottom: 0;
}

.detail-row {
    display: flex;
    padding: 0.75rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    font-weight: 500;
    width: 120px;
    flex-shrink: 0;
}

.detail-value {
    color: var(--text-muted);
}

.section-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    background: var(--header-bg);
    border-bottom: 1px solid var(--border-color);
    font-weight: 600;
    color: var(--blue-text);
}

.section-header svg {
    width: 1.25rem;
    height: 1.25rem;
    color: var(--blue-text);
}

.section-content {
    padding: 0;
}

.code-container {
    background: var(--blue-lighter);
    padding: 0;
    overflow-x: auto;
    font-family: "SFMono-Regular", Menlo, Monaco, Consolas, monospace;
    font-size: 0.875rem;
    line-height: 1.7;
}

.code-line {
    display: flex;
    white-space: pre;
}

.code-line.error {
    background: var(--error-line-bg);
}

.line-number {
    color: var(--text-muted);
    text-align: right;
    padding: 0 0.75rem;
    min-width: 3rem;
    user-select: none;
    border-right: 1px solid var(--blue-border);
    background: rgba(219, 234, 254, 0.3);
}

.line-content {
    padding: 0 1rem 0 0.75rem;
    flex: 1;
}

.stack-trace {
    font-family: "SFMono-Regular", Menlo, Monaco, Consolas, monospace;
    font-size: 0.8125rem;
    line-height: 1.5;
    color: var(--text-muted);
    padding: 1rem 1.5rem;
    white-space: pre-wrap;
    overflow-x: auto;
    background: var(--blue-lighter);
}

.stack-line {
    margin-bottom: 0.25rem;
}

.keyword {
    color: #8250df;
}

.function {
    color: #0550ae;
}

.string {
    color: #0a3622;
}

.variable {
    color: #953800;
}

.comment {
    color: #6a737d;
}

@media (max-width: 768px) {
    .error-container {
        margin: 1rem;
    }

    .detail-row {
        flex-direction: column;
    }

    .detail-label {
        width: 100%;
        margin-bottom: 0.25rem;
    }
}
</style>