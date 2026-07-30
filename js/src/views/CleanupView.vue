<template>
  <div class="cleanup-view">
    <div v-if="!folderStore.selectedFolder" class="empty-state">
      <div class="empty-icon">👁</div>
      <h2>Kein Ordner ausgeweählt</h2>
      <p>Wähle oben einen Ordner mit Fotos aus, den du aufäumen möchtest.</p>
    </div>

    <template v-else-if="loading">
      <div class="loading-screen"><div class="spinner"></div><p>Lade Bilder und Cluster...</p></div>
    </template>

    <template v-else-if="error">
      <div class="error-screen"><p>{{ error }}</p><button @click="loadAll">Neu laden</button></div>
    </template>

    <template v-else-if="images.length === 0 && clusters.length === 0 && !scanBanner">
      <div class="empty-state">
        <div class="empty-icon">👭</div>
        <h2>Keine Bilder</h2>
        <p>Im Ordner wurden kiene Bilder gefunden.</p>
      </div>
    </template>

    <template v-else-if="scanBanner">
      <div class="scan-banner">
        <div class="scan-banner-icon">🔍</div>
        <div class="scan-banner-text">
          <strong>{{ scanBanner.hashedImages }} / {{ scanBanner.totalImages }}</strong> Bilder analysiert
          <span v-if="scanBanner.pendingImages > 0"> — noch <strong>{{ scanBanner.pendingImages }}</strong> ausstehend</span>
        </div>
        <div class="scan-banner-bar">
          <div class="scan-banner-fill" :style="{ width: scanPercent + '%' }"></div>
        </div>
        <p class="scan-banner-hint">Cluster werden geladen, sobald die Analyse abgeschlossen ist. Einzelbild-Swipe-Ansicht ist verfügbar.</p>
        <button class="scan-banner-skip" @click="scanBanner = null; viewMode = 'swipe'">Zur Einzelbild-Ansicht</button>
      </div>
    </template>

    <template v-else-if="allDone">
      <div class="empty-state">
        <div class="empty-icon">🚉</div>
        <h2>Fertig!</h2>
        <p>{{ clusters.length > 0 ? 'Alle Cluster bewertet.' : 'Alle Bilder bewertet.' }}</p>
        <div class="final-stats">
          <div class="stat-card keep">Behalten: {{ stats.keep }}</div>
          <div class="stat-card delete">Löschen: {{ stats.delete }}</div>
          <div class="stat-card skip">Übersprungen: {{ stats.skip }}</div>
        </div>
        <button class="reset-btn" style="margin-top:12px" @click="resetAll">Neustart (gleicher Ordner)</button>
      </div>
    </template>

    <template v-else>
      <div v-if="detailImage" class="detail-overlay" @click.self="closeDetail">
        <div class="detail-header">
          <button class="detail-back-btn" @click="closeDetail">← Zurück</button>
          <div class="detail-name">{{ detailImage.name }}</div>
          <div v-if="detailImgLoading" class="detail-loading-spinner"></div>
          <button :class="['detail-fav-btn', { active: localFavorites.has(detailImage.fileId) }]"
            @click="toggleDetailFavorite">★</button>
        </div>
        <div class="detail-image-wrap">
          <button class="detail-nav detail-prev" @click="detailPrev">‹</button>
          <img :src="detailHiresUrl || previewUrl(detailImage.fileId, 1024, 1024)"
            :alt="detailImage.name" class="detail-image" @load="onDetailImgLoaded" />
          <button class="detail-nav detail-next" @click="detailNext">›</button>
        </div>
        <div class="detail-footer">
          <div class="detail-counter">{{ detailIndex + 1 }} / {{ currentCluster?.images.length || 0 }}</div>
          <div v-if="detailImage.faces && detailImage.faces > 0" class="detail-faces">
            {{ detailImage.faces }} Gesicht{{ detailImage.faces !== 1 ? 'er' : '' }}
          </div>
        </div>
      </div>

      <div v-else-if="viewMode === 'overview' && currentCluster" class="cluster-overview" ref="clusterOverviewArea">
        <div class="overview-header">
          <button class="back-btn" @click="viewMode = 'cluster-card'">← Zurück</button>
          <div class="overview-title">Cluster {{ currentClusterIndex + 1 }} / {{ clusters.length }}</div>
          <div class="overview-count">{{ currentCluster.images.length }} Bilder</div>
        </div>
        <p class="overview-hint">Klick auf ein Bild zem Vergrüßern. Favoriten-Stern rechts oben im Viewer.</p>
        <div class="overview-grid">
          <div v-for="img in currentCluster.images" :key="img.fileId"
            :class="['overview-card', { 'is-favorite': localFavorites.has(img.fileId) }]"
            @click="openDetail(img)">
            <img :src="previewUrl(img.fileId, 256, 256)" :alt="img.name" loading="lazy" />
            <div class="overview-card-badge">
              <span v-if="img.faces && img.faces > 0" class="face-badge">{{ img.faces }} Gesichter</span>
            </div>
            <div class="overview-star" :class="{ active: localFavorites.has(img.fileId) }">★</div>
            <div class="overview-name">{{ img.name }}</div>
          </div>
        </div>
        <div class="overview-actions">
          <button class="vote-btn delete" @click="overviewDeleteAll" :disabled="clusterAnimating">Alle löschen</button>
          <button class="vote-btn keep overview-apply" @click="overviewApplyFavorites" :disabled="clusterAnimating">
            {{ localFavorites.size }} Favorit{{ localFavorites.size !== 1 ? 'en' : '' }} behalten
          </button>
          <button class="vote-btn keep" @click="voteKeepAll" :disabled="clusterAnimating">Alle behalten</button>
        </div>
        <div v-if="clusterAnimating" class="cluster-progress">{{ clusterVoteProgress }}</div>
      </div>

      <div v-else-if="viewMode === 'cluster-card' && currentCluster" class="cluster-card-mode" ref="clusterCardArea">
        <div class="card-mode-header">
          <div class="card-mode-title">Cluster {{ currentClusterIndex + 1 }} / {{ clusters.length }}</div>
          <div class="card-mode-count">{{ currentCluster.images.length }} Bilder</div>
        </div>
        <div class="card-deck-container">
          <div class="card-deck">
            <div v-for="(deckImg, di) in getDeckImages()" :key="deckImg.fileId"
              :class="['deck-card', 'deck-card-' + (di + 1)]"
              :style="{ zIndex: 10 - di, transform: 'rotate(' + (di * 3 - 3) + 'deg) translateY(' + (di * 6) + 'px)' }">
              <img :src="previewUrl(deckImg.fileId, 128, 128)" alt="" />
            </div>
            <div class="main-card" :class="cardSwipeClass" ref="mainCardRef"
              @click="openClusterOverview"
              @pointerdown="onCardPointerDown" @pointermove="onCardPointerMove"
              @pointerup="onCardPointerUp" @pointercancel="onCardPointerUp">
              <img :src="previewUrl(currentCluster.favorite.fileId, 512, 512)"
                :alt="currentCluster.favorite.name" draggable="false" />
              <div class="main-card-overlay">
                <div class="main-card-label">Favorit</div>
                <div v-if="currentCluster.favorite.faces && currentCluster.favorite.faces > 0" class="main-card-faces">
                  {{ currentCluster.favorite.faces }} Gesicht{{ currentCluster.favorite.faces !== 1 ? 'er' : '' }}
                </div>
              </div>
              <div :class="['vote-indicator', 'keep', { show: cardSwipeDir === 'right' }]">BEHALTEN</div>
              <div :class="['vote-indicator', 'delete', { show: cardSwipeDir === 'left' }]">LÖSCHEN</div>
              <div :class="['vote-indicator', 'skip', { show: cardSwipeDir === 'up' }]">ÜBERSPRINGEN</div>
            </div>
          </div>
        </div>
        <div class="card-mode-actions">
          <button class="action-btn action-skip" @click="skipCluster">Überspringen</button>
          <button class="action-btn action-overview" @click="openClusterOverview">Übersicht</button>
          <button class="action-btn action-delete" @click="voteClusterDeleteAll" :disabled="clusterAnimating">Alle löschen</button>
          <button class="action-btn action-keep" @click="voteClusterKeepFavorite" :disabled="clusterAnimating">
            Nur Favorit behalten
          </button>
        </div>
        <div v-if="clusterAnimating" class="cluster-progress">{{ clusterVoteProgress }}</div>
      </div>

      <div v-else-if="swipeDetailImage" class="swipe-detail-overlay" @click.self="closeSwipeDetail">
        <div class="detail-header">
          <button class="detail-back-btn" @click="closeSwipeDetail">← Zurück</button>
          <div class="detail-name">{{ swipeDetailImage.name }}</div>
        </div>
        <div class="detail-image-wrap">
