<template>
  <NcModal
    :show="show"
    label-id="crate-modal-title"
    @close="$emit('close')"
  >
    <div class="crate-modal">
      <h2 id="crate-modal-title">
        {{ title }}
      </h2>
      <form @submit.prevent="submit">
        <div class="crate-field">
          <label for="field-artist">Artist <span class="required">*</span></label>
          <input
            id="field-artist"
            v-model="form.artist"
            type="text"
            required
            placeholder="e.g. Pink Floyd"
          >
        </div>

        <div class="crate-field">
          <label for="field-title">Album <span class="required">*</span></label>
          <input
            id="field-title"
            v-model="form.title"
            type="text"
            required
            placeholder="e.g. The Dark Side of the Moon"
          >
        </div>

        <div class="crate-field">
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
              Select format
            </option>
            <option
              v-for="fmt in formats"
              :key="fmt"
              :value="fmt"
            >
              {{ fmt }}
            </option>
          </select>
        </div>

        <div class="crate-field">
          <label for="field-year">Year</label>
          <input
            id="field-year"
            v-model.number="form.year"
            type="number"
            min="1877"
            :max="currentYear"
            placeholder="e.g. 1973"
          >
        </div>

        <div class="crate-field">
          <label for="field-notes">Notes</label>
          <textarea
            id="field-notes"
            v-model="form.notes"
            rows="3"
            placeholder="Condition, pressing info, etc."
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
            {{ saving ? 'Saving…' : (item ? 'Save changes' : 'Add item') }}
          </NcButton>
        </div>
      </form>
    </div>
  </NcModal>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { NcModal, NcButton } from '@nextcloud/vue'

const props = defineProps({
  show: { type: Boolean, required: true },
  item: { type: Object, default: null },
  defaultStatus: { type: String, default: 'owned' },
})

const emit = defineEmits(['close', 'save'])

const formats = ['Vinyl', 'CD', 'SACD', 'Cassette', 'MiniDisc']
const currentYear = new Date().getFullYear()

const saving = ref(false)

const blankForm = () => ({
  title: '',
  artist: '',
  format: '',
  year: null,
  notes: '',
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
          }
        : blankForm()
    }
  },
)

const title = computed(() => props.item ? 'Edit item' : 'Add item')

async function submit() {
  saving.value = true
  try {
    const payload = {
      ...form.value,
      year: form.value.year || null,
      notes: form.value.notes || null,
      status: props.item ? props.item.status : props.defaultStatus,
    }
    emit('save', payload)
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.crate-modal {
  padding: 20px 24px 24px;
  min-width: 380px;
}

.crate-modal h2 {
  margin-top: 0;
  margin-bottom: 20px;
  font-size: 1.2em;
}

.crate-field {
  display: flex;
  flex-direction: column;
  margin-bottom: 14px;
}

.crate-field label {
  font-size: 0.875em;
  font-weight: 500;
  margin-bottom: 4px;
  color: var(--color-main-text);
}

.crate-field .required {
  color: var(--color-error);
}

.crate-field input,
.crate-field select,
.crate-field textarea {
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 6px 10px;
  font-size: 1em;
  font-family: inherit;
}

.crate-field input:focus,
.crate-field select:focus,
.crate-field textarea:focus {
  border-color: var(--color-primary-element);
  outline: none;
}

.crate-modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 20px;
}
</style>
