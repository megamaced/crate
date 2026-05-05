<template>
  <div class="enrichment-search">
    <div class="enrichment-search-row">
      <input
        v-model="query"
        type="search"
        placeholder="Search RAWG (game title…)"
        :disabled="searching"
        @keydown.enter.prevent="search"
      >
      <NcButton
        variant="secondary"
        :disabled="searching || query.trim() === ''"
        @click="search"
      >
        {{ searching ? 'Searching…' : 'Search' }}
      </NcButton>
    </div>

    <p
      v-if="noKey"
      class="enrichment-hint enrichment-hint--warn"
    >
      No RAWG API key saved — add one in Settings to enable game search.
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
        :key="result.rawgId"
        class="enrichment-result"
        @mousedown.prevent="select(result)"
      >
        <div
          class="enrichment-result-thumb"
          :class="{ 'enrichment-result-thumb--placeholder': !result.thumb }"
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
          <span class="enrichment-result-meta">
            <template v-if="result.year">{{ result.year }}</template>
            <template v-if="result.genres">, {{ result.genres }}</template>
          </span>
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

const props = defineProps({
  hasKey: { type: Boolean, default: false },
})

const emit = defineEmits(['select'])

const query = ref('')
const results = ref([])
const searching = ref(false)
const searched = ref(false)
const noKey = computed(() => !props.hasKey)

async function search() {
  if (query.value.trim() === '') return
  searching.value = true
  searched.value = false
  results.value = []
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/rawg/search'), {
      params: { q: query.value.trim() },
    })
    const data = res.data.ocs?.data ?? []
    results.value = Array.isArray(data) ? data : []
    searched.value = true
  } catch (e) {
    console.error('RAWG search failed', e)
    showError('RAWG search failed')
    searched.value = true
  } finally {
    searching.value = false
  }
}

async function select(result) {
  // Fetch full game details (developer, publisher, description)
  try {
    const res = await axios.get(generateOcsUrl(`/apps/crate/api/v1/rawg/game/${result.rawgId}`))
    const detail = res.data.ocs?.data ?? result
    emit('select', detail)
  } catch {
    emit('select', result)
  }
  results.value = []
  query.value = ''
  searched.value = false
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
  width: 56px;
  height: 40px;
  border-radius: 4px;
  flex-shrink: 0;
  background: var(--color-background-dark);
  overflow: hidden;
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

.enrichment-result-meta {
  font-size: 0.75em;
  color: var(--color-text-maxcontrast);
}
</style>
