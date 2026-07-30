import { defineStore } from "pinia"
import { ref } from "vue"
import { generateUrl } from "@/utils/helpers"

export interface ConsensusFile {
  fileId: number
  deleteCount: number
  fileInfo: {
    name: string
    size: number
    mimeType: string
    path: string
  } | null
}

export interface ConsensusTab {
  label: string
  consensus: number
  totalUsers: number
  isUnanimous: boolean
  files: ConsensusFile[]
  count: number
}

export interface UserStat {
  userId: string
  totalVotes: number
  deleteVotes: number
  keepVotes: number
}

export const useConsensusStore = defineStore("consensus", () => {
  const tabs = ref<Record<string, ConsensusTab>>({})
  const maxConsensus = ref(0)
  const totalUsers = ref(0)
  const myDeleteCount = ref(0)
  const users = ref<UserStat[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  let pollTimer: ReturnType<typeof setInterval> | null = null

  function previewUrl(fileId: number): string {
    const query = "fileId=" + fileId + "&x=256&y=256&a=1";
    return (window.OC.getRootPath() || "") + "/index.php/core/preview?" + query;
  }

  function formatSize(bytes: number): string {
    if (!bytes || bytes < 0) return "0 B"
    if (bytes < 1024) return bytes + " B"
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + " KB"
    return (bytes / (1024 * 1024)).toFixed(1) + " MB"
  }

  async function loadConsensus(folder: string) {
    loading.value = true
    error.value = null
    try {
      const url = generateUrl("api/v1/dashboard/consensus") + "?folder=" + encodeURIComponent(folder)
      const resp = await fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
      if (!resp.ok) throw new Error("HTTP " + resp.status)
      const data = await resp.json()
      if (data.error) { error.value = data.error; return }
      tabs.value = data.tabs || {}
      maxConsensus.value = data.maxConsensus || 0
      totalUsers.value = data.totalUsers || 0
      myDeleteCount.value = data.myDeleteCount || 0
    } catch (e: any) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  async function loadUsers() {
    try {
      const url = generateUrl("api/v1/dashboard/folder-users")
      const resp = await fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
      if (!resp.ok) return
      const data = await resp.json()
      if (data.error) return
      users.value = data.users || []
      totalUsers.value = data.totalUsers || 0
    } catch (e) {}
  }

  function startPolling(folder: string) {
    stopPolling()
    pollTimer = setInterval(() => {
      loadConsensus(folder)
      loadUsers()
    }, 30000)
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  return {
    tabs, maxConsensus, totalUsers, myDeleteCount, users, loading, error,
    previewUrl, formatSize,
    loadConsensus, loadUsers, startPolling, stopPolling,
  }
})
