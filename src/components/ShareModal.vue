<template>
  <NcModal
    :show="show"
    label-id="share-modal-title"
    size="small"
    @close="$emit('close')"
  >
    <div class="share-modal">
      <h2 id="share-modal-title">
        {{ headingForTarget }}
      </h2>
      <p
        v-if="displayName"
        class="share-subtitle"
      >
        <strong>{{ displayName }}</strong>
      </p>
      <p
        v-if="subscopeHint"
        class="share-hint"
      >
        {{ subscopeHint }}
      </p>

      <!-- Access level -->
      <div class="share-permission">
        <NcCheckboxRadioSwitch
          type="switch"
          :model-value="allowWrite"
          @update:model-value="allowWrite = $event"
        >
          Allow adding &amp; editing (read/write)
        </NcCheckboxRadioSwitch>
      </div>

      <!-- User search -->
      <div class="share-search">
        <input
          v-model="query"
          type="text"
          placeholder="Search users by name or username…"
          class="share-input"
          autocomplete="off"
          @input="onQueryInput"
        >
      </div>

      <!-- Search results -->
      <div
        v-if="searchResults.length > 0"
        class="share-results"
      >
        <button
          v-for="user in searchResults"
          :key="user.uid"
          class="share-result-row"
          @click="shareWith(user)"
        >
          <span class="share-result-name">{{ user.displayName }}</span>
          <span class="share-result-uid">{{ user.uid }}</span>
        </button>
      </div>

      <p
        v-if="query.length >= 2 && searchResults.length === 0 && !searching"
        class="share-no-results"
      >
        No users found.
      </p>

      <!-- Current shares -->
      <div
        v-if="currentShares.length > 0"
        class="share-current"
      >
        <p class="share-current-label">
          Shared with
        </p>
        <div
          v-for="share in currentShares"
          :key="share.id"
          class="share-current-row"
        >
          <span class="share-current-user">
            {{ share.sharedWithUserId }}
            <span
              class="share-access-badge"
              :class="share.canWrite ? 'share-access-badge--write' : ''"
            >{{ share.canWrite ? 'Can edit' : 'Read-only' }}</span>
          </span>
          <NcButton
            variant="tertiary"
            size="small"
            :aria-label="'Unshare with ' + share.sharedWithUserId"
            @click="unshare(share)"
          >
            Remove
          </NcButton>
        </div>
      </div>

      <p
        v-if="statusMessage"
        class="share-status"
        :class="{ 'share-status--error': statusError }"
      >
        {{ statusMessage }}
      </p>
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
  /**
   * { type: 'album'|'playlist', id: number, name: string }
   *   — per-item shares
   * { type: 'library' }
   *   — whole-library share
   * { type: 'category', category: 'music'|'film'|'book'|'game'|'comic', name: string }
   *   — per-category share
   */
  target: { type: Object, default: null },
})

defineEmits(['close'])

const CATEGORY_LABELS = { music: 'Music', film: 'Films', book: 'Books', game: 'Games', comic: 'Comics' }

const query = ref('')
const searching = ref(false)
const searchResults = ref([])
const currentShares = ref([])
const allowWrite = ref(false)
const statusMessage = ref('')
const statusError = ref(false)
let searchTimeout = null
let searchController = null

const displayName = computed(() => {
  if (!props.target) return ''
  if (props.target.type === 'library') return ''
  if (props.target.type === 'category') return CATEGORY_LABELS[props.target.category] ?? props.target.category
  return props.target.name ?? ''
})

const headingForTarget = computed(() => {
  switch (props.target?.type) {
    case 'library':  return 'Share whole library'
    case 'category': return 'Share category'
    default:         return 'Share'
  }
})

const subscopeHint = computed(() => {
  const access = allowWrite.value
    ? 'They can add and edit items, but not delete them.'
    : 'Read-only.'
  switch (props.target?.type) {
    case 'library':  return `Sharees can view every item in your collection. ${access}`
    case 'category': return `Sharees can view items in this category. ${access}`
    default:         return ''
  }
})

