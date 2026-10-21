import { register } from '@nextcloud/l10n'
import { describe, expect, it } from 'vitest'
import { ENRICHMENT_PROVIDERS, resultMetaLine } from '../../src/utils/enrichmentProviders.js'

describe('a search result line', () => {
  it('shows a format by its display name and keeps the stored value', () => {
    register('crate', { Cassette: 'Kassette' })
    const result = { title: 'Low-Life', format: 'Cassette', year: 1985, label: 'Factory' }

    expect(resultMetaLine(ENRICHMENT_PROVIDERS.music, result)).toBe('Kassette, 1985, Factory')
    expect(result.format).toBe('Cassette')
  })

  it('shows a product name as it is', () => {
    expect(resultMetaLine(ENRICHMENT_PROVIDERS.music, { format: 'CD', year: 2001 })).toBe('CD, 2001')
  })

  it('prints only the fields the provider lists', () => {
    expect(resultMetaLine(ENRICHMENT_PROVIDERS.film, { format: 'Cassette', year: 1999 })).toBe('1999')
  })
})
