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
                Wishlist
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

        <!-- Barcode / ISBN (music + books only) -->
        <div
          v-if="fieldConfig.showBarcode"
          class="crate-field"
        >
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
import { FORMAT_GROUPS, FIELD_CONFIG, CATEGORY_LABELS } from '../utils/categoryFormats.js'

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
const discogsThumbnailUrl = ref(null)
const removeArtworkFlag = ref(false)  // user clicked "Remove" — wipe artwork entirely
const replaceArtworkFlag = ref(false) // Discogs switch — delete stale cache then PUT new URL

const hasArtwork = computed(() => {
  if (artworkPreviewUrl.value || discogsThumbnailUrl.value) return true
  if (removeArtworkFlag.value) return false
  return !!(props.item?.artworkPath)
})

const previewStyle = computed(() => {
  if (artworkPreviewUrl.value) {
    return { backgroundImage: `url(${artworkPreviewUrl.value})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  if (discogsThumbnailUrl.value) {
    return { backgroundImage: `url(${discogsThumbnailUrl.value})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  if (props.item?.id && props.item?.artworkPath && !removeArtworkFlag.value) {
    const url = generateUrl('/apps/crate/artwork/' + props.item.id)
    return { backgroundImage: `url(${url}?t=${Date.now()})`, backgroundSize: 'cover', backgroundPosition: 'center' }
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
    alert('File picker not available.')
    return
  }
  oc.dialogs.filepicker(
    'Select artwork',
    async (path) => {
      try {
        const uid = oc.currentUser ?? ''
        const webdavUrl = `/remote.php/dav/files/${uid}${path}`
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
  discogsThumbnailUrl.value = null
  if (artworkPreviewUrl.value) {
    URL.revokeObjectURL(artworkPreviewUrl.value)
    artworkPreviewUrl.value = null
  }
}

function resetArtworkState() {
  artworkFile.value = null
  removeArtworkFlag.value = false
  replaceArtworkFlag.value = false
  discogsThumbnailUrl.value = null
  if (artworkPreviewUrl.value) {
    URL.revokeObjectURL(artworkPreviewUrl.value)
    artworkPreviewUrl.value = null
  }
}

// ── Form ───────────────────────────────────────────────────────────────────────
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
})

const form = ref(blankForm())

watch(
  () => props.show,
  (open) => {
    if (open) {
      resetArtworkState()
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
          }
        : blankForm()
    }
  },
)

// Reset format when category changes (format lists are incompatible across categories)
watch(() => form.value.category, (newCat, oldCat) => {
  if (newCat !== oldCat) {
    form.value.format = ''
    discogsThumbnailUrl.value = null
  }
})

// ── Per-category computed ──────────────────────────────────────────────────────
const fieldConfig = computed(() => FIELD_CONFIG[form.value.category] ?? FIELD_CONFIG.music)
const formatGroups = computed(() => FORMAT_GROUPS[form.value.category] ?? FORMAT_GROUPS.music)

const modalTitle = computed(() => {
  const cat = CATEGORY_LABELS[form.value.category] ?? 'item'
  return props.item ? `Edit ${cat.slice(0, -1)}` : `Add ${cat.slice(0, -1)}`
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

// ── Discogs apply ──────────────────────────────────────────────────────────────
function applyDiscogs(result) {
  form.value.artist = result.artist || form.value.artist
  form.value.title = result.title || form.value.title
  form.value.format = result.format || form.value.format
  form.value.year = result.year || form.value.year
  form.value.discogsId = result.discogsId || null
  form.value.artworkPath = result.thumb || null
  discogsThumbnailUrl.value = result.thumb || null
  // If the item already has artwork (local file or cached Discogs URL), flag for
  // pre-save deletion so stale cache is cleared before the PUT sets the new artworkPath.
  if (result.thumb && props.item?.artworkPath) {
    artworkFile.value = null
    replaceArtworkFlag.value = true
  }
}

// ── TMDB apply (films) ─────────────────────────────────────────────────────────
function applyTmdb(result) {
  form.value.artist    = result.artist    || form.value.artist
  form.value.title     = result.title     || form.value.title
  form.value.year      = result.year      || form.value.year
  form.value.label     = result.label     || form.value.label
  form.value.discogsId = result.tmdbId    || null
  if (result.artworkUrl || result.thumb) {
    form.value.artworkPath = result.artworkUrl || result.thumb
    discogsThumbnailUrl.value = result.thumb || result.artworkUrl
    if (props.item?.artworkPath) {
      artworkFile.value = null
      replaceArtworkFlag.value = true
    }
  }
}

// ── Open Library apply (books) ─────────────────────────────────────────────────
function applyOpenLibrary(result) {
  form.value.artist    = result.artist    || form.value.artist
  form.value.title     = result.title     || form.value.title
  form.value.year      = result.year      || form.value.year
  form.value.label     = result.label     || form.value.label
  form.value.barcode   = result.barcode   || form.value.barcode
  form.value.discogsId = result.workKey   || null
  if (result.artworkUrl || result.thumb) {
    form.value.artworkPath = result.artworkUrl || result.thumb
    discogsThumbnailUrl.value = result.artworkUrl || result.thumb
    if (props.item?.artworkPath) {
      artworkFile.value = null
      replaceArtworkFlag.value = true
    }
  }
}

// ── RAWG apply (games) ─────────────────────────────────────────────────────────
function applyRawg(result) {
  form.value.artist    = result.artist    || form.value.artist
  form.value.title     = result.title     || form.value.title
  form.value.year      = result.year      || form.value.year
  form.value.label     = result.label     || form.value.label
  form.value.discogsId = result.rawgId    || null
  if (result.artworkUrl || result.thumb) {
    form.value.artworkPath = result.artworkUrl || result.thumb
    discogsThumbnailUrl.value = result.artworkUrl || result.thumb
    if (props.item?.artworkPath) {
      artworkFile.value = null
      replaceArtworkFlag.value = true
    }
  }
}

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

// ── ComicVine apply (comics) ───────────────────────────────────────────────────
function applyComicVine(result) {
  form.value.title     = result.title     || form.value.title
  form.value.year      = result.year      || form.value.year
  form.value.label     = result.label     || form.value.label
  form.value.discogsId = result.comicVineId || null
  if (result.artworkUrl || result.thumb) {
    form.value.artworkPath = result.artworkUrl || result.thumb
    discogsThumbnailUrl.value = result.artworkUrl || result.thumb
    if (props.item?.artworkPath) {
      artworkFile.value = null
      replaceArtworkFlag.value = true
    }
  }
}

// ── Submit ─────────────────────────────────────────────────────────────────────
async function submit() {
  saving.value = true
  try {
    const payload = {
      ...form.value,
      year:            form.value.year || null,
      notes:           form.value.notes || null,
      label:           form.value.label || null,
      barcode:         form.value.barcode || null,
      _artworkFile:    artworkFile.value,
      _removeArtwork:  removeArtworkFlag.value,
      _replaceArtwork: replaceArtworkFlag.value,
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
