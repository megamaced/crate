<template>
  <NcModal
    :show="show"
    label-id="import-modal-title"
    size="normal"
    @close="handleClose"
  >
    <div class="import-modal">
      <h2 id="import-modal-title">
        {{ t('crate', 'Import collection') }}
      </h2>

      <!-- ── Step 1: File picker ── -->
      <template v-if="step === 'pick'">
        <p class="import-hint">
          {{ t('crate', 'Upload a CSV or XLSX file.') }}
          {{ hintLead }}
        </p>
        <p class="import-hint">
          <strong>{{ t('crate', 'Required columns:') }}</strong> {{ hintRequired }}<br>
          <!-- TRANSLATORS: label before the list of columns an import file may also have -->
          <strong>{{ t('crate', 'Optional:') }}</strong> {{ hintOptional }}
        </p>

        <div class="import-field">
          <label
            class="import-label"
            for="import-category"
          >{{ t('crate', 'Category') }}</label>
          <select
            id="import-category"
            v-model="selectedCategory"
            class="import-select"
            :disabled="!!owner"
          >
            <option value="music">
              {{ categoryLabel('music') }}
            </option>
            <option value="film">
              {{ categoryLabel('film') }}
            </option>
            <option value="book">
              {{ categoryLabel('book') }}
            </option>
            <option value="game">
              {{ categoryLabel('game') }}
            </option>
            <option value="comic">
              {{ categoryLabel('comic') }}
            </option>
          </select>
        </div>

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
            accept=".csv,.xlsx"
            style="display:none"
            @change="onFileChange"
          >
          <span v-if="!selectedFile">{{ t('crate', 'Click or drag a CSV / XLSX file here') }}</span>
          <span v-else>{{ selectedFile.name }}</span>
        </div>

        <div class="import-or">
          <!-- TRANSLATORS: between the file drop zone and the button that picks a file from Nextcloud Files -->
          <span>{{ t('crate', 'or') }}</span>
        </div>

        <div class="import-nc-pick">
          <NcButton
            type="button"
            variant="secondary"
            :disabled="pickingFromNc"
            @click="pickFromNextcloud"
          >
            {{ pickingFromNc
              ? t('crate', 'Loading…')
              : t('crate', 'Pick from Nextcloud Files') }}
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
            {{ t('crate', 'Cancel') }}
          </NcButton>
          <NcButton
            type="button"
            variant="primary"
            :disabled="!selectedFile || parsing"
            @click="doParse"
          >
            {{ parsing
              ? t('crate', 'Reading…')
              : t('crate', 'Next') }}
          </NcButton>
        </div>
      </template>

      <!-- ── Step 2: Column mapping ── -->
      <template v-else-if="step === 'map'">
        <p class="import-hint">
          {{ mapHint.before }}<em>{{ ignoreLabel }}</em>{{ mapHint.after }}
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
                {{ ignoreLabel }}
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
          {{ n('crate', 'Preview (first %n row):', 'Preview (first %n rows):', previewRows.length) }}
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
                  <small>{{ mappedFieldLabel(mapping[i]) }}</small>
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
          {{ n('crate', '%n row in file.', '%n rows in file.', totalRows) }}
        </p>

        <p
          v-if="mappingError"
          class="import-error"
        >
          {{ mappingError }}
        </p>

        <div
          v-if="enrichTokenAvailable"
          class="import-toggle"
        >
          <label class="import-toggle-label">
            <input
              v-model="autoEnrichOnImport"
              type="checkbox"
            >
            <!-- TRANSLATORS: {service} is the name of the online service items are enriched from, such as Discogs or TMDB -->
            {{ t('crate', 'Enrich from {service} after importing', { service: enrichServiceName }) }}
          </label>
        </div>

        <div
          v-if="marketAvailable && marketTokenAvailable"
          class="import-toggle"
        >
          <label class="import-toggle-label">
            <input
              v-model="autoFetchMarketRates"
              type="checkbox"
            >
            <!-- TRANSLATORS: {service} is the name of the price service, Discogs or PriceCharting -->
            {{ t('crate', 'Fetch market rates from {service} after importing', { service: marketServiceName }) }}
          </label>
        </div>

        <div class="import-actions">
          <NcButton
            type="button"
            variant="tertiary"
            @click="step = 'pick'"
          >
            {{ t('crate', 'Back') }}
          </NcButton>
          <NcButton
            type="button"
            variant="primary"
            :disabled="!mappingValid || importing"
            @click="doImport"
          >
            {{ importing
              ? t('crate', 'Importing…')
              : n('crate', 'Import %n row', 'Import %n rows', totalRows) }}
          </NcButton>
        </div>
      </template>

      <!-- ── Step 3: Results + enrichment progress ── -->
      <template v-else-if="step === 'results'">
        <div class="import-results">
          <div class="import-stat import-stat--created">
            <span class="import-stat__num">{{ result.created }}</span>
            <!-- TRANSLATORS: under the number of items the import added, which is shown above it -->
            <span class="import-stat__label">{{ n('crate', 'item added', 'items added', result.created) }}</span>
          </div>
          <div class="import-stat">
            <span class="import-stat__num">{{ result.duplicates }}</span>
            <!-- TRANSLATORS: under the number of rows left out because the item is already in the collection -->
            <span class="import-stat__label">{{ n('crate', 'duplicate skipped', 'duplicates skipped', result.duplicates) }}</span>
          </div>
          <div class="import-stat">
            <span class="import-stat__num">{{ result.skipped }}</span>
            <!-- TRANSLATORS: under the number of rows the import could not use -->
            <span class="import-stat__label">{{ n('crate', 'row skipped', 'rows skipped', result.skipped) }}</span>
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
        <template v-if="enrichTokenAvailable && result.created > 0 && (enrich.running.value || enrich.total.value > 0)">
          <div class="import-enrich">
            <p class="import-enrich__label">
              <template v-if="enrich.finished.value">
                <!-- TRANSLATORS: {service} is the name of the online service items are enriched from, such as Discogs or TMDB -->
                {{ n('crate', 'Enrichment complete — {done} of %n item enriched from {service}.', 'Enrichment complete — {done} of %n items enriched from {service}.', enrich.total.value, { done: enrich.done.value, service: enrichServiceName }) }}
                <template v-if="enrich.failed.value > 0">
                  {{ n('crate', '%n could not be matched.', '%n could not be matched.', enrich.failed.value) }}
                </template>
              </template>
              <template v-else>
                <!-- TRANSLATORS: {service} is the name of the online service items are enriched from, such as Discogs or TMDB -->
                {{ t('crate', 'Enriching from {service}… {done} / {total}', { service: enrichServiceName, done: enrich.done.value, total: enrich.total.value }) }}
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
              {{ t('crate', 'You can safely close this dialog — enrichment will continue in the background.') }}
              {{ navigateWarning.before }}<strong>{{ navigateWarning.inner }}</strong>{{ navigateWarning.after }}
            </p>
          </div>
        </template>
        <template v-else-if="!enrichTokenAvailable && result.created > 0">
          <p class="import-hint import-hint--warn">
            <!-- TRANSLATORS: {service} is the name of the online service items are enriched from, such as Discogs or TMDB -->
            {{ t('crate', '{service} enrichment skipped — no API key configured.', { service: enrichServiceName }) }}
            {{ t('crate', 'Add your key in Settings to enrich artwork and metadata.') }}
          </p>
        </template>

        <!-- Market value progress — only shown once the queue has been started -->
        <template v-if="marketAvailable && marketTokenAvailable && autoFetchMarketRates && result.created > 0 && (marketQueue.running.value || marketQueue.total.value > 0)">
          <div class="import-enrich">
            <p class="import-enrich__label">
              <template v-if="marketQueue.finished.value">
                <!-- TRANSLATORS: {service} is the name of the price service, Discogs or PriceCharting -->
                {{ n('crate', 'Market rates complete — {done} of %n item priced via {service}.', 'Market rates complete — {done} of %n items priced via {service}.', marketQueue.total.value, { done: marketQueue.done.value, service: marketServiceName }) }}
                <template v-if="marketQueue.failed.value > 0">
                  {{ n('crate', '%n had no listing.', '%n had no listing.', marketQueue.failed.value) }}
                </template>
              </template>
              <template v-else>
                <!-- TRANSLATORS: {service} is the name of the price service, Discogs or PriceCharting -->
                {{ t('crate', 'Fetching market rates from {service}… {done} / {total}', { service: marketServiceName, done: marketQueue.done.value, total: marketQueue.total.value }) }}
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
            {{ t('crate', 'Done') }}
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
import { n, splitAround, splitAt, t } from '../l10n.js'
import { FIELD_CONFIG, categoryLabel, fieldLabels } from '../utils/categoryFormats.js'