<button class="detail-nav detail-prev" v-if="images.length > 1" @click="swipeDetailPrev" :disabled="swipeDetailIndex <= 0">‹</button>
          <img :src="swipeHiresUrl || previewUrl(swipeDetailImage.id, 1024, 1024)"
            :alt="swipeDetailImage.name" class="detail-image" @load="onSwipeImgLoaded" />
<button class="detail-nav detail-next" v-if="images.length > 1" @click="swipeDetailNext" :disabled="swipeDetailIndex >= images.length - 1">›</button>
        </div>
        <div class="detail-footer">
          <div class="detail-counter">{{ swipeDetailIndex + 1 }} / {{ images.length }}</div>
          <div class="detail-meta">{{ formatSize(swipeDetailImage.size) }}</div>
          <div class="detail-actions">
          </div>
        </div>
      </div>
      <div v-else class="swipe-container">
        <div class="swipe-header">
          <div class="swipe-header-left">
            <div>
              <div class="progress-info">Bild {{ currentIndex + 1 }} / {{ images.length }}</div>
            </div>
          </div>
          <div class="progress-bar-wrap"><div class="progress-bar-fill" :style="{ width: progressPercent + '%' }"></div></div>
          <div class="quick-stats">
            <span class="qs keep">K: {{ stats.keep }}</span>
            <span class="qs delete">D: {{ stats.delete }}</span>
            <span class="qs skip">S: {{ stats.skip }}</span>
          </div>
        </div>
        <div class="swipe-content" ref="swipeArea"
          @pointerdown="onPointerDown" @pointermove="onPointerMove"
          @pointerup="onPointerUp" @pointercancel="onPointerUp">
          <div :class="['image-card', swipeClass]" ref="imageCard" style="cursor:pointer">
            <img :src="currentImage.url" :alt="currentImage.name" draggable="false" @dragstart.prevent @error="onImageError" />
            <div :class="['vote-indicator', 'keep', { show: swipeDir === 'right' }]">BEHALTEN</div>
            <div :class="['vote-indicator', 'delete', { show: swipeDir === 'left' }]">LÖSCHEN</div>
            <div :class="['vote-indicator', 'skip', { show: swipeDir === 'up' }]">ÜBERSPRINGEN</div>
          </div>
        </div>
        <div class="image-info">
          <div class="name">{{ currentImage.name }}</div>
          <div class="meta">{{ formatSize(currentImage.size) }}</div>
        </div>
        <div class="vote-buttons">
          <button class="vote-btn delete" @click="vote('delete')" :disabled="animating">Löschen</button>
          <button class="vote-btn skip" @click="vote('skip')" :disabled="animating">Überspringen</button>
          <button class="vote-btn keep" @click="vote('keep')" :disabled="animating">Behalten</button>
        </div>
      </div>
    </template>
  </div>
</template>
<script setup lang="ts">
import { previewUrl } from "../utils/previewUrl"

// Track which images have been loaded at 2048px (browser HTTP cache handles actual data)
const hiresLoaded = new Set<number>()

function hiresUrlFor(fileId: number): string {
  return previewUrl(fileId, 2048, 2048)
}

function clearHiresCache(): void {
  hiresLoaded.clear()
}

// AbortController for in-flight original-image fetches
// No AbortController needed — using Image() preloading, browser HTTP cache handles it
function abortOrigFetch(): void {
  // No-op — kept for compatibility with existing call sites
}

import { ref, computed, onMounted, onUnmounted, watch, nextTick } from "vue"
import { generateUrl, getCSRFToken } from "@/utils/helpers"
import { useFolderStore } from "@/stores/folderStore"

const folderStore = useFolderStore()

interface SwipeImage { id: number; name: string; url: string; size: number; path: string }
interface ClusterImage { fileId: number; name: string; size: number; mimeType: string; path: string; faces?: number }
interface Cluster { id: number; images: ClusterImage[]; count: number; favorite: ClusterImage; totalFaces: number }

type ViewMode = 'swipe' | 'cluster-card' | 'overview'

