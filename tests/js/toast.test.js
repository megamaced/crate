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
    const toast = document.querySelector('[aria-live="assertive"]')
    expect(toast).not.toBeNull()
    expect(toast.textContent).toContain(message)
    expect(toast.querySelector('b, img')).toBeNull()
  })

  it('does the same for warnings', () => {
    showWarning('Shelf <unknown> & co')
    const toast = document.querySelector('[aria-live="polite"]')
    expect(toast).not.toBeNull()
    expect(toast.textContent).toContain('Shelf <unknown> & co')
  })
})
