<template>
  <NcAppSettingsDialog
    :open="open"
    :show-navigation="false"
    name="Crate settings"
    @update:open="$emit('update:open', $event)"
  >
    <NcAppSettingsSection
      id="crate-settings-enrich"
      name="Enrichment"
    >
      <p class="settings-hint">
        Crate uses the
        <a
          href="https://www.discogs.com/developers/"
          target="_blank"
          rel="noopener"
        >Discogs API</a>
        to fetch album metadata, artwork, tracklists, genres and artist info.
        Enter your personal access token below — you can generate one at
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

      <div class="settings-enrichment-options">
        <NcCheckboxRadioSwitch
          v-model="autoEnrichOnClick"
          :disabled="!hasToken"
        >
          Auto-enrich albums when clicking on them
        </NcCheckboxRadioSwitch>

        <div class="settings-actions settings-enrich-all">
          <NcButton
            variant="secondary"
            :disabled="!hasToken || enrich.running.value || marketQueue.running.value"
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
          >Add a token above to enable enrichment.</span>
        </div>
      </div>
    </NcAppSettingsSection>

    <NcAppSettingsSection
      id="crate-settings-market"
      name="Market values"
    >
      <p class="settings-hint">
        Fetches the current lowest Discogs marketplace price for each album.
        Prices are cached on the item and shown in the collection, album detail, and home page.
      </p>

      <div class="settings-enrichment-options">
        <NcCheckboxRadioSwitch
          v-model="autoFetchMarketRates"
          :disabled="!hasToken"
        >
          Fetch market rates automatically
        </NcCheckboxRadioSwitch>

        <div class="settings-field">
          <label for="market-currency">Currency</label>
          <select
            id="market-currency"
            v-model="marketCurrency"
            class="settings-currency-select"
          >
            <option
              v-for="c in currencies"
              :key="c"
              :value="c"
            >
              {{ c }}
            </option>
          </select>
        </div>

        <div class="settings-actions settings-enrich-all">
          <NcButton
            variant="secondary"
            :disabled="!hasToken || marketQueue.running.value || enrich.running.value"
            @click="refreshAllMarketRates"
          >
            {{ marketQueue.running.value
              ? `Fetching… ${marketQueue.done.value} / ${marketQueue.total.value}`
              : 'Refresh all market rates' }}
          </NcButton>
          <NcButton
            v-if="marketQueue.running.value"
            variant="tertiary"
            @click="marketQueue.cancel()"
          >
            Stop
          </NcButton>
          <span
            v-if="!hasToken"
            class="settings-hint"
            style="margin:0"
          >Add a Discogs token above to enable market rates.</span>
        </div>
      </div>
    </NcAppSettingsSection>

    <NcAppSettingsSection
      id="crate-settings-danger"
      name="Danger zone"
    >
      <p class="settings-hint">
        Permanently delete every item in your collection and wishlist, all playlists, and any shares you have created. This cannot be undone.
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
        <p>This will permanently delete <strong>all items</strong> from your collection and wishlist, along with <strong>all playlists</strong> and <strong>shares you have created</strong>. There is no undo.</p>
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
import { NcAppSettingsDialog, NcAppSettingsSection, NcButton, NcCheckboxRadioSwitch, NcDialog } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { useEnrichQueue } from '../composables/useEnrichQueue.js'
import { useMarketValueQueue } from '../composables/useMarketValueQueue.js'
import { useSettings } from '../composables/useSettings.js'

defineProps({
  open: { type: Boolean, required: true },
})
const emit = defineEmits(['update:open', 'token-changed'])

const enrich = useEnrichQueue()
const marketQueue = useMarketValueQueue()
const { autoEnrichOnClick, autoFetchMarketRates, marketCurrency } = useSettings()

const currencies = ['GBP', 'USD', 'EUR', 'CAD', 'AUD', 'JPY', 'CHF', 'MXN', 'BRL', 'NZD', 'SEK', 'ZAR']

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
    showError('Failed to load settings')
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
    emit('token-changed', hasToken.value)
    tokenInput.value = ''
    savedMessage.value = 'Saved!'
    setTimeout(() => { savedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to save token', e)
    showError('Failed to save Discogs token')
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
    emit('token-changed', false)
    savedMessage.value = 'Token removed.'
    setTimeout(() => { savedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to clear token', e)
    showError('Failed to clear Discogs token')
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
    showError('Failed to wipe collection')
    wipedMessage.value = 'Failed — check the console.'
  } finally {
    wiping.value = false
  }
}

onMounted(load)

async function refreshAllMarketRates() {
  if (marketQueue.running.value) return
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/media'))
    const all = res.data.ocs?.data ?? []
    const ids = all.filter(item => item.discogsId).map(item => item.id)
    if (ids.length > 0) {
      marketQueue.start(ids, marketCurrency.value)
    }
  } catch (e) {
    console.error('Failed to load items for market rate refresh', e)
  }
}

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
  color: #4ade80;
}

.settings-enrichment-options {
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.settings-enrich-all {
  margin-top: 4px;
}

.settings-currency-select {
  display: block;
  margin-top: 6px;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 6px 10px;
  font-size: 1em;
  min-width: 120px;
}

.settings-currency-select:focus {
  border-color: var(--color-primary-element);
  outline: none;
}
</style>
