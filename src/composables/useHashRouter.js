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

  let pendingHashSets = 0

  function setHash(hash) {
    pendingHashSets++
    window.location.hash = hash
  }

  function hashForView(v, id = null) {
    if (v === 'detail' && id) return `#/detail/${id}`
    if (v === 'playlist-detail' && id) return `#/playlists/${id}`
    if (v === 'collection') return '#/collection'
    if (v === 'wishlist') return '#/wishlist'
    if (v === 'playlists') return '#/playlists'
    if (v === 'shared') return '#/shared'
    return '#/'
  }

  function parseHash() {
    const parts = (window.location.hash || '#/').replace(/^#\//, '').split('/')
    if (parts[0] === 'detail' && parts[1]) return { view: 'detail', itemId: parseInt(parts[1], 10), playlistId: null }
    if (parts[0] === 'playlists' && parts[1]) return { view: 'playlist-detail', itemId: null, playlistId: parseInt(parts[1], 10) }
    if (parts[0] === 'playlists') return { view: 'playlists', itemId: null, playlistId: null }
    if (parts[0] === 'collection') return { view: 'collection', itemId: null, playlistId: null }
    if (parts[0] === 'wishlist') return { view: 'wishlist', itemId: null, playlistId: null }
    if (parts[0] === 'shared') return { view: 'shared', itemId: null, playlistId: null }
    return { view: 'home', itemId: null, playlistId: null }
  }

  function consumePendingHash() {
    if (pendingHashSets > 0) {
      pendingHashSets--
      return true
    }
    return false
  }

  function saveScroll(el) {
    savedScrollTop.value = el?.scrollTop ?? 0
  }

  async function restoreScroll(el) {
    const top = savedScrollTop.value
    await nextTick()
    el?.scrollTo({ top, behavior: 'instant' })
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
