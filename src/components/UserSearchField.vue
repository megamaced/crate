<template>
  <div class="user-search">
    <input
      v-model="query"
      type="text"
      :placeholder="placeholder"
      class="user-search__input"
      autocomplete="off"
      @input="onQueryInput"
    >
    <div
      v-if="results.length > 0"
      class="user-search__results"
    >
      <button
        v-for="user in results"
        :key="user.uid"
        type="button"
        class="user-search__row"
        @click="$emit('select', user)"
      >
        <span class="user-search__name">{{ user.displayName }}</span>
        <span class="user-search__uid">{{ user.uid }}</span>
      </button>
    </div>
    <p
      v-if="showNoResults"
      class="user-search__empty"
    >
      No users found.
    </p>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, watch } from 'vue'
import { useUserSearch } from '../composables/useUserSearch.js'

const props = defineProps({
  placeholder: { type: String, default: 'Search users by name or username…' },
  /**
   * Set false while the containing dialog is closed: the pending timer and any
   * in-flight request are dropped so a reopened dialog starts clean.
   */
  active: { type: Boolean, default: true },
})

defineEmits(['select'])

const { query, searching, results, onQueryInput, reset, dispose } = useUserSearch()

const showNoResults = computed(() =>
  query.value.trim().length >= 2 && results.value.length === 0 && !searching.value,
)

watch(() => props.active, (active) => {
  if (active) reset()
  else dispose()
})

onBeforeUnmount(dispose)

defineExpose({ reset })
</script>

<style scoped>
.user-search {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.user-search__input {
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

.user-search__input:focus {
  border-color: var(--color-primary-element);
  outline: none;
  background: var(--color-main-background);
}

.user-search__results {
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius);
  overflow: hidden;
}

.user-search__row {
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

.user-search__row:last-child {
  border-bottom: none;
}

.user-search__row:hover {
  background: var(--color-background-hover);
}

.user-search__name {
  font-weight: 500;
  font-size: 0.875em;
}

.user-search__uid {
  font-size: 0.78em;
  color: var(--color-text-maxcontrast);
}

.user-search__empty {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
  margin: 0;
}
</style>
