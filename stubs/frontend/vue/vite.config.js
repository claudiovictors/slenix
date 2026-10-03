import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import fs from 'node:fs'


const hotFile = 'public/hot'

const slenixHot = () => ({
  name: 'slenix-hot-file',
  configureServer(server) {
    server.httpServer?.once('listening', () => {
      const addr = server.httpServer.address()
      const port = typeof addr === 'object' && addr ? addr.port : 5173
      fs.writeFileSync(hotFile, `http://localhost:${port}`)
    })
    const clean = () => { try { fs.rmSync(hotFile, { force: true }) } catch {} }
    process.on('exit', clean)
    process.on('SIGINT', () => process.exit())
    process.on('SIGTERM', () => process.exit())
  },
})

export default defineConfig({
  plugins: [vue(), slenixHot()],
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: ['resources/css/app.css', 'resources/js/app.js'],
    },
  },
  server: {
    host: 'localhost',
    port: 5173,
    strictPort: true,
    cors: true,
    origin: 'http://localhost:5173',
  },
})