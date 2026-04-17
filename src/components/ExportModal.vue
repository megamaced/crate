<template>
  <NcModal
    :show="show"
    size="small"
    label-id="export-modal-title"
    @close="$emit('close')"
  >
    <div class="export-modal">
      <h2 id="export-modal-title">
        Export collection
      </h2>

      <div class="export-field">
        <label class="export-label">Format</label>
        <div class="export-radio-group">
          <label class="export-radio-label">
            <input
              v-model="format"
              type="radio"
              value="csv"
            > CSV
          </label>
          <label class="export-radio-label">
            <input
              v-model="format"
              type="radio"
              value="xlsx"
            > XLSX (Excel)
          </label>
        </div>
      </div>

      <div class="export-field">
        <label class="export-label">Include</label>
        <label class="export-checkbox-label">
          <input
            v-model="includeEnriched"
            type="checkbox"
          >
          Enriched Discogs data
          <span class="export-hint">(genres, tracklist, country, artist bio, pressing notes)</span>
        </label>
        <label class="export-checkbox-label">
          <input
            v-model="includeMarket"
            type="checkbox"
          >
          Market data
          <span class="export-hint">(value, currency, fetched date)</span>
        </label>
      </div>

      <p
        v-if="error"
        class="export-error"
      >
        {{ error }}
      </p>

      <div class="export-actions">
        <NcButton
          variant="tertiary"
          @click="$emit('close')"
        >
          Cancel
        </NcButton>
        <NcButton
          variant="primary"
          :disabled="exporting"
          @click="doExport"
        >
          {{ exporting ? 'Preparing download…' : 'Download' }}
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script setup>
import { ref, watch } from 'vue'
import { NcModal, NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const props = defineProps({
  show:  { type: Boolean, required: true },
  scope: { type: String,  default: 'owned' },
})

const emit = defineEmits(['close'])

const format          = ref('csv')
const includeEnriched = ref(false)
const includeMarket   = ref(false)
const exporting       = ref(false)
const error           = ref('')

watch(() => props.show, (open) => {
  if (open) error.value = ''
})

async function doExport() {
  exporting.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({
      format:          format.value,
      scope:           props.scope,
      includeEnriched: includeEnriched.value ? '1' : '0',
      includeMarket:   includeMarket.value   ? '1' : '0',
    })
    const url = generateUrl('/apps/crate/export') + '?' + params.toString()
    const res = await axios.get(url, { responseType: 'blob' })

    const ext      = format.value === 'xlsx' ? 'xlsx' : 'csv'
    const filename = `crate-export-${new Date().toISOString().slice(0, 10)}.${ext}`

    const blobUrl = URL.createObjectURL(res.data)
    const a       = document.createElement('a')
    a.href        = blobUrl
    a.download    = filename
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(blobUrl)

    emit('close')
  } catch (e) {
    console.error('Export failed', e)
    error.value = 'Export failed — please try again.'
  } finally {
    exporting.value = false
  }
}
</script>

<style scoped>
.export-modal {
  padding: 24px 28px 28px;
  width: 100%;
  box-sizing: border-box;
}

.export-modal h2 {
  margin: 0 0 20px;
  font-size: 1.25em;
  font-weight: 700;
}

.export-field {
  margin-bottom: 18px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.export-label {
  font-size: 0.875em;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.export-radio-group {
  display: flex;
  gap: 20px;
}

.export-radio-label,
.export-checkbox-label {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 0.9em;
  cursor: pointer;
  user-select: none;
}

.export-radio-label input,
.export-checkbox-label input {
  cursor: pointer;
  flex-shrink: 0;
}

.export-hint {
  font-size: 0.82em;
  color: var(--color-text-maxcontrast);
}

.export-error {
  font-size: 0.875em;
  color: var(--color-error);
  margin: 0 0 12px;
}

.export-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 24px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}
</style>