const props = defineProps({
  show:                  { type: Boolean, required: true },
  category:              { type: String,  default: 'music' },
  // Shared mode: when set to a shared collection's owner uid, imported rows are
  // created in that owner's collection (the caller must hold a read/write share
  // covering `category`). The category selector is then locked to `category`
  // so the rows can't be routed to a category the share doesn't cover.
  owner:                 { type: String,  default: null },
  // One flag per enrichment / market-value provider. Open Library (books)
  // doesn't need a key, so there's no prop for it.
  hasDiscogsToken:       { type: Boolean, default: false },
  hasTmdbToken:          { type: Boolean, default: false },
  hasRawgKey:            { type: Boolean, default: false },
  hasComicVineKey:       { type: Boolean, default: false },
  hasPriceChartingToken: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'imported'])

// Shared queues (survive modal close/reopen)
const enrich = useEnrichQueue()
const marketQueue = useMarketValueQueue()
const { autoEnrichOnImport, autoFetchMarketRates, marketCurrency } = useSettings()

// ── state ────────────────────────────────────────────────────────────────────
const step = ref('pick')
const selectedCategory = ref(props.category)
const selectedFile = ref(null)
const dragging = ref(false)
const pickingFromNc = ref(false)
const parsing = ref(false)
// A commit can run for minutes. Without this the button stays live and a second
// click starts a second /import/commit that races the first past the server's
// duplicate check.
const importing = ref(false)
const parseError = ref('')
const mappingError = ref('')

