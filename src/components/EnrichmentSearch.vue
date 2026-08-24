<template>
  <div class="enrichment-search">
    <div class="enrichment-search-row">
      <input
        v-model="query"
        type="search"
        :placeholder="provider.placeholder"
        :disabled="busy"
        @keydown.enter.prevent="search"
      >
      <NcButton
        variant="secondary"
        :disabled="busy || query.trim() === ''"
        @click="search"
      >
        {{ searching ? 'Searching…' : 'Search' }}
      </NcButton>
    </div>

    <p
      v-if="missingCredential"
      class="enrichment-hint enrichment-hint--warn"
    >
      {{ provider.credentialHint }}
    </p>
    <p
      v-else-if="selecting"
      class="enrichment-hint"
    >
      Fetching details from {{ provider.label }}…
    </p>
    <p
      v-else-if="searched && results.length === 0"
      class="enrichment-hint"
    >
      No results found.
    </p>

    <ul
      v-if="results.length > 0"
      class="enrichment-results"
    >
      <li
        v-for="result in results"
        :key="result[provider.idKey]"
        class="enrichment-result"
        @mousedown.prevent="select(result)"
      >
        <div
          class="enrichment-result-thumb"
          :class="[
            `enrichment-result-thumb--${provider.thumbShape}`,
            { 'enrichment-result-thumb--placeholder': !result.thumb },
          ]"
        >
          <img
            v-if="result.thumb"
            :src="result.thumb"
            alt=""
            loading="lazy"
            referrerpolicy="no-referrer"
          >
        </div>
        <div class="enrichment-result-info">
          <span class="enrichment-result-title">{{ result.title }}</span>
          <span
            v-if="provider.showArtist"
            class="enrichment-result-artist"
          >{{ result.artist }}</span>
          <span class="enrichment-result-meta">
            <slot
              name="meta"
              :result="result"
            >{{ metaLine(result) }}</slot>
          </span>
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup>
/**
 * External metadata search, shared by every category.
 *
 * All per-provider variation lives in the descriptor from
 * utils/enrichmentProviders.js. The `#meta` slot overrides the result line
 * when a caller needs more than the descriptor's `metaFields` can express.
 */
import { computed, ref } from 'vue'
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { showError, showWarning } from '@nextcloud/dialogs'

const props = defineProps({
  /** A descriptor from utils/enrichmentProviders.js. */
  provider: { type: Object, required: true },
  /**
   * Whether the user has saved the credential this provider needs. Ignored for
   * providers that need none (their `credentialHint` is null).
   */
  hasCredential: { type: Boolean, default: false },
})

const emit = defineEmits(['select'])

const query = ref('')
const results = ref([])
const searching = ref(false)
const searched = ref(false)
const selecting = ref(false)

// One flag for both requests, so neither a search nor a detail fetch can be
// started while the other is running.
const busy = computed(() => searching.value || selecting.value)
const missingCredential = computed(() =>
  !!props.provider.credentialHint && !props.hasCredential,
)

function metaLine(result) {
  return (props.provider.metaFields ?? [])
    .map(field => result[field])
    .filter(Boolean)
    .join(', ')
}

async function search() {
  const q = query.value.trim()
  if (q === '' || busy.value) return
  searching.value = true
  searched.value = false
  results.value = []
  try {
    const params = props.provider.searchParams ? props.provider.searchParams(q) : { q }
    const res = await axios.get(props.provider.searchPath(), { params })
    const data = res.data.ocs?.data ?? []
    results.value = Array.isArray(data) ? data : []
  } catch (e) {
    console.error(`${props.provider.label} search failed`, e)
    showError(`${props.provider.label} search failed`)
  } finally {
    searched.value = true
    searching.value = false
  }
}

/**
 * Apply one result. Providers whose search rows are thin need a second request
 * for the full record; the list is cleared before that await so a second click
 * can't emit a different result and have the two merge in completion order.
 */
async function select(result) {
  if (busy.value) return
  selecting.value = true
  results.value = []
  query.value = ''
  searched.value = false
  try {
    if (!props.provider.detailPath) {
      emit('select', result)
      return
    }
    try {
      const res = await axios.get(props.provider.detailPath(result))
      const detail = res.data.ocs?.data ?? null
      emit('select', detail ? props.provider.mergeDetail(result, detail) : result)
    } catch (e) {
      // The thin search row is still usable, but the user asked for the full
      // record — say so rather than letting the gaps look like missing data.
      console.error(`${props.provider.label} detail fetch failed`, e)
      showWarning(`Couldn't fetch full details from ${props.provider.label} — using the search result.`)
      emit('select', result)
    }
  } finally {
    selecting.value = false
  }
}
</script>

<style scoped>
.enrichment-search {
  margin-bottom: 16px;
}

.enrichment-search-row {
  display: flex;
  gap: 8px;
  margin-bottom: 4px;
}

.enrichment-search-row input {
  flex: 1;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 6px 10px;
  font-size: 1em;
  font-family: inherit;
}

.enrichment-search-row input:focus {
  border-color: var(--color-primary-element);
  outline: none;
}

.enrichment-hint {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  margin: 0 0 4px;
}

.enrichment-hint--warn {
  color: #fbbf24;
}

.enrichment-results {
  list-style: none;
  padding: 0;
  margin: 0 0 12px;
  max-height: 220px;
  overflow-y: auto;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
}

.enrichment-result {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  cursor: pointer;
  transition: background 0.1s;
}

.enrichment-result:not(:last-child) {
  border-bottom: 1px solid var(--color-border);
}

.enrichment-result:hover {
  background: var(--color-background-hover);
}

.enrichment-result-thumb {
  border-radius: 4px;
  flex-shrink: 0;
  overflow: hidden;
}

/* Album sleeves are square; film posters, book jackets, box art and comic
   covers are all taller than they are wide. */
.enrichment-result-thumb--square {
  width: 44px;
  height: 44px;
}

.enrichment-result-thumb--portrait {
  width: 40px;
  height: 56px;
}

.enrichment-result-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.enrichment-result-thumb--placeholder {
  background: var(--color-background-dark);
}

.enrichment-result-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.enrichment-result-title {
  font-weight: 600;
  font-size: 0.9em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.enrichment-result-artist {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.enrichment-result-meta {
  font-size: 0.75em;
  color: var(--color-text-maxcontrast);
}
</style>
