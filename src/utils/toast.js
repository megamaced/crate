// Toasts that show their message exactly as given. @nextcloud/dialogs parses a
// string message as HTML and keeps only its visible text, so "a < b" or a
// literal "&lt;", in a translation or in a message from the server, would show
// altered or lose part of itself. Toastify accepts element nodes, so pass a
// span with textContent to preserve the literal message.
import {
  showError as showErrorNode,
  showWarning as showWarningNode,
} from '@nextcloud/dialogs'

function messageNode(text) {
  const message = document.createElement('span')
  message.textContent = text
  return message
}

export const showError = (text, options) =>
  showErrorNode(messageNode(text), options)

export const showWarning = (text, options) =>
  showWarningNode(messageNode(text), options)
