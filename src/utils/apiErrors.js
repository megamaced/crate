/**
 * Global axios response interceptor. Surfaces server / network errors as
 * user-visible toasts so components that forget to add a `.catch()` still
 * tell the user something went wrong.
 *
 * Per-call opt-out: set `__silent: true` on the axios request config
 * (useful for background queues that surface their own progress UI).
 *
 * Per-call override: set `__errorMessage: 'text'` to override the default
 * message shown for this call.
 */
import axios from '@nextcloud/axios'
import { showError, showWarning } from './toast.js'

export function installErrorInterceptor() {
  axios.interceptors.response.use(
    (response) => response,
    (error) => {
      const cfg     = error.config || {}
      const silent  = cfg.__silent === true
      const status  = error.response?.status
      const custom  = cfg.__errorMessage

      if (!silent) {
        if (custom) {
          showError(custom)
        } else if (status === 429) {
          showWarning('Rate limit reached. Please wait and try again.')
        } else if (status === 401 || status === 403) {
          showError('Not authorised for this action.')
        } else if (status && status >= 500) {
          showError('Server error. Please try again.')
        } else if (!error.response) {
          // No response = network failure / timeout / CORS
          showError('Network error. Check your connection.')
        }
        // 4xx (other than auth/rate-limit) left to callers — often "not found"
        // is expected and the component shows its own empty-state UX.
      }

      return Promise.reject(error)
    }
  )
}
