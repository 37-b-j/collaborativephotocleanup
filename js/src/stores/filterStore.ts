import { defineStore } from "pinia"
import { ref, computed, watch } from "vue"
import { generateUrl, getCSRFToken } from "@/utils/helpers"

export interface Folder {
  id: number
  name: string
  path: string
}

const STORAGE_KEY = "photocleanup_filter_state"
const CACHE_TTL = 5 * 60 * 1000

function loadFromStorage() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) {
      const parsed = JSON.parse(raw)
      return {
        selectedFolder: parsed.selectedFolder || "all",
        includeSubfolders: parsed.includeSubfolders !== false,
      }
    }
  } catch (e) {
    console.warn("Failed to load filter state from localStorage", e)
  }
  return {}
}

function saveToStorage(state: any) {
  try {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        selectedFolder: state.selectedFolder,
        includeSubfolders: state.includeSubfolders,
      })
    )
  } catch (e) {
    console.warn("Failed to save filter state to localStorage", e)
  }
}

export const useFilterStore = defineStore("filter", () => {
  const persisted = loadFromStorage()

  const selectedFolder = ref<string>(persisted.selectedFolder || "all")
  const includeSubfolders = ref<boolean>(persisted.includeSubfolders !== false)
  const availableFolders = ref<Folder[]>([])
  const lastUpdated = ref<number | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  const currentFolder = computed<Folder | null>(() => {
    if (selectedFolder.value === "all") return null
    return availableFolders.value.find((f) => f.path === selectedFolder.value) || null
  })

  const hasActiveFilter = computed(() => selectedFolder.value !== "all")
  const depth = computed(() => (includeSubfolders.value ? -1 : 0))

  watch(
    [selectedFolder, includeSubfolders],
    () => {
      saveToStorage({
        selectedFolder: selectedFolder.value,
        includeSubfolders: includeSubfolders.value,
      })
    },
    { deep: true }
  )

  async function loadFolders(): Promise<void> {
    if (loading.value) return
    if (lastUpdated.value && Date.now() - lastUpdated.value < CACHE_TTL && availableFolders.value.length > 0) {
      return
    }

    loading.value = true
    error.value = null

    try {
      const url = generateUrl("api/v1/folders")
      const response = await fetch(url, {
        headers: {
          requesttoken: getCSRFToken(),
          "Content-Type": "application/json",
        },
      })

      if (!response.ok) {
        throw new Error("HTTP " + response.status + ": " + response.statusText)
      }

      const data = await response.json()
      const folders = Array.isArray(data) ? data : (data.folders || data || [])
      availableFolders.value = folders.map((f: any) => ({
        id: f.id || 0,
        name: f.name || "Unnamed",
        path: f.path || "/" + (f.name || "Unnamed"),
      }))

      lastUpdated.value = Date.now()
    } catch (e: any) {
      error.value = e.message || "Failed to load folders"
      console.error("FilterStore: loadFolders failed", e)
    } finally {
      loading.value = false
    }
  }

  function setFolders(folders: Folder[]): void {
    availableFolders.value = folders
    lastUpdated.value = Date.now()
  }

  function applyFilter(): void {
    window.dispatchEvent(
      new CustomEvent("photocleanup:filter-change", {
        detail: {
          folder: selectedFolder.value,
          includeSubfolders: includeSubfolders.value,
          folderObject: currentFolder.value,
        },
      })
    )
  }

  function resetFilter(): void {
    selectedFolder.value = "all"
    includeSubfolders.value = true
    applyFilter()
  }

  return {
    selectedFolder,
    includeSubfolders,
    availableFolders,
    lastUpdated,
    loading,
    error,
    currentFolder,
    hasActiveFilter,
    depth,
    loadFolders,
    setFolders,
    applyFilter,
    resetFilter,
  }
})
