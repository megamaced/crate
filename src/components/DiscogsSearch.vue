<template>
  <div class="discogs-search">
    <div class="discogs-search-row">
      <input
        v-model="query"
        type="search"
        placeholder="Search Discogs (artist, album, barcode…)"
        :disabled="searching"
        @keydown.enter.prevent="search"
      >
      <NcButton
        type="secondary"
        :disabled="searching || query.trim() === ''"
        @click="search"
      >
        {{ searching ? 'Searching…' : 'Search' }}
      </NcButton>
    </div>

    <p
      v-if="noToken"
      class="discogs-hint discogs-hint--warn"
    >
      Add your Discogs token in Settings to enable search.
    </p>
    <p
      v-else-if="searched && results.length === 0"
      class="discogs-hint"
    >
      No results found.
    </p>

    <ul
      v-if="results.length > 0"
      class="discogs-results"
    >
      <li
        v-for="result in results"
        :key="result.discogsId"
        class="discogs-result"
        @mousedown.prevent="select(result)"
      >
          <img
            v-if="result.thumb"
            :src="result.thumb"
            :alt="result.title"
            class="discogs-result-thumb"
            loading="lazy"
          >
          <div
            v-else
            class="discogs-result-thumb discogs-result-thumb--placeholder"
          />
          <div class="discogs-result-info">
            <span class="discogs-result-title">{{ result.title }}</span>
            <span class="discogs-result-artist">{{ result.artist }}</span>
            <span class="discogs-result-meta">
              {{ result.format }}<template v-if="result.year">, {{ result.year }}</template><template v-if="result.label">, {{ result.label }}</template>
            </span>
          </div>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const emit = defineEmits(['select'])

const query = ref('')
const results = ref([])
const searching = ref(false)
const searched = ref(false)
const noToken = ref(false)

async function search() {
  if (query.value.trim() === '') return
  searching.value = true
  searched.value = false
  noToken.value = false
  results.value = []
  try {
    const isBarcode = /^\d{8,14}$/.test(query.value.trim())
    const params = isBarcode
      ? { barcode: query.value.trim() }
      : { q: query.value.trim() }

    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/discogs/search'), { params })
    const data = res.data.ocs?.data ?? []

    if (data === null || (Array.isArray(data) && data.length === 0 && !isBarcode)) {
      const settingsRes = await axios.get(generateOcsUrl('/apps/crate/api/v1/settings/discogs-token'))
      noToken.value = !(settingsRes.data.ocs?.data?.hasToken ?? false)
    }

    results.value = Array.isArray(data) ? data : []
    searched.value = true
  } catch (e) {
    console.error('Discogs search failed', e)
    searched.value = true
  } finally {
    searching.value = false
  }
}

function select(result) {
  emit('select', result)
  results.value = []
  query.value = ''
  searched.value = false
}
</script>

<style scoped>
.discogs-search {
  margin-bottom: 16px;
}

.discogs-search-row {
  display: flex;
  gap: 8px;
  margin-bottom: 4px;
}

.discogs-search-row input {
  flex: 1;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 6px 10px;
  font-size: 1em;
  font-family: inherit;
}

.discogs-search-row input:focus {
  border-color: var(--color-primary-element);
  outline: none;
}

.discogs-hint {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  margin: 0 0 4px;
}

.discogs-hint--warn {
  color: var(--color-warning);
}

.discogs-results {
  list-style: none;
  padding: 0;
  margin: 0 0 12px;
  max-height: 220px;
  overflow-y: auto;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
}

.discogs-result {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  cursor: pointer;
  transition: background 0.1s;
}

.discogs-result:not(:last-child) {
  border-bottom: 1px solid var(--color-border);
}

.discogs-result:hover {
  background: var(--color-background-hover);
}

.discogs-result-thumb {
  width: 44px;
  height: 44px;
  border-radius: 4px;
  object-fit: cover;
  flex-shrink: 0;
}

.discogs-result-thumb--placeholder {
  background: var(--color-background-dark);
}

.discogs-result-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.discogs-result-title {
  font-weight: 600;
  font-size: 0.9em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.discogs-result-artist {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.discogs-result-meta {
  font-size: 0.75em;
  color: var(--color-text-maxcontrast);
}
</style>
