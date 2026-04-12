<template>
  <NcApp>
    <template #navigation>
      <NcAppNavigation>
        <template #list>
          <NcAppNavigationItem
            name="My Collection"
            :active="view === 'collection'"
            @click="view = 'collection'"
          />
          <NcAppNavigationItem
            name="Wishlist"
            :active="view === 'wishlist'"
            @click="view = 'wishlist'"
          />
        </template>
      </NcAppNavigation>
    </template>
    <template #default>
      <NcAppContent>
        <div class="crate-content">
          <h2>{{ view === 'collection' ? 'My Collection' : 'Wishlist' }}</h2>
          <p v-if="loading">Loading...</p>
          <p v-else-if="items.length === 0">No items yet. Add your first record!</p>
          <ul v-else>
            <li v-for="item in items" :key="item.id">
              {{ item.artist }} — {{ item.title }} ({{ item.format }}, {{ item.year }})
            </li>
          </ul>
        </div>
      </NcAppContent>
    </template>
  </NcApp>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { NcApp, NcAppContent, NcAppNavigation, NcAppNavigationItem } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const view = ref('collection')
const items = ref([])
const loading = ref(false)

async function loadItems() {
  loading.value = true
  try {
    const response = await axios.get(generateOcsUrl('/apps/crate/api/v1/media'))
    const all = response.data.ocs?.data ?? []
    items.value = view.value === 'wishlist'
      ? all.filter(i => i.status === 'wanted')
      : all.filter(i => i.status === 'owned')
  } catch (e) {
    console.error('Failed to load media items', e)
  } finally {
    loading.value = false
  }
}

watch(view, loadItems)
onMounted(loadItems)
</script>

<style scoped>
.crate-content {
  padding: 20px;
}
</style>
