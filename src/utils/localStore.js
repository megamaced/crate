/**
 * localStorage access that cannot take a component down.
 *
 * Both reads and writes throw in contexts where site data is unavailable
 * (private-mode Safari, a sandboxed iframe, a browser set to refuse storage),
 * and writes additionally throw on quota exhaustion. A throw during `setup()`
 * destroys the component, so every access in the app goes through these.
 */

/**
 * @param {string} key storage key
 * @param {string|null} defaultValue value to use when absent or unreadable
 * @returns {string|null}
 */
export function readString(key, defaultValue = null) {
  try {
    return localStorage.getItem(key) ?? defaultValue
  } catch {
    return defaultValue
  }
}

/**
 * @param {string} key storage key
 * @param {boolean} defaultValue value to use when absent or unreadable
 * @returns {boolean}
 */
export function readBool(key, defaultValue) {
  try {
    const val = localStorage.getItem(key)
    if (val === null) return defaultValue
    return val === 'true'
  } catch {
    return defaultValue
  }
}

/**
 * @param {string} key storage key
 * @param {string} value value to store
 */
export function safeSet(key, value) {
  try {
    localStorage.setItem(key, value)
  } catch {
    // Storage unavailable or full — the in-memory value still stands.
  }
}
