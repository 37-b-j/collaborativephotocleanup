<template>
  <div id="app">
    <div class="app-top-bar">
      <header class="app-header">
        <FolderBar />
      </header>
      <nav class="app-nav">
        <a href="#/" class="nav-link" :class="{ active: currentRoute === '/' }" @click.prevent="navigate('/')">Home</a>
        <a href="#/photos" class="nav-link" :class="{ active: currentRoute === '/photos' }" @click.prevent="navigate('/photos')">Photos</a>
        <a href="#/cleanup" class="nav-link" :class="{ active: currentRoute === '/cleanup' }" @click.prevent="navigate('/cleanup')">Cleanup</a>
        <a href="#/cleanup-execute" class="nav-link" :class="{ active: currentRoute === '/cleanup-execute' }" @click.prevent="navigate('/cleanup-execute')">Execute</a>
        <a href="#/collaboration" class="nav-link" :class="{ active: currentRoute === '/collaboration' }" @click.prevent="navigate('/collaboration')">Team</a>
        <a href="#/settings" class="nav-link" :class="{ active: currentRoute === '/settings' }" @click.prevent="navigate('/settings')">Settings</a>
      </nav>
    </div>
    <main>
      <router-view />
    </main>
    <footer class="app-footer">
      <p>PhotoCleanup v{{ version }} — Collaborative photo cleanup, exclusively vibe coded ✨</p>
    </footer>
  </div>
</template>
<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick } from "vue"
import { useRouter, useRoute } from "vue-router"
import FolderBar from "@/components/FolderBar.vue"

const router = useRouter()
const route = useRoute()
const currentRoute = computed(() => route.path)
const version = ref(__APP_VERSION__)

function navigate(path: string) {
  if (route.path === path) {
    window.scrollTo({ top: 0, behavior: "instant" })
    setTimeout(() => window.dispatchEvent(new CustomEvent("cleanup-nav-refresh")), 50)
  } else {
    router.push(path)
  }
}

function enforceScroll() {
  requestAnimationFrame(() => {
    const app = document.getElementById('app')
    const header = document.getElementById('header')
    if (!app) return
    const hh = header ? header.offsetHeight : 50
    app.style.display = 'flex'
    app.style.flexDirection = 'column'
    app.style.overflow = 'hidden'
    app.style.height = `calc(100vh - ${hh}px)`
  })
}

onMounted(() => {
  nextTick(() => enforceScroll())
  window.addEventListener('resize', enforceScroll)
})

onUnmounted(() => {
  window.removeEventListener('resize', enforceScroll)
})
</script>
<style>
#app { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; max-width: 1200px; margin: 0 auto; padding: 8px 20px 40px 20px; box-sizing: border-box; width: 100%; display: flex; flex-direction: column; overflow: hidden; height: calc(100vh - 50px); }
@media (max-width: 768px) { #app { padding: 0 8px 30px 8px; max-width: 100vw; } }
.app-top-bar { flex-shrink: 0; z-index: 0; background: #fff; padding-bottom: 4px; }
.app-header { text-align: center; margin-bottom: 0; border-bottom: 1px solid #e0e0e0; padding-bottom: 8px; }
.app-header h1 { color: #333; margin: 0; font-size: 1.8em; }
.app-description { color: #666; margin: 8px 0 0; font-size: 0.95em; }
.app-nav { display: flex; justify-content: center; gap: 4px; margin-bottom: 0; padding: 6px; background: #f5f5f5; border-radius: 10px; flex-wrap: wrap; }
.nav-link { padding: 8px 18px; border-radius: 8px; text-decoration: none; color: #555; font-weight: 600; font-size: 0.9em; }
.nav-link:hover { background: #e0e0e0; color: #333; }
.nav-link.active { background: #0082c9; color: #fff !important; }
main { flex: 1; min-height: 0; overflow-y: scroll; }
.app-footer { flex-shrink: 0; margin-top: 40px; padding-top: 20px; border-top: 1px solid #e0e0e0; text-align: center; color: #999; font-size: 0.9em; }
</style>
