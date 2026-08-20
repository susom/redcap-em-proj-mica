import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// The module page loads whatever it finds in dist/assets, so the build must emit a small, stable
// set of files there and nothing else. `manifest: false` and no source maps keep it to one JS and
// one CSS: MICA::reviewAssetFiles() emits a tag per file it finds, and a stray .map or a
// code-split chunk would either be loaded as a script or silently never loaded at all.
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    sourcemap: false,
    rollupOptions: {
      output: {
        manualChunks: undefined,
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: './src/test-setup.js',
    css: false,
  },
})
