import { defineStore } from "pinia"
import { generateUrl } from "@/utils/helpers"
import { ref, computed } from "vue"

const STORAGE_KEY = "photocleanup_votes"
const SYNC_KEY = "photocleanup_sync_queue"

export const useVoteStore = defineStore("vote", () => {
  const localVotes = ref<Map<number, number>>(new Map())
  const syncQueue = ref<Array<{ fileId: number; vote: number; timestamp: number }>>([])
  const isOnline = ref(navigator.onLine)
  const isSyncing = ref(false)

  function loadFromStorage() {
    try {
      const stored = localStorage.getItem(STORAGE_KEY)
      if (stored) {
        const parsed = JSON.parse(stored) as Record<string, number>
        const map = new Map<number, number>()
        for (const [k, v] of Object.entries(parsed)) {
          map.set(Number(k), v)
        }
        localVotes.value = map
      }
      const sync = localStorage.getItem(SYNC_KEY)
      if (sync) {
        syncQueue.value = JSON.parse(sync)
      }
    } catch (e) {
      console.warn("[VoteStore] Storage load failed:", e)
    }
  }

  function saveToStorage() {
    try {
      const obj: Record<number, number> = {}
      localVotes.value.forEach((vote, fileId) => {
        obj[fileId] = vote
      })
      localStorage.setItem(STORAGE_KEY, JSON.stringify(obj))
      localStorage.setItem(SYNC_KEY, JSON.stringify(syncQueue.value))
    } catch (e) {
      console.warn("[VoteStore] Storage save failed:", e)
    }
  }

  const pendingSyncCount = computed(() => syncQueue.value.length)
  const totalVotes = computed(() => localVotes.value.size)

  function getVote(fileId: number): number | null {
    return localVotes.value.get(fileId) ?? null
  }

  function getVoteStats(): { keep: number; delete: number } {
    let keep = 0
    let del = 0
    localVotes.value.forEach((vote) => {
      if (vote === 1) keep++
      else if (vote === 0) del++
    })
    return { keep, delete: del }
  }

  async function submitVote(fileId: number, vote: number): Promise<boolean> {
    localVotes.value.set(fileId, vote)
    saveToStorage()

    if (isOnline.value) {
      try {
        const token = (window as any).OC?.requestToken || ""
        const response = await fetch(generateUrl("api/v1/vote"), {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "requesttoken": token,
          },
          body: JSON.stringify({ fileId, vote }),
        })
        if (!response.ok) throw new Error("HTTP " + response.status)
        const data = await response.json()
        if (data.success) return true
        throw new Error(data.error || "Unknown error")
      } catch (err) {
        console.warn("[VoteStore] Direct vote failed, queuing:", err)
        syncQueue.value.push({ fileId, vote, timestamp: Date.now() })
        saveToStorage()
        return false
      }
    } else {
      syncQueue.value.push({ fileId, vote, timestamp: Date.now() })
      saveToStorage()
      return false
    }
  }

  async function syncOfflineVotes(): Promise<number> {
    if (isSyncing.value || !isOnline.value || syncQueue.value.length === 0) return 0

    isSyncing.value = true
    const batch = [...syncQueue.value]
    let syncedCount = 0

    try {
      const token = (window as any).OC?.requestToken || ""
      const response = await fetch(generateUrl("api/v1/vote/batch-sync"), {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "requesttoken": token,
        },
        body: JSON.stringify({ votes: batch.map(v => ({ fileId: v.fileId, vote: v.vote })) }),
      })

      if (response.ok) {
        const data = await response.json()
        if (data.success) {
          const syncedIds = new Set(batch.map(v => v.fileId + "-" + v.vote))
          syncQueue.value = syncQueue.value.filter(v => !syncedIds.has(v.fileId + "-" + v.vote))
          saveToStorage()
          syncedCount = data.synced || batch.length
        }
      }
    } catch (err) {
      console.warn("[VoteStore] Sync failed:", err)
    } finally {
      isSyncing.value = false
    }
    return syncedCount
  }

  async function fetchMyVotes(): Promise<void> {
    if (!isOnline.value) return
    try {
      const token = (window as any).OC?.requestToken || ""
      const response = await fetch(generateUrl("api/v1/my-votes"), {
        headers: { "requesttoken": token },
      })
      if (response.ok) {
        const data = await response.json()
        if (data.votes) {
          data.votes.forEach((v: { fileId: number; vote: number }) => {
            localVotes.value.set(v.fileId, v.vote)
          })
          saveToStorage()
        }
      }
    } catch (err) {
      console.warn("[VoteStore] Failed to fetch my votes:", err)
    }
  }

  async function fetchVoteStats(fileId: number): Promise<object | null> {
    try {
      const token = (window as any).OC?.requestToken || ""
      const response = await fetch(generateUrl("api/v1/vote-stats/" + fileId), {
        headers: { "requesttoken": token },
      })
      if (response.ok) return await response.json()
      return null
    } catch (err) {
      console.warn("[VoteStore] Failed to fetch vote stats:", err)
      return null
    }
  }

  window.addEventListener("online", () => {
    isOnline.value = true
    syncOfflineVotes()
  })
  window.addEventListener("offline", () => {
    isOnline.value = false
  })

  loadFromStorage()

  return {
    localVotes, syncQueue, isOnline, isSyncing,
    pendingSyncCount, totalVotes, getVote, getVoteStats,
    submitVote, syncOfflineVotes, fetchMyVotes, fetchVoteStats,
  }
})