const images = ref<SwipeImage[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const currentIndex = ref(0)
const animating = ref(false)
const votes = ref<Record<number, number>>({})
const swipeArea = ref<HTMLElement | null>(null)
const imageCard = ref<HTMLElement | null>(null)
const swipeDir = ref("")
const swipeClass = ref("")
const startX = ref(0)
const startY = ref(0)
const swiping = ref(false)

const clusters = ref<Cluster[]>([])
const skippedClusterIds = ref<Set<number>>(new Set())
const currentClusterIndex = ref(0)
const clusterAnimating = ref(false)
const clusterVoteProgress = ref("")
const viewMode = ref<ViewMode>('swipe')
const scanBanner = ref<{ totalImages: number; hashedImages: number; pendingImages: number } | null>(null)

const mainCardRef = ref<HTMLElement | null>(null)
const clusterCardArea = ref<HTMLElement | null>(null)
const clusterOverviewArea = ref<HTMLElement | null>(null)
const cardSwipeDir = ref("")
const cardSwipeClass = ref("")
const cardStartX = ref(0)
const cardStartY = ref(0)
const cardSwiping = ref(false)

const localFavorites = ref<Set<number>>(new Set())

const detailImage = ref<ClusterImage | null>(null)
const detailImgLoading = ref(false)
const detailHiresUrl = ref("")
const detailLoadingFileId = ref<number | null>(null)
const detailIndex = ref(0)
const swipeDetailImage = ref<SwipeImage | null>(null)
const swipeHiresUrl = ref("")
const swipeDetailIndex = ref(0)

const currentCluster = computed(() => {
  if (clusters.value.length === 0) return null
  if (currentClusterIndex.value >= clusters.value.length) return null
  return clusters.value[currentClusterIndex.value]
})

const currentImage = computed(() => {
  if (currentIndex.value >= images.value.length) return null
  return images.value[currentIndex.value] || null
})

const allDone = computed(() => {
  if (viewMode.value === 'cluster-card' && clusters.value.length > 0) return false
  if (viewMode.value === 'overview' && clusters.value.length > 0) return false
  if (images.value.length === 0) return false
  return currentIndex.value >= images.value.length
})

const stats = computed(() => {
  let k = 0, d = 0, s = 0
  for (const v of Object.values(votes.value)) {
    if (v === 1) k++
    else if (v === 0) d++
    else if (v === -1) s++
  }
  return { keep: k, delete: d, skip: s }
})

const progressPercent = computed(() => {
  if (clusters.value.length > 0) {
    if (clusters.value.length === 0) return 0
    return Math.round((currentClusterIndex.value / clusters.value.length) * 100)
  }
  return images.value.length === 0 ? 0 : Math.round((Object.keys(votes.value).length / images.value.length) * 100)
})

const scanPercent = computed(() => {
  if (!scanBanner.value || scanBanner.value.totalImages === 0) return 0
  return Math.round((scanBanner.value.hashedImages / scanBanner.value.totalImages) * 100)
})

const SK = "swipe_votes_cleanup"
function saveToStorage() { try { localStorage.setItem(SK, JSON.stringify(votes.value)) } catch (e) {} }

function formatSize(b: number): string {
  if (!b) return ""
  const u = ["B", "KB", "MB", "GB"]
  const i = Math.floor(Math.log(b) / Math.log(1024))
  return (b / Math.pow(1024, i)).toFixed(1) + " " + u[i]
}
function onImageError(e: Event) {
  const img = e.target as HTMLImageElement
  img.src = previewUrl(currentImage.value?.id, 512, 512)
}
function getDeckImages(): ClusterImage[] {
  if (!currentCluster.value) return []
  const others = currentCluster.value.images.filter(
    (i: ClusterImage) => i.fileId !== currentCluster.value!.favorite.fileId
  )
  return others.slice(0, 3)
}

watch(() => [folderStore.selectedFolder, folderStore.refreshCounter], () => {
  if (folderStore.selectedFolder) {
    loadAll()
  }
})

watch(viewMode, () => {
  scrollToCurrent()
})

watch(loading, (isLoading) => {
  if (!isLoading) {
    scrollToCurrent()
  }
})

async function loadAll() {
    if (!folderStore.selectedFolder) return
  abortOrigFetch()
  images.value = []
  clusters.value = []
  currentClusterIndex.value = 0
  currentIndex.value = 0
  viewMode.value = 'swipe'
  localFavorites.value = new Set()
  detailImage.value = null
  skipRound = false
  skippedClusterIds.value = new Set()
  await loadImages()
  await loadClusters()
  console.log("[CleanupView] loadAll: after loadClusters, clusters.length=", clusters.value.length, "viewMode=", viewMode.value)
  if (clusters.value.length > 0) {
    // Filter out clusters where all images are already voted
    let unvotedClusters = []
    for (let ci = 0; ci < clusters.value.length; ci++) {
      const cl = clusters.value[ci]
      const hasUnvoted = cl.images.some((img: any) => votes.value[img.fileId] === undefined)
      if (hasUnvoted) {
        unvotedClusters.push(cl)
      }
    }
    if (unvotedClusters.length > 0) {
      clusters.value = unvotedClusters
      currentClusterIndex.value = 0
      viewMode.value = 'cluster-card'
      scrollToCard()
      console.log("[CleanupView] loadAll: switching to cluster-card, unvoted clusters=", unvotedClusters.length)
    } else {
      viewMode.value = 'swipe'
      console.log("[CleanupView] loadAll: all clusters already voted, staying in swipe")
    }
  } else {
    viewMode.value = 'swipe'
    console.log("[CleanupView] loadAll: staying in swipe mode")
  }
}

async function resetAll() {
  const csrfToken = getCSRFToken()
  // DELETE all votes via API (clean slate)
  const promises = []
  for (const key of Object.keys(votes.value)) {
    promises.push(
      fetch(generateUrl("api/v1/vote/delete"), {
        method: "DELETE",
        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "requesttoken": csrfToken },
        body: JSON.stringify({ fileId: parseInt(key) }),
      }).catch(() => {})
    )
  }
  // Also delete votes for current images
  for (const img of images.value) {
    promises.push(
      fetch(generateUrl("api/v1/vote/delete"), {
        method: "DELETE",
        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "requesttoken": csrfToken },
        body: JSON.stringify({ fileId: img.id }),
      }).catch(() => {})
    )
  }
  await Promise.all(promises)
  // Clear local storage
  votes.value = {}
  saveToStorage()
  // Reset UI
  currentIndex.value = 0
  clusters.value = []
  currentClusterIndex.value = 0
  viewMode.value = 'swipe'
  folderStore.triggerRefresh()
  loadAll()
}

async function loadImages() {
    if (!folderStore.selectedFolder) return
  loading.value = true
  error.value = null
  try {
    const params = new URLSearchParams({ folder: folderStore.selectedFolder!, subfolders: String(folderStore.includeSubfolders) })
    const url = generateUrl("api/v1/photos") + "?" + params.toString()
    const resp = await fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
    if (!resp.ok) throw new Error("HTTP " + resp.status)
    const data = await resp.json()
    if (data.error) { error.value = data.error; return }
    images.value = (data.photos || []).map((img: any) => ({
      id: img.fileId || img.id,
      name: img.name || "Bild",
      url: previewUrl((img.fileId || img.id), 512, 512),
      size: img.size || 0,
      path: img.path || "",
    }))
    advance()
  } catch (e: any) { error.value = e.message || "Fehler" }
  finally { loading.value = false }
}

