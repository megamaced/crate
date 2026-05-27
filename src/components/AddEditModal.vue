<template>
  <NcModal
    :show="show"
    label-id="crate-modal-title"
    size="normal"
    @close="$emit('close')"
  >
    <div class="crate-modal">
      <h2 id="crate-modal-title">
        {{ modalTitle }}
      </h2>

      <!-- Enrichment search — component varies by category -->
      <DiscogsSearch
        v-if="form.category === 'music'"
        :has-token="hasToken"
        @select="applyDiscogs"
      />
      <TMDBSearch
        v-else-if="form.category === 'film'"
        :has-token="hasTmdbToken"
        @select="applyTmdb"
      />
      <OpenLibrarySearch
        v-else-if="form.category === 'book'"
        @select="applyOpenLibrary"
      />
      <RAWGSearch
        v-else-if="form.category === 'game'"
        :has-key="hasRawgKey"
        @select="applyRawg"
      />
      <ComicVineSearch
        v-else-if="form.category === 'comic'"
        :has-key="hasComicVineKey"
        @select="applyComicVine"
      />

      <form @submit.prevent="submit">
        <!-- Category picker -->
        <div class="crate-row">
          <div class="crate-field crate-field--grow">
            <label for="field-category">Category</label>
            <select
              id="field-category"
              v-model="form.category"
            >
              <option value="music">
                Music
              </option>
              <option value="film">
                Films
              </option>
              <option value="book">
                Books
              </option>
              <option value="game">
                Games
              </option>
              <option value="comic">
                Comics
              </option>
            </select>
          </div>

          <div class="crate-field crate-field--grow">
            <label for="field-status">Status</label>
            <select
              id="field-status"
              v-model="form.status"
            >
              <option value="owned">
                Owned
              </option>
              <option value="wanted">
                Wanted
              </option>
            </select>
          </div>
        </div>

        <!-- Artist / Director / Author / Developer + Format -->
        <div class="crate-row">
          <div class="crate-field crate-field--grow">
            <label for="field-artist">{{ fieldConfig.artist }} <span class="required">*</span></label>
            <input
              id="field-artist"
              v-model="form.artist"
              type="text"
              required
              :placeholder="artistPlaceholder"
              autocomplete="off"
            >
          </div>

          <div class="crate-field crate-field--format">
            <label for="field-format">Format <span class="required">*</span></label>
            <select
              id="field-format"
              v-model="form.format"
              required
            >
              <option
                value=""
                disabled
              >
                Select…
              </option>
              <optgroup
                v-for="group in formatGroups"
                :key="group.label"
                :label="group.label"
              >
                <option
                  v-for="fmt in group.formats"
                  :key="fmt"
                  :value="fmt"
                >
                  {{ fmt }}
                </option>
              </optgroup>
            </select>
          </div>
        </div>

        <!-- Title -->
        <div class="crate-field">
          <label for="field-title">{{ fieldConfig.title }} <span class="required">*</span></label>
          <input
            id="field-title"
            v-model="form.title"
            type="text"
            required
            :placeholder="titlePlaceholder"
            autocomplete="off"
          >
        </div>

        <!-- Year + Label -->
        <div class="crate-row">
          <div class="crate-field crate-field--year">
            <label for="field-year">Year</label>
            <input
              id="field-year"
              v-model.number="form.year"
              type="number"
              min="1800"
              :max="currentYear"
              placeholder="e.g. 1973"
            >
          </div>

          <div class="crate-field crate-field--grow">
            <label for="field-label">{{ fieldConfig.label }}</label>
            <input
              id="field-label"
              v-model="form.label"
              type="text"
              :placeholder="labelPlaceholder"
              autocomplete="off"
            >
          </div>
        </div>

        <!-- Barcode / ISBN — shown for every category so a user can manually
             record one even when the upstream search service doesn't return it
             (TMDB / RAWG / ComicVine don't expose barcodes, but the user may
             still want to track UPC / SKU / etc.). -->
        <div class="crate-field">
          <label for="field-barcode">{{ fieldConfig.barcode }}</label>
          <div
            v-if="form.category === 'book'"
            class="crate-barcode-row"
          >
            <input
              id="field-barcode"
              v-model="form.barcode"
              type="text"
              :placeholder="barcodePlaceholder"
              autocomplete="off"
              @keydown.enter.prevent="lookupIsbn"
            >
            <NcButton
              type="button"
              variant="secondary"
              :disabled="!form.barcode || isbnLooking"
              @click="lookupIsbn"
            >
              {{ isbnLooking ? 'Looking up…' : 'Look up' }}
            </NcButton>
          </div>
          <input
            v-else
            id="field-barcode"
            v-model="form.barcode"
            type="text"
            :placeholder="barcodePlaceholder"
            autocomplete="off"
          >
        </div>

        <!-- Purchase price + currency (what the user paid) -->
        <div class="crate-row">
          <div class="crate-field crate-field--price">
            <label for="field-purchase-price">Original price</label>
            <input
              id="field-purchase-price"
              v-model.number="form.purchasePrice"
              type="number"
              min="0"
              step="0.01"
              placeholder="e.g. 24.99"
              autocomplete="off"
            >
          </div>

          <div class="crate-field crate-field--currency">
            <label for="field-purchase-currency">Currency</label>
            <select
              id="field-purchase-currency"
              v-model="form.purchasePriceCurrency"
              :disabled="!form.purchasePrice"
            >
              <option
                v-for="code in currencyOptions"
                :key="code"
                :value="code"
              >
                {{ code }}
              </option>
            </select>
          </div>
        </div>

        <div class="crate-field">
          <label for="field-notes">Notes</label>
          <textarea
            id="field-notes"
            v-model="form.notes"
            rows="3"
            placeholder="Condition, purchase details, thoughts…"
          />
        </div>

        <!-- Artwork -->
        <div class="crate-field">
          <label>Artwork</label>
          <div class="crate-artwork-row">
            <div
              class="crate-artwork-thumb"
              :style="previewStyle"
            >
              <span
                v-if="!hasArtwork"
                class="crate-artwork-placeholder"
              >{{ artworkPlaceholder }}</span>
            </div>
            <div class="crate-artwork-controls">
              <input
                ref="fileInput"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                style="display:none"
                @change="onFileSelected"
              >
              <NcButton
                type="button"
                variant="tertiary"
                native-type="button"
                @click="fileInput.click()"
              >
                Upload from computer
              </NcButton>
              <NcButton
                type="button"
                variant="tertiary"
                native-type="button"
                @click="pickFromNextcloud"
              >
                Pick from Files
              </NcButton>
              <NcButton
                v-if="hasArtwork"
                type="button"
                variant="tertiary"
                native-type="button"
                @click="doRemoveArtwork"
              >
                Remove
              </NcButton>
            </div>
          </div>
        </div>

        <!--
          Extra photo slots: disc shots, receipts, sleevenotes, etc. Distinct
          from the artwork above — the artwork is the canonical cover art and
          may be supplied by an enrichment source, while these are always
          user-uploaded.
        -->
        <div class="crate-field">
          <label>Additional photos</label>
          <div class="crate-photos-row">
            <PhotoSlot
              :slot-num="1"
              :file="photo1File"
              :remove="photo1Remove"
              :existing="!!item?.hasPhoto1"
              :item-id="item?.id"
              :updated-at="item?.updatedAt"
              @pick="handlePhotoPicked(1, $event)"
              @remove="handlePhotoRemoved(1)"
            />
            <PhotoSlot
              :slot-num="2"
              :file="photo2File"
              :remove="photo2Remove"
              :existing="!!item?.hasPhoto2"
              :item-id="item?.id"
              :updated-at="item?.updatedAt"
              @pick="handlePhotoPicked(2, $event)"
              @remove="handlePhotoRemoved(2)"
            />
          </div>
        </div>

        <div class="crate-modal-actions">
          <NcButton
            type="button"
            variant="tertiary"
            @click="$emit('close')"
          >
            Cancel
          </NcButton>
          <NcButton
            type="submit"
            variant="primary"
            :disabled="saving"
          >
            {{ saving ? 'Saving…' : (item ? 'Save changes' : 'Add to collection') }}
          </NcButton>
        </div>
      </form>
    </div>
  </NcModal>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { NcModal, NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import ComicVineSearch from './ComicVineSearch.vue'
import DiscogsSearch from './DiscogsSearch.vue'
import TMDBSearch from './TMDBSearch.vue'
import OpenLibrarySearch from './OpenLibrarySearch.vue'
import RAWGSearch from './RAWGSearch.vue'
import PhotoSlot from './PhotoSlot.vue'
import { FORMAT_GROUPS, FIELD_CONFIG } from '../utils/categoryFormats.js'
import { settingsCurrencies } from '../api.js'
import { useSettings } from '../composables/useSettings.js'

const props = defineProps({
  show:          { type: Boolean, required: true },
  item:          { type: Object,  default: null },
  defaultStatus: { type: String,  default: 'owned' },
  hasToken:         { type: Boolean, default: false },
  hasTmdbToken:     { type: Boolean, default: false },
  hasRawgKey:       { type: Boolean, default: false },
  hasComicVineKey:  { type: Boolean, default: false },
  category:         { type: String,  default: 'music' },
})

const emit = defineEmits(['close', 'save'])

const currentYear = new Date().getFullYear()
const saving = ref(false)

// ── Artwork ────────────────────────────────────────────────────────────────────
const fileInput = ref(null)
const artworkFile = ref(null)
const artworkPreviewUrl = ref(null)
// URL of the artwork preview supplied by an enrichment source
// (Discogs/TMDB/Open Library/RAWG/ComicVine). Used for the modal's preview
// thumbnail before the item is saved; once saved the backend caches it
// and the local /apps/crate/artwork/{id} URL takes over.
const enrichPreviewUrl = ref(null)
const removeArtworkFlag = ref(false)  // user clicked "Remove" — wipe artwork entirely
const replaceArtworkFlag = ref(false) // Enrichment switch — delete stale cache then PUT new URL

const hasArtwork = computed(() => {
  if (artworkPreviewUrl.value || enrichPreviewUrl.value) return true
  if (removeArtworkFlag.value) return false
  return !!(props.item?.artworkPath)
})

const previewStyle = computed(() => {
  if (artworkPreviewUrl.value) {
    return { backgroundImage: `url(${artworkPreviewUrl.value})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  if (enrichPreviewUrl.value) {
    return { backgroundImage: `url(${enrichPreviewUrl.value})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  if (props.item?.id && props.item?.artworkPath && !removeArtworkFlag.value) {
    // Cache-bust on item.updatedAt so the URL is stable across renders
    // (Date.now() inside a computed defeats memoisation).
    const v = encodeURIComponent(props.item.updatedAt ?? '')
    const url = generateUrl('/apps/crate/artwork/' + props.item.id) + (v ? `?v=${v}` : '')
    return { backgroundImage: `url(${url})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  return {}
})

const artworkPlaceholder = computed(() => {
  const icons = { music: '♪', film: '🎬', book: '📖', game: '🎮', comic: '📚' }
  return icons[form.value.category] ?? '♪'
})

function onFileSelected(e) {
  const file = e.target.files[0]
  if (!file) return
  if (artworkPreviewUrl.value) URL.revokeObjectURL(artworkPreviewUrl.value)
  artworkFile.value = file
  artworkPreviewUrl.value = URL.createObjectURL(file)
  removeArtworkFlag.value = false
  e.target.value = ''
}

function pickFromNextcloud() {
  const oc = window.OC
  if (!oc?.dialogs?.filepicker) {
    showError('File picker not available.')
    return
  }
  oc.dialogs.filepicker(
    'Select artwork',
    async (path) => {
      try {
        const uid = encodeURIComponent(oc.currentUser ?? '')
        const safePath = path.split('/').map(encodeURIComponent).join('/')
        const webdavUrl = `/remote.php/dav/files/${uid}${safePath}`
        const resp = await axios.get(webdavUrl, { responseType: 'arraybuffer' })
        const mime = resp.headers['content-type']?.split(';')[0]?.trim() || 'image/jpeg'
        const fileName = path.split('/').pop() || 'artwork'
        const blob = new Blob([resp.data], { type: mime })
        if (artworkPreviewUrl.value) URL.revokeObjectURL(artworkPreviewUrl.value)
        artworkFile.value = new File([blob], fileName, { type: mime })
        artworkPreviewUrl.value = URL.createObjectURL(artworkFile.value)
        removeArtworkFlag.value = false
      } catch (e) {
        console.error('Failed to fetch artwork from Nextcloud', e)
        showError('Failed to fetch artwork')
      }
    },
    false,
    ['image/jpeg', 'image/png', 'image/webp'],
    true,
  )
}

function doRemoveArtwork() {
  artworkFile.value = null
  removeArtworkFlag.value = true
  enrichPreviewUrl.value = null
  if (artworkPreviewUrl.value) {
    URL.revokeObjectURL(artworkPreviewUrl.value)
    artworkPreviewUrl.value = null
  }
}

function resetArtworkState() {
  artworkFile.value = null
  removeArtworkFlag.value = false
  replaceArtworkFlag.value = false
  enrichPreviewUrl.value = null
  if (artworkPreviewUrl.value) {
    URL.revokeObjectURL(artworkPreviewUrl.value)
    artworkPreviewUrl.value = null
  }
}

// ── Extra photo slots ──────────────────────────────────────────────────────────
// Each slot tracks a pending File the user picked (uploaded after save) and a
// "remove" flag set when the user clicked Remove on an existing photo. The
// PhotoSlot component renders both the file preview and the existing remote
// thumb when present.
const photo1File   = ref(null)
const photo2File   = ref(null)
const photo1Remove = ref(false)
const photo2Remove = ref(false)

function resetPhotoState() {
  photo1File.value = null
  photo2File.value = null
  photo1Remove.value = false
  photo2Remove.value = false
}

function handlePhotoPicked(slot, file) {
  if (slot === 1) {
    photo1File.value = file
    photo1Remove.value = false
  } else if (slot === 2) {
    photo2File.value = file
    photo2Remove.value = false
  }
}

function handlePhotoRemoved(slot) {
  if (slot === 1) {
    photo1File.value = null
    photo1Remove.value = true
  } else if (slot === 2) {
    photo2File.value = null
    photo2Remove.value = true
  }
}

// ── Form ───────────────────────────────────────────────────────────────────────
const { marketCurrency } = useSettings()

const blankForm = () => ({
  title:      '',
  artist:     '',
  format:     '',
  year:       null,
  notes:      '',
  status:     props.defaultStatus,
  discogsId:  null,
  artworkPath: null,
  label:      null,
  barcode:    null,
  category:   props.category,
  purchasePrice:          null,
  purchasePriceCurrency:  marketCurrency.value,
})

const form = ref(blankForm())

// Currency options — supplied by the backend allowlist. Falls back to a
// minimal default if the request fails (e.g. offline), so the form remains
// usable rather than blocking save.
const currencyOptions = ref([
  'GBP', 'USD', 'EUR', 'CAD', 'AUD', 'JPY', 'CHF', 'MXN', 'BRL', 'NZD', 'SEK', 'ZAR',
])
axios.get(settingsCurrencies())
  .then((res) => {
    const list = res.data.ocs?.data
    if (Array.isArray(list) && list.length > 0) {
      currencyOptions.value = list
    }
  })
  .catch(() => { /* fall back to the static default */ })

watch(
  () => props.show,
  (open) => {
    if (open) {
      resetArtworkState()
      resetPhotoState()
      form.value = props.item
        ? {
            title:      props.item.title,
            artist:     props.item.artist,
            format:     props.item.format,
            year:       props.item.year ?? null,
            notes:      props.item.notes ?? '',
            status:     props.item.status ?? props.defaultStatus,
            discogsId:  props.item.discogsId ?? null,
            artworkPath: props.item.artworkPath ?? null,
            label:      props.item.label ?? null,
            barcode:    props.item.barcode ?? null,
            category:   props.item.category ?? props.category,
            purchasePrice:         props.item.purchasePrice ?? null,
            purchasePriceCurrency: props.item.purchasePriceCurrency ?? marketCurrency.value,
          }
        : blankForm()
    }
  },
)

// Reset format when category changes (format lists are incompatible across categories)
watch(() => form.value.category, (newCat, oldCat) => {
  if (newCat !== oldCat) {
    form.value.format = ''
    enrichPreviewUrl.value = null
  }
})

// ── Per-category computed ──────────────────────────────────────────────────────
const fieldConfig = computed(() => FIELD_CONFIG[form.value.category] ?? FIELD_CONFIG.music)
const formatGroups = computed(() => FORMAT_GROUPS[form.value.category] ?? FORMAT_GROUPS.music)

// Per-category singular noun for the modal heading. Avoids the historical
// "Music" → "Musi" bug where `slice(0, -1)` assumed every category label
// ended in an "s".
const CATEGORY_SINGULAR = {
  music: 'album',
  film:  'film',
  book:  'book',
  game:  'game',
  comic: 'comic',
}
const modalTitle = computed(() => {
  const noun = CATEGORY_SINGULAR[form.value.category] ?? 'item'
  return (props.item ? 'Edit ' : 'Add ') + noun
})

const artistPlaceholder = computed(() => {
  const examples = { music: 'e.g. Pink Floyd', film: 'e.g. Christopher Nolan', book: 'e.g. George Orwell', game: 'e.g. Nintendo', comic: 'e.g. Alan Moore' }
  return examples[form.value.category] ?? ''
})

const titlePlaceholder = computed(() => {
  const examples = { music: 'e.g. The Dark Side of the Moon', film: 'e.g. Inception', book: 'e.g. 1984', game: 'e.g. Super Mario Bros.', comic: 'e.g. Watchmen' }
  return examples[form.value.category] ?? ''
})

const labelPlaceholder = computed(() => {
  const examples = { music: 'e.g. EMI', film: 'e.g. Warner Bros.', book: 'e.g. Penguin', game: 'e.g. Nintendo', comic: 'e.g. DC Comics' }
  return examples[form.value.category] ?? ''
})

const barcodePlaceholder = computed(() => {
  return form.value.category === 'book' ? 'e.g. 978-0451524935' : 'e.g. 5099902987521'
})

// ── Enrichment apply (all sources) ─────────────────────────────────────────────
// Each enrichment source emits a normalised result; this helper merges it
// into the form. `idKey` is the source-specific id field. Per-category keys
// (format, barcode) are only copied when present in the result.
const ENRICH_ID_KEY = {
  music: 'discogsId',
  film:  'tmdbId',
  book:  'workKey',
  game:  'rawgId',
  comic: 'comicVineId',
}

function applyEnrichment(result) {
  if (result.artist) form.value.artist = result.artist
  if (result.title) form.value.title = result.title
  if (result.year) form.value.year = result.year
  if (result.format) form.value.format = result.format
  if (result.label) form.value.label = result.label
  if (result.barcode) form.value.barcode = result.barcode

  const idKey = ENRICH_ID_KEY[form.value.category]
  form.value.discogsId = (idKey && result[idKey]) || null

  const previewUrl = result.thumb || result.artworkUrl
  const fullUrl = result.artworkUrl || result.thumb
  if (fullUrl) {
    form.value.artworkPath = fullUrl
    enrichPreviewUrl.value = previewUrl
    if (props.item) {
      // Always clear any pending file upload and flag for cache replacement
      // when applying enrichment to an existing item. This ensures stale
      // cached artwork is purged even if the item currently has no artworkPath
      // (e.g. after strip enrichment).
      artworkFile.value = null
      replaceArtworkFlag.value = true
    }
  }
}

// Source-specific wrappers retained as named handlers so the templates can
// stay declarative (@select="applyDiscogs" etc.).
const applyDiscogs     = applyEnrichment
const applyTmdb        = applyEnrichment
const applyOpenLibrary = applyEnrichment
const applyRawg        = applyEnrichment

// ── ISBN lookup (books) ────────────────────────────────────────────────────────
const isbnLooking = ref(false)

async function lookupIsbn() {
  if (!form.value.barcode || isbnLooking.value) return
  isbnLooking.value = true
  try {
    const res = await axios.get(
      generateOcsUrl(`/apps/crate/api/v1/openlibrary/isbn/${encodeURIComponent(form.value.barcode.trim())}`),
    )
    const data = res.data.ocs?.data
    if (data && data.title) {
      applyOpenLibrary(data)
    } else {
      showError('ISBN not found in Open Library')
    }
  } catch {
    showError('ISBN not found in Open Library')
  } finally {
    isbnLooking.value = false
  }
}

const applyComicVine = applyEnrichment

// ── Submit ─────────────────────────────────────────────────────────────────────
async function submit() {
  saving.value = true
  try {
    // Normalise the purchase price. The backend treats null + null as "clear",
    // and rejects a non-null price without a currency. An invalid number from
    // the input (e.g. "1.2.3") parses to NaN; coerce that to null so v-model
    // doesn't send an unexpected non-number through the API.
    const rawPrice = form.value.purchasePrice
    const price = (rawPrice === null || rawPrice === '' || Number.isNaN(rawPrice))
      ? null
      : Number(rawPrice)
    const payload = {
      ...form.value,
      year:                  form.value.year || null,
      notes:                 form.value.notes || null,
      label:                 form.value.label || null,
      barcode:               form.value.barcode || null,
      purchasePrice:         price,
      purchasePriceCurrency: price !== null ? (form.value.purchasePriceCurrency || marketCurrency.value) : null,
      _artworkFile:          artworkFile.value,
      _removeArtwork:        removeArtworkFlag.value,
      _replaceArtwork:       replaceArtworkFlag.value,
      _photo1File:           photo1File.value,
      _photo2File:           photo2File.value,
      _photo1Remove:         photo1Remove.value,
      _photo2Remove:         photo2Remove.value,
    }
    emit('save', payload)
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.crate-modal {
  padding: 24px 28px 28px;
  min-width: min(440px, 90vw);
}

.crate-modal h2 {
  margin: 0 0 20px;
  font-size: 1.25em;
  font-weight: 700;
}

/* Two-column rows */
.crate-row {
  display: flex;
  gap: 14px;
  align-items: flex-end;
}

.crate-field--grow {
  flex: 1 1 0;
}

.crate-field--format {
  flex: 0 0 160px;
}

.crate-field--year {
  flex: 0 0 110px;
}

.crate-field--price {
  flex: 1 1 0;
}

.crate-field--currency {
  flex: 0 0 110px;
}

/* Two-column photo strip. Stacks below the artwork row, lays out the slots
   left-to-right. Matches the artwork-row padding so the modal stays balanced. */
.crate-photos-row {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  align-items: flex-start;
}

.crate-barcode-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.crate-barcode-row input {
  flex: 1;
}

/* Fields */
.crate-field {
  display: flex;
  flex-direction: column;
  margin-bottom: 16px;
}

.crate-field label {
  font-size: 0.8em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 6px;
  color: var(--color-text-maxcontrast);
}

.crate-field .required {
  color: var(--color-error);
}

.crate-field input,
.crate-field select,
.crate-field textarea {
  width: 100%;
  box-sizing: border-box;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-background-dark);
  color: var(--color-main-text);
  padding: 9px 12px;
  font-size: 1em;
  font-family: inherit;
  transition: border-color 0.15s;
}

.crate-field input:focus,
.crate-field select:focus,
.crate-field textarea:focus {
  border-color: var(--color-primary-element);
  outline: none;
  background: var(--color-main-background);
}

.crate-field textarea {
  resize: vertical;
  min-height: 80px;
}

.crate-modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}

@media (max-width: 480px) {
  .crate-row {
    flex-direction: column;
    gap: 0;
  }
  .crate-field--format,
  .crate-field--year {
    flex: unset;
  }
}

/* Artwork picker */
.crate-artwork-row {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}

.crate-artwork-thumb {
  flex-shrink: 0;
  width: 80px;
  height: 80px;
  border-radius: var(--border-radius);
  border: 2px solid var(--color-border-dark);
  background: var(--color-background-dark);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.crate-artwork-placeholder {
  font-size: 1.8em;
  opacity: 0.3;
}

.crate-artwork-controls {
  display: flex;
  flex-direction: column;
  gap: 6px;
  align-items: flex-start;
}
</style>
