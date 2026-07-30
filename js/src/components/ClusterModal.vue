<template>
  <!-- FULL-PREVIEW OVERLAY (wenn ein Bild im Vollbild geöffnet ist) -->
  <div v-if="fullViewImage" class="fullview-overlay">
    <div class="fullview-header">
      <span class="fullview-counter">{{ fullViewIndex + 1 }} / {{ fullViewImages.length }}</span>
      <button class="fullview-close" @click="closeFullView">&times;</button>
    </div>
    <div class="fullview-body">
      <button class="fullview-nav prev" @click="prevImage" :disabled="fullViewIndex <= 0">&lt;</button>
      <div class="fullview-image-wrap">
        <img
          :src="previewUrl(fullViewImage.fileId, 1200, 1200)"
          :alt="fullViewImage.name"
          @error="onFullImgError"
        />
        <div class="fullview-info">
          <span class="fullview-name">{{ fullViewImage.name }}</span>
          <span class="fullview-faces">👤 {{ fullViewImage.faces }} faces</span>
          <button
            v-if="fullViewImage.fileId !== (tempFavorite?.fileId ?? -1)"
            class="fullview-fav-btn"
            @click="selectAsFavorite(fullViewImage); closeFullView()"
          >
            ⭐ Als Favorit setzen
          </button>
          <span v-else class="fullview-is-fav">⭐ Aktueller Favorit</span>
        </div>
      </div>
      <button class="fullview-nav next" @click="nextImage" :disabled="fullViewIndex >= fullViewImages.length - 1">&gt;</button>
    </div>
  </div>

  <!-- CLUSTER REVIEW MODAL -->
  <div v-if="show" class="modal-overlay" @click.self="close">
    <div class="modal-container">
      <div class="modal-header">
        <h2>🔍 Cluster Review</h2>
        <button class="close-btn" @click="close">&times;</button>
      </div>

      <div class="modal-body">
        <!-- Favorit / Primary -->
        <div class="favorite-section">
          <h3>⭐ Favorit ({{ tempFavorite?.faces ?? 0 }} Gesichter)</h3>
          <div class="favorite-card" :class="{ selected: tempFavorite?.fileId === (currentCluster?.favorite?.fileId ?? -1) }">
            <img
              :src="previewUrl((tempFavorite?.fileId ?? 0), 400, 400)"
              :alt="tempFavorite?.name"
              @error="onImgError"
              @click="openFullView(tempFavorite, 0)"
              style="cursor: pointer"
              title="Klick für Vollbild"
            />
            <div class="fav-info">
              <span class="fav-name">{{ tempFavorite?.name ?? '' }}</span>
              <span class="fav-faces">👤 {{ tempFavorite?.faces ?? 0 }} faces</span>
              <span class="fav-hint">📷 Klick für Vollbild</span>
            </div>
          </div>
        </div>

        <!-- Grid aller ähnlichen Bilder -->
        <div class="grid-section">
          <h3>📸 Ähnliche Bilder ({{ (currentCluster?.images?.length ?? 1) - 1 }})</h3>
          <p class="hint">Tippe für Vollbild · Tippe ⭐ zum Favorit wählen</p>
          <div class="thumbnail-grid">
            <div
              v-for="(img, idx) in otherImages"
              :key="img.fileId"
              :class="['thumbnail-card', { selected: img.fileId === tempFavorite?.fileId }]"
            >
              <img
                :src="previewUrl(img.fileId, 200, 200)"
                :alt="img.name"
                @error="onImgError"
                @click="openFullView(img, idx + 1)"
                title="Klick für Vollbild"
              />
              <div class="thumb-info">
                <span class="thumb-name">{{ img.name }}</span>
                <span class="thumb-faces">👤 {{ img.faces }}</span>
              </div>
              <button
                v-if="img.fileId !== tempFavorite?.fileId"
                class="thumb-fav-btn"
                @click.stop="selectAsFavorite(img)"
                title="Als Favorit setzen"
              >⭐</button>
              <div v-else class="selected-badge">⭐</div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="action-btn keep-btn" @click="confirmKeepFavorite">
          ✅ Favorit behalten, Rest löschen
        </button>
        <button class="action-btn delete-btn" @click="confirmDeleteAll">
          🗑️ Alle löschen
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { previewUrl } from "../utils/previewUrl"
import { computed, ref } from "vue"
import { useReviewStore, type ClusterImage } from "@/stores/reviewStore"

