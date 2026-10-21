import { onBeforeUnmount, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { t } from '../l10n.js'
import { showError } from '../utils/toast.js'

/**
 * Reusable composable for managing a single API token/key setting.
 *
 * @param {object} options
 * @param {string} options.endpoint - OCS endpoint path (e.g. '/settings/discogs-token')
 * @param {string} options.payloadKey - Key name in the POST body ('token' or 'key')
 * @param {string} options.responseKey - Key in response to check presence ('hasToken' or 'hasKey')
 * @param {string} options.label - English label for console messages
 * @param {() => string} options.name - The setting's translated name for messages the user sees, e.g. "Discogs token"
 */
export function useTokenSetting({ endpoint, payloadKey = 'token', responseKey = 'hasToken', label, name }) {
	const input = ref('')
	const hasValue = ref(false)
	const saving = ref(false)
	const message = ref('')

	const url = () => generateOcsUrl(`/apps/crate/api/v1${endpoint}`)

	// One handle for the transient "Saved!" / "removed." message. A second save
	// must not have the first save's timer wipe its message, and the timer must
	// not outlive the component.
	let messageTimer = null
	function flashMessage(text, ms = 3000) {
		clearTimeout(messageTimer)
		message.value = text
		messageTimer = setTimeout(() => { message.value = '' }, ms)
	}
	onBeforeUnmount(() => clearTimeout(messageTimer))

	async function load() {
		try {
			const res = await axios.get(url())
			hasValue.value = res.data.ocs?.data?.[responseKey] ?? false
		} catch (e) {
			console.error(`Failed to load ${label}`, e)
		}
	}

	async function save() {
		saving.value = true
		clearTimeout(messageTimer)
		message.value = ''
		try {
			await axios.post(url(), { [payloadKey]: input.value })
			hasValue.value = input.value !== ''
			flashMessage(t('crate', 'Saved!'))
		} catch (e) {
			console.error(`Failed to save ${label}`, e?.response?.status)
			// TRANSLATORS: {name} is a key's name, e.g. "Discogs token" or "RAWG key"
			showError(t('crate', 'Failed to save {name}', { name: name() }))
			clearTimeout(messageTimer)
			message.value = t('crate', 'Failed to save.')
		} finally {
			saving.value = false
		}
	}

	async function clear() {
		saving.value = true
		try {
			await axios.post(url(), { [payloadKey]: '' })
			hasValue.value = false
			input.value = ''
			// TRANSLATORS: {name} is a key's name, e.g. "Discogs token" or "RAWG key"
			flashMessage(t('crate', '{name} removed.', { name: name() }))
		} catch (e) {
			console.error(`Failed to clear ${label}`, e?.response?.status)
			// TRANSLATORS: {name} is a key's name, e.g. "Discogs token" or "RAWG key"
			showError(t('crate', 'Failed to clear {name}', { name: name() }))
		} finally {
			saving.value = false
		}
	}

	return { input, hasValue, saving, message, load, save, clear }
}
