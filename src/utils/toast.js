// Toasts that show their message exactly as given. @nextcloud/dialogs parses a
// string message as HTML and keeps only its visible text, so "a < b" or a
// literal "&lt;", in a translation or in a message from the server, would show
// altered or lose part of itself. Handed a text node, it displays the node
// as is.
import {
  showError as showErrorNode,
  showWarning as showWarningNode,
} from '@nextcloud/dialogs'

export const showError = (text, options) =>
  showErrorNode(document.createTextNode(text), options)

export const showWarning = (text, options) =>
  showWarningNode(document.createTextNode(text), options)
