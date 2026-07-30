/// <reference types="vite/client" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<{}, {}, any>
  export default component
}

interface Window {
  oc_csrf_token: string
  OC: {
    generateUrl: (url: string, params?: Record<string, string>) => string
    getRootPath: () => string
  }
}

declare const __APP_VERSION__: string
