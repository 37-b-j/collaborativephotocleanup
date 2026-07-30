<template>
  <div class="collaboration-view">
    <div v-if="!folderStore.selectedFolder" class="folder-selection">
      <h2>Team-Bewertung</h2>
      <p class="hint">Wähle oben einen Ordner für die gemeinsame Bewertung aus.</p>
    </div>
    <template v-else>
      <div class="collab-header">
        <span class="folder-label">{{ folderStore.selectedFolderName }}</span>
        <div class="header-stats">
          <span class="stat-badge" :class="{ group: isGroupFolder }">{{ isGroupFolder ? '👥 Group Share' : '👤 Privat' }}</span>
          <span class="stat-badge">{{ consensusStore.totalUsers > 0 ? consensusStore.totalUsers : folderInfo.totalMembers }} User</span>
          <span class="stat-badge delete">{{ consensusStore.myDeleteCount }} löschen</span>
        </div>
      </div>
      <div v-if="isGroupFolder && folderInfo.members.length > 0" class="member-bar">
        <span class="member-label">Teilnehmer:</span>
        <span v-for="m in folderInfo.members" :key="m" class="member-chip">{{ m }}</span>
      </div>

      <div v-if="consensusStore.loading" class="loading"><div class="spinner"></div></div>
      <div v-else-if="consensusStore.error" class="error">{{ consensusStore.error }}</div>
      <div v-else-if="sortedTabs.length === 0" class="empty">
        <div class="empty-icon">🤝</div>
        <h3>Keine Löschkandidaten</h3>
        <p>Du hast noch keine Dateien zum Löschen markiert. Gehe zum Cleanup-Tab und bewerte Bilder.</p>
      </div>

      <div v-else class="tabs-container">
        <div class="tab-nav">
          <button
            v-for="tab in sortedTabs"
            :key="tab.consensus"
            class="tab-btn"
            :class="{ active: activeTab === tab.consensus, unanimous: tab.isUnanimous }"
            @click="activeTab = tab.consensus"
          >
            {{ tab.label }}
            <span class="tab-count">{{ tab.count }}</span>
          </button>
        </div>

        <div v-for="tab in sortedTabs" :key="tab.consensus">
          <div v-if="activeTab === tab.consensus" class="tab-content">
            <div class="tab-header">
              <h3>{{ tab.label }}</h3>
              <button
                v-if="isCurrentUserDelete(tab)"
                class="batch-delete-btn"
                @click="confirmBatchDelete(tab)"
                :disabled="deleting"
              >
                {{ deleting ? 'Löschen...' : 'Alle ' + tab.count + ' Dateien löschen' }}
              </button>
            </div>

            <div class="file-grid">
              <div v-for="file in tab.files" :key="file.fileId" class="file-card" @click="openDetail(file, getFileIndex(tab, file))">
                <img
                  v-if="file.fileInfo"
                  :src="consensusStore.previewUrl(file.fileId)"
                  :alt="file.fileInfo.name"
                  loading="lazy"
                />
                <div class="file-info">
                  <div class="file-name">{{ file.fileInfo?.name || 'Unbekannt' }}</div>
                  <div class="file-meta">
                    <span>{{ consensusStore.formatSize(file.fileInfo?.size || 0) }}</span>
                    <span class="delete-count">{{ file.deleteCount }} User</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
    <!-- Detail/Fullscreen Viewer -->
    <div v-if="detailImage" class="detail-overlay" @click.self="closeDetail">
      <div class="detail-header">
        <button class="detail-back-btn" @click="closeDetail">← Zurück</button>
        <div class="detail-name">{{ detailImage.fileInfo?.name || 'Unbekannt' }}</div>
      </div>
      <div class="detail-image-wrap">
        <button class="detail-nav detail-prev" @click="detailPrev" :disabled="detailIndex <= 0">‹</button>
        <img :src="previewUrl(detailImage.fileId, 1024, 1024)"
          :alt="detailImage.fileInfo?.name" class="detail-image" />
        <button class="detail-nav detail-next" @click="detailNext" :disabled="detailIndex >= allFiles.length - 1">›</button>
      </div>
      <div class="detail-footer">
        <div class="detail-counter">{{ detailIndex + 1 }} / {{ allFiles.length }}</div>
        <div class="detail-meta">{{ consensusStore.formatSize(detailImage.fileInfo?.size || 0) }} · {{ detailImage.deleteCount }} User</div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { previewUrl } from "../utils/previewUrl"