async function loadClusters() {
    if (!folderStore.selectedFolder) return
  try {
    const threshold = localStorage.getItem('photocleanup_cluster_threshold')
    const params: Record<string, string> = { folder: folderStore.selectedFolder! }
    if (threshold) params.threshold = threshold
    const url = generateUrl("api/v1/similar-clusters") + "?" + new URLSearchParams(params).toString()
    console.log("[CleanupView] loadClusters: fetching", url)
    const resp = await fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
    console.log("[CleanupView] loadClusters: status", resp.status, "ok", resp.ok)
    if (!resp.ok) return
    const data = await resp.json()
    console.log("[CleanupView] loadClusters: data", data)
    if (data.clusters && data.clusters.length > 0) {
      clusters.value = data.clusters
      console.log("[CleanupView] loadClusters: set", clusters.value.length, "clusters")
    } else {
      console.log("[CleanupView] loadClusters: no clusters in response")
    }
    if (data.needsScan) {
      scanBanner.value = {
        totalImages: data.totalImages ?? 0,
        hashedImages: data.hashedImages ?? 0,
        pendingImages: (data.totalImages ?? 0) - (data.hashedImages ?? 0),
      }
      startScanPolling()
    } else {
      scanBanner.value = null
    }
  } catch (e: any) { console.error("Cluster load error:", e) }
}

let scanPollInterval: ReturnType<typeof setInterval> | null = null

async function checkScanProgress() {
  if (!folderStore.selectedFolder) return
  try {
    const params = new URLSearchParams({ folder: folderStore.selectedFolder })
    const resp = await fetch(generateUrl("api/v1/scan-status") + "?" + params.toString(),
      { headers: { "X-Requested-With": "XMLHttpRequest" } })
    if (!resp.ok) return
    const data = await resp.json()
    scanBanner.value = {
      totalImages: data.totalImages ?? 0,
      hashedImages: data.hashedImages ?? 0,
      pendingImages: data.pendingImages ?? 0,
    }
    if (data.ready) {
      stopScanPolling()
      scanBanner.value = null
      await loadClusters()
    }
  } catch (e) { console.warn("[CleanupView] scan status check failed:", e) }
}

function startScanPolling() {
  if (scanPollInterval) return
  scanPollInterval = setInterval(checkScanProgress, 5000)
}

function stopScanPolling() {
  if (scanPollInterval) {
    clearInterval(scanPollInterval)
    scanPollInterval = null
  }
}

let skipRound = false
function advance() {
  for (let i = currentIndex.value; i < images.value.length; i++) {
    if (votes.value[images.value[i].id] === undefined) {
      currentIndex.value = i
      scrollToSwipe()
      return
    }
  }
  if (!skipRound) {
    const skipped = images.value.filter(img => votes.value[img.id] === -1)
    if (skipped.length > 0) {
      skipRound = true
      for (const img of skipped) {
        delete votes.value[img.id]
      }
      saveToStorage()
      currentIndex.value = 0
      advance()
      scrollToSwipe()
      return
    }
  }
  currentIndex.value = images.value.length
}

function scrollToCurrent() {
  setTimeout(() => {
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        if (viewMode.value === 'swipe') scrollToSwipe()
        else if (viewMode.value === 'cluster-card') scrollToCard()
        else if (viewMode.value === 'overview') scrollToOverview()
      })
    })
  }, 300)
}

function scrollToSwipe() {
  nextTick(() => {
    if (swipeArea.value) {
      swipeArea.value.scrollIntoView({ behavior: 'instant', block: 'center' })
    }
  })
}

function scrollToCard() {
  nextTick(() => {
    if (clusterCardArea.value) {
      clusterCardArea.value.scrollIntoView({ behavior: 'instant', block: 'center' })
    }
  })
}

function scrollToOverview() {
  nextTick(() => {
    if (clusterOverviewArea.value) {
      clusterOverviewArea.value.scrollIntoView({ behavior: 'instant', block: 'center' })
    }
  })
}

function vote(voteVal: string) {
  if (animating.value || !currentImage.value) return
  const img = currentImage.value
  let nv = 0
  if (voteVal === "keep") nv = 1
  else if (voteVal === "skip") nv = -1
  animating.value = true
  votes.value[img.id] = nv
  saveToStorage()
  fetch(generateUrl("api/v1/vote"), {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "requesttoken": getCSRFToken() },
    body: JSON.stringify({ fileId: img.id, vote: nv }),
  }).catch(() => {})
  swipeClass.value = "swiping-" + voteVal
  setTimeout(() => {
    swipeClass.value = ""
    currentIndex.value++
    advance()
    scrollToSwipe()
    animating.value = false
  }, 300)
}

function onPointerDown(e: PointerEvent) {
  startX.value = e.clientX
  startY.value = e.clientY
  swiping.value = true
  if (imageCard.value) imageCard.value.style.transition = "none"
}

function onPointerMove(e: PointerEvent) {
  if (!swiping.value || animating.value || !imageCard.value) return
  const dx = e.clientX - startX.value
  const dy = e.clientY - startY.value
  const absDx = Math.abs(dx)
  const absDy = Math.abs(dy)
  if (absDx < 5 && absDy < 5) return
  let dir = ""
  if (absDx > absDy && absDx > 30) dir = dx > 0 ? "right" : "left"
  else if (absDy > absDx && absDy > 30) dir = "up"
  swipeDir.value = dir
  const rot = dx * 0.05
  imageCard.value.style.transform = "translate(" + dx + "px, " + dy + "px) rotate(" + rot + "deg)"
  imageCard.value.style.opacity = String(Math.max(0.3, 1 - absDx / 400))
}

function onPointerUp(e: PointerEvent) {
  if (!swiping.value || animating.value || !imageCard.value) return
  swiping.value = false
  const dx = e.clientX - startX.value
  const dy = e.clientY - startY.value
  const absDx = Math.abs(dx)
  const absDy = Math.abs(dy)
  if (imageCard.value) {
    imageCard.value.style.transition = "all 0.3s ease"
    imageCard.value.style.transform = ""
    imageCard.value.style.opacity = "1"
  }
  if (absDx > 80 && absDx > absDy) vote(dx > 0 ? "keep" : "delete")
  else if (absDy > 80 && absDy > absDx) vote("skip")
  else if (absDx < 10 && absDy < 10 && currentImage.value) {
    // Tap detected - open detail viewer
    openSwipeDetail(currentImage.value, currentIndex.value)
  }
  swipeDir.value = ""
}