const headers = ref([])
const previewRows = ref([])
const totalRows = ref(0)
const mapping = ref({}) // colIndex => field name or ''

const result = ref(null)

// ── field options for mapping UI (category-aware labels) ──────────────────────
const mappableFields = computed(() => {
  const labels = fieldLabels(selectedCategory.value)
  const formatFieldLabel = (selectedCategory.value === 'game')
    ? t('crate', 'Format / Platform')
    : t('crate', 'Format')
  // TRANSLATORS: {owned} and {wanted} are the values a file's status column takes; they stay in English
  const statusFieldLabel = t('crate', 'Status ({owned} / {wanted})', {
    owned: 'owned',
    wanted: 'wanted',
  })
  return [
    { value: 'artist',    label: labels.artist },
    { value: 'title',     label: labels.title },
    { value: 'format',    label: formatFieldLabel },
    { value: 'year',      label: t('crate', 'Year') },
    { value: 'notes',     label: t('crate', 'Notes') },
    { value: 'status',    label: statusFieldLabel },
    // TRANSLATORS: an import column holding a star rating, a whole number from 1 to 5
    { value: 'rating',    label: t('crate', 'Rating (1–5)') },
    { value: 'discogsId', label: t('crate', 'Enrichment ID') },
    { value: 'barcode',   label: labels.barcode },
    { value: 'label',     label: labels.label },
    { value: 'category',  label: t('crate', 'Category') },
    // /import/preview auto-detects these two and the modal posts its mapping
    // back verbatim, so without matching options a Crate export of its own
    // price-bearing data is rejected wholesale.
    { value: 'purchasePrice',         label: t('crate', 'Purchase price') },
    { value: 'purchasePriceCurrency', label: t('crate', 'Purchase currency') },
  ]
})

