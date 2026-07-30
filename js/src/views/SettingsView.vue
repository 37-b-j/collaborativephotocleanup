<template>
  <div class="settings-view">
    <h2>Einstellungen</h2>
    
    <div class="settings-section">
      <h3>Cluster-Sensitivität</h3>
      <p>Bestimmt, wie ähnlich Bilder sein müssen, um gruppiert zu werden. Höhere Werte = mehr Bilder pro Cluster (weniger sensitiv).</p>
      <div class="number-picker">
        <button class="np-btn np-minus" @click="decrement" :disabled="threshold <= 0" aria-label="Verringern">
          <span class="np-icon">−</span>
        </button>
        <div class="np-value-wrap">
          <input
            type="number"
            class="np-value"
            v-model.number="threshold"
            @change="onThresholdChange"
            min="0"
            max="100"
          />
          <span class="np-label">Hamming-Distanz</span>
        </div>
        <button class="np-btn np-plus" @click="increment" :disabled="threshold >= 100" aria-label="Erhöhen">
          <span class="np-icon">+</span>
        </button>
      </div>
      <p class="np-hint">Standard: 25. Niedrig (z.B. 5) = nur sehr ähnliche Bilder. Hoch (z.B. 50) = viele Bilder pro Cluster.</p>
    </div>

    <div class="settings-section">
      <h3>Daten zurücksetzen</h3>
      <p>Setzt alle Bewertungen (Löschen/Behalten) zurück und startet die Aufräum-Ansicht neu. Die Bilder bleiben erhalten.</p>
      <button class="reset-btn" @click="resetAll" :disabled="resetting">
        {{ resetting ? 'Wird zurückgesetzt...' : 'Neustart (alle Bewertungen löschen)' }}
      </button>
      <p v-if="resetDone" class="success-msg">{{ resetDone }}</p>
      <p v-if="resetError" class="error-msg">{{ resetError }}</p>
    </div>

    <div class="settings-section">
      <h3>Cache-Info</h3>
      <p>pHashes werden in der Datenbank zwischengespeichert. Nach dem ersten Durchlauf werden Cluster deutlich schneller geladen.</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { generateUrl, getCSRFToken } from '@/utils/helpers'

const THRESHOLD_STORAGE_KEY = 'photocleanup_cluster_threshold'

const resetting = ref(false)
const resetDone = ref('')
const resetError = ref('')

// Load saved threshold or default 25
const saved = localStorage.getItem(THRESHOLD_STORAGE_KEY)
const threshold = ref(saved ? parseInt(saved) : 25)

function onThresholdChange() {
  if (threshold.value < 0) threshold.value = 0
  if (threshold.value > 100) threshold.value = 100
  localStorage.setItem(THRESHOLD_STORAGE_KEY, String(threshold.value))
}

function decrement() {
  if (threshold.value > 0) {
    threshold.value -= 5
    onThresholdChange()
  }
}

function increment() {
  if (threshold.value < 100) {
    threshold.value += 5
    onThresholdChange()
  }
}

async function resetAll() {
  resetting.value = true
  resetDone.value = ''
  resetError.value = ''
  
  try {
    const csrfToken = getCSRFToken()
    
    localStorage.removeItem('swipe_votes_cleanup')
    localStorage.removeItem('photocleanup_votes')
    localStorage.removeItem('photocleanup_sync_queue')
    
    const resp = await fetch(generateUrl('api/v1/vote/delete-all'), {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'requesttoken': csrfToken,
      },
    })
    
    if (resp.ok) {
      resetDone.value = 'Alle Bewertungen wurden zurückgesetzt. Gehe zurück zur Cleanup-Ansicht.'
    } else {
      const data = await resp.json()
      resetError.value = data.error || 'Fehler beim Zurücksetzen'
    }
  } catch (e: any) {
    resetError.value = e.message || 'Netzwerkfehler'
  } finally {
    resetting.value = false
  }
}
</script>

<style scoped>
.settings-view {
  max-width: 600px;
  margin: 0 auto;
  padding: 20px;
}

.settings-view h2 {
  margin-bottom: 24px;
  color: #333;
}

.settings-section {
  background: #fff;
  border-radius: 12px;
  padding: 20px;
  margin-bottom: 20px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.settings-section h3 {
  margin: 0 0 8px;
  color: #333;
  font-size: 1.1em;
}

.settings-section p {
  color: #666;
  font-size: 0.9em;
  margin: 0 0 16px;
  line-height: 1.5;
}

/* Number Picker */
.number-picker {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0;
  margin: 20px 0;
}

.np-btn {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
  flex-shrink: 0;
}

.np-minus {
  background: #e8f0fe;
  color: #0082c9;
}

.np-plus {
  background: #e8f0fe;
  color: #0082c9;
}

.np-btn:hover:not(:disabled) {
  background: #0082c9;
  color: #fff;
  transform: scale(1.08);
}

.np-btn:active:not(:disabled) {
  transform: scale(0.95);
}

.np-btn:disabled {
  opacity: 0.3;
  cursor: default;
}

.np-icon {
  font-size: 28px;
  font-weight: 300;
  line-height: 1;
}

.np-value-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin: 0 20px;
}

.np-value {
  width: 80px;
  height: 56px;
  text-align: center;
  font-size: 28px;
  font-weight: 700;
  color: #333;
  border: 2px solid #e0e0e0;
  border-radius: 12px;
  background: #fafafa;
  outline: none;
  -moz-appearance: textfield;
  transition: border-color 0.2s;
}

.np-value::-webkit-inner-spin-button,
.np-value::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

.np-value:focus {
  border-color: #0082c9;
  background: #fff;
}

.np-label {
  font-size: 0.75em;
  color: #999;
  margin-top: 6px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.np-hint {
  font-size: 0.8em !important;
  color: #aaa !important;
  margin-top: 8px !important;
  text-align: center;
}

.reset-btn {
  padding: 10px 24px;
  background: #0082c9;
  color: #fff;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.95em;
  font-weight: 600;
  transition: all 0.2s;
}

.reset-btn:hover:not(:disabled) {
  background: #006aa3;
  transform: translateY(-1px);
}

.reset-btn:disabled {
  opacity: 0.6;
  cursor: default;
}

.success-msg {
  color: #2e7d32 !important;
  margin-top: 12px !important;
  font-weight: 500;
}

.error-msg {
  color: #c62828 !important;
  margin-top: 12px !important;
  font-weight: 500;
}
</style>
