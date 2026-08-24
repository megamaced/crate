<template>
  <NcModal
    :show="show"
    size="normal"
    label-id="share-collection-modal-title"
    @close="$emit('close')"
  >
    <div class="sc-modal">
      <h2 id="share-collection-modal-title">
        Share collection
      </h2>
      <p class="sc-hint">
        Share your whole library or individual categories with another Nextcloud
        user. Read-only lets them browse; read/write also lets them add and edit
        items (but not delete them).
      </p>

      <!-- What to share -->
      <div class="sc-field">
        <label class="sc-label">What to share</label>
        <div class="sc-checkboxes">
          <NcCheckboxRadioSwitch
            :model-value="wholeLibrary"
            @update:model-value="wholeLibrary = $event"
          >
            Whole library
          </NcCheckboxRadioSwitch>
          <NcCheckboxRadioSwitch
            v-for="cat in categoryOptions"
            :key="cat.value"
            :model-value="!wholeLibrary && selectedCategories.includes(cat.value)"
            :disabled="wholeLibrary"
            @update:model-value="toggleCategory(cat.value, $event)"
          >
            {{ cat.label }}
          </NcCheckboxRadioSwitch>
        </div>
      </div>

      <!-- Access level -->
      <div class="sc-field">
        <label class="sc-label">Access</label>
        <NcCheckboxRadioSwitch
          type="switch"
          :model-value="allowWrite"
          @update:model-value="allowWrite = $event"
        >
          Allow adding &amp; editing (read/write)
        </NcCheckboxRadioSwitch>
      </div>

      <!-- User search -->
      <div class="sc-field">
        <label class="sc-label">Share with</label>
        <p
          v-if="selectedUser"
          class="sc-selected-user"
        >
          <strong>{{ selectedUser.displayName }}</strong>
          <span class="sc-selected-uid">{{ selectedUser.uid }}</span>
          <NcButton
            variant="tertiary"
            size="small"
            @click="clearSelectedUser"
          >
            Change
          </NcButton>
        </p>
        <UserSearchField
          v-else
          :active="show"
          @select="pickUser"
        />
      </div>

      <!-- Per-target results -->
      <ul
        v-if="results.length > 0"
        class="sc-report"
      >
        <li
          v-for="r in results"
          :key="r.label"
          :class="['sc-report-row', 'sc-report-row--' + r.state]"
        >
          <span class="sc-report-label">{{ r.label }}</span>
          <span class="sc-report-msg">{{ r.message }}</span>
        </li>
      </ul>

      <div class="sc-actions">
        <NcButton
          variant="tertiary"
          @click="$emit('close')"
        >
          Close
        </NcButton>
        <NcButton
          variant="primary"
          :disabled="!canShare || sharing"
          @click="doShare"
        >
          {{ sharing ? 'Sharing…' : 'Share' }}
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { NcModal, NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import UserSearchField from './UserSearchField.vue'

const props = defineProps({
  show: { type: Boolean, required: true },
  /** Category to pre-select, e.g. 'music'|'film'|'book'|'game'|'comic'. */
  category: { type: String, default: 'music' },
})

defineEmits(['close'])

const categoryOptions = [
  { value: 'music', label: 'Music' },
  { value: 'film',  label: 'Films' },
  { value: 'book',  label: 'Books' },
  { value: 'game',  label: 'Games' },
  { value: 'comic', label: 'Comics' },
]

const wholeLibrary = ref(false)
const selectedCategories = ref([])
const allowWrite = ref(false)

const selectedUser = ref(null)

const sharing = ref(false)
const results = ref([])

const canShare = computed(() =>
  !!selectedUser.value && (wholeLibrary.value || selectedCategories.value.length > 0),
)

watch(() => props.show, (open) => {
  if (!open) return
  // Reset and seed the passed-in category. UserSearchField clears its own
  // field, timer and in-flight request via :active.
  wholeLibrary.value = false
  selectedCategories.value = props.category ? [props.category] : []
  allowWrite.value = false
  selectedUser.value = null
  results.value = []
},
// The modal is mounted only while open, so the first "open" is the mount
// itself and never arrives as a change.
{ immediate: true })

function toggleCategory(value, checked) {
  if (checked) {
    if (!selectedCategories.value.includes(value)) {
      selectedCategories.value = [...selectedCategories.value, value]
    }
  } else {
    selectedCategories.value = selectedCategories.value.filter(v => v !== value)
  }
}

function pickUser(user) {
  selectedUser.value = user
}

function clearSelectedUser() {
  selectedUser.value = null
}

async function doShare() {
  if (!canShare.value) return
  sharing.value = true
  results.value = []
  const userId = selectedUser.value.uid
  const permission = allowWrite.value ? 'readwrite' : 'read'

  // Build the list of {label, url} targets: whole library OR each selected
  // category as its own POST (there is no bulk-create endpoint).
  const targets = wholeLibrary.value
    ? [{ label: 'Whole library', url: generateOcsUrl('/apps/crate/api/v1/share/library') }]
    : selectedCategories.value.map(cat => ({
      label: categoryOptions.find(c => c.value === cat)?.label ?? cat,
      url: generateOcsUrl(`/apps/crate/api/v1/share/category/${cat}`),
    }))

  const report = []
  for (const target of targets) {
    try {
      await axios.post(target.url, { userId, permission })
      report.push({ label: target.label, state: 'ok', message: 'Shared' })
    } catch (e) {
      // 409 = already shared with this user — note it but keep going.
      if (e.response?.status === 409) {
        report.push({ label: target.label, state: 'skip', message: 'Already shared' })
      } else {
        const msg = e.response?.data?.ocs?.data?.error ?? 'Failed to share'
        report.push({ label: target.label, state: 'error', message: msg })
      }
    }
  }
  results.value = report
  sharing.value = false
}
</script>

<style scoped>
.sc-modal {
  padding: 24px 28px 28px;
  width: 100%;
  box-sizing: border-box;
}

.sc-modal h2 {
  margin: 0 0 8px;
  font-size: 1.25em;
  font-weight: 700;
}

.sc-hint {
  margin: 0 0 20px;
  font-size: 0.82em;
  color: var(--color-text-maxcontrast);
}

.sc-field {
  margin-bottom: 18px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.sc-label {
  font-size: 0.875em;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.sc-checkboxes {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.sc-selected-user {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.9em;
  margin: 0;
}

.sc-selected-uid {
  font-size: 0.82em;
  color: var(--color-text-maxcontrast);
}

.sc-report {
  list-style: none;
  margin: 0 0 8px;
  padding: 0;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  overflow: hidden;
}

.sc-report-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 14px;
  font-size: 0.85em;
  border-bottom: 1px solid var(--color-border);
}

.sc-report-row:last-child {
  border-bottom: none;
}

.sc-report-label {
  font-weight: 500;
}

.sc-report-msg {
  font-size: 0.9em;
  color: var(--color-text-maxcontrast);
}

.sc-report-row--ok .sc-report-msg {
  color: #4ade80;
}

.sc-report-row--error .sc-report-msg {
  color: var(--color-error);
}

.sc-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 24px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}
</style>
