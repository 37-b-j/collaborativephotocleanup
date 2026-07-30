<template>
  <div class="execute-view">
    <div v-if="!folderStore.selectedFolder" class="folder-pick">
      <h2>Cleanup ausführen</h2>
      <p class="subtitle">Wähle oben einen Ordner, um die zumöschen markierten Bilder zu verwalten.</p>
    </div>

    <div v-else class="execute-main">
      <div class="ex-header">
        <div class="ex-title">Execute: {{ folderStore.selectedFolderName }}</div>
        <button class="ex-refresh" @click="loadPreview" :disabled="loading">↻</button>
      </div>

      <div v-if="loading" class="ex-loading"><div class="spinner"></div> Lade...</div>
      <div v-else-if="error" class="ex-error">{{ error }}</div>

      <div v-else-if="preview && preview.totalFiles === 0" class="ex-empty">
        <p>Keine Dateien zum Löschen markiert.</p>
        <router-link to="/cleanup" class="ex-link">→ Aus der Cleanup-Ansicht</router-link>
      </div>

      <div v-else-if="preview" class="ex-content">
        <div class="ex-summary">
          <div class="ex-stat danger">{{ preview.totalFiles }} zum Löschen</div>
          <div class="ex-stat">{{ preview.totalSizeHuman }}</div>
          <div class="ex-stat ready">{{ readyCount }} bereit</div>
          <div class="ex-stat missing" v-if="missingCount > 0">{{ missingCount }} fehlen</div>
        </div>

        <div class="ex-actions">
          <button class="ex-btn danger" @click="executeCleanup" :disabled="executing || readyCount === 0">
            {{ executing ? 'Wird ausgeführt...' : '🗑️ Forciert löschen (' + readyCount + ' Dateien)' }}
          </button>
          <button class="ex-btn" @click="loadPreview" :disabled="loading">Vorschau aktualisieren</button>
        </div>

        <div class="ex-grid">
          <div v-for="(file, fi) in displayFiles" :key="file.fileId"
            :class="['ex-card', { removed: file._removed }]">
            <div class="ex-card-img" @click="openDetail(file, fi)">
              <img v-if="file.previewUrl" :src="file.previewUrl" :alt="file.name" loading="lazy" />
              <div v-else class="ex-no-preview"></div>
              <div class="ex-card-overlay">
                <span class="ex-overlay-icon">🔍</span>
              </div>
            </div>
            <div class="ex-card-info">
              <div class="ex-card-name" :title="file.name">{{ file.name }}</div>
              <div class="ex-card-size">{{ formatSize(file.size) }}</div>
              <div class="ex-card-status">
                <span :class="'badge-sm ' + file.status">{{ statusLabel(file.status) }}</span>
              </div>
            </div>
            <div class="ex-card-actions">
              <button v-if="!file._removed" class="ex-card-btn remove" @click="removeFromExecute(file)" title="Von Löschliste entfernen">
                ↩ Behalten
              </button>
              <span v-else class="ex-kept">✓ Bleibt</span>
            </div>
          </div>
        </div>

        <div v-if="detailImage" class="detail-overlay" @click.self="closeDetail">
          <div class="detail-header">
            <button class="detail-back-btn" @click="closeDetail">← Zurück</button>
            <div class="detail-name">{{ detailImage.name }}</div>
            <div class="detail-fileid" v-if="detailImage.fileId">ID: {{ detailImage.fileId }}</div>
          </div>
          <div class="detail-image-wrap">
            <button class="detail-nav detail-prev" @click="detailPrev" :disabled="detailIndex <= 0">‹</button>
            <img :src="previewUrl(detailImage.fileId, 1024, 1024)"
              :alt="detailImage.name" class="detail-image" />
            <button class="detail-nav detail-next" @click="detailNext" :disabled="detailIndex >= displayFiles.length - 1">›</button>
          </div>
          <div class="detail-footer">
            <div class="detail-counter">{{ detailIndex + 1 }} / {{ displayFiles.length }}</div>
            <div class="detail-meta">{{ formatSize(detailImage.size) }} · {{ statusLabel(detailImage.status) }}</div>
            <div class="detail-actions">
              <button v-if="!detailImage._removed" class="detail-action-btn remove" @click="removeFromExecute(detailImage)">
                ↩ Von Löschliste entfernen
              </button>
              <span v-else class="detail-kept">✓ Wird behalten</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="result" class="ex-result">
        <h3>✅ Ergebnis</h3>
        <div class="ex-result-stats">
          <div class="ex-rs">Verarbeitet: <strong>{{ result.processed }}</strong></div>
          <div class="ex-rs">Fehlgeschlagen: <strong>{{ result.failed }}</strong></div>
          <div class="ex-rs">Übersprungen: <strong>{{ result.skipped || 0 }}</strong></div>
        </div>
        <div class="ex-result-actions">
          <button class="ex-btn" @click="loadPreview">Erneut prüfen</button>
          <button class="ex-btn" @click="result = null">Schließen</button>
        </div>
      </div>

      <div class="ex-quarantine">
        <h3>📦 Quarantäne</h3>
        <p class="ex-q-desc">Verschobene Dateien ({{ quarantineCount }}). <button class="ex-link-btn" @click="loadQuarantine" :disabled="qLoading">Aktualisieren</button></p>
        <div v-if="qLoading" class="ex-loading">Lade...</div>
        <div v-else-if="quarantine.length > 0" class="ex-q-list">
          <div v-for="qf in quarantine" :key="qf.name" class="ex-q-item">
            <span class="ex-q-name">{{ qf.name }}</span>
            <button class="ex-btn sm" @click="restoreFile(qf.name)">↩ Wiederherstellen</button>
          </div>
          <button class="ex-btn danger sm" @click="emptyQuarantine" :disabled="qEmptying">
            {{ qEmptying ? 'Wird geleert...' : '📍 Quarantäne leeren' }}
          </button>
        </div>
        <div v-else-if="!qLoading" class="ex-q-empty">Quarantäne ist leer.</div>
      </div>
    </div>
  </div>