// TRANSLATORS: the column-mapping choice that leaves a column out of the import
const ignoreLabel = computed(() => t('crate', 'ignore'))

/** What a column is mapped to, named as the mapping dropdown names it. */
function mappedFieldLabel(field) {
  if (!field) return ignoreLabel.value
  return mappableFields.value.find(f => f.value === field)?.label ?? field
}

// The mapping step's hint, cut around the "ignore" choice it shows in italics.
const mapHint = computed(() => {
  // TRANSLATORS: {ignore} is the column-mapping choice "ignore", shown in italics
  return splitAt(t('crate', 'Map your columns to Crate fields. Columns marked {ignore} will be skipped.'), 'ignore')
})

// Per-category prose for the step 1 hints.
const hintLead = computed(() => {
  switch (selectedCategory.value) {
    case 'film':
      return t('crate', 'One row per film. If you own the same film on multiple formats (Blu-ray, DVD, etc.), add a row for each.')
    case 'book':
      return t('crate', 'One row per book.')
    case 'game':
      return t('crate', 'One row per game. If you own the same game on multiple platforms, add a row for each.')
    case 'comic':
      return t('crate', 'One row per issue or volume.')
    default:
      return t('crate', 'One row per album. If you own the same album on multiple formats, add a row for each. A Discogs collection export (CSV) imports as it is.')
  }
})

// The column names to give the file. They stay in English, because the server
// matches the headers against them, and come from the same FIELD_CONFIG the
// column-mapper uses, so the required-columns list always matches what the
// mapping step will accept.
const hintRequired = computed(() => {
  const cfg = FIELD_CONFIG[selectedCategory.value] ?? FIELD_CONFIG.music
  return `${cfg.artist}, ${cfg.title}, Format`
})

const hintOptional = computed(() => {
  const cfg = FIELD_CONFIG[selectedCategory.value] ?? FIELD_CONFIG.music
  return ['Year', 'Notes', 'Status', 'Rating', 'EnrichmentId', cfg.barcode, cfg.label].join(', ')
})

// Reset mapping when category changes (field labels differ)
watch(selectedCategory, () => { mapping.value = {} })

const mappingValid = computed(() => {
  const mapped = Object.values(mapping.value).filter(Boolean)
  return mapped.includes('artist') && mapped.includes('title') && mapped.includes('format')
})

// ── per-category enrichment + market-value availability ──────────────────────
// Each category has its own upstream enrichment service; Open Library
// (books) needs no key, the rest do. Films and books have no market-value
// source at all.
const ENRICH_SERVICE = {
  music: 'Discogs',
  film:  'TMDB',
  book:  'Open Library',
  game:  'RAWG',
  comic: 'ComicVine',
}
const enrichServiceName = computed(() => ENRICH_SERVICE[selectedCategory.value] ?? ENRICH_SERVICE.music)

/** Whether the user has the credential needed to enrich this category. */
const enrichTokenAvailable = computed(() => {
  switch (selectedCategory.value) {
    case 'music': return props.hasDiscogsToken
    case 'film':  return props.hasTmdbToken
    case 'book':  return true   // Open Library doesn't require a key
    case 'game':  return props.hasRawgKey
    case 'comic': return props.hasComicVineKey
    default:      return false
  }
})

const CATEGORIES_WITH_MARKET = ['music', 'game', 'comic']
/** Whether the category supports fetching market values at all. */
const marketAvailable = computed(() => CATEGORIES_WITH_MARKET.includes(selectedCategory.value))

const marketServiceName = computed(() => {
  if (selectedCategory.value === 'music') return 'Discogs'
  if (['game', 'comic'].includes(selectedCategory.value)) return 'PriceCharting'
  return ''
})

/** Whether the user has the credential needed for market values. */
const marketTokenAvailable = computed(() => {
  if (selectedCategory.value === 'music') return props.hasDiscogsToken
  if (['game', 'comic'].includes(selectedCategory.value)) return props.hasPriceChartingToken
  return false
})