const reviewStore = useReviewStore()

const show = computed(() => reviewStore.showModal)
const currentCluster = computed(() => reviewStore.currentCluster)
const tempFavorite = computed(() => reviewStore.tempFavorite)

// Full-view state
const fullViewImage = ref<ClusterImage | null>(null)
const fullViewIndex = ref(0)
const fullViewImages = computed(() => {
  if (!currentCluster.value) return []
  return currentCluster.value.images
})

const otherImages = computed(() => {
  if (!currentCluster.value) return []
  return currentCluster.value.images.filter(
    (img: ClusterImage) => img.fileId !== currentCluster.value?.favorite.fileId
  )
})

function selectAsFavorite(img: ClusterImage) {
  reviewStore.selectAsFavorite(img)
}

function close() {
  reviewStore.closeReview()
}

function confirmKeepFavorite() {
  reviewStore.confirmKeepFavorite()
}

function confirmDeleteAll() {
  reviewStore.confirmDeleteAll()
}

function onImgError(e: Event) {
  const img = e.target as HTMLImageElement
  img.style.display = 'none'
}

function onFullImgError(e: Event) {
  const img = e.target as HTMLImageElement
  img.src = previewUrl((fullViewImage.value?.fileId ?? 0), 512, 512)
}

// Full-view navigation
function openFullView(img: ClusterImage, idx: number) {
  fullViewImage.value = img
  fullViewIndex.value = idx
}

function closeFullView() {
  fullViewImage.value = null
}

function prevImage() {
  if (fullViewIndex.value > 0) {
    fullViewIndex.value--
    fullViewImage.value = fullViewImages.value[fullViewIndex.value]
  }
}

function nextImage() {
  if (fullViewIndex.value < fullViewImages.value.length - 1) {
    fullViewIndex.value++
    fullViewImage.value = fullViewImages.value[fullViewIndex.value]
  }
}
</script>