</template>
<script setup lang="ts">
import { previewUrl } from "../utils/previewUrl"
import { ref, computed, watch, onMounted, onUnmounted } from "vue"
import { generateUrl, getCSRFToken } from "@/utils/helpers"
import { useFolderStore } from "@/stores/folderStore"

const folderStore = useFolderStore()

interface PreviewFile { fileId: number; name: string; size: number; previewUrl: string; status: string; _removed?: boolean }
interface PreviewData { totalFiles: number; totalSize: number; totalSizeHuman: string; files: PreviewFile[]; folder: string }
interface QFile { name: string; size: number; mimeType: string; previewUrl: string; quarantinedAt: string }

const preview = ref<PreviewData | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const executing = ref(false)
const result = ref<any>(null)

const detailImage = ref<PreviewFile | null>(null)
const detailIndex = ref(0)

const displayFiles = computed(() => {
  if (!preview.value) return []
  return preview.value.files
})

const readyCount = computed(() => {
  if (!preview.value) return 0
  return preview.value.files.filter(f => f.status === "ready" && !f._removed).length
})
const missingCount = computed(() => {
  if (!preview.value) return 0
  return preview.value.files.filter(f => f.status === "missing").length
})

const quarantine = ref<QFile[]>([])
const qLoading = ref(false)
const qEmptying = ref(false)
const quarantineCount = computed(() => quarantine.value.length)

watch(() => folderStore.selectedFolder, (newFolder, oldFolder) => {
  if (newFolder && newFolder !== oldFolder) {
    preview.value = null
    result.value = null
    quarantine.value = []
    detailImage.value = null
    loadPreview()
    loadQuarantine()
  }
})

async function loadPreview() {
  if (!folderStore.selectedFolder) return
  loading.value = true
  error.value = null
  try {
    const url = generateUrl("api/v1/cleanup/preview") + "?folder=" + encodeURIComponent(folderStore.selectedFolder)
    const resp = await fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
    const data = await resp.json()
    if (data.error) throw new Error(data.error)
    const oldRemoved = new Map<number, boolean>()
    if (preview.value) {
      for (const f of preview.value.files) {
        if (f._removed) oldRemoved.set(f.fileId, true)
      }
    }
    if (data.files) {
      for (const f of data.files) {
        if (oldRemoved.has(f.fileId)) f._removed = true
      }
    }
    preview.value = data
    detailImage.value = null
  } catch (e: any) {
    error.value = e.message || "Fehler"
  } finally {
    loading.value = false
  }
}

async function executeCleanup() {
  if (!folderStore.selectedFolder || !preview.value) return
  executing.value = true
  try {
    const resp = await fetch(generateUrl("api/v1/cleanup/execute"), {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "requesttoken": getCSRFToken() },
      body: JSON.stringify({ folder: folderStore.selectedFolder })
    })
    const data = await resp.json()
    if (data.error) throw new Error(data.error)
    result.value = data
    await loadPreview()
    await loadQuarantine()
  } catch (e: any) {
    error.value = e.message || "Fehler"
  } finally {
    executing.value = false
  }
}

async function removeFromExecute(file: PreviewFile) {
  try {
    await fetch(generateUrl("api/v1/vote"), {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "requesttoken": getCSRFToken() },
      body: JSON.stringify({ fileId: file.fileId, vote: 1 })
    })
    file._removed = true
  } catch (e: any) {
    console.error("Remove failed:", e)
  }
}

