<template>
  <NcAppSettingsDialog
    :open="open"
    :show-navigation="false"
    name="Crate settings"
    @update:open="$emit('update:open', $event)"
  >
    <NcAppSettingsSection
      id="crate-settings-discogs"
      name="Discogs"
    >
      <p class="settings-hint">
        Crate uses the
        <a
          href="https://www.discogs.com/developers/"
          target="_blank"
          rel="noopener"
        >Discogs API</a>
        to search for album metadata and artwork. Enter your personal access token below.
        You can generate one at
        <a
          href="https://www.discogs.com/settings/developers"
          target="_blank"
          rel="noopener"
        >discogs.com/settings/developers</a>.
      </p>

      <div class="settings-field">
        <label for="discogs-token">Personal access token</label>
        <div class="settings-token-row">
          <input
            id="discogs-token"
            v-model="tokenInput"
            :type="showToken ? 'text' : 'password'"
            :placeholder="hasToken ? '(token saved — paste a new one to replace)' : 'Paste your token here'"
            autocomplete="off"
          >
          <NcButton
            variant="tertiary"
            :aria-label="showToken ? 'Hide token' : 'Show token'"
            @click="showToken = !showToken"
          >
            {{ showToken ? 'Hide' : 'Show' }}
          </NcButton>
        </div>
      </div>

      <div class="settings-actions">
        <NcButton
          variant="primary"
          :disabled="saving || tokenInput === ''"
          @click="save"
        >
          {{ saving ? 'Saving…' : 'Save token' }}
        </NcButton>
        <NcButton
          v-if="hasToken"
          variant="tertiary"
          :disabled="saving"
          @click="clearToken"
        >
          Remove token
        </NcButton>
        <span
          v-if="savedMessage"
          class="settings-saved"
        >{{ savedMessage }}</span>
      </div>
    </NcAppSettingsSection>

    <NcAppSettingsSection
      id="crate-settings-enrich"
      name="Enrichment"
    >
      <p class="settings-hint">
        Enrich your entire collection with artwork, tracklists, genres and artist info from Discogs.
        Only items without existing enrichment data will be processed.
      </p>
      <div class="settings-actions">
        <NcButton
          variant="secondary"
          :disabled="!hasToken || enrich.running.value"
          @click="enrichAll"
        >
          {{ enrich.running.value ? `Enriching… ${enrich.done.value} / ${enrich.total.value}` : 'Enrich all un-enriched items' }}
        </NcButton>
        <NcButton
          v-if="enrich.running.value"
          variant="tertiary"
          @click="enrich.cancel()"
        >
          Stop
        </NcButton>
        <span
          v-if="!hasToken"
          class="settings-hint"
          style="margin:0"
        >Requires a Discogs token — save one above first.</span>
      </div>
    </NcAppSettingsSection>

    <NcAppSettingsSection
      id="crate-settings-danger"
      name="Danger zone"
    >
      <p class="settings-hint">
        Permanently delete every item in your collection and wishlist. This cannot be undone.
      </p>
      <div class="settings-actions">
        <NcButton
          variant="error"
          :disabled="wiping"
          @click="confirmWipe = true"
        >
          {{ wiping ? 'Wiping…' : 'Wipe collection' }}
        </NcButton>
        <span
          v-if="wipedMessage"
          class="settings-saved"
        >{{ wipedMessage }}</span>
      </div>

      <NcDialog
        v-if="confirmWipe"
        name="Wipe collection"
        :open="confirmWipe"
        @closing="confirmWipe = false"
      >
        <p>This will permanently delete <strong>all items</strong> from your collection and wishlist. There is no undo.</p>
        <template #actions>
          <NcButton
            type="button"
            variant="tertiary"
            @click="confirmWipe = false"
          >
            Cancel
          </NcButton>
          <NcButton
            type="button"
            variant="error"
            @click="wipeCollection"
          >
            Yes, wipe everything
          </NcButton>
        </template>
      </NcDialog>
    </NcAppSettingsSection>
  </NcAppSettingsDialog>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { NcAppSettingsDialog, NcAppSettingsSection, NcButton, NcDialog } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { useEnrichQueue } from '../composables/useEnrichQueue.js'

defineProps({
  open: { type: Boolean, required: true },
})
defineEmits(['update:open'])

const enrich = useEnrichQueue()

const tokenInput = ref('')
const hasToken = ref(false)
const showToken = ref(false)
const saving = ref(false)
const savedMessage = ref('')

const confirmWipe = ref(false)
const wiping = ref(false)
const wipedMessage = ref('')

async function load() {
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/settings/discogs-token'))
    hasToken.value = res.data.ocs?.data?.hasToken ?? false
  } catch (e) {
    console.error('Failed to load settings', e)
  }
}

async function save() {
  saving.value = true
  savedMessage.value = ''
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/discogs-token'), {
      token: tokenInput.value,
    })
    hasToken.value = tokenInput.value !== ''
    tokenInput.value = ''
    savedMessage.value = 'Saved!'
    setTimeout(() => { savedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to save token', e)
    savedMessage.value = 'Failed to save.'
  } finally {
    saving.value = false
  }
}

async function clearToken() {
  saving.value = true
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/discogs-token'), { token: '' })
    hasToken.value = false
    savedMessage.value = 'Token removed.'
    setTimeout(() => { savedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to clear token', e)
  } finally {
    saving.value = false
  }
}

async function wipeCollection() {
  confirmWipe.value = false
  wiping.value = true
  wipedMessage.value = ''
  try {
    await axios.delete(generateOcsUrl('/apps/crate/api/v1/media'))
    wipedMessage.value = 'Collection wiped.'
    setTimeout(() => { wipedMessage.value = '' }, 4000)
  } catch (e) {
    console.error('Failed to wipe collection', e)
    wipedMessage.value = 'Failed — check the console.'
  } finally {
    wiping.value = false
  }
}

onMounted(load)

async function enrichAll() {
  if (enrich.running.value) return
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/media'))
    const all = res.data.ocs?.data ?? []
    const needsEnrich = all.filter(item =>
      !item.genres && !item.artistBio
      && !(Array.isArray(item.tracklist) && item.tracklist.length > 0),
    ).map(item => item.id)
    if (needsEnrich.length > 0) {
      enrich.start(needsEnrich)
    }
  } catch (e) {
    console.error('Failed to load items for enrichment', e)
  }
}
</script>

<style scoped>
.settings-hint {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
  margin-bottom: 16px;
  line-height: 1.5;
}

.settings-hint a {
  color: var(--color-primary-element);
}

.settings-field {
  margin-bottom: 12px;
}

.settings-field label {
  display: block;
  font-size: 0.875em;
  font-weight: 500;
  margin-bottom: 6px;
}

.settings-token-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.settings-token-row input {
  flex: 1;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 6px 10px;
  font-size: 1em;
  font-family: monospace;
}

.settings-token-row input:focus {
  border-color: var(--color-primary-element);
  outline: none;
}

.settings-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.settings-saved {
  font-size: 0.875em;
  color: var(--color-success);
}
</style>
