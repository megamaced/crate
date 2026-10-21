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

describe('a book picked from search', () => {
  const work = { overview: 'A hobbit goes on an adventure.', artworkUrl: 'work-L.jpg' }

  it('keeps the cover of the edition it shows over the work cover', () => {
    const row = { title: 'Der Kleine Hobbit', artworkUrl: 'edition-L.jpg', thumb: 'edition-M.jpg', coverFromEdition: true }

    const merged = ENRICHMENT_PROVIDERS.book.mergeDetail(row, work)

    expect(merged.artworkUrl).toBe('edition-L.jpg')
    expect(merged.thumb).toBe('edition-M.jpg')
    expect(merged.title).toBe('Der Kleine Hobbit')
    expect(merged.overview).toBe(work.overview)
  })

  it('shows no cover rather than the work cover when the edition has none', () => {
    const row = { title: 'Der Kleine Hobbit', artworkUrl: null, thumb: null, coverFromEdition: true }

    expect(ENRICHMENT_PROVIDERS.book.mergeDetail(row, work).artworkUrl).toBeNull()
  })

  it('takes the work cover when the row is the work', () => {
    const merged = ENRICHMENT_PROVIDERS.book.mergeDetail({ title: 'The Hobbit', artworkUrl: 'row-L.jpg' }, work)

    expect(merged.artworkUrl).toBe('work-L.jpg')
  })
})
