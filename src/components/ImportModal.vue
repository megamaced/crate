<template>
  <NcModal
    :show="show"
    label-id="import-modal-title"
    size="large"
    @close="handleClose"
  >
    <div class="import-modal">
      <h2 id="import-modal-title">
        Import collection
      </h2>

      <!-- ── Step 1: File picker ── -->
      <template v-if="step === 'pick'">
        <p class="import-hint">
          Upload a CSV or XLSX file. One row per album — one physical format per row.
          If you own the same album on multiple formats, add a row for each.
        </p>
        <p class="import-hint">
          <strong>Required columns:</strong> Artist, Title (or Album), Format<br>
          <strong>Optional:</strong> Year, Notes, Status, DiscogsId, Barcode, Label
        </p>

        <div
          class="import-dropzone"
          :class="{ 'import-dropzone--over': dragging }"
          @dragover.prevent="dragging = true"
          @dragleave="dragging = false"
          @drop.prevent="onDrop"
          @click="$refs.fileInput.click()"
        >
          <input
            ref="fileInput"
            type="file"
            accept=".csv,.xlsx,.xls,.ods"
            style="display:none"
            @change="onFileChange"
          >
          <span v-if="!selectedFile">Click or drag a CSV / XLSX file here</span>
          <span v-else>{{ selectedFile.name }}</span>
        </div>

        <div class="import-or">
          <span>or</span>
        </div>

        <div class="import-nc-pick">
          <NcButton
            type="button"
            variant="secondary"
            :disabled="pickingFromNc"
            @click="pickFromNextcloud"
          >
            {{ pickingFromNc ? 'Loading…' : 'Pick from Nextcloud Files' }}
          </NcButton>
        </div>

        <p
          v-if="parseError"
          class="import-error"
        >
          {{ parseError }}
        </p>

        <div class="import-actions">
          <NcButton
            type="button"
            variant="tertiary"
            @click="handleClose"
          >
            Cancel
          </NcButton>
          <NcButton
            type="button"
            variant="primary"
            :disabled="!selectedFile || parsing"
            @click="doParse"
          >
            {{ parsing ? 'Reading…' : 'Next' }}
          </NcButton>
        </div>
      </template>

      <!-- ── Step 2: Column mapping ── -->
      <template v-else-if="step === 'map'">
        <p class="import-hint">
          Map your columns to Crate fields. Columns marked <em>ignore</em> will be skipped.
        </p>

        <div class="import-mapping">
          <div
            v-for="(header, i) in headers"
            :key="i"
            class="import-mapping-row"
          >
            <span class="import-mapping-col">{{ header }}</span>
            <span class="import-mapping-arrow">→</span>
            <select v-model="mapping[i]">
              <option value="">
                ignore
              </option>
              <option
                v-for="f in mappableFields"
                :key="f.value"
                :value="f.value"
              >
                {{ f.label }}
              </option>
            </select>
          </div>
        </div>

        <p class="import-hint import-hint--section">
          Preview (first {{ previewRows.length }} rows):
        </p>
        <div class="import-preview-wrap">
          <table class="import-preview">
            <thead>
              <tr>
                <th
                  v-for="(header, i) in headers"
                  :key="i"
                >
                  {{ header }}<br>
                  <small>{{ mapping[i] || 'ignore' }}</small>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(row, ri) in previewRows"
                :key="ri"
              >
                <td
                  v-for="(cell, ci) in row"
                  :key="ci"
                >
                  {{ cell }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="import-hint">
          {{ totalRows }} rows in file.
        </p>

        <p
          v-if="mappingError"
          class="import-error"
        >
          {{ mappingError }}
        </p>

        <div
          v-if="hasToken"
          class="import-toggle"
        >
          <label class="import-toggle-label">
            <input
              v-model="autoEnrichOnImport"
              type="checkbox"
            >
            Enrich from Discogs after importing
          </label>
        </div>

        <div
          v-if="hasToken"
          class="import-toggle"
        >
          <label class="import-toggle-label">
            <input
              v-model="autoFetchMarketRates"
              type="checkbox"
            >
            Fetch market rates after importing
          </label>
        </div>

        <div class="import-actions">
          <NcButton
            type="button"
            variant="tertiary"
            @click="step = 'pick'"
          >
            Back
          </NcButton>
          <NcButton
            type="button"
            variant="primary"
            :disabled="!mappingValid"
            @click="doImport"
          >
            Import {{ totalRows }} rows
          </NcButton>
        </div>
      </template>

      <!-- ── Step 3: Results + enrichment progress ── -->
      <template v-else-if="step === 'results'">
        <div class="import-results">
          <div class="import-stat import-stat--created">
            <span class="import-stat__num">{{ result.created }}</span>
            <span class="import-stat__label">items added</span>
          </div>
          <div class="import-stat">
            <span class="import-stat__num">{{ result.duplicates }}</span>
            <span class="import-stat__label">duplicates skipped</span>
          </div>
          <div class="import-stat">
            <span class="import-stat__num">{{ result.skipped }}</span>
            <span class="import-stat__label">rows skipped</span>
          </div>
        </div>

        <ul
          v-if="result.errors && result.errors.length"
          class="import-errors-list"
        >
          <li
            v-for="(err, i) in result.errors"
            :key="i"
          >
            {{ err }}
          </li>
        </ul>

        <!-- Enrichment progress -->
        <template v-if="hasToken && result.created > 0">
          <div class="import-enrich">
            <p class="import-enrich__label">
              <template v-if="enrich.finished.value">
                Enrichment complete — {{ enrich.done.value }} of {{ enrich.total.value }} items enriched from Discogs.
                <template v-if="enrich.failed.value > 0">
                  {{ enrich.failed.value }} could not be matched.
                </template>
              </template>
              <template v-else>
                Enriching from Discogs… {{ enrich.done.value }} / {{ enrich.total.value }}
              </template>
            </p>
            <div class="import-enrich__bar-wrap">
              <div
                class="import-enrich__bar"
                :style="{ width: enrich.progress.value + '%' }"
              />
            </div>
            <p
              v-if="!enrich.finished.value"
              class="import-enrich__hint"
            >
              You can safely close this dialog — enrichment will continue in the background.
              <strong>Do not navigate away from Crate</strong> or the browser tab, or progress will be lost.
            </p>
          </div>
        </template>
        <template v-else-if="!hasToken && result.created > 0">
          <p class="import-hint import-hint--warn">
            Discogs enrichment skipped — no API token configured.
            Add your token in Settings to enrich artwork and metadata.
          </p>
        </template>

        <!-- Market value progress -->
        <template v-if="hasToken && autoFetchMarketRates && result.created > 0">
          <div class="import-enrich">
            <p class="import-enrich__label">
              <template v-if="marketQueue.finished.value">
                Market rates complete — {{ marketQueue.done.value }} of {{ marketQueue.total.value }} items priced.
                <template v-if="marketQueue.failed.value > 0">
                  {{ marketQueue.failed.value }} had no listing.
                </template>
              </template>
              <template v-else>
                Fetching market rates… {{ marketQueue.done.value }} / {{ marketQueue.total.value }}
              </template>
            </p>
            <div class="import-enrich__bar-wrap">
              <div
                class="import-enrich__bar"
                :style="{ width: marketQueue.progress.value + '%' }"
              />
            </div>
          </div>
        </template>

        <div class="import-actions">
          <NcButton
            type="button"
            variant="primary"
            @click="handleClose"
          >
            Done
          </NcButton>
        </div>
      </template>
    </div>
  </NcModal>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { NcModal, NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { useEnrichQueue } from '../composables/useEnrichQueue.js'
import { useMarketValueQueue } from '../composables/useMarketValueQueue.js'
import { useSettings } from '../composables/useSettings.js'

const props = defineProps({
  show: { type: Boolean, required: true },
  hasToken: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'imported'])

// Shared queues (survive modal close/reopen)
const enrich = useEnrichQueue()
const marketQueue = useMarketValueQueue()
const { autoEnrichOnImport, autoFetchMarketRates, marketCurrency } = useSettings()

// ── state ────────────────────────────────────────────────────────────────────
const step = ref('pick')
const selectedFile = ref(null)
const parsing = ref(false)
const parseError = ref('')
const mappingError = ref('')

const headers = ref([])
const previewRows = ref([])
const totalRows = ref(0)
const mapping = ref({}) // colIndex => field name or ''

const result = ref(null)

// ── field options for mapping UI ──────────────────────────────────────────────
const mappableFields = [
  { value: 'artist',    label: 'Artist' },
  { value: 'title',     label: 'Title / Album' },
  { value: 'format',    label: 'Format' },
  { value: 'year',      label: 'Year' },
  { value: 'notes',     label: 'Notes' },
  { value: 'status',    label: 'Status' },
  { value: 'discogsId', label: 'Discogs ID' },
  { value: 'barcode',   label: 'Barcode' },
  { value: 'label',     label: 'Label' },
]

const mappingValid = computed(() => {
  const mapped = Object.values(mapping.value).filter(Boolean)
  return mapped.includes('artist') && mapped.includes('title') && mapped.includes('format')
})

// ── reset on open ─────────────────────────────────────────────────────────────
watch(() => props.show, (open) => {
  if (open) reset()
})

function reset() {
  step.value = 'pick'
  selectedFile.value = null
  parsing.value = false
  pickingFromNc.value = false
  parseError.value = ''
  mappingError.value = ''
  headers.value = []
  previewRows.value = []
  totalRows.value = 0
  mapping.value = {}
  result.value = null
  if (enrich.finished.value) enrich.reset()
  if (marketQueue.finished.value) marketQueue.reset()
}

// ── file selection ────────────────────────────────────────────────────────────
const dragging = ref(false)
const pickingFromNc = ref(false)

function onFileChange(e) {
  const f = e.target.files[0]
  if (f) selectedFile.value = f
}

function onDrop(e) {
  dragging.value = false
  const f = e.dataTransfer.files[0]
  if (f) selectedFile.value = f
}

async function pickFromNextcloud() {
  const oc = window.OC
  if (!oc?.dialogs?.filepicker) {
    alert('Nextcloud file picker is not available.')
    return
  }
  oc.dialogs.filepicker(
    'Select CSV or XLSX file',
    async (path) => {
      pickingFromNc.value = true
      try {
        const uid = oc.currentUser ?? ''
        const webdavUrl = `/remote.php/dav/files/${uid}${path}`
        const resp = await axios.get(webdavUrl, { responseType: 'arraybuffer' })
        const fileName = path.split('/').pop() || 'import'
        const mime = resp.headers['content-type'] || 'application/octet-stream'
        selectedFile.value = new File([resp.data], fileName, { type: mime })
        parseError.value = ''
      } catch (e) {
        parseError.value = 'Failed to fetch file from Nextcloud.'
      } finally {
        pickingFromNc.value = false
      }
    },
    false,
    [],
    true,
  )
}

// ── step 1 → 2: parse & preview ──────────────────────────────────────────────
async function doParse() {
  parseError.value = ''
  parsing.value = true
  try {
    const fd = new FormData()
    fd.append('file', selectedFile.value)
    const res = await axios.post(generateOcsUrl('/apps/crate/api/v1/import/preview'), fd)
    const data = res.data.ocs?.data
    headers.value = data.headers
    previewRows.value = data.preview
    totalRows.value = data.totalRows
    // Convert mapping object keys back to numbers
    const raw = data.mapping
    const m = {}
    Object.keys(raw).forEach(k => { m[parseInt(k)] = raw[k] || '' })
    mapping.value = m
    step.value = 'map'
  } catch (e) {
    parseError.value = e.response?.data?.ocs?.data?.error ?? 'Failed to parse file.'
  } finally {
    parsing.value = false
  }
}

// ── step 2 → 3: commit import ─────────────────────────────────────────────────
async function doImport() {
  mappingError.value = ''
  try {
    const fd = new FormData()
    fd.append('file', selectedFile.value)
    // Serialise mapping: colIndex => field or ''
    const mappingObj = {}
    Object.keys(mapping.value).forEach(k => { mappingObj[k] = mapping.value[k] || '' })
    fd.append('mapping', JSON.stringify(mappingObj))

    const res = await axios.post(generateOcsUrl('/apps/crate/api/v1/import/commit'), fd)
    result.value = res.data.ocs?.data
    step.value = 'results'

    emit('imported')

    const newIds = result.value.itemIds ?? []
    if (props.hasToken && autoEnrichOnImport.value && newIds.length > 0) {
      enrich.start(newIds)
    }
    if (props.hasToken && autoFetchMarketRates.value && newIds.length > 0) {
      marketQueue.start(newIds, marketCurrency.value)
    }
  } catch (e) {
    mappingError.value = e.response?.data?.ocs?.data?.error ?? 'Import failed.'
  }
}

// ── close ─────────────────────────────────────────────────────────────────────
function handleClose() {
  emit('close')
}
</script>

<style scoped>
.import-modal {
  padding: 24px 28px 28px;
  min-width: min(560px, 92vw);
  max-width: 700px;
}

.import-modal h2 {
  margin: 0 0 16px;
  font-size: 1.25em;
  font-weight: 700;
}

.import-hint {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
  margin: 0 0 12px;
  line-height: 1.5;
}

.import-hint--section {
  margin-top: 20px;
}

.import-hint--warn {
  color: var(--color-warning);
}

.import-error {
  font-size: 0.875em;
  color: var(--color-error);
  margin: 8px 0;
}

/* Picker divider */
.import-or {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 10px 0;
  color: var(--color-text-maxcontrast);
  font-size: 0.85em;
}

.import-or::before,
.import-or::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--color-border-dark);
}

