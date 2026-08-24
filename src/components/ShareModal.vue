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
      <UserSearchField
        ref="userSearch"
        class="share-user-search"
        :active="show"
        @select="shareWith"
      />

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
import { ref, watch, computed, onBeforeUnmount } from 'vue'
import { NcModal, NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import UserSearchField from './UserSearchField.vue'

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

const userSearch = ref(null)
const currentShares = ref([])
const allowWrite = ref(false)
const statusMessage = ref('')
const statusError = ref(false)

// One handle for the transient status line: a second share must not have the
// first one's timer clear its message, and the timer must not outlive the modal.
let statusTimer = null
function flashStatus(text, isError, ms) {
  clearTimeout(statusTimer)
  statusError.value = isError
  statusMessage.value = text
  statusTimer = setTimeout(() => { statusMessage.value = '' }, ms)
}
onBeforeUnmount(() => clearTimeout(statusTimer))

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
    // UserSearchField drops its own timer and in-flight request via :active.
    clearTimeout(statusTimer)
    statusMessage.value = ''
    allowWrite.value = false
    return
  }
  if (props.target) {
    await loadCurrentShares()
  }
},
// The modal is mounted only while open, so the first "open" is the mount
// itself and never arrives as a change.
{ immediate: true })

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

async function shareWith(user) {
  const url = urlForCreate()
  if (!url) return
  clearTimeout(statusTimer)
  statusMessage.value = ''
  try {
    await axios.post(url, { userId: user.uid, permission: allowWrite.value ? 'readwrite' : 'read' })
    userSearch.value?.reset()
    flashStatus(`Shared with ${user.displayName}.`, false, 3000)
    await loadCurrentShares()
  } catch (e) {
    flashStatus(e.response?.data?.ocs?.data?.error ?? 'Failed to share.', true, 4000)
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

.share-user-search {
  margin-bottom: 16px;
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
