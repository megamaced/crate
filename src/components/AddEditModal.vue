<template>
  <NcModal
    :show="show"
    label-id="crate-modal-title"
    size="normal"
    @close="$emit('close')"
  >
    <div class="crate-modal">
      <h2 id="crate-modal-title">
        {{ title }}
      </h2>

      <!-- Discogs search -->
      <DiscogsSearch @select="applyDiscogs" />

      <form @submit.prevent="submit">
        <!-- Two-column row: Artist + Format -->
        <div class="crate-row">
          <div class="crate-field crate-field--grow">
            <label for="field-artist">Artist <span class="required">*</span></label>
            <input
              id="field-artist"
              v-model="form.artist"
              type="text"
              required
              placeholder="e.g. Pink Floyd"
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

        <div class="crate-field">
          <label for="field-title">Album / Title <span class="required">*</span></label>
          <input
            id="field-title"
            v-model="form.title"
            type="text"
            required
            placeholder="e.g. The Dark Side of the Moon"
            autocomplete="off"
          >
        </div>

        <!-- Two-column row: Year + Status -->
        <div class="crate-row">
          <div class="crate-field crate-field--year">
            <label for="field-year">Year</label>
            <input
              id="field-year"
              v-model.number="form.year"
              type="number"
              min="1857"
              :max="currentYear"
              placeholder="e.g. 1973"
            >
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

        <div class="crate-field">
          <label for="field-notes">Notes</label>
          <textarea
            id="field-notes"
            v-model="form.notes"
            rows="3"
            placeholder="Condition, pressing info, purchase details…"
          />
        </div>

        <!-- Album Art -->
        <div class="crate-field">
          <label>Album Art</label>
          <div class="crate-artwork-row">
            <div
              class="crate-artwork-thumb"
              :style="previewStyle"
            >
              <span
                v-if="!hasArtwork"
                class="crate-artwork-placeholder"
              >♪</span>
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
import { generateUrl } from '@nextcloud/router'
import DiscogsSearch from './DiscogsSearch.vue'

const props = defineProps({
  show: { type: Boolean, required: true },
  item: { type: Object, default: null },
  defaultStatus: { type: String, default: 'owned' },
})

const emit = defineEmits(['close', 'save'])

const formatGroups = [
  {
    label: 'Vinyl',
    formats: ['Vinyl', '7" Single', '10"', '12" Single', 'Picture Disc', 'Flexi-disc', 'Shellac', 'Lathe Cut'],
  },
  {
    label: 'Tape',
    formats: ['Cassette', '8-Track', 'Reel-to-Reel', 'DAT', 'DCC', '4-Track Cartridge', 'Microcassette'],
  },
  {
    label: 'Disc',
    formats: ['CD', 'SACD', 'CD-R', 'SHM-CD', 'HDCD', 'CDV', 'Blu-ray Audio', 'DVD-Audio', 'LaserDisc', 'MiniDisc'],
  },
]

const currentYear = new Date().getFullYear()
const saving = ref(false)

// ── Artwork ────────────────────────────────────────────────────────────────
const fileInput = ref(null)
const artworkFile = ref(null) // File object awaiting upload
const artworkPreviewUrl = ref(null) // blob URL for local preview
const removeArtworkFlag = ref(false)

const hasArtwork = computed(() => {
  if (artworkPreviewUrl.value) return true
  if (removeArtworkFlag.value) return false
  return !!(props.item?.artworkPath)
})

const previewStyle = computed(() => {
  if (artworkPreviewUrl.value) {
    return { backgroundImage: `url(${artworkPreviewUrl.value})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  if (props.item?.id && props.item?.artworkPath && !removeArtworkFlag.value) {
    const url = generateUrl('/apps/crate/artwork/' + props.item.id)
    return { backgroundImage: `url(${url}?t=${Date.now()})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  return {}
})

function onFileSelected(e) {
  const file = e.target.files[0]
  if (!file) return
  if (artworkPreviewUrl.value) URL.revokeObjectURL(artworkPreviewUrl.value)
  artworkFile.value = file
  artworkPreviewUrl.value = URL.createObjectURL(file)
  removeArtworkFlag.value = false
  // Reset the input so picking the same file again fires the event
  e.target.value = ''
}

function pickFromNextcloud() {
  // Use the Nextcloud built-in file picker (always available, no extra chunks)
  const oc = window.OC
  if (!oc?.dialogs?.filepicker) {
    alert('File picker not available.')
    return
  }
  oc.dialogs.filepicker(
    'Select album artwork',
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
      }
    },
    false, // not multiselect
    ['image/jpeg', 'image/png', 'image/webp'], // MIME filter
    true, // modal
  )
}

function doRemoveArtwork() {
  artworkFile.value = null
  removeArtworkFlag.value = true
  if (artworkPreviewUrl.value) {
    URL.revokeObjectURL(artworkPreviewUrl.value)
    artworkPreviewUrl.value = null
  }
}

function resetArtworkState() {
  artworkFile.value = null
  removeArtworkFlag.value = false
  if (artworkPreviewUrl.value) {
    URL.revokeObjectURL(artworkPreviewUrl.value)
    artworkPreviewUrl.value = null
  }
}

const blankForm = () => ({
  title: '',
  artist: '',
  format: '',
  year: null,
  notes: '',
  status: props.defaultStatus,
  discogsId: null,
  artworkPath: null,
})

const form = ref(blankForm())

watch(
  () => props.show,
  (open) => {
    if (open) {
      resetArtworkState()
      form.value = props.item
        ? {
            title: props.item.title,
            artist: props.item.artist,
            format: props.item.format,
            year: props.item.year ?? null,
            notes: props.item.notes ?? '',
            status: props.item.status ?? props.defaultStatus,
            discogsId: props.item.discogsId ?? null,
            artworkPath: props.item.artworkPath ?? null,
          }
        : blankForm()
    }
  },
)

const title = computed(() => props.item ? 'Edit item' : 'Add item')

function applyDiscogs(result) {
  form.value.artist = result.artist || form.value.artist
  form.value.title = result.title || form.value.title
  form.value.format = result.format || form.value.format
  form.value.year = result.year || form.value.year
  form.value.discogsId = result.discogsId || null
  form.value.artworkPath = result.thumb || null
}

async function submit() {
  saving.value = true
  try {
    const payload = {
      ...form.value,
      year: form.value.year || null,
      notes: form.value.notes || null,
      _artworkFile: artworkFile.value,
      _removeArtwork: removeArtworkFlag.value,
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
