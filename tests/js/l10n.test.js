import { register } from '@nextcloud/l10n'
import { describe, expect, it } from 'vitest'
import { createApp } from 'vue'
import { n, t } from '../../src/l10n.js'

// What a template shows, rendered by Vue the way Crate's components render it.
function rendered(template, data = {}) {
  const el = document.createElement('div')
  const app = createApp({ template, data: () => data })
  app.config.globalProperties.t = t
  app.config.globalProperties.n = n
  app.mount(el)
  const text = el.textContent
  app.unmount()
  return text
}

const tricky = 'A < B & C &lt; D <b>bold</b>'

describe('translations show as written text', () => {
  it('keeps angle brackets, ampersands and entities in a source string', () => {
    expect(t('crate', 'Shelf <unknown> & friends')).toBe('Shelf <unknown> & friends')
    expect(rendered("{{ t('crate', 'Shelf <unknown> & friends') }}")).toBe('Shelf <unknown> & friends')
  })

  it('keeps them in placeholder values', () => {
    expect(t('crate', 'Owned by {name}', { name: tricky })).toBe(`Owned by ${tricky}`)
    expect(rendered("{{ t('crate', 'Owned by {name}', { name }) }}", { name: tricky })).toBe(`Owned by ${tricky}`)
  })

  it('keeps them in a translation', () => {
    register('crate', { 'Fixture shelf': 'Étagère « <x> » & co &amp;' })
    expect(rendered("{{ t('crate', 'Fixture shelf') }}")).toBe('Étagère « <x> » & co &amp;')
  })

  it('keeps them in plural forms', () => {
    register('crate', { '_%n fixture record_::_%n fixture records_': ['%n disque <a>', '%n disques & co'] })
    expect(rendered("{{ n('crate', '%n fixture record', '%n fixture records', 1) }}")).toBe('1 disque <a>')
    expect(rendered("{{ n('crate', '%n fixture record', '%n fixture records', 3) }}")).toBe('3 disques & co')
  })

  it('leaves a placeholder with no value as written', () => {
    expect(t('crate', 'Hello {name}')).toBe('Hello {name}')
  })
})