.import-nc-pick {
  display: flex;
  justify-content: center;
  margin-bottom: 16px;
}

/* Drop zone */
.import-dropzone {
  border: 2px dashed var(--color-border-dark);
  border-radius: var(--border-radius-large);
  padding: 40px 24px;
  text-align: center;
  cursor: pointer;
  font-size: 0.9em;
  color: var(--color-text-maxcontrast);
  transition: border-color 0.15s, background 0.15s;
  margin-bottom: 16px;
}

.import-dropzone:hover,
.import-dropzone--over {
  border-color: var(--color-primary-element);
  background: var(--color-background-dark);
  color: var(--color-main-text);
}

/* Column mapping */
.import-mapping {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 4px;
  max-height: 240px;
  overflow-y: auto;
}

.import-mapping-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.import-mapping-col {
  flex: 0 0 160px;
  font-size: 0.875em;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.import-mapping-arrow {
  color: var(--color-text-maxcontrast);
}

.import-mapping-row select {
  flex: 1;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 5px 8px;
  font-size: 0.875em;
}

/* Preview table */
.import-preview-wrap {
  overflow-x: auto;
  margin-bottom: 8px;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
}

.import-preview {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8em;
}

.import-preview th,
.import-preview td {
  padding: 5px 10px;
  border-bottom: 1px solid var(--color-border);
  white-space: nowrap;
  text-align: left;
}

.import-preview th {
  background: var(--color-background-dark);
  font-weight: 600;
}

.import-preview th small {
  font-weight: 400;
  color: var(--color-primary-element);
}

.import-preview tr:last-child td {
  border-bottom: none;
}

/* Results */
.import-results {
  display: flex;
  gap: 24px;
  margin-bottom: 20px;
}

.import-stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  min-width: 80px;
}

