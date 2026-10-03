import '../css/app.css'
import 'boxicons/css/boxicons.min.css'
import { createApp } from 'vue'
import App from './App.vue'

try {
  const saved = localStorage.getItem('theme')
  const dark = window.matchMedia('(prefers-color-scheme: dark)').matches
  document.documentElement.dataset.theme = saved || (dark ? 'dark' : 'light')
} catch {
  document.documentElement.dataset.theme = 'light'
}

const el = document.getElementById('app')
if (el) {
  createApp(App, JSON.parse(el.dataset.props || '{}')).mount(el)
}