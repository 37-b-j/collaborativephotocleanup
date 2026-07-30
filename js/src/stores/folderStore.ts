import { defineStore } from "pinia"
import { ref } from "vue"

const STORAGE_KEY = "photocleanup_global_folder"

export const useFolderStore = defineStore("folder", () => {
  const selectedFolder = ref<string | null>(null)
  const selectedFolderName = ref("")
  const includeSubfolders = ref(true)
  const refreshCounter = ref(0)
  const selectionId = ref(0)

  function triggerRefresh() { refreshCounter.value++ }

  function loadFromStorage() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY)
      if (raw) {
        const data = JSON.parse(raw)
        selectedFolder.value = data.folder || null
        selectedFolderName.value = data.name || ""
        includeSubfolders.value = data.subfolders !== false
      }
    } catch (e) {
      console.warn("[FolderStore] Storage load failed:", e)
    }
  }

  function saveToStorage() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        folder: selectedFolder.value,
        name: selectedFolderName.value,
        subfolders: includeSubfolders.value,
      }))
    } catch (e) {
      console.warn("[FolderStore] Storage save failed:", e)
    }
  }

  function selectFolder(path: string, name: string) {
    selectedFolder.value = path
    selectedFolderName.value = name
    selectionId.value++
    triggerRefresh()
    saveToStorage()
  }

  function clearFolder() {
    selectedFolder.value = null
    selectedFolderName.value = ""
    saveToStorage()
  }

  function openFolderPicker() {
    const win = window as any
    const OC = win.OC
    if (!OC || !OC.dialogs || !OC.dialogs.filepicker) {
      console.error("OC.dialogs.filepicker not available")
      return
    }
    OC.dialogs.filepicker(
      "Wähle einen Ordner mit Fotos",
      (path: string) => {
        if (path) {
          const parts = path.split("/").filter(Boolean)
          const name = parts.length > 0 ? parts[parts.length - 1] : "Ausgewählter Ordner"
          selectFolder(path, name)
        }
      },
      false,
      ["httpd/unix-directory"],
      undefined,
      1,
      "/",
      { allowDirectoryChooser: true }
    )
  }

  loadFromStorage()

  return {
    selectedFolder,
    selectedFolderName,
    includeSubfolders,
    refreshCounter,
    selectionId,
    triggerRefresh,
    selectFolder,
    clearFolder,
    openFolderPicker,
  }
})
