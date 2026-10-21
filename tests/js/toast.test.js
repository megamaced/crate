import { afterEach, describe, expect, it } from 'vitest'
import { showError, showWarning } from '../../src/utils/toast.js'

afterEach(() => {
  document.body.innerHTML = ''
})

describe('toasts show their message exactly as given', () => {
  it.each([
    ['angle brackets', 'Price < £5 > none'],
    ['an ampersand', 'Rock & Roll'],
    ['a literal entity', 'Type &lt; then &amp;'],
    ['markup', 'Not <b>bold</b>, <img src=x onerror=alert(1)>'],
  ])('keeps %s', (_label, message) => {
    showError(message)
    const toast = document.querySelector('[role="alert"]')
    expect(toast.textContent).toContain(message)
    expect(toast.querySelector('b, img')).toBeNull()
  })

  it('does the same for warnings', () => {
    showWarning('Shelf <unknown> & co')
    expect(document.body.textContent).toContain('Shelf <unknown> & co')
  })
})
