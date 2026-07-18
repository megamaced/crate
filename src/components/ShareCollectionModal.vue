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
        <template v-else>
          <input
            v-model="query"
            type="text"
            placeholder="Search users by name or username…"
            class="sc-input"
            autocomplete="off"
            @input="onQueryInput"
          >
          <div
            v-if="searchResults.length > 0"
            class="sc-results"
          >
            <button
              v-for="user in searchResults"
              :key="user.uid"
              class="sc-result-row"
              @click="pickUser(user)"
            >
              <span class="sc-result-name">{{ user.displayName }}</span>
              <span class="sc-result-uid">{{ user.uid }}</span>
            </button>
          </div>
          <p
            v-if="query.length >= 2 && searchResults.length === 0 && !searching"
            class="sc-no-results"
          >
            No users found.
          </p>
        </template>
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
import { showError } from '@nextcloud/dialogs'

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

const query = ref('')
const searching = ref(false)
const searchResults = ref([])
const selectedUser = ref(null)

const sharing = ref(false)
const results = ref([])

let searchTimeout = null
let searchController = null

const canShare = computed(() =>
  !!selectedUser.value && (wholeLibrary.value || selectedCategories.value.length > 0),
)

watch(() => props.show, (open) => {
  if (open) {
    // Reset and seed the passed-in category.
    wholeLibrary.value = false
    selectedCategories.value = props.category ? [props.category] : []
    allowWrite.value = false
    query.value = ''
    searchResults.value = []
    selectedUser.value = null
    results.value = []
  } else {
    clearTimeout(searchTimeout)
    searchTimeout = null
    if (searchController) {
      searchController.abort()
      searchController = null
    }
  }
})

function toggleCategory(value, checked) {
  if (checked) {
    if (!selectedCategories.value.includes(value)) {
      selectedCategories.value = [...selectedCategories.value, value]
    }
  } else {
    selectedCategories.value = selectedCategories.value.filter(v => v !== value)
  }
}

function onQueryInput() {
  clearTimeout(searchTimeout)
  searchResults.value = []
  if (query.value.trim().length < 2) return
  searchTimeout = setTimeout(doSearch, 300)
}

async function doSearch() {
  if (searchController) searchController.abort()
  searchController = new AbortController()
  searching.value = true
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/users/search'), {
      params: { q: query.value.trim() },
      signal: searchController.signal,
    })
    searchResults.value = res.data.ocs?.data ?? []
  } catch (e) {
    if (e.name === 'CanceledError' || e.code === 'ERR_CANCELED') return
    console.error('User search failed', e)
    showError('User search failed')
  } finally {
    searching.value = false
  }
}

function pickUser(user) {
  selectedUser.value = user
  query.value = ''
  searchResults.value = []
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

.sc-input {
  width: 100%;
  box-sizing: border-box;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-background-dark);
  color: var(--color-main-text);
  padding: 8px 12px;
  font-size: 0.9em;
  font-family: inherit;
}

.sc-input:focus {
  border-color: var(--color-primary-element);
  outline: none;
  background: var(--color-main-background);
}

.sc-results {
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  overflow: hidden;
}

.sc-result-row {
  display: flex;
  flex-direction: column;
  gap: 1px;
  width: 100%;
  padding: 10px 14px;
  border: none;
  background: none;
  cursor: pointer;
  text-align: left;
  transition: background 0.1s;
  color: var(--color-main-text);
  border-bottom: 1px solid var(--color-border);
}

.sc-result-row:last-child {
  border-bottom: none;
}

.sc-result-row:hover {
  background: var(--color-background-hover);
}

.sc-result-name {
  font-weight: 500;
  font-size: 0.875em;
}

.sc-result-uid {
  font-size: 0.78em;
  color: var(--color-text-maxcontrast);
}

.sc-no-results {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
  margin: 0;
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