function openDetail(file: PreviewFile, idx: number) {
  detailIndex.value = idx
  detailImage.value = file
  document.body.style.overflow = "hidden"
}
function closeDetail() {
  detailImage.value = null
  document.body.style.overflow = ""
}
function detailPrev() {
  if (detailIndex.value <= 0) return
  detailIndex.value--
  detailImage.value = displayFiles.value[detailIndex.value]
}
function detailNext() {
  if (detailIndex.value >= displayFiles.value.length - 1) return
  detailIndex.value++
  detailImage.value = displayFiles.value[detailIndex.value]
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

async function loadQuarantine() {
  qLoading.value = true
  try {
    const resp = await fetch(generateUrl("api/v1/cleanup/quarantine"), { headers: { "X-Requested-With": "XMLHttpRequest" } })
    const data = await resp.json()
    quarantine.value = data.files || data || []
  } catch (e: any) {
    console.error(e)
  } finally {
    qLoading.value = false
  }
}

async function restoreFile(name: string) {
  try {
    const resp = await fetch(generateUrl("api/v1/cleanup/restore"), {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "requesttoken": getCSRFToken() },
      body: JSON.stringify({ name })
    })
    const data = await resp.json()
    if (data.success) await loadQuarantine()
  } catch (e: any) {
    console.error(e)
  }
}

async function emptyQuarantine() {
  if (!confirm("Quarantäne wirklich endgültig leeren? Alle Dateien werden unwiderruflich gelöscht!")) return
  qEmptying.value = true
  try {
    const resp = await fetch(generateUrl("api/v1/cleanup/empty-quarantine"), {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "requesttoken": getCSRFToken() },
    })
    const data = await resp.json()
    if (data.success) quarantine.value = []
  } catch (e: any) {
    console.error(e)
  } finally {
    qEmptying.value = false
  }
}

function formatSize(bytes: number): string {
  if (!bytes) return "0 B"
  const u = ["B", "KB", "MB", "GB"]
  const i = Math.floor(Math.log(bytes) / Math.log(1024))
  return (bytes / Math.pow(1024, i)).toFixed(1) + " " + u[i]
}
function statusLabel(s: string): string {
  if (s === "ready") return "bereit"
  if (s === "missing") return "fehlt"
  return "Fehler"
}

onMounted(() => {
  if (folderStore.selectedFolder) {
    loadPreview()
    loadQuarantine()
  }
})

onUnmounted(() => {
  document.body.style.overflow = ""
})
</script>
<style scoped>
.execute-view { position: relative; max-width: 900px; margin: 0 auto; }

