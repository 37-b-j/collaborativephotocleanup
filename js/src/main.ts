import { createApp } from "vue"
import { createPinia } from "pinia"
import App from "./App.vue"
import router from "./router"
import { generateUrl, getCSRFToken } from "./utils/helpers"
import "./style.css"

// Initialize Vue app
const app = createApp(App)

// Use Pinia for state management
app.use(createPinia())

// Use router
app.use(router)

// Mount the app
app.mount("#app")

// Log for debugging
console.log("Collaborative Photocleanup Vue 3 app initialized")

// PWA: Register service worker
if ("serviceWorker" in navigator) {
  const rootPath = window.OC?.getRootPath?.() || "";
  const swPath = rootPath + "/apps/collaborativephotocleanup/sw.js";
  const swScope = rootPath + "/apps/collaborativephotocleanup/";
  navigator.serviceWorker
    .register(swPath, { scope: swScope })
    .then((reg) => {
      console.log("[PWA] Service Worker registered:", reg.scope);
    })
    .catch((err) => {
      console.warn("[PWA] Service Worker registration failed:", err);
    });
}
