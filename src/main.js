import { createApp } from 'vue'
import '@nextcloud/dialogs/style.css'
import { showError } from './utils/toast.js'
import App from './App.vue'
import { n, t } from './l10n.js'
import { installErrorInterceptor } from './utils/apiErrors.js'

installErrorInterceptor()

const app = createApp(App)

// Every template can call t() and n() without importing them.
app.config.globalProperties.t = t
app.config.globalProperties.n = n

app.config.errorHandler = (err) => {
  console.error('[Crate] Unhandled error:', err)
  showError(t('crate', 'An unexpected error occurred.'))
}

app.mount('#app')
