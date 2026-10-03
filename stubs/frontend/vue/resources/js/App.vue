<script setup>
import { ref } from 'vue'

defineProps({ name: { type: String, default: 'Slenix' } })

const LINKS = [
  { label: 'Explore the Docs', href: 'https://slenix.vercel.app/', tone: 'blue' },
  { label: 'GitHub Repository', href: 'https://github.com/claudiovictors/slenix', tone: 'purple' },
  { label: 'Vue Docs', href: 'https://vuejs.org', tone: 'pink' },
  { label: 'Vite Docs', href: 'https://vite.dev', tone: 'red' },
]

const theme = ref(document.documentElement.dataset.theme)

function toggleTheme() {
  const next = theme.value === 'dark' ? 'light' : 'dark'
  document.documentElement.dataset.theme = next
  try { localStorage.setItem('theme', next) } catch {}
  theme.value = next
}
</script>

<template>
  <button class="theme-btn" aria-label="Toggle theme" @click="toggleTheme">
    <i :class="['bx', theme === 'dark' ? 'bx-sun' : 'bx-moon']"></i>
  </button>

  <div class="shell">
    <div class="layout">
      <div class="left">
        <div class="brand">
          <img src="/logo.svg" alt="" />
          <span>{{ name }}</span>
        </div>
        <h1>Hello, {{ name }}</h1>
        <p>
          Congratulations! Your app is running with Vue. Edit
          <code>resources/js/App.vue</code> and see it update live.
        </p>
      </div>

      <div class="right">
        <a v-for="l in LINKS" :key="l.label" :class="['pill', l.tone]" :href="l.href" target="_blank" rel="noopener">
          {{ l.label }} <i class="bx bx-link-external"></i>
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
</template>