watch(() => props.show, async (open) => {
  if (!open) {
    clearTimeout(searchTimeout)
    searchTimeout = null
    if (searchController) {
      searchController.abort()
      searchController = null
    }
    query.value = ''
    searchResults.value = []
    statusMessage.value = ''
    allowWrite.value = false
    return
  }
  if (props.target) {
    await loadCurrentShares()
  }
})

// Reload shares when the parent reuses an open modal but switches target.
watch(
  () => [props.target?.type, props.target?.id, props.target?.category].join(':'),
  async () => {
    if (!props.show || !props.target) return
    currentShares.value = []
    await loadCurrentShares()
  },
)

function urlForList() {
  if (!props.target) return null
  switch (props.target.type) {
    case 'album':    return generateOcsUrl(`/apps/crate/api/v1/share/album/${props.target.id}`)
    case 'playlist': return generateOcsUrl(`/apps/crate/api/v1/share/playlist/${props.target.id}`)
    case 'library':  return generateOcsUrl('/apps/crate/api/v1/share/library')
    case 'category': return generateOcsUrl(`/apps/crate/api/v1/share/category/${props.target.category}`)
    default:         return null
  }
}

function urlForCreate() {
  return urlForList()
}

async function loadCurrentShares() {
  const url = urlForList()
  if (!url) return
  try {
    const res = await axios.get(url)
    currentShares.value = res.data.ocs?.data ?? []
  } catch (e) {
    console.error('Failed to load shares', e)
    showError('Failed to load shares')
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

async function shareWith(user) {
  const url = urlForCreate()
  if (!url) return
  statusMessage.value = ''
  try {
    await axios.post(url, { userId: user.uid, permission: allowWrite.value ? 'readwrite' : 'read' })
    query.value = ''
    searchResults.value = []
    statusError.value = false
    statusMessage.value = `Shared with ${user.displayName}.`
    setTimeout(() => { statusMessage.value = '' }, 3000)
    await loadCurrentShares()
  } catch (e) {
    statusError.value = true
    statusMessage.value = e.response?.data?.ocs?.data?.error ?? 'Failed to share.'
    setTimeout(() => { statusMessage.value = '' }, 4000)
  }
}

async function unshare(share) {
  try {
    await axios.delete(generateOcsUrl(`/apps/crate/api/v1/share/${share.id}`))
    await loadCurrentShares()
  } catch (e) {
    console.error('Failed to unshare', e)
    showError('Failed to unshare')
  }
}
</script>

<style scoped>
.share-modal {
  padding: 24px 28px 28px;
  min-width: min(380px, 90vw);
}

.share-modal h2 {
  margin: 0 0 4px;
  font-size: 1.15em;
  font-weight: 700;
}

.share-subtitle {
  margin: 0 0 8px;
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
}

.share-hint {
  margin: 0 0 16px;
  font-size: 0.82em;
  color: var(--color-text-maxcontrast);
}

.share-search {
  margin-bottom: 8px;
}

.share-input {
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

.share-input:focus {
  border-color: var(--color-primary-element);
  outline: none;
  background: var(--color-main-background);
}

.share-results {
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  overflow: hidden;
  margin-bottom: 16px;
}

.share-result-row {
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

.share-result-row:last-child {
  border-bottom: none;
}

.share-result-row:hover {
  background: var(--color-background-hover);
}

.share-result-name {
  font-weight: 500;
  font-size: 0.875em;
}

.share-result-uid {
  font-size: 0.78em;
  color: var(--color-text-maxcontrast);
}

.share-no-results {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
  margin: 0 0 16px;
}

/* Current shares */
.share-current {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}

.share-current-label {
  font-size: 0.78em;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-maxcontrast);
  margin: 0 0 8px;
}

.share-current-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 4px 0;
}

.share-current-user {
  font-size: 0.875em;
  display: flex;
  align-items: center;
  gap: 8px;
}

.share-permission {
  margin: 4px 0 16px;
}

.share-access-badge {
  font-size: 0.72em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 1px 7px;
  border-radius: 10px;
  background: var(--color-background-dark);
  color: var(--color-text-maxcontrast);
}

.share-access-badge--write {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
}

/* Status */
.share-status {
  margin: 12px 0 0;
  font-size: 0.875em;
  color: #4ade80;
}

.share-status--error {
  color: var(--color-error);
}
</style>