function skipCluster() {
  abortOrigFetch()
  skippedClusterIds.value.add(clusters.value[currentClusterIndex.value]?.id ?? 0)
  currentClusterIndex.value++
  if (currentClusterIndex.value >= clusters.value.length) {
    if (skippedClusterIds.value.size > 0) {
      const remaining = clusters.value.filter(c => skippedClusterIds.value.has(c.id))
      if (remaining.length > 0) {
        clusters.value = remaining
        skippedClusterIds.value = new Set()
        currentClusterIndex.value = 0
        scrollToCard()
        localFavorites.value = new Set()
        detailImage.value = null
        return
      }
    }
    clusters.value = []
    viewMode.value = 'swipe'
    advance()
    scrollToSwipe()
  } else {
    scrollToCard()
  }
  localFavorites.value = new Set()
  detailImage.value = null
}

function advanceToNextCluster() {
  abortOrigFetch()
  clusters.value.splice(currentClusterIndex.value, 1)
  if (clusters.value.length === 0) {
    viewMode.value = 'swipe'
    advance()
    scrollToSwipe()
  } else {
    if (currentClusterIndex.value >= clusters.value.length) {
      currentClusterIndex.value = 0
    }
    localFavorites.value = new Set()
    detailImage.value = null
    scrollToCard()
  }
}

function openClusterOverview() {
  abortOrigFetch()
  if (currentCluster.value) {
    localFavorites.value = new Set([currentCluster.value.favorite.fileId])
  }
  viewMode.value = 'overview'
  detailImage.value = null
  scrollToOverview()
}

function onCardPointerDown(e: PointerEvent) {
  cardStartX.value = e.clientX
  cardStartY.value = e.clientY
  cardSwiping.value = true
  if (mainCardRef.value) mainCardRef.value.style.transition = "none"
}

function onCardPointerMove(e: PointerEvent) {
  if (!cardSwiping.value || clusterAnimating.value || !mainCardRef.value) return
  const dx = e.clientX - cardStartX.value
  const dy = e.clientY - cardStartY.value
  const absDx = Math.abs(dx)
  const absDy = Math.abs(dy)
  if (absDx < 5 && absDy < 5) return
  if (absDx > absDy) {
    cardSwipeDir.value = dx > 0 ? "right" : "left"
  } else {
    cardSwipeDir.value = dy > 0 ? "down" : "up"
  }
  const rot = dx * 0.05
  mainCardRef.value.style.transform = "translate(" + dx + "px, " + dy + "px) rotate(" + rot + "deg)"
  mainCardRef.value.style.opacity = String(Math.max(0.3, 1 - Math.max(absDx, absDy) / 400))
}

function onCardPointerUp(e: PointerEvent) {
  if (!cardSwiping.value || clusterAnimating.value || !mainCardRef.value) return
  cardSwiping.value = false
  const dx = e.clientX - cardStartX.value
  const dy = e.clientY - cardStartY.value
  const absDx = Math.abs(dx)
  const absDy = Math.abs(dy)
  mainCardRef.value.style.transition = "all 0.3s ease"
  mainCardRef.value.style.transform = ""
  mainCardRef.value.style.opacity = "1"
  if (absDy > absDx && dy < -80) {
    skipCluster()
  } else if (absDx > 80) {
    if (dx > 0) voteClusterKeepFavorite()
    else voteClusterDeleteAll()
  }
  cardSwipeDir.value = ""
}

function onDetailImgLoaded() {
  const fileId = detailImage.value?.fileId
  if (!fileId) return

  // If 2048px already loaded before, show it immediately (browser HTTP cache)
  if (hiresLoaded.has(fileId)) {
    detailHiresUrl.value = hiresUrlFor(fileId)
    detailImgLoading.value = false
    return
  }

  // 1024px just loaded — preload 2048px in background
  detailImgLoading.value = true
  const url = hiresUrlFor(fileId)
  const img = new Image()
  img.onload = () => {
    hiresLoaded.add(fileId)
    if (detailImage.value?.fileId === fileId) {
      detailHiresUrl.value = url
      detailImgLoading.value = false
    }
  }
  img.onerror = () => {
    if (detailImage.value?.fileId === fileId) {
      detailImgLoading.value = false
    }
  }
  img.src = url
}

function openDetail(img: ClusterImage) {
  if (!currentCluster.value) return
  detailIndex.value = currentCluster.value.images.findIndex(i => i.fileId === img.fileId)
  if (hiresLoaded.has(img.fileId)) {
    detailHiresUrl.value = hiresUrlFor(img.fileId)
    detailImgLoading.value = false
  } else {
    detailHiresUrl.value = ""
    detailImgLoading.value = true
  }
  detailImage.value = { ...img, _origUrl: "" }
}

function closeDetail() {
  abortOrigFetch()
  detailImage.value = null
  detailHiresUrl.value = ""
  detailImgLoading.value = false
}
function onSwipeImgLoaded(e: Event) {
  const currentId = swipeDetailImage.value?.id
  if (!currentId) return

  // If 2048px already loaded before, show it immediately
  if (hiresLoaded.has(currentId)) {
    swipeHiresUrl.value = hiresUrlFor(currentId)
    return
  }

  // 1024px just loaded — preload 2048px in background
  const url = hiresUrlFor(currentId)
  const img = new Image()
  img.onload = () => {
    hiresLoaded.add(currentId)
    if (swipeDetailImage.value?.id === currentId) {
      swipeHiresUrl.value = url
    }
  }
  img.src = url
}

function openSwipeDetail(img, idx) {
  swipeDetailIndex.value = idx
  swipeHiresUrl.value = hiresLoaded.has(img.id) ? hiresUrlFor(img.id) : ""
  swipeDetailImage.value = { ...img, _origUrl: "" }
  document.body.style.overflow = "hidden"
}
function closeSwipeDetail() {
  abortOrigFetch()
  swipeDetailImage.value = null
  document.body.style.overflow = ""
}
function swipeDetailPrev() {
  if (swipeDetailIndex.value <= 0) return
  swipeDetailIndex.value--
  const p = images.value[swipeDetailIndex.value] || null
  if (!p) { swipeDetailImage.value = null; return }
  swipeHiresUrl.value = hiresLoaded.has(p.id) ? hiresUrlFor(p.id) : ""
  swipeDetailImage.value = { ...p, _origUrl: "" }
}
function swipeDetailNext() {
  if (swipeDetailIndex.value >= images.value.length - 1) return
  swipeDetailIndex.value++
  const p = images.value[swipeDetailIndex.value] || null
  if (!p) { swipeDetailImage.value = null; return }
  swipeHiresUrl.value = hiresLoaded.has(p.id) ? hiresUrlFor(p.id) : ""
  swipeDetailImage.value = { ...p, _origUrl: "" }
}