.folder-pick { padding: 40px 20px; text-align: center; }
.folder-pick h2 { margin: 0 0 4px; color: #333; font-size: 1.5em; }
.subtitle { color: #666; font-size: 0.9em; margin: 0 0 24px; }

.execute-main { padding: 10px 0; }
.ex-header { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.ex-title { font-weight: 700; color: #333; flex: 1; }
.ex-refresh { padding: 6px 12px; background: #f0f0f0; border: none; border-radius: 6px; cursor: pointer; font-size: 1.1em; }
.ex-loading { text-align: center; padding: 30px; color: #888; }
.ex-error { padding: 15px; background: #ffebee; color: #c62828; border-radius: 8px; margin-bottom: 16px; }
.ex-empty { text-align: center; padding: 40px; color: #888; background: #f9f9f9; border-radius: 12px; }
.ex-link { color: #0082c9; }
.spinner { width: 24px; height: 24px; border: 3px solid #e0e0e0; border-top-color: #0082c9; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 10px; }
@keyframes spin { to { transform: rotate(360deg); } }

.ex-summary { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.ex-stat { padding: 6px 14px; background: #f5f5f5; border-radius: 8px; font-size: 0.85em; font-weight: 600; }
.ex-stat.danger { background: #ffebee; color: #c62828; }
.ex-stat.ready { background: #e8f5e9; color: #2e7d32; }
.ex-stat.missing { background: #fff3e0; color: #e65100; }

.ex-actions { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
.ex-btn { padding: 8px 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.85em; background: #f0f0f0; color: #333; }
.ex-btn:hover:not(:disabled) { background: #e0e0e0; }
.ex-btn.danger { background: #f44336; color: #fff; }
.ex-btn.danger:hover:not(:disabled) { background: #d32f2f; }
.ex-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.ex-btn.sm { padding: 4px 10px; font-size: 0.8em; }

.ex-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; margin-bottom: 24px; }
.ex-card { border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); background: #fff; transition: all 0.2s; }
.ex-card.removed { opacity: 0.5; }
.ex-card-img { position: relative; cursor: pointer; aspect-ratio: 1; overflow: hidden; background: #f0f0f0; }
.ex-card-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
.ex-no-preview { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 2em; color: #ccc; }
.ex-card-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; }
.ex-card-img:hover .ex-card-overlay { opacity: 1; }
.ex-overlay-icon { font-size: 1.5em; color: #fff; }
.ex-card-info { padding: 6px 8px; }
.ex-card-name { font-size: 0.75em; font-weight: 600; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ex-card-size { font-size: 0.7em; color: #888; }
.ex-card-status { margin-top: 2px; }
.badge-sm { font-size: 0.7em; padding: 2px 6px; border-radius: 4px; font-weight: 600; }
.badge-sm.ready { background: #e8f5e9; color: #2e7d32; }
.badge-sm.missing { background: #fff3e0; color: #e65100; }
.badge-sm.error { background: #ffebee; color: #c62828; }
.ex-card-actions { padding: 0 8px 8px; }
.ex-card-btn { width: 100%; padding: 4px 8px; border: 1px solid #4caf50; background: #fff; color: #4caf50; border-radius: 6px; cursor: pointer; font-size: 0.75em; font-weight: 600; }
.ex-card-btn:hover { background: #e8f5e9; }
.ex-kept { font-size: 0.75em; color: #4caf50; font-weight: 600; }

.detail-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.92); z-index: 10000; display: flex; flex-direction: column; }
.detail-header { display: flex; align-items: center; gap: 12px; padding: 12px 16px; color: #fff; }
.detail-back-btn { background: rgba(255,255,255,0.15); color: #fff; border: none; border-radius: 6px; padding: 6px 14px; cursor: pointer; font-size: 0.9em; }
.detail-back-btn:hover { background: rgba(255,255,255,0.3); }
.detail-name { flex: 1; font-weight: 600; font-size: 0.95em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.detail-fileid { font-size: 0.75em; color: rgba(255,255,255,0.5); }
.detail-image-wrap { flex: 1; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; }
.detail-image { max-width: 100%; max-height: 100%; object-fit: contain; }
.detail-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.15); color: #fff; border: none; font-size: 2.5em; width: 50px; height: 80px; display: flex; align-items: center; justify-content: center; cursor: pointer; border-radius: 8px; transition: background 0.2s; }
.detail-nav:hover:not(:disabled) { background: rgba(255,255,255,0.3); }
.detail-nav:disabled { opacity: 0.3; cursor: default; }
.detail-prev { left: 10px; }
.detail-next { right: 10px; }
.detail-footer { display: flex; justify-content: center; align-items: center; gap: 16px; padding: 12px 16px; color: rgba(255,255,255,0.8); font-size: 0.85em; flex-wrap: wrap; }
.detail-counter { color: rgba(255,255,255,0.6); }
.detail-meta { color: rgba(255,255,255,0.5); }
.detail-actions { display: flex; gap: 8px; align-items: center; }
.detail-action-btn { padding: 6px 14px; border: 1px solid #4caf50; background: rgba(76,175,80,0.2); color: #4caf50; border-radius: 6px; cursor: pointer; font-size: 0.85em; font-weight: 600; }
.detail-action-btn:hover { background: rgba(76,175,80,0.4); }
.detail-kept { color: #4caf50; font-weight: 600; }

.ex-result { background: #f5f5f5; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
.ex-result h3 { margin: 0 0 12px; }
.ex-result-stats { display: flex; gap: 16px; margin-bottom: 12px; }
.ex-rs { padding: 6px 12px; background: #fff; border-radius: 6px; font-size: 0.9em; }
.ex-result-actions { display: flex; gap: 8px; }

.ex-quarantine { background: #fff8e1; border: 1px solid #ffe082; border-radius: 12px; padding: 16px; margin-top: 20px; }
.ex-quarantine h3 { margin: 0 0 4px; }
.ex-q-desc { font-size: 0.85em; color: #666; margin: 0 0 10px; }
.ex-link-btn { background: none; border: none; color: #0082c9; cursor: pointer; text-decoration: underline; font-size: inherit; }
.ex-q-list { display: flex; flex-direction: column; gap: 6px; }
.ex-q-item { display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; background: #fff; border-radius: 6px; }
.ex-q-name { font-size: 0.85em; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ex-q-empty { color: #888; font-size: 0.85em; padding: 10px 0; }

@media (max-width: 600px) {
  .ex-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); }
  .ex-summary { flex-direction: column; }
  .detail-nav { font-size: 2em; width: 40px; height: 60px; }
}
</style>