// The warning under the enrichment progress bar, cut around its bold part.
const navigateWarning = computed(() => {
  const text = t('crate', '{strongStart}Do not navigate away from Crate{strongEnd} or the browser tab, or progress will be lost.')
  return splitAround(text, 'strongStart', 'strongEnd')
})

// ── reset on open ─────────────────────────────────────────────────────────────
// The modal is mounted only while open, so the first "open" is the mount itself
// and never arrives as a change.
watch(() => props.show, (open) => {
  if (open) reset()
}, { immediate: true })

function reset() {
  step.value = 'pick'
  selectedCategory.value = props.category
  selectedFile.value = null
  parsing.value = false
  importing.value = false
  dragging.value = false
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
    parseError.value = t('crate', 'Nextcloud file picker is not available.')
    return
  }
  oc.dialogs.filepicker(
    t('crate', 'Select CSV or XLSX file'),
    async (path) => {
      pickingFromNc.value = true
      try {
        const uid = encodeURIComponent(oc.currentUser ?? '')
        const safePath = path.split('/').map(encodeURIComponent).join('/')
        const webdavUrl = `/remote.php/dav/files/${uid}${safePath}`
        const resp = await axios.get(webdavUrl, { responseType: 'arraybuffer' })
        const fileName = path.split('/').pop() || 'import'
        const mime = resp.headers['content-type'] || 'application/octet-stream'
        selectedFile.value = new File([resp.data], fileName, { type: mime })
        parseError.value = ''
      } catch {
        parseError.value = t('crate', 'Failed to fetch file from Nextcloud.')
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
    parseError.value = e.response?.data?.ocs?.data?.error ?? t('crate', 'Failed to parse file.')
  } finally {
    parsing.value = false
  }
}

// ── step 2 → 3: commit import ─────────────────────────────────────────────────
async function doImport() {
  if (importing.value) return
  importing.value = true
  mappingError.value = ''
  try {
    const fd = new FormData()
    fd.append('file', selectedFile.value)
    // Serialise mapping: colIndex => field or ''
    const mappingObj = {}
    Object.keys(mapping.value).forEach(k => { mappingObj[k] = mapping.value[k] || '' })
    fd.append('mapping', JSON.stringify(mappingObj))
    fd.append('category', selectedCategory.value)
    // Shared mode: create the rows in the owner's collection (backend checks
    // the caller holds a read/write share covering this category).
    if (props.owner) fd.append('owner', props.owner)

    const res = await axios.post(generateOcsUrl('/apps/crate/api/v1/import/commit'), fd)
    result.value = res.data.ocs?.data
    step.value = 'results'

    emit('imported')

    const newIds = result.value.itemIds ?? []
    const doEnrich = enrichTokenAvailable.value && autoEnrichOnImport.value && newIds.length > 0
    const doMarket = marketAvailable.value && marketTokenAvailable.value
      && autoFetchMarketRates.value && newIds.length > 0

    if (doEnrich) {
      enrich.start(newIds).then(() => {
        if (doMarket) marketQueue.start(newIds, marketCurrency.value)
      })
    } else if (doMarket) {
      marketQueue.start(newIds, marketCurrency.value)
    }
  } catch (e) {
    mappingError.value = e.response?.data?.ocs?.data?.error ?? t('crate', 'Import failed.')
  } finally {
    importing.value = false
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
  width: 100%;
  box-sizing: border-box;
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
  color: #fbbf24;
}

.import-error {
  font-size: 0.875em;
  color: var(--color-error);
  margin: 8px 0;
}

/* Category selector */
.import-field {
  margin-bottom: 14px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.import-label {
  font-size: 0.8em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-maxcontrast);
}

.import-select {
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-background-dark);
  color: var(--color-main-text);
  padding: 8px 10px;
  font-size: 0.9em;
  font-family: inherit;
  width: 200px;
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
  color: #4ade80;
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