import { ref, computed, watch, onMounted, onUnmounted } from "vue"
import { useFolderStore } from "@/stores/folderStore"
import { useConsensusStore, type ConsensusTab } from "@/stores/consensusStore"
import { generateUrl, getCSRFToken } from "@/utils/helpers"

const folderStore = useFolderStore()
const consensusStore = useConsensusStore()
const activeTab = ref<number>(0)
const folderInfo = ref<{ isGroupFolder: boolean; owner: string | null; members: string[]; totalMembers: number }>({
  isGroupFolder: false,
  owner: null,
  members: [],
  totalMembers: 0,
})
const isGroupFolder = computed(() => folderInfo.value.isGroupFolder || consensusStore.totalUsers > 1)
const deleting = ref(false)

// Detail overlay
const detailImage = ref<ConsensusFile | null>(null)
const detailIndex = ref(0)

const allFiles = computed(() => {
  const files: ConsensusFile[] = []
  for (const tab of sortedTabs.value) {
    for (const f of tab.files) files.push(f)
  }
  return files
})

function getFileIndex(tab: ConsensusTab, file: ConsensusFile): number {
  return allFiles.value.findIndex(f => f.fileId === file.fileId)
}

function openDetail(file: ConsensusFile, idx: number) {
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
  detailImage.value = allFiles.value[detailIndex.value]
}

function detailNext() {
  if (detailIndex.value >= allFiles.value.length - 1) return
  detailIndex.value++
  detailImage.value = allFiles.value[detailIndex.value]
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

const sortedTabs = computed(() => {
  return Object.values(consensusStore.tabs).sort((a, b) => b.consensus - a.consensus)
})

function isCurrentUserDelete(tab: ConsensusTab): boolean {
  return tab.files.length > 0
}

async function confirmBatchDelete(tab: ConsensusTab) {
  if (!confirm('Wirklich alle ' + tab.count + ' Dateien aus "' + tab.label + '" löschen?')) return
  deleting.value = true
  try {
    const fileIds = tab.files.map(f => f.fileId)
    const url = generateUrl("api/v1/cleanup/execute")
    const resp = await fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "requesttoken": getCSRFToken(),
      },
      body: JSON.stringify({
        folder: folderStore.selectedFolder,
        fileIds: fileIds,
      }),
    })
    const data = await resp.json()
    if (data.success) {
      await consensusStore.loadConsensus(folderStore.selectedFolder!)
      await consensusStore.loadUsers()
    }
  } catch (e) {
    console.error("Batch delete failed", e)
  } finally {
    deleting.value = false
  }
}

async function loadFolderInfo() {
  if (!folderStore.selectedFolder) return
  try {
    const url = generateUrl("api/v1/dashboard/folder-info") + "?folder=" + encodeURIComponent(folderStore.selectedFolder)
    const resp = await fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
    if (resp.ok) {
      const data = await resp.json()
      folderInfo.value = data
    }
  } catch (e) {}
}

watch(() => folderStore.selectedFolder, (newVal, oldVal) => {
  if (newVal && newVal !== oldVal) {
    activeTab.value = 0
    loadFolderInfo()
    consensusStore.loadConsensus(newVal)
    consensusStore.loadUsers()
    consensusStore.startPolling(newVal)
  }
})

onMounted(() => {
  if (folderStore.selectedFolder) {
    loadFolderInfo()
    consensusStore.loadConsensus(folderStore.selectedFolder)
    consensusStore.loadUsers()
    consensusStore.startPolling(folderStore.selectedFolder)
  }
})

onUnmounted(() => {
  consensusStore.stopPolling()
  document.body.style.overflow = ""
})
</script>

