<template>
  <div class="scv-view">
    <div class="scv-toolbar">
      <h2 class="scv-heading">
        {{ categoryLabel }}
        <span class="scv-heading-sub">shared with me</span>
      </h2>
      <NcButton
        v-if="addOwner"
        variant="secondary"
        @click="$emit('add-shared', { owner: addOwner, category })"
      >
        <template #icon>
          <span class="scv-plus">+</span>
        </template>
        Add to {{ addOwner }}’s {{ categoryLabel.toLowerCase() }}
      </NcButton>
    </div>

    <p
      v-if="loading"
      class="scv-status"
    >
      Loading…
    </p>

    <div
      v-else-if="items.length === 0"
      class="scv-empty"
    >
      <p>Nothing has been shared with you in this category.</p>
    </div>

    <div
      v-else
      class="scv-list"
    >
      <div
        v-for="item in items"
        :key="item.id"
        class="scv-row"
        @click="$emit('detail', item)"
      >
        <MediaThumb
          :item="item"
          class="scv-thumb"
        />
        <div class="scv-info">
          <span class="scv-title">{{ item.title }}</span>
          <span class="scv-artist">{{ item.artist }}</span>
          <span class="scv-meta">
            <span class="scv-badge">{{ item.format }}</span>
            <template v-if="item.year">&thinsp;{{ item.year }}</template>
            <span class="scv-shared-by">&ensp;·&ensp;Shared by {{ item.sharedByUser }}</span>
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { CATEGORY_LABELS } from '../utils/categoryFormats.js'
import { useSharedContent } from '../composables/useSharedContent.js'
import MediaThumb from './MediaThumb.vue'

const props = defineProps({
  category: { type: String, required: true },
})

defineEmits(['detail', 'add-shared'])

const { loading, itemsByCategory, writeOwnersForCategory, load } = useSharedContent()

const categoryLabel = computed(() => CATEGORY_LABELS[props.category] ?? props.category)
const items = computed(() => itemsByCategory.value[props.category] ?? [])

// If multiple owners share this category read/write, keep it simple: add into
// the first one's collection and label the button with their name.
const addOwner = computed(() => writeOwnersForCategory(props.category)[0] ?? null)

onMounted(load)
defineExpose({ load, reload: load })
</script>

<style scoped>
.scv-view {
  padding: 0 20px 40px;
  max-width: 860px;
}

.scv-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  padding: calc(var(--default-clickable-area, 44px) + 8px) 0 24px;
}

.scv-heading {
  margin: 0;
  font-size: 1.4em;
}

.scv-heading-sub {
  font-size: 0.6em;
  font-weight: 500;
  color: var(--color-text-maxcontrast);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin-left: 8px;
}

.scv-plus {
  font-size: 1.1em;
}

.scv-status {
  color: var(--color-text-maxcontrast);
}

.scv-empty {
  color: var(--color-text-maxcontrast);
  margin-top: 40px;
}

.scv-list {
  display: flex;
  flex-direction: column;
}

.scv-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 8px 12px;
  border-radius: var(--border-radius-large);
  cursor: pointer;
  transition: background 0.1s;
}

.scv-row:hover {
  background: var(--color-background-hover);
}

.scv-thumb {
  width: 44px;
  height: 44px;
  border-radius: var(--border-radius);
  flex-shrink: 0;
}

.scv-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.scv-title {
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.scv-artist {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.scv-meta {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
}

.scv-badge {
  background: var(--color-background-dark);
  padding: 1px 6px;
  border-radius: 10px;
  font-size: 0.85em;
  font-weight: 600;
}

.scv-shared-by {
  font-style: italic;
}
</style>
