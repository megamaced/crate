<template>
  <div class="crate-home">
    <p
      v-if="loading"
      class="crate-status"
    >
      Loading…
    </p>

    <template v-else-if="items.length === 0">
      <div class="crate-home-empty">
        <p>Your collection is empty.</p>
        <NcButton
          type="primary"
          @click="$emit('add')"
        >
          Add your first record
        </NcButton>
      </div>
    </template>

    <template v-else>
      <section class="crate-home-section">
        <h3>Recently Added</h3>
        <div class="crate-card-grid">
          <MediaCard
            v-for="item in recentItems"
            :key="item.id"
            :item="item"
            @detail="$emit('detail', item)"
          />
        </div>
      </section>

      <section
        v-if="randomItems.length > 0"
        class="crate-home-section"
      >
        <h3>
          Random Pick
          <NcButton
            type="tertiary"
            class="crate-reshuffle"
            @click="reshuffle"
          >
            Shuffle
          </NcButton>
        </h3>
        <div class="crate-card-grid">
          <MediaCard
            v-for="item in randomItems"
            :key="'r' + item.id"
            :item="item"
            @detail="$emit('detail', item)"
          />
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import MediaCard from './MediaCard.vue'

defineEmits(['add', 'detail'])

const loading = ref(false)
const items = ref([])
const shuffleSeed = ref(0)

const CARD_COUNT = 6

async function load() {
  loading.value = true
  try {
    const response = await axios.get(generateOcsUrl('/apps/crate/api/v1/media'))
    const all = response.data.ocs?.data ?? []
    items.value = all.filter(i => i.status === 'owned')
  } catch (e) {
    console.error('Failed to load items', e)
  } finally {
    loading.value = false
  }
}

const recentItems = computed(() => items.value.slice(0, CARD_COUNT))

const randomItems = computed(() => {
  // shuffleSeed is used to trigger recomputation on reshuffle
  void shuffleSeed.value
  if (items.value.length <= CARD_COUNT) return []
  const pool = [...items.value].sort(() => Math.random() - 0.5)
  return pool.slice(0, CARD_COUNT)
})

function reshuffle() {
  shuffleSeed.value++
}

onMounted(load)
defineExpose({ load })
</script>

<style scoped>
.crate-home {
  padding: 20px;
  padding-top: calc(var(--default-clickable-area, 44px) + 8px);
}

.crate-home-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  margin-top: 60px;
  color: var(--color-text-maxcontrast);
}

.crate-home-section {
  margin-bottom: 36px;
}

.crate-home-section h3 {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 14px;
  font-size: 1em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-maxcontrast);
}

.crate-reshuffle {
  margin-inline-start: 4px;
}

.crate-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 12px;
}

.crate-status {
  color: var(--color-text-maxcontrast);
}
</style>