<style scoped>
/* === FULL-VIEW OVERLAY === */
.fullview-overlay {
  position: fixed; inset: 0; z-index: 99999;
  background: rgba(0,0,0,0.92);
  display: flex; flex-direction: column;
}
.fullview-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 16px; color: #fff;
}
.fullview-counter { font-size: 1em; font-weight: 600; }
.fullview-close {
  font-size: 2em; border: none; background: rgba(255,255,255,0.15);
  color: #fff; width: 40px; height: 40px; border-radius: 50%;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
}
.fullview-close:hover { background: rgba(255,255,255,0.3); }
.fullview-body {
  flex: 1; display: flex; align-items: center; justify-content: center;
  gap: 8px; padding: 8px; overflow: hidden;
}
.fullview-nav {
  font-size: 2em; border: none; background: rgba(255,255,255,0.15);
  color: #fff; width: 48px; height: 48px; border-radius: 50%;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.fullview-nav:hover:not(:disabled) { background: rgba(255,255,255,0.3); }
.fullview-nav:disabled { opacity: 0.3; cursor: default; }
.fullview-image-wrap {
  flex: 1; display: flex; flex-direction: column; align-items: center;
  justify-content: center; max-height: 100%; overflow: hidden;
}
.fullview-image-wrap img {
  max-width: 100%; max-height: 70vh; object-fit: contain;
  border-radius: 8px; box-shadow: 0 4px 30px rgba(0,0,0,0.5);
}
.fullview-info {
  display: flex; align-items: center; gap: 12px; margin-top: 12px;
  color: #fff; flex-wrap: wrap; justify-content: center;
}
.fullview-name { font-weight: 600; font-size: 0.9em; word-break: break-all; }
.fullview-faces { font-size: 0.85em; opacity: 0.8; }
.fullview-fav-btn {
  padding: 6px 14px; background: #ffc107; color: #333;
  border: none; border-radius: 8px; font-weight: 700;
  font-size: 0.85em; cursor: pointer;
}
.fullview-fav-btn:hover { background: #ffb300; }
.fullview-is-fav { color: #ffc107; font-weight: 700; font-size: 0.85em; }

/* === MODAL === */
.modal-overlay {
  position: fixed; inset: 0; z-index: 9999;
  background: rgba(0,0,0,0.6);
  display: flex; align-items: center; justify-content: center;
  padding: 16px;
}
.modal-container {
  background: #fff; border-radius: 16px;
  max-width: 700px; width: 100%; max-height: 90vh;
  display: flex; flex-direction: column;
  overflow: hidden;
  box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px; border-bottom: 1px solid #e0e0e0;
}
.modal-header h2 { margin: 0; font-size: 1.2em; }
.close-btn { font-size: 1.5em; border: none; background: none; cursor: pointer; color: #888; padding: 0 4px; }
.close-btn:hover { color: #333; }
.modal-body { overflow-y: auto; padding: 16px 20px; flex: 1; }
.modal-body h3 { margin: 0 0 8px; font-size: 1em; color: #333; }
.hint { font-size: 0.8em; color: #888; margin: 0 0 12px; }

.favorite-section { margin-bottom: 20px; }
.favorite-card {
  border: 3px solid #e0e0e0; border-radius: 12px; overflow: hidden;
  display: flex; align-items: center; gap: 12px;
  padding: 8px; transition: border-color 0.2s; cursor: pointer;
}
.favorite-card.selected { border-color: #ffc107; }
.favorite-card img { width: 100px; height: 100px; object-fit: cover; border-radius: 8px; }
.fav-info { display: flex; flex-direction: column; gap: 2px; }
.fav-name { font-weight: 600; font-size: 0.9em; word-break: break-all; }
.fav-faces { font-size: 0.8em; color: #666; }
.fav-hint { font-size: 0.7em; color: #999; }

.grid-section { }
.thumbnail-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
  gap: 10px;
}
.thumbnail-card {
  position: relative;
  border: 2px solid #e0e0e0; border-radius: 10px; overflow: hidden;
  cursor: pointer; transition: all 0.2s;
}
.thumbnail-card:hover { border-color: #aaa; transform: translateY(-2px); }
.thumbnail-card.selected { border-color: #ffc107; box-shadow: 0 0 0 2px rgba(255,193,7,0.4); }
.thumbnail-card img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; }
.thumb-info { padding: 6px 8px; font-size: 0.7em; }
.thumb-name { display: block; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.thumb-faces { color: #888; }
.selected-badge { position: absolute; top: 4px; right: 4px; font-size: 1.5em; }
.thumb-fav-btn {
  position: absolute; top: 4px; right: 4px;
  font-size: 1.2em; background: rgba(0,0,0,0.5); border: none;
  border-radius: 50%; width: 28px; height: 28px;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  opacity: 0; transition: opacity 0.2s;
}
.thumbnail-card:hover .thumb-fav-btn { opacity: 1; }
.thumb-fav-btn:hover { background: rgba(0,0,0,0.7); }

.modal-footer {
  display: flex; gap: 12px; padding: 16px 20px; border-top: 1px solid #e0e0e0;
  flex-wrap: wrap;
}
.action-btn {
  flex: 1; min-width: 160px; padding: 12px 16px;
  border: none; border-radius: 10px; font-size: 0.9em; font-weight: 700;
  cursor: pointer; transition: all 0.2s;
}
.action-btn:hover { transform: translateY(-1px); }
.keep-btn { background: #4caf50; color: #fff; }
.keep-btn:hover { background: #388e3c; }
.delete-btn { background: #f44336; color: #fff; }
.delete-btn:hover { background: #c62828; }

@media (max-width: 500px) {
  .modal-container { border-radius: 10px; max-height: 95vh; }
  .thumbnail-grid { grid-template-columns: repeat(3, 1fr); }
  .action-btn { font-size: 0.8em; padding: 10px; }
  .favorite-card img { width: 70px; height: 70px; }
  .fullview-nav { width: 36px; height: 36px; font-size: 1.3em; }
  .fullview-body { gap: 4px; padding: 4px; }
}
</style>