.import-stat__num {
  font-size: 2em;
  font-weight: 700;
  line-height: 1;
}

.import-stat--created .import-stat__num {
  color: var(--color-success);
}

.import-stat__label {
  font-size: 0.75em;
  color: var(--color-text-maxcontrast);
  text-align: center;
}

.import-errors-list {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  margin: 0 0 16px;
  padding-left: 18px;
  max-height: 120px;
  overflow-y: auto;
}

/* Enrichment progress */
.import-enrich {
  margin-bottom: 20px;
}

.import-enrich__label {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
  margin: 0 0 8px;
}

.import-enrich__bar-wrap {
  height: 6px;
  background: var(--color-background-dark);
  border-radius: 3px;
  overflow: hidden;
}

.import-enrich__bar {
  height: 100%;
  background: var(--color-primary-element);
  border-radius: 3px;
  transition: width 0.4s ease;
}

.import-enrich__hint {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  margin: 8px 0 0;
  font-style: italic;
}

/* Import toggle */
.import-toggle {
  margin-top: 14px;
}

.import-toggle-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.875em;
  cursor: pointer;
  user-select: none;
}

.import-toggle-label input[type='checkbox'] {
  cursor: pointer;
  width: 16px;
  height: 16px;
  flex-shrink: 0;
}

/* Actions */
.import-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}
</style>
