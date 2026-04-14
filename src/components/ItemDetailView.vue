<template>
  <div class="detail-view">
    <!-- Top bar -->
    <div class="detail-topbar">
      <NcButton
        variant="tertiary"
        class="detail-back"
        @click="$emit('back')"
      >
        ← Back
      </NcButton>
      <div class="detail-topbar-actions">
        <NcButton
          v-if="!enriching && !stripping"
          variant="secondary"
          @click="enrich"
        >
          {{ isEnriched ? 'Re-enrich from Discogs' : 'Enrich from Discogs' }}
        </NcButton>
        <NcButton
          v-if="isEnriched && !enriching && !stripping"
          variant="tertiary"
          @click="stripEnrich"
        >
          Remove Discogs data
        </NcButton>
        <span
          v-if="enriching"
          class="detail-enriching"
        >Fetching from Discogs…</span>
        <span
          v-if="stripping"
          class="detail-enriching"
        >Removing…</span>
        <NcButton
          variant="tertiary"
          @click="$emit('edit', item)"
        >
          Edit
        </NcButton>
        <NcButton
          variant="error"
          @click="$emit('delete', item)"
        >
          Delete
        </NcButton>
      </div>
    </div>

    <!-- Hero -->
    <div class="detail-hero">
      <div
        class="detail-artwork"
        :style="artStyle"
      />

      <div class="detail-hero-info">
        <h2 class="detail-title">
          {{ item.title }}
        </h2>
        <p class="detail-artist">
          {{ item.artist }}
        </p>

        <div class="detail-badges">
          <span class="badge badge-format">{{ item.format }}</span>
          <span
            v-if="item.year"
            class="badge badge-year"
          >{{ item.year }}</span>
          <span
            v-if="item.status === 'wanted'"
            class="badge badge-wanted"
          >Wishlist</span>
          <span
            v-if="item.country"
            class="badge badge-country"
          >{{ item.country }}</span>
        </div>

        <!-- Metadata grid -->
        <dl class="detail-meta">
          <template v-if="item.label">
            <dt>Label</dt>
            <dd>{{ item.label }}</dd>
          </template>
          <template v-if="item.genres">
            <dt>Genres</dt>
            <dd>{{ item.genres }}</dd>
          </template>
          <template v-if="item.barcode">
            <dt>Barcode</dt>
            <dd>{{ item.barcode }}</dd>
          </template>
          <template v-if="item.notes">
            <dt>Notes</dt>
            <dd>{{ item.notes }}</dd>
          </template>
        </dl>
      </div>
    </div>

    <!-- Tracklist -->
    <section
      v-if="tracklist.length > 0"
      class="detail-section"
    >
      <h3>Tracklist</h3>
      <table class="detail-tracklist">
        <tbody>
          <tr
            v-for="(track, idx) in tracklist"
            :key="idx"
          >
            <td class="track-pos">
              {{ track.position || (idx + 1) }}
            </td>
            <td class="track-title">
              {{ track.title }}
            </td>
            <td class="track-dur">
              {{ track.duration }}
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- Pressing notes -->
    <section
      v-if="item.pressingNotes"
      class="detail-section"
    >
      <h3>Notes</h3>
      <p class="detail-notes-text">
        {{ item.pressingNotes }}
      </p>
    </section>

    <!-- Artist info -->
    <section
      v-if="item.artistBio || (members.length > 0)"
      class="detail-section"
    >
      <h3>About {{ item.artist }}</h3>
      <p
        v-if="item.artistBio"
        class="detail-bio"
      >
        {{ item.artistBio }}
      </p>
      <div
        v-if="members.length > 0"
        class="detail-members"
      >
        <strong>Members:</strong>
        {{ members.join(', ') }}
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'

const props = defineProps({
  item: { type: Object, required: true },
})

const emit = defineEmits(['back', 'edit', 'delete', 'enriched'])

const enriching = ref(false)
const stripping = ref(false)

// True once full release data has been fetched from Discogs
const isEnriched = computed(() =>
  !!(props.item.genres || props.item.artistBio || (Array.isArray(props.item.tracklist) && props.item.tracklist.length > 0)),
)

const FORMAT_COLOURS = {
  Vinyl: ['#6b21a8', '#a855f7'],
  CD: ['#1d4ed8', '#60a5fa'],
  SACD: ['#0f766e', '#2dd4bf'],
  Cassette: ['#b45309', '#fbbf24'],
  MiniDisc: ['#0e7490', '#38bdf8'],
}

