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

      <!-- Discogs search (add mode only) -->
      <DiscogsSearch
        v-if="!item"
        @select="applyDiscogs"
      />

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

        <div class="crate-modal-actions">
          <NcButton
            type="tertiary"
            @click="$emit('close')"
          >
            Cancel
          </NcButton>
          <NcButton
            native-type="submit"
            type="primary"
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
    formats: ['CD', 'SACD', 'CD-R', 'SHM-CD', 'HDCD', 'CDV', 'Blu-ray Audio', 'DVD-Audio', 'LaserDisc'],
  },
  {
    label: 'Other',
    formats: ['MiniDisc'],
  },
]

const currentYear = new Date().getFullYear()
const saving = ref(false)

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
</style>