function detailPrev() {
  if (!currentCluster.value || detailIndex.value <= 0) return
  detailIndex.value--
  const p = currentCluster.value.images[detailIndex.value]
  if (hiresLoaded.has(p.fileId)) {
    detailHiresUrl.value = hiresUrlFor(p.fileId)
    detailImgLoading.value = false
  } else {
    detailHiresUrl.value = ""
    detailImgLoading.value = true
  }
  detailImage.value = { ...p, _origUrl: "" }
}

function detailNext() {
  if (!currentCluster.value || detailIndex.value >= currentCluster.value.images.length - 1) return
  detailIndex.value++
  const p = currentCluster.value.images[detailIndex.value]
  if (hiresLoaded.has(p.fileId)) {
    detailHiresUrl.value = hiresUrlFor(p.fileId)
    detailImgLoading.value = false
  } else {
    detailHiresUrl.value = ""
    detailImgLoading.value = true
  }
  detailImage.value = { ...p, _origUrl: "" }
}

function onCleanupDetailKey(e: KeyboardEvent) {
  if (detailImage.value !== null) {
    if (e.key === "Escape") closeDetail()
    if (e.key === "ArrowLeft") detailPrev()
    if (e.key === "ArrowRight") detailNext()
    if (e.key === " " || e.key === "Spacebar") { e.preventDefault(); toggleDetailFavorite(); }
  } else if (swipeDetailImage.value !== null) {
    if (e.key === "Escape") closeSwipeDetail()
    if (e.key === "ArrowLeft") swipeDetailPrev()
    if (e.key === "ArrowRight") swipeDetailNext()
  }
}

function toggleDetailFavorite() {
  if (!detailImage.value) return
  const newSet = new Set(localFavorites.value)
  if (newSet.has(detailImage.value.fileId)) {
    newSet.delete(detailImage.value.fileId)
  } else {
    newSet.add(detailImage.value.fileId)
  }
  localFavorites.value = newSet
}

async function voteClusterKeepFavorite() {
  if (clusterAnimating.value || !currentCluster.value) return
  clusterAnimating.value = true
  const cluster = currentCluster.value
  const fav = cluster.favorite
  const others = cluster.images.filter((i: ClusterImage) => i.fileId !== fav.fileId)
  clusterVoteProgress.value = "Behalte Favorit, markiere " + others.length + " Bilder..."
  if (fav) {
    votes.value[fav.fileId] = 1
    fetch(generateUrl("api/v1/vote"), {
      method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "requesttoken": getCSRFToken() },
      body: JSON.stringify({ fileId: fav.fileId, vote: 1 }),
    }).catch(() => {})
  }
  for (let i = 0; i < others.length; i++) {
    const img = others[i]
    votes.value[img.fileId] = 0
    fetch(generateUrl("api/v1/vote"), {
      method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "requesttoken": getCSRFToken() },
      body: JSON.stringify({ fileId: img.fileId, vote: 0 }),
    }).catch(() => {})
    clusterVoteProgress.value = "Bearbeite " + (i + 1) + " / " + others.length + "..."
    if (i % 3 === 2) await new Promise((r) => setTimeout(r, 50))
  }
  saveToStorage()
  clusterVoteProgress.value = "Fertig! " + (1 + others.length) + " Bilder bewertet."
  setTimeout(() => {
    clusterAnimating.value = false
    clusterVoteProgress.value = ""
    advanceToNextCluster()
  }, 500)
}

async function voteClusterDeleteAll() {
  if (clusterAnimating.value || !currentCluster.value) return
  clusterAnimating.value = true
  const cluster = currentCluster.value
  clusterVoteProgress.value = "Markiere alle " + cluster.images.length + " Bilder..."
  for (let i = 0; i < cluster.images.length; i++) {
    const img = cluster.images[i]
    votes.value[img.fileId] = 0
    fetch(generateUrl("api/v1/vote"), {
      method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "requesttoken": getCSRFToken() },
      body: JSON.stringify({ fileId: img.fileId, vote: 0 }),
    }).catch(() => {})
    clusterVoteProgress.value = "Ösche " + (i + 1) + " / " + cluster.images.length + "..."
    if (i % 3 === 2) await new Promise((r) => setTimeout(r, 50))
  }
  saveToStorage()
  clusterVoteProgress.value = "Fertig! " + cluster.images.length + " Bilder tum Löschen markiert."
  setTimeout(() => {
    clusterAnimating.value = false
    clusterVoteProgress.value = ""
    advanceToNextCluster()
  }, 500)
}

async function overviewApplyFavorites() {
  if (clusterAnimating.value || !currentCluster.value || localFavorites.value.size === 0) return
  clusterAnimating.value = true
  const cluster = currentCluster.value
  clusterVoteProgress.value = "Übernimmte " + localFavorites.value.size + " Favoriten..."
  const favIds = Array.from(localFavorites.value)
  for (let i = 0; i < favIds.length; i++) {
    const fid = favIds[i]
    votes.value[fid] = 1
    fetch(generateUrl("api/v1/vote"), {
      method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "requesttoken": getCSRFToken() },
      body: JSON.stringify({ fileId: fid, vote: 1 }),
    }).catch(() => {})
    clusterVoteProgress.value = "Favorit " + (i + 1) + " / " + favIds.length + "..."
    if (i % 5 === 4) await new Promise((r) => setTimeout(r, 50))
  }
  const others = cluster.images.filter((i: ClusterImage) => !localFavorites.value.has(i.fileId))
  for (let i = 0; i < others.length; i++) {
    const img = others[i]
    votes.value[img.fileId] = 0
    fetch(generateUrl("api/v1/vote"), {
      method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "requesttoken": getCSRFToken() },
      body: JSON.stringify({ fileId: img.fileId, vote: 0 }),
    }).catch(() => {})
    clusterVoteProgress.value = "Markiere " + (i + 1) + " / " + others.length + " tum Löschen..."
    if (i % 3 === 2) await new Promise((r) => setTimeout(r, 50))
  }
  saveToStorage()
  clusterVoteProgress.value = "Fertig! " + cluster.images.length + " Bilder bewertet."
  setTimeout(() => {
    clusterAnimating.value = false
    clusterVoteProgress.value = ""
    advanceToNextCluster()
  }, 500)
}

async function overviewDeleteAll() {
  await voteClusterDeleteAll()
}

async function voteKeepAll() {
  if (clusterAnimating.value || !currentCluster.value) return
  clusterAnimating.value = true
  const cluster = currentCluster.value
  clusterVoteProgress.value = "Behalte alle " + cluster.images.length + " Bilder..."
  for (let i = 0; i < cluster.images.length; i++) {
    const img = cluster.images[i]
    votes.value[img.fileId] = 1
    fetch(generateUrl("api/v1/vote"), {
      method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "requesttoken": getCSRFToken() },
      body: JSON.stringify({ fileId: img.fileId, vote: 1 }),
    }).catch(() => {})
    clusterVoteProgress.value = "Behalte " + (i + 1) + " / " + cluster.images.length + "..."
    if (i % 3 === 2) await new Promise((r) => setTimeout(r, 50))
  }
  saveToStorage()
  clusterVoteProgress.value = "Fertig! " + cluster.images.length + " Bilder behalten."
  setTimeout(() => {
    clusterAnimating.value = false
    clusterVoteProgress.value = ""
    advanceToNextCluster()
  }, 500)
}

