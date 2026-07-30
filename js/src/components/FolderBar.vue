<template>
  <div class="folder-bar" v-if="folderStore.selectedFolder">
    <div class="folder-bar-left">
      <span class="folder-bar-label">Selected Location:</span>
      <span class="folder-bar-path">{{ folderStore.selectedFolder }}</span>
    </div>
    <button class="folder-bar-btn" @click="folderStore.openFolderPicker()" title="Ordner ändern">
      📁
    </button>
  </div>
  <div class="folder-bar folder-bar-empty" v-else>
    <div class="folder-bar-left">
      <span class="folder-bar-label">Kein Ordner ausgewählt</span>
    </div>
    <button class="folder-bar-btn" @click="folderStore.openFolderPicker()" title="Ordner auswählen">
      📁 Ordner wählen
    </button>
  </div>
</template>

<script setup lang="ts">
import { useFolderStore } from "@/stores/folderStore"
const folderStore = useFolderStore()
</script>

<style scoped>
.folder-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 2px 8px;
  background: #f0f8ff;
  border: 1px solid #b3d9f2;
  border-radius: 6px;
  margin-bottom: 8px;
  gap: 6px;
}
.folder-bar-empty {
  background: #fff8e1;
  border-color: #ffe082;
}
.folder-bar-left {
  display: flex;
  align-items: center;
  gap: 4px;
  flex: 1;
  min-width: 0;
}
.folder-bar-label {
  font-size: 0.8em;
  color: #666;
  white-space: nowrap;
  font-weight: 600;
}
.folder-bar-path {
  font-size: 0.85em;
  color: #0082c9;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.folder-bar-btn {
  padding: 2px 10px;
  background: #0082c9;
  color: #fff;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.85em;
  font-weight: 600;
  white-space: nowrap;
  flex-shrink: 0;
  transition: background 0.2s;
}
.folder-bar-btn:hover {
  background: #006daa;
}
</style>
