<template>
  <div class="photos-view">
    <div v-if="!folderStore.selectedFolder" class="folder-selection">
      <h2>Foto-Galerie</h2>
      <p class="hint">Wähle oben einen Ordner mit Fotos aus.</p>
    </div>
    <template v-else>
      <div v-if="loading" class="loading"><div class="spinner"></div></div>
      <div v-else-if="photos.length === 0" class="empty">Keine Bilder in diesem Ordner.</div>
      <div v-else class="photo-grid">
        <div v-for="p in photos" :key="p.fileId" class="photo-card" @click="openDetail(p)">
          <img :src="previewUrl(p.fileId, 256, 256)" :alt="p.name" loading="lazy" />
          <div class="photo-name">{{ p.name }}</div>
        </div>
      </div>
    </template>

    <!-- Detail/Fullscreen Viewer -->
    <div v-if="detailImage" class="detail-overlay" @click.self="closeDetail">
      <div class="detail-header">
        <button class="detail-back-btn" @click="closeDetail">← Zurück</button>
        <div class="detail-name">{{ detailImage.name }}</div>
      </div>
      <div class="detail-image-wrap">
        <button class="detail-nav detail-prev" @click="detailPrev" :disabled="detailIndex <= 0">‹</button>
        <img :src="hiresUrl || previewUrl(detailImage.fileId, 1024, 1024)"
          :alt="detailImage.name" class="detail-image" @load="onImgLoaded" />
        <button class="detail-nav detail-next" @click="detailNext" :disabled="detailIndex >= photos.length - 1">›</button>
      </div>
      <div class="detail-footer">
        <div class="detail-counter">{{ detailIndex + 1 }} / {{ photos.length }}</div>
        <div class="detail-meta">{{ formatSize(detailImage.size) }}</div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { previewUrl } from "../utils/previewUrl"
import { ref, watch, onMounted, onUnmounted } from "vue"
import { generateUrl } from "@/utils/helpers"
import { useFolderStore } from "@/stores/folderStore"

const folderStore = useFolderStore()

const photos = ref<any[]>([])
const loading = ref(false)
const detailImage = ref<any>(null)
const hiresUrl = ref("")
const detailIndex = ref(0)


function formatSize(bytes: number): string {
  if (!bytes || bytes < 0) return "0 B"
  if (bytes < 1024) return bytes + " B"
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + " KB"
  return (bytes / (1024 * 1024)).toFixed(1) + " MB"
}

function onImgLoaded(e: Event) {
  const img = e.target as HTMLImageElement
  const origUrl = detailImage.value?._origUrl
  if (origUrl && !img.src.includes("/api/v1/preview/")) {
    const test = new Image()
    test.onload = () => { hiresUrl.value = origUrl }
    test.src = origUrl
  }
}

function openDetail(p: any) {
  detailIndex.value = photos.value.indexOf(p)
  hiresUrl.value = ""
  detailImage.value = { ...p, _origUrl: generateUrl("api/v1/preview/" + p.fileId) }
  document.body.style.overflow = "hidden"
}

function closeDetail() {
  detailImage.value = null
  document.body.style.overflow = ""
}

function detailPrev() {
  if (detailIndex.value <= 0) return
  detailIndex.value--
  const p = photos.value[detailIndex.value]
  hiresUrl.value = ""
  detailImage.value = { ...p, _origUrl: generateUrl("api/v1/preview/" + p.fileId) }
}

function detailNext() {
  if (detailIndex.value >= photos.value.length - 1) return
  detailIndex.value++
  const p = photos.value[detailIndex.value]
  hiresUrl.value = ""
  detailImage.value = { ...p, _origUrl: generateUrl("api/v1/preview/" + p.fileId) }
}

function onDetailKey(e: KeyboardEvent) {
  if (e.key === "Escape") closeDetail()
  if (e.key === "ArrowLeft") detailPrev()
  if (e.key === "ArrowRight") detailNext()
}

if (typeof window !== "undefined") {
  window.addEventListener("keydown", (e) => {
    if (detailImage.value !== null) onDetailKey(e)
  })
}

watch(() => [folderStore.selectedFolder, folderStore.refreshCounter], () => {
  if (folderStore.selectedFolder) {
    loadPhotos()
  }
})

async function loadPhotos() {
  if (!folderStore.selectedFolder) return
  loading.value = true
  try {
    const params = new URLSearchParams({ folder: folderStore.selectedFolder, subfolders: String(folderStore.includeSubfolders) })
    const resp = await fetch(generateUrl("api/v1/photos") + "?" + params.toString(), { headers: { "X-Requested-With": "XMLHttpRequest" } })
    const data = await resp.json()
    photos.value = data.photos || []
  } catch (e: any) { console.error(e) }
  finally { loading.value = false }
}

onMounted(() => {
  if (folderStore.selectedFolder) {
    loadPhotos()
  }
})

onUnmounted(() => {
  document.body.style.overflow = ""
})
</script>

<style scoped>
.photos-view { max-width: 900px; margin: 0 auto; }
.folder-selection { padding: 40px 20px; text-align: center; }
.folder-selection h2 { text-align: center; color: #333; }
.hint { text-align: center; color: #666; font-size: 0.9em; margin-bottom: 16px; }
.photos-header { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; }
.folder-label { font-weight: 600; color: #0082c9; }
.photo-count { color: #666; font-size: 0.85em; margin-left: auto; }
.loading { display: flex; justify-content: center; padding: 40px; }
.spinner { width: 32px; height: 32px; border: 3px solid #e0e0e0; border-top-color: #0082c9; border-radius: 50%; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.empty { text-align: center; padding: 40px; color: #888; }
.photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
.photo-card { border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); background: #fff; cursor: pointer; transition: transform 0.2s; }
.photo-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.photo-card img { width: 100%; aspect-ratio: auto; object-fit: contain; display: block; max-height: 200px; }
.photo-name { padding: 6px 10px; font-size: 0.8em; color: #555; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Detail overlay */
.detail-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.92); z-index: 10000; display: flex; flex-direction: column; }
.detail-header { display: flex; align-items: center; padding: 12px 16px; gap: 12px; flex-shrink: 0; }
.detail-back-btn { background: rgba(255,255,255,0.15); border: none; color: #fff; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.9em; }
.detail-name { color: #fff; font-size: 1em; font-weight: 500; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.detail-image-wrap { flex: 1; display: flex; align-items: center; justify-content: center; position: relative; min-height: 0; }
.detail-image { max-width: 95%; max-height: 85vh; object-fit: contain; border-radius: 4px; }
.detail-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.15); border: none; color: #fff; font-size: 2em; width: 48px; height: 64px; display: flex; align-items: center; justify-content: center; cursor: pointer; border-radius: 6px; z-index: 10; }
.detail-nav:disabled { opacity: 0.3; cursor: default; }
.detail-prev { left: 16px; }
.detail-next { right: 16px; }
.detail-footer { padding: 12px 16px; display: flex; align-items: center; gap: 16px; flex-shrink: 0; }
.detail-counter { color: rgba(255,255,255,0.7); font-size: 0.85em; }
.detail-meta { color: rgba(255,255,255,0.5); font-size: 0.8em; }
</style>