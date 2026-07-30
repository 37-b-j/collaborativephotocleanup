import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'
import pkg from './package.json'

const outDir = resolve(__dirname, 'collaborativephotocleanup')

export default defineConfig({
  plugins: [vue()],

  define: {
    __APP_VERSION__: JSON.stringify(pkg.version),
  },

  base: '/apps/collaborativephotocleanup/js/collaborativephotocleanup/',

  build: {
    outDir: outDir,
    emptyOutDir: true,
    target: 'es2015',
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'src/main.ts'),
      },
      output: {
        format: 'iife',
        entryFileNames: 'collaborativephotocleanup-[name].js',
        chunkFileNames: 'collaborativephotocleanup-[name].js',
        assetFileNames: 'collaborativephotocleanup-[name].[ext]'
      }
    }
  },

  resolve: {
    alias: {
      '@': resolve(__dirname, 'src')
    }
  }
})