const artStyle = computed(() => {
  if (props.item.artworkPath) {
    const url = generateUrl('/apps/crate/artwork/' + props.item.id)
    return {
      backgroundImage: `url(${url})`,
      backgroundSize: 'cover',
      backgroundPosition: 'center',
    }
  }
  const colours = FORMAT_COLOURS[props.item.format] ?? ['#374151', '#6b7280']
  return { background: `linear-gradient(135deg, ${colours[0]}, ${colours[1]})` }
})

const tracklist = computed(() => {
  if (!props.item.tracklist) return []
  if (Array.isArray(props.item.tracklist)) return props.item.tracklist
  try { return JSON.parse(props.item.tracklist) } catch { return [] }
})

const members = computed(() => {
  if (!props.item.artistMembers) return []
  if (Array.isArray(props.item.artistMembers)) return props.item.artistMembers
  try { return JSON.parse(props.item.artistMembers) } catch { return [] }
})

async function enrich() {
  enriching.value = true
  try {
    const res = await axios.post(
      generateOcsUrl('/apps/crate/api/v1/media/' + props.item.id + '/enrich'),
    )
    const updated = res.data.ocs?.data ?? null
    if (updated) {
      emit('enriched', updated)
    }
  } catch (e) {
    console.error('Enrich failed', e)
  } finally {
    enriching.value = false
  }
}

async function stripEnrich() {
  stripping.value = true
  try {
    const res = await axios.delete(
      generateOcsUrl('/apps/crate/api/v1/media/' + props.item.id + '/enrich'),
    )
    const updated = res.data.ocs?.data ?? null
    if (updated) {
      emit('enriched', updated)
    }
  } catch (e) {
    console.error('Strip enrich failed', e)
  } finally {
    stripping.value = false
  }
}
</script>

<style scoped>
.detail-view {
  padding: 0 20px 40px;
  max-width: 860px;
}

/* Top bar */
.detail-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 0 16px;
  /* Push below the Nextcloud top-bar / sidebar-toggle button */
  padding-top: calc(var(--default-clickable-area, 44px) + 8px);
  position: sticky;
  top: 0;
  background: var(--color-main-background);
  z-index: 10;
}

.detail-topbar-actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}

.detail-enriching {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
}

/* Hero */
.detail-hero {
  display: grid;
  grid-template-columns: 260px 1fr;
  gap: 32px;
  margin-bottom: 36px;
}

@media (max-width: 640px) {
  .detail-hero {
    grid-template-columns: 1fr;
  }
}

.detail-artwork {
  width: 100%;
  aspect-ratio: 1;
  border-radius: var(--border-radius-large);
  background: var(--color-background-dark);
}

.detail-hero-info {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.detail-title {
  margin: 0;
  font-size: 1.8em;
  line-height: 1.2;
}

.detail-artist {
  margin: 0;
  font-size: 1.1em;
  color: var(--color-text-maxcontrast);
}

/* Badges */
.detail-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 0.8em;
  font-weight: 600;
}

.badge-format {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
}

.badge-year,
.badge-country {
  background: var(--color-background-dark);
  color: var(--color-main-text);
}

.badge-wanted {
  background: var(--color-warning);
  color: #fff;
}

/* Metadata */
.detail-meta {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 4px 16px;
  margin: 0;
  font-size: 0.875em;
}

.detail-meta dt {
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
}

.detail-meta dd {
  margin: 0;
}

/* Sections */
.detail-section {
  margin-bottom: 32px;
}

.detail-section h3 {
  font-size: 0.85em;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--color-text-maxcontrast);
  margin: 0 0 14px;
}

/* Tracklist */
.detail-tracklist {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9em;
}

.detail-tracklist tr:hover {
  background: var(--color-background-hover);
}

.track-pos {
  width: 3em;
  color: var(--color-text-maxcontrast);
  padding: 5px 8px 5px 0;
  text-align: right;
}

.track-title {
  padding: 5px 8px;
}

.track-dur {
  padding: 5px 0 5px 8px;
  color: var(--color-text-maxcontrast);
  text-align: right;
  white-space: nowrap;
}

/* Bio */
.detail-bio {
  font-size: 0.9em;
  line-height: 1.6;
  color: var(--color-main-text);
  white-space: pre-line;
  margin: 0 0 10px;
  max-height: 220px;
  overflow-y: auto;
}

.detail-notes-text {
  font-size: 0.9em;
  line-height: 1.6;
  white-space: pre-line;
  margin: 0;
  max-height: 180px;
  overflow-y: auto;
}

.detail-members {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
}
</style>
