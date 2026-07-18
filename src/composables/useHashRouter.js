/**
 * Hash-based router for the Crate SPA.
 *
 * Manages view state, URL hash sync, and back-navigation with scroll restore.
 */
import { ref, nextTick } from 'vue'

export function useHashRouter() {
  const view = ref('home')
  const previousView = ref('home')
  const savedScrollTop = ref(0)

  let lastSetHash = null

  function setHash(hash) {
    lastSetHash = hash
    window.location.hash = hash
  }

  function hashForView(v, id = null) {
    if (v === 'detail' && id) return `#/detail/${id}`
    if (v === 'playlist-detail' && id) return `#/playlists/${id}`
    if (v === 'music') return '#/music'
    if (v === 'films') return '#/films'
    if (v === 'books') return '#/books'
    if (v === 'comics') return '#/comics'
    if (v === 'games') return '#/games'
    if (v === 'playlists') return '#/playlists'
    if (v === 'shared') return '#/shared'
    if (v === 'shared-cat' && id) return `#/shared-cat/${id}`
    if (v === 'shared-playlists') return '#/shared-playlists'
    if (v === 'shared-by-me') return '#/shared-by-me'
    return '#/'
  }

  function parseHash() {
    const parts = (window.location.hash || '#/').replace(/^#\//, '').split('/')
    if (parts[0] === 'detail' && parts[1]) return { view: 'detail', itemId: parseInt(parts[1], 10), playlistId: null }
    if (parts[0] === 'playlists' && parts[1]) return { view: 'playlist-detail', itemId: null, playlistId: parseInt(parts[1], 10) }
    if (parts[0] === 'playlists') return { view: 'playlists', itemId: null, playlistId: null }
    if (parts[0] === 'music') return { view: 'music', itemId: null, playlistId: null }
    if (parts[0] === 'films') return { view: 'films', itemId: null, playlistId: null }
    if (parts[0] === 'books') return { view: 'books', itemId: null, playlistId: null }
    if (parts[0] === 'comics') return { view: 'comics', itemId: null, playlistId: null }
    if (parts[0] === 'games') return { view: 'games', itemId: null, playlistId: null }
    // Legacy hash routes redirect to music
    if (parts[0] === 'collection' || parts[0] === 'wishlist') return { view: 'music', itemId: null, playlistId: null }
    if (parts[0] === 'shared-cat' && parts[1]) return { view: 'shared-cat', itemId: null, playlistId: null, category: decodeURIComponent(parts[1]) }
    if (parts[0] === 'shared-playlists') return { view: 'shared-playlists', itemId: null, playlistId: null }
    if (parts[0] === 'shared') return { view: 'shared', itemId: null, playlistId: null }
    if (parts[0] === 'shared-by-me') return { view: 'shared-by-me', itemId: null, playlistId: null }
    return { view: 'home', itemId: null, playlistId: null }
  }

  function consumePendingHash() {
    if (lastSetHash !== null && window.location.hash === lastSetHash) {
      lastSetHash = null
      return true
    }
    lastSetHash = null
    return false
  }

  function saveScroll(el) {
    savedScrollTop.value = el?.scrollTop ?? 0
  }

  async function restoreScroll(el) {
    const top = savedScrollTop.value
    if (!el || top === 0) return
    // Wait for Vue to flush DOM updates, then use a short rAF so the
    // browser has actually laid out the now-visible content.
    await nextTick()
    await new Promise(resolve => requestAnimationFrame(resolve))
    el.scrollTo({ top, behavior: 'instant' })
  }

  return {
    view,
    previousView,
    savedScrollTop,
    setHash,
    hashForView,
    parseHash,
    consumePendingHash,
    saveScroll,
    restoreScroll,
  }
}
