import { describe, expect, it } from 'vitest'
import { compareItems, sortItems } from '../../src/utils/sortItems.js'

const items = [
  { id: 1, artist: 'Can', title: 'Tago Mago', rating: 3 },
  { id: 2, artist: 'Neu!', title: 'Neu!', rating: null },
  { id: 3, artist: 'Faust', title: 'IV', rating: 5 },
  { id: 4, artist: 'Can', title: 'Ege Bamyasi', rating: 3 },
  { id: 5, artist: 'Cluster', title: 'Zuckerzeit' },
  { id: 6, artist: 'Harmonia', title: 'Musik von Harmonia', rating: 1 },
]

const ids = list => list.map(item => item.id)

describe('sorting by rating', () => {
  it('counts down from five stars and ends with the unrated', () => {
    expect(ids(sortItems(items, 'rating', 'desc'))).toEqual([3, 4, 1, 6, 5, 2])
  })

  it('counts up from one star and still ends with the unrated', () => {
    expect(ids(sortItems(items, 'rating', 'asc'))).toEqual([6, 4, 1, 3, 5, 2])
  })

  it('orders equal ratings by artist then title', () => {
    const tied = sortItems(items, 'rating', 'desc').filter(item => item.rating === 3)
    expect(tied.map(item => item.title)).toEqual(['Ege Bamyasi', 'Tago Mago'])
  })

  it('agrees with compareItems in both directions', () => {
    for (const dir of ['asc', 'desc']) {
      const viaCompare = [...items].sort((a, b) => compareItems(a, b, 'rating', dir))
      expect(ids(viaCompare)).toEqual(ids(sortItems(items, 'rating', dir)))
    }
  })
})