<style scoped>
.collaboration-view { max-width: 900px; margin: 0 auto; }
.folder-selection { padding: 40px 20px; text-align: center; }
.folder-selection h2 { text-align: center; color: #333; }
.hint { text-align: center; color: #666; font-size: 0.9em; margin-bottom: 16px; }
.collab-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
.folder-label { font-weight: 600; color: #0082c9; font-size: 1.1em; }
.header-stats { display: flex; gap: 8px; }
.stat-badge { background: #e3f2fd; color: #0082c9; padding: 4px 12px; border-radius: 12px; font-size: 0.85em; font-weight: 600; }
.stat-badge.delete { background: #ffebee; color: #c62828; }
.loading, .error, .empty { text-align: center; padding: 40px; color: #666; }
.empty-icon { font-size: 48px; margin-bottom: 12px; }
.spinner { width: 32px; height: 32px; border: 3px solid #e0e0e0; border-top-color: #0082c9; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto; }
@keyframes spin { to { transform: rotate(360deg); } }
.tabs-container { margin-top: 12px; }
.tab-nav { display: flex; gap: 4px; margin-bottom: 16px; flex-wrap: wrap; }
.tab-btn { padding: 8px 16px; border: 1px solid #ddd; background: #fff; border-radius: 8px; cursor: pointer; font-size: 0.85em; font-weight: 600; color: #555; transition: all 0.2s; display: flex; align-items: center; gap: 6px; }
.tab-btn:hover { background: #f5f5f5; }
.tab-btn.active { background: #0082c9; color: #fff; border-color: #0082c9; }
.tab-btn.unanimous.active { background: #2e7d32; border-color: #2e7d32; }
.tab-count { background: rgba(255,255,255,0.3); padding: 2px 8px; border-radius: 10px; font-size: 0.8em; }
.tab-btn.active .tab-count { background: rgba(255,255,255,0.25); }
.tab-content { animation: fadeIn 0.2s; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
.tab-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px; }
.tab-header h3 { margin: 0; color: #333; font-size: 1.1em; }
.batch-delete-btn { padding: 8px 20px; background: #c62828; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.85em; transition: background 0.2s; }
.batch-delete-btn:hover { background: #b71c1c; }
.batch-delete-btn:disabled { background: #ccc; cursor: default; }
.file-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
.file-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; overflow: hidden; transition: box-shadow 0.2s; cursor: pointer; }
.file-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.1); }
.file-card img { width: 100%; aspect-ratio: auto; object-fit: contain; display: block; max-height: 160px; background: #111; }
.file-info { padding: 8px; }
.file-name { font-size: 0.8em; font-weight: 600; color: #333; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.file-meta { display: flex; justify-content: space-between; font-size: 0.75em; color: #888; margin-top: 4px; }
.delete-count { color: #c62828; font-weight: 600; }
.detail-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.92); z-index: 10000; display: flex; flex-direction: column; }
.detail-header { display: flex; align-items: center; gap: 12px; padding: 12px 16px; color: #fff; flex-shrink: 0; }
.detail-back-btn { background: rgba(255,255,255,0.15); color: #fff; border: none; border-radius: 6px; padding: 6px 14px; cursor: pointer; font-size: 0.9em; }
.detail-back-btn:hover { background: rgba(255,255,255,0.3); }
.detail-name { flex: 1; font-weight: 600; font-size: 0.95em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.detail-image-wrap { flex: 1; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; }
.detail-image { max-width: 100%; max-height: 100%; object-fit: contain; }
.detail-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.15); color: #fff; border: none; font-size: 2.5em; width: 50px; height: 80px; display: flex; align-items: center; justify-content: center; cursor: pointer; border-radius: 8px; transition: background 0.2s; }
.detail-nav:hover:not(:disabled) { background: rgba(255,255,255,0.3); }
.detail-nav:disabled { opacity: 0.3; cursor: default; }
.detail-prev { left: 10px; }
.detail-next { right: 10px; }
.detail-footer { display: flex; justify-content: center; align-items: center; gap: 16px; padding: 12px 16px; color: rgba(255,255,255,0.8); font-size: 0.85em; flex-shrink: 0; }
.detail-counter { color: rgba(255,255,255,0.6); }
.detail-meta { color: rgba(255,255,255,0.5); }
@media (max-width: 600px) { .detail-nav { font-size: 2em; width: 40px; height: 60px; } }
.member-bar { display: flex; align-items: center; gap: 6px; margin-bottom: 12px; flex-wrap: wrap; padding: 8px 12px; background: #e8f5e9; border-radius: 8px; font-size: 0.8em; }
.member-label { color: #2e7d32; font-weight: 600; }
.member-chip { background: #c8e6c9; color: #1b5e20; padding: 2px 10px; border-radius: 10px; font-weight: 600; font-size: 0.85em; }
.stat-badge.group { background: #e8f5e9; color: #2e7d32; }
</style>
