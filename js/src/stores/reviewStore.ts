import { defineStore } from "pinia"
import { generateUrl } from "@/utils/helpers"
import { ref, computed } from "vue"

const STORAGE_KEY = "photocleanup_cluster_reviews"

export interface ClusterImage {
  fileId: number
  name: string
  size: number
  mimeType: string
  path: string
  faces: number
}

export interface Cluster {
  id: number
  images: ClusterImage[]
  count: number
  favorite: ClusterImage
  totalFaces: number
}

export interface ReviewDecision {
  clusterId: number
  action: 'keep-favorite' | 'delete-all'
  favoriteFileId: number | null
  timestamp: number
}

export const useReviewStore = defineStore("review", () => {
  const clusters = ref<Cluster[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const decisions = ref<Map<number, ReviewDecision>>(new Map())
  const showModal = ref(false)
  const currentCluster = ref<Cluster | null>(null)
  const tempFavorite = ref<ClusterImage | null>(null)
  const reviewedClusters = ref<Set<number>>(new Set())
  const scanStatus = ref<{ totalImages: number; hashedImages: number; pendingImages: number; ready: boolean } | null>(null)
  const pollingScan = ref(false)

  function loadFromStorage() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY)
      if (raw) {
        const data = JSON.parse(raw)
        if (data.reviewed) {
          reviewedClusters.value = new Set(data.reviewed)
        }
        if (data.decisions) {
          const map = new Map<number, ReviewDecision>()
          for (const [k, v] of Object.entries(data.decisions)) {
            map.set(Number(k), v as ReviewDecision)
          }
          decisions.value = map
        }
      }
    } catch (e) {
      console.warn("[ReviewStore] Storage load failed:", e)
    }
  }

  function saveToStorage() {
    try {
      const obj: Record<number, ReviewDecision> = {}
      decisions.value.forEach((v, k) => { obj[k] = v })
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        reviewed: Array.from(reviewedClusters.value),
        decisions: obj,
      }))
    } catch (e) {
      console.warn("[ReviewStore] Storage save failed:", e)
    }
  }

  const isClusterReviewed = (clusterId: number): boolean => {
    return reviewedClusters.value.has(clusterId)
  }

  const getDecision = (clusterId: number): ReviewDecision | null => {
    return decisions.value.get(clusterId) ?? null
  }

  async function loadClusters(folder: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const threshold = localStorage.getItem('photocleanup_cluster_threshold')
      const paramsObj: Record<string, string> = { folder: encodeURIComponent(folder) }
      if (threshold) paramsObj.threshold = threshold
      const params = new URLSearchParams(paramsObj)
      const resp = await fetch(generateUrl("api/v1/similar-clusters") + "?" + params.toString())
      if (!resp.ok) throw new Error("HTTP " + resp.status)
      const data = await resp.json()
      if (data.error) { error.value = data.error; return }
      clusters.value = (data.clusters || []).filter((c: Cluster) => c.count >= 2)
      scanStatus.value = {
        totalImages: data.totalImages ?? 0,
        hashedImages: data.hashedImages ?? 0,
        pendingImages: (data.totalImages ?? 0) - (data.hashedImages ?? 0),
        ready: data.needsScan === false,
      }
      if (data.needsScan && !pollingScan.value) {
        startScanPolling(folder)
      }
    } catch (e: any) {
      error.value = e.message || "Failed to load clusters"
      console.warn("[ReviewStore] Load clusters failed:", e)
    } finally {
      loading.value = false
    }
  }

  function openReview(cluster: Cluster) {
    currentCluster.value = cluster
    tempFavorite.value = { ...cluster.favorite }
    showModal.value = true
  }

  function closeReview() {
    showModal.value = false
    currentCluster.value = null
    tempFavorite.value = null
  }

  function selectAsFavorite(image: ClusterImage) {
    tempFavorite.value = { ...image }
  }

  function confirmKeepFavorite() {
    if (!currentCluster.value) return
    const c = currentCluster.value
    const fid = tempFavorite.value?.fileId ?? c.favorite.fileId
    decisions.value.set(c.id, {
      clusterId: c.id,
      action: 'keep-favorite',
      favoriteFileId: fid,
      timestamp: Date.now(),
    })
    reviewedClusters.value.add(c.id)
    saveToStorage()
    closeReview()
  }

  function confirmDeleteAll() {
    if (!currentCluster.value) return
    const c = currentCluster.value
    decisions.value.set(c.id, {
      clusterId: c.id,
      action: 'delete-all',
      favoriteFileId: null,
      timestamp: Date.now(),
    })
    reviewedClusters.value.add(c.id)
    saveToStorage()
    closeReview()
  }

  async function checkScanStatus(folder: string) {
    try {
      const params = new URLSearchParams({ folder: encodeURIComponent(folder) })
      const resp = await fetch(generateUrl("api/v1/scan-status") + "?" + params.toString())
      if (!resp.ok) return
      const data = await resp.json()
      scanStatus.value = data
      if (data.ready) {
        pollingScan.value = false
        await loadClusters(folder)
      }
    } catch (e) {
      console.warn("[ReviewStore] scan status check failed:", e)
    }
  }

  function startScanPolling(folder: string) {
    if (pollingScan.value) return
    pollingScan.value = true
    const interval = setInterval(async () => {
      if (!pollingScan.value) {
        clearInterval(interval)
        return
      }
      await checkScanStatus(folder)
    }, 5000)
  }

  function stopScanPolling() {
    pollingScan.value = false
  }

  function resetAll() {
    clusters.value = []
    decisions.value = new Map()
    reviewedClusters.value = new Set()
    scanStatus.value = null
    pollingScan.value = false
    saveToStorage()
  }

  loadFromStorage()

  return {
    clusters, loading, error, decisions, showModal, currentCluster, tempFavorite,
    reviewedClusters, isClusterReviewed, getDecision, scanStatus, pollingScan,
    loadClusters, openReview, closeReview, selectAsFavorite,
    confirmKeepFavorite, confirmDeleteAll, resetAll, stopScanPolling,
    saveToStorage,
  }
})
