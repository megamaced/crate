<template>
	<div>
		<h2>{{ t('crate', 'Template text') }}</h2>
		<NcButton :aria-label="t('crate', 'Attribute text')" />
		<p>{{ t('crate', 'Hello {name}', { name }) }}</p>
		<!-- TRANSLATORS: shown when a shelf has nothing on it -->
		<p>{{ t('crate', 'Nothing here yet') }}</p>
		<p>{{ n('crate', '%n record', '%n records', count) }}</p>
		<p>{{ t('crate', 'Don\'t panic') }}</p>
	</div>
</template>

<script>
import { n, t } from '../l10n.js'

export default {
	computed: {
		label() {
			return t('crate', 'Script text')
		},
		summary() {
			return n('crate', '%n script item', '%n script items', this.count)
		},
	},
}
</script>
