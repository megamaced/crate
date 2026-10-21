import { afterEach, describe, expect, it } from 'vitest'
import { createApp, nextTick } from 'vue'
import StarRating from '../../src/components/StarRating.vue'

let app = null

afterEach(() => {
  app?.unmount()
  app = null
  document.body.innerHTML = ''
})

/** Mount an editable control bound to a rating; returns its root and the model. */
function mountEditable(initial = null) {
  const root = document.createElement('div')
  document.body.appendChild(root)
  const vm = createApp({
    components: { StarRating },
    data: () => ({ rating: initial }),
    template: '<StarRating v-model="rating" legend="Rating" />',
  })
  app = vm
  return { root, model: vm.mount(root) }
}

const filledStars = root => root.querySelectorAll('.star-rating__star--on').length
const options = root => [...root.querySelectorAll('.star-rating__option')]

describe('the editable star rating', () => {
  it('sets the rating from the star chosen', async () => {
    const { root, model } = mountEditable()

    options(root)[3].querySelector('input').click()
    await nextTick()

    expect(model.rating).toBe(4)
    expect(filledStars(root)).toBe(4)
  })

  it('shows no stars once cleared, even with the pointer still over the control', async () => {
    const { root, model } = mountEditable()
    const five = options(root)[4]
    const none = options(root)[5]

    five.dispatchEvent(new MouseEvent('mouseenter'))
    five.querySelector('input').click()
    await nextTick()
    expect(filledStars(root)).toBe(5)

    // Straight from the fifth star to "Not rated", without leaving the control.
    none.dispatchEvent(new MouseEvent('mouseenter'))
    none.querySelector('input').click()
    await nextTick()

    expect(model.rating).toBeNull()
    expect(filledStars(root)).toBe(0)
  })

  it('ends a hover preview when a choice is made from the keyboard', async () => {
    const { root, model } = mountEditable(5)

    options(root)[1].dispatchEvent(new MouseEvent('mouseenter'))
    await nextTick()
    expect(filledStars(root)).toBe(2)

    // Arrow keys select a radio without moving the pointer.
    const none = options(root)[5].querySelector('input')
    none.checked = true
    none.dispatchEvent(new Event('change'))
    await nextTick()

    expect(model.rating).toBeNull()
    expect(filledStars(root)).toBe(0)
  })
})

describe('the read-only star rating', () => {
  it('reads out as one label', async () => {
    const root = document.createElement('div')
    document.body.appendChild(root)
    app = createApp({
      components: { StarRating },
      template: '<StarRating :model-value="3" readonly />',
    })
    app.mount(root)
    await nextTick()

    const rating = root.querySelector('[role="img"]')
    expect(rating.getAttribute('aria-label')).toBe('3 out of 5 stars')
    expect(filledStars(root)).toBe(3)
  })
})
