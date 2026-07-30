<template>
  <header class="filter-header">
    <div class="filter-container">
      <!-- Ordner-Dropdown -->
      <div class="filter-select-wrapper">
        <select
          v-model="localSelected"
          class="filter-select"
          :class="{ 'has-active': store.hasActiveFilter }"
          @change="onFilterChange"
        >
          <option :value="null">📂 Alle Bilder</option>
          <option
            v-for="folder in store.availableFolders"
            :key="folder.id"
            :value="folder.path"
          >
            {{ folder.name }}
          </option>
        </select>
        <span v-if="store.loading" class="filter-loading">⟳</span>
      </div>

      <!-- Unterordner-Checkbox -->
      <label
        v-if="store.hasActiveFilter"
        class="filter-checkbox-label"
      >
        <input
          type="checkbox"
          v-model="store.includeSubfolders"
          class="filter-checkbox"
        />
        <span class="filter-checkbox-text">Unterordner einbeziehen</span>
      </label>

      <!-- Aktionsbuttons -->
      <div class="filter-actions">
        <button
          v-if="store.hasActiveFilter"
          @click="resetFilter"
          class="filter-btn filter-btn-reset"
          title="Filter zurücksetzen"
        >
          ✕ Zurücksetzen
        </button>
      </div>

      <!-- Fehleranzeige -->
      <div v-if="store.error" class="filter-error">
        ⚠️ {{ store.error }}
      </div>
    </div>

    <!-- Breadcrumb -->
    <div v-if="store.hasActiveFilter && store.currentFolder" class="filter-breadcrumb">
      <span class="breadcrumb-item" @click="resetFilter">Alle Bilder</span>
      <span class="breadcrumb-separator">›</span>
      <span class="breadcrumb-item active">{{ store.currentFolder.name }}</span>
    </div>
  </header>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from "vue"
import { useFolderStore } from "@/stores/folderStore"

const store = useFolderStore()
const localSelected = ref<string | null>(store.selectedFolder)

// Sync localSelected <-> store.selectedFolder
watch(() => store.selectedFolder, (val) => {
  localSelected.value = val
})

function onFilterChange() {
  if (!localSelected.value) {
    store.clearFolder()
  } else {
    const folder = store.availableFolders.find(f => f.path === localSelected.value)
    store.selectFolder(localSelected.value, folder?.name || localSelected.value)
  }
  store.triggerRefresh()
}

function resetFilter() {
  store.clearFolder()
  localSelected.value = null
  store.triggerRefresh()
}

onMounted(async () => {
  await store.loadFolders()
})
</script>

<style scoped>
.filter-header {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 12px;
  padding: 16px 20px;
  margin-bottom: 16px;
  color: white;
  box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.filter-container {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
}

.filter-select-wrapper {
  position: relative;
  flex: 1;
  min-width: 200px;
}

.filter-select {
  width: 100%;
  padding: 10px 14px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.15);
  color: white;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  appearance: none;
  backdrop-filter: blur(10px);
  transition: all 0.2s ease;
}

.filter-select:hover {
  background: rgba(255, 255, 255, 0.25);
  border-color: rgba(255, 255, 255, 0.5);
}

.filter-select.has-active {
  border-color: #4ade80;
  background: rgba(255, 255, 255, 0.2);
}

.filter-select option {
  background: #1e1b4b;
  color: white;
  padding: 8px;
}

.filter-loading {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  animation: spin 1s linear infinite;
  font-size: 18px;
}

@keyframes spin {
  from { transform: translateY(-50%) rotate(0deg); }
  to { transform: translateY(-50%) rotate(360deg); }
}

.filter-checkbox-label {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  white-space: nowrap;
  padding: 6px 12px;
  border-radius: 6px;
  transition: background 0.2s;
}

.filter-checkbox-label:hover {
  background: rgba(255, 255, 255, 0.1);
}

.filter-checkbox {
  width: 18px;
  height: 18px;
  accent-color: #4ade80;
  cursor: pointer;
}

.filter-checkbox-text {
  font-size: 13px;
  font-weight: 400;
}

.filter-actions {
  display: flex;
  gap: 8px;
}

.filter-btn {
  padding: 8px 16px;
  border: none;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
}

.filter-btn-reset {
  background: rgba(255, 255, 255, 0.2);
  color: white;
  border: 1px solid rgba(255, 255, 255, 0.3);
}

.filter-btn-reset:hover {
  background: rgba(255, 255, 255, 0.3);
}

.filter-error {
  width: 100%;
  padding: 8px 12px;
  background: rgba(239, 68, 68, 0.2);
  border: 1px solid rgba(239, 68, 68, 0.4);
  border-radius: 6px;
  font-size: 13px;
}

.filter-breadcrumb {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid rgba(255, 255, 255, 0.2);
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
}

.breadcrumb-item {
  cursor: pointer;
  opacity: 0.7;
  transition: opacity 0.2s;
}

.breadcrumb-item:hover {
  opacity: 1;
  text-decoration: underline;
}

.breadcrumb-item.active {
  opacity: 1;
  font-weight: 600;
}

.breadcrumb-separator {
  opacity: 0.5;
}

/* Responsive: Mobile */
@media (max-width: 640px) {
  .filter-header {
    padding: 12px 14px;
    border-radius: 8px;
  }

  .filter-container {
    flex-direction: column;
    gap: 8px;
  }

  .filter-select-wrapper {
    min-width: 100%;
  }

  .filter-checkbox-label {
    align-self: flex-start;
  }

  .filter-actions {
    width: 100%;
  }

  .filter-btn {
    flex: 1;
    text-align: center;
    padding: 10px;
  }
}
</style>