const navHandler = () => {
  if (images.value.length > 0 || clusters.value.length > 0) {
    scrollToCurrent()
  }
}

onMounted(() => {
    try { const d = localStorage.getItem(SK); if (d) votes.value = JSON.parse(d) } catch (e) {}
  window.addEventListener("cleanup-nav-refresh", navHandler)
  window.addEventListener("keydown", onCleanupDetailKey)
  if (folderStore.selectedFolder) {
        loadAll()
  }
})

onUnmounted(() => {
  window.removeEventListener("cleanup-nav-refresh", navHandler)
  window.removeEventListener("keydown", onCleanupDetailKey)
  stopScanPolling()
  document.body.style.overflow = ""
  abortOrigFetch()
  clearHiresCache()
})
</script>
<style scoped>
.cleanup-view { position: relative; z-index: 1; max-width: 600px; margin: 0 auto; }

.loading-screen, .error-screen, .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px; color: #666; gap: 12px; text-align: center; }
.empty-icon { font-size: 48px; }
.spinner { width: 32px; height: 32px; border: 3px solid #e0e0e0; border-top-color: #0082c9; border-radius: 50%; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.error-screen { color: #c62828; }
.error-screen button, .reset-btn { padding: 8px 20px; background: #0082c9; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-size: 0.9em; }
.final-stats { display: flex; gap: 12px; flex-wrap: wrap; justify-content: center; }
.stat-card { padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 0.9em; }
.stat-card.keep { background: #e8f5e9; color: #2e7d32; }
.stat-card.delete { background: #ffebee; color: #c62828; }
.stat-card.skip { background: #fff3e0; color: #e65100; }

.swipe-container { display: flex; flex-direction: column; gap: 12px; }
.swipe-header { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.swipe-header-left { display: flex; align-items: center; gap: 8px; flex: 1; }
.progress-info { font-size: 0.8em; color: #666; }
.progress-bar-wrap { height: 4px; background: #e0e0e0; border-radius: 2px; flex: 1; min-width: 60px; }
.progress-bar-fill { height: 100%; background: #0082c9; border-radius: 2px; transition: width 0.3s; }
.quick-stats { display: flex; gap: 6px; font-size: 0.75em; }
.qs { padding: 2px 6px; border-radius: 4px; }
.qs.keep { background: #e8f5e9; }
.qs.delete { background: #ffebee; }
.qs.skip { background: #fff3e0; }
.swipe-content { display: flex; justify-content: center; align-items: center; touch-action: pan-y; padding: 8px 0; }
.image-card { position: relative; width: 100%; max-width: 400px; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.15); background: #111; display: flex; align-items: center; justify-content: center; }
.image-card img { width: 100%; display: block; object-fit: contain; user-select: none; }
.image-card.swiping-keep { animation: swipeRight 0.3s ease forwards; }
.image-card.swiping-delete { animation: swipeLeft 0.3s ease forwards; }
.image-card.swiping-skip { animation: swipeUp 0.3s ease forwards; }
@keyframes swipeRight { to { transform: translateX(150%) rotate(10deg); opacity: 0; } }
@keyframes swipeLeft { to { transform: translateX(-150%) rotate(-10deg); opacity: 0; } }
@keyframes swipeUp { to { transform: translateY(-150%); opacity: 0; } }
.vote-indicator { position: absolute; top: 20px; padding: 6px 16px; border-radius: 8px; font-weight: 800; font-size: 1.1em; opacity: 0; transition: opacity 0.2s; pointer-events: none; }
.vote-indicator.keep { right: 20px; background: rgba(76,175,80,0.9); color: #fff; }
.vote-indicator.delete { left: 20px; background: rgba(244,67,54,0.9); color: #fff; }
.vote-indicator.skip { left: 50%; transform: translateX(-50%); top: auto; bottom: 60px; background: rgba(255,152,0,0.9); color: #fff; }
.vote-indicator.show { opacity: 1; }
.image-info { text-align: center; flex-shrink: 0; }
.image-info .name { font-weight: 600; font-size: 0.9em; color: #333; word-break: break-all; }
.image-info .meta { font-size: 0.8em; color: #888; }
.vote-buttons { display: flex; justify-content: center; gap: 10px; padding: 12px 0 20px; flex-shrink: 0; }
.vote-btn { flex: 1; max-width: 120px; padding: 12px; border: none; border-radius: 12px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
.vote-btn:hover:not(:disabled) { transform: translateY(-2px); }
.vote-btn:disabled { opacity: 0.5; cursor: default; }
.vote-btn.keep { background: #4caf50; color: #fff; }
.vote-btn.delete { background: #f44336; color: #fff; }
.vote-btn.skip { background: #ff9800; color: #fff; }

.cluster-card-mode { max-width: 600px; margin: 0 auto; padding: 0; }
.card-mode-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; background: #fff8e1; padding: 10px 14px; border-radius: 14px; border-left: 4px solid #ff9800; flex-shrink: 0; }
.card-mode-title { font-weight: 800; color: #fff; font-size: 1.1em; background: #ff9800; padding: 6px 16px; border-radius: 20px; display: inline-block; }
.card-mode-count { font-size: 0.85em; color: #e65100; font-weight: 700; background: #fff3e0; padding: 4px 12px; border-radius: 12px; margin-left: auto; }
.card-deck-container { display: flex; flex-direction: column; align-items: center; margin-bottom: 8px; justify-content: center; }
.card-deck { position: relative; width: 100%; max-width: 400px; min-height: 300px; display: flex; justify-content: center; align-items: flex-end; }
.deck-card { position: absolute; width: 85%; max-width: 340px; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.12); background: #fff; pointer-events: none; }
.deck-card img { width: 100%; display: block; max-height: 200px; object-fit: contain; }
.deck-card-1 { transform: rotate(-3deg) translateY(6px); }
.deck-card-2 { transform: rotate(0deg) translateY(12px); }
.deck-card-3 { transform: rotate(3deg) translateY(18px); }
.main-card { position: relative; width: 100%; max-width: 400px; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.2); background: #111; cursor: pointer; z-index: 20; display: flex; align-items: center; justify-content: center; }
.main-card img { width: 100%; display: block; object-fit: contain; user-select: none; }
.main-card-overlay { position: absolute; bottom: 0; left: 0; right: 0; padding: 40px 12px 12px; background: linear-gradient(transparent, rgba(0,0,0,0.7)); display: flex; justify-content: space-between; align-items: flex-end; }
.main-card-label { color: #fff; font-weight: 700; font-size: 0.9em; background: rgba(0,0,0,0.5); padding: 3px 10px; border-radius: 6px; }
.main-card-faces { color: #ffd54f; font-weight: 600; font-size: 0.8em; background: rgba(0,0,0,0.5); padding: 3px 10px; border-radius: 6px; }
.card-mode-actions { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; padding: 8px 0 20px; flex-shrink: 0; }
.action-btn { padding: 10px 14px; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 0.85em; transition: all 0.2s; }
.action-btn:hover:not(:disabled) { transform: translateY(-1px); }
.action-btn:disabled { opacity: 0.5; cursor: default; }
.action-skip { background: #f0f0f0; color: #666; }
.action-overview { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; }
.action-delete { background: #f44336; color: #fff; }
.action-keep { background: #4caf50; color: #fff; }
.cluster-progress { text-align: center; padding: 8px; color: #666; font-size: 0.85em; }

.cluster-overview { max-width: 600px; margin: 0 auto; scroll-margin-block: 50vh; }
.overview-header { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
.overview-title { font-weight: 700; color: #333; font-size: 1em; }
.overview-count { font-size: 0.85em; color: #888; margin-left: auto; }
.overview-hint { color: #888; font-size: 0.8em; margin: 0 0 12px; text-align: center; }
.overview-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; margin-bottom: 16px; }
.overview-card { position: relative; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); background: #fff; cursor: pointer; transition: all 0.2s; }
.overview-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.overview-card.is-favorite { box-shadow: 0 0 0 3px #ffd54f, 0 4px 12px rgba(255,213,79,0.4); }
.overview-card img { width: 100%; aspect-ratio: auto; object-fit: contain; display: block; max-height: 180px; }
.overview-card-badge { position: absolute; top: 4px; left: 4px; }
.face-badge { background: rgba(0,0,0,0.7); color: #ffd54f; font-size: 0.65em; padding: 2px 6px; border-radius: 4px; }
.overview-star { position: absolute; top: 4px; right: 4px; font-size: 1.5em; color: rgba(255,255,255,0.5); text-shadow: 0 1px 3px rgba(0,0,0,0.5); transition: all 0.2s; }
.overview-star.active { color: #ffd54f; text-shadow: 0 0 8px rgba(255,213,79,0.6); }
.overview-name { padding: 4px 8px; font-size: 0.7em; color: #555; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.overview-actions { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; padding: 8px 0 20px; flex-shrink: 0; }
.overview-apply { max-width: 200px; }

.detail-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.92); z-index: 10000; display: flex; flex-direction: column; }
.detail-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; color: #fff; }
.detail-back-btn { background: rgba(255,255,255,0.15); color: #fff; border: none; border-radius: 8px; padding: 6px 14px; font-size: 0.9em; cursor: pointer; }
.detail-name { font-size: 0.9em; font-weight: 600; text-align: center; flex: 1; margin: 0 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.detail-fav-btn { background: none; border: none; font-size: 2em; color: rgba(255,255,255,0.4); cursor: pointer; padding: 4px 8px; transition: all 0.2s; }
.detail-fav-btn.active { color: #ffd54f; text-shadow: 0 0 12px rgba(255,213,79,0.6); }

.detail-loading-spinner{width:20px;height:20px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite;flex-shrink:0;margin-left:auto;pointer-events:none;margin-right:8px}
.detail-image-wrap { flex: 1; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; }
.detail-image { max-width: 100%; max-height: 100%; object-fit: contain; }
.detail-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.15); color: #fff; border: none; font-size: 2.5em; width: 50px; height: 80px; display: flex; align-items: center; justify-content: center; cursor: pointer; border-radius: 8px; transition: background 0.2s; }
.detail-nav:hover { background: rgba(255,255,255,0.3); }
.detail-prev { left: 10px; }
.detail-next { right: 10px; }
.detail-footer { display: flex; justify-content: center; align-items: center; gap: 16px; padding: 12px 16px; color: rgba(255,255,255,0.7); font-size: 0.85em; }
.detail-faces { color: #ffd54f; }
.cleanup-view .swipe-detail-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.92); z-index: 10000; display: flex; flex-direction: column; }
.cleanup-view .detail-actions { display: flex; gap: 8px; }
.cleanup-view .detail-action-btn { padding: 6px 14px; border: none; border-radius: 6px; cursor: pointer; font-size: 0.85em; font-weight: 600; }
.cleanup-view .detail-action-btn.keep { background: #4caf50; color: #fff; }
.cleanup-view .detail-action-btn.delete { background: #f44336; color: #fff; }
.cleanup-view .detail-action-btn.skip { background: #ff9800; color: #fff; }
.cleanup-view .detail-meta { color: rgba(255,255,255,0.5); }

.cleanup-view .detail-actions { display: flex; gap: 8px; }
.cleanup-view .detail-action-btn { padding: 6px 14px; border: none; border-radius: 6px; cursor: pointer; font-size: 0.85em; font-weight: 600; }
.cleanup-view .detail-action-btn.keep { background: #4caf50; color: #fff; }
.cleanup-view .detail-action-btn.delete { background: #f44336; color: #fff; }
.cleanup-view .detail-action-btn.skip { background: #ff9800; color: #fff; }
.cleanup-view .detail-meta { color: rgba(255,255,255,0.5); }

@media (max-width: 500px) {
  .vote-btn { padding: 10px; font-size: 0.85em; }
  .action-btn { font-size: 0.8em; padding: 8px 12px; }
  .overview-grid { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 8px; }
  .detail-nav { font-size: 2em; width: 40px; height: 60px; }
}

.scan-banner { max-width: 600px; margin: 20px auto; padding: 20px 24px; background: #e3f2fd; border-radius: 14px; border-left: 4px solid #0082c9; text-align: center; }
.scan-banner-icon { font-size: 32px; margin-bottom: 8px; }
.scan-banner-text { font-size: 0.95em; color: #333; margin-bottom: 12px; }
.scan-banner-text strong { color: #0082c9; }
.scan-banner-bar { height: 6px; background: #e0e0e0; border-radius: 3px; overflow: hidden; margin-bottom: 10px; }
.scan-banner-fill { height: 100%; background: linear-gradient(90deg, #0082c9, #4fc3f7); border-radius: 3px; transition: width 1s ease; }
.scan-banner-hint { font-size: 0.8em; color: #666; margin: 0 0 12px; }
.scan-banner-skip { padding: 8px 20px; background: #0082c9; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-size: 0.9em; }
</style>
