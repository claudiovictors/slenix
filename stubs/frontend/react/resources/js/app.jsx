import '../css/app.css'
import 'boxicons/css/boxicons.min.css'
import { useState } from 'react'
import { createRoot } from 'react-dom/client'

try {
  const saved = localStorage.getItem('theme')
  const dark = window.matchMedia('(prefers-color-scheme: dark)').matches
  document.documentElement.dataset.theme = saved || (dark ? 'dark' : 'light')
} catch {
  document.documentElement.dataset.theme = 'light'
}

const LINKS = [
  { label: 'Explore the Docs', href: 'https://slenix.vercel.app/', tone: 'blue' },
  { label: 'GitHub Repository', href: 'https://github.com/claudiovictors/slenix', tone: 'purple' },
  { label: 'React Docs', href: 'https://react.dev', tone: 'pink' },
  { label: 'Vite Docs', href: 'https://vite.dev', tone: 'red' },
]

function ThemeToggle() {
  const [theme, setTheme] = useState(document.documentElement.dataset.theme)

  const toggle = () => {
    const next = theme === 'dark' ? 'light' : 'dark'
    document.documentElement.dataset.theme = next
    try { localStorage.setItem('theme', next) } catch {}
    setTheme(next)
  }

  return (
    <button className="theme-btn" onClick={toggle} aria-label="Toggle theme">
      <i className={`bx ${theme === 'dark' ? 'bx-sun' : 'bx-moon'}`} />
    </button>
  )
}

function App({ name = 'Slenix' }) {
  return (
    <>
      <ThemeToggle />
      <div className="shell">
        <div className="layout">
          <div className="left">
            <div className="brand">
              <img src="/logo.svg" alt="" />
              <span>{name}</span>
            </div>
            <h1>Hello, {name}</h1>
            <p>
              Congratulations! Your app is running with React. Edit{' '}
              <code>resources/js/app.jsx</code> and see it update live.
            </p>
          </div>

          <div className="right">
            {LINKS.map((l) => (
              <a key={l.label} className={`pill ${l.tone}`} href={l.href} target="_blank" rel="noopener">
                {l.label} <i className="bx bx-link-external" />
              </a>
            ))}
            <div className="socials">
              <a href="https://github.com/claudiovictors/slenix" target="_blank" rel="noopener" aria-label="GitHub">
                <i className="bx bxl-github" />
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
    </>
  )
}

const el = document.getElementById('app')
if (el) {
  createRoot(el).render(<App {...JSON.parse(el.dataset.props || '{}')} />)
}