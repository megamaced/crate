// Flat-config equivalent of the legacy .eslintrc.js — required by ESLint v9+.
const js = require('@eslint/js')
const pluginVue = require('eslint-plugin-vue')
const globals = require('globals')

module.exports = [
    js.configs.recommended,
    // eslint-plugin-vue v10 dropped the `vue3-` prefix; `flat/recommended` is Vue 3.
    ...pluginVue.configs['flat/recommended'],
    {
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.node,
                ...globals.es2021,
            },
        },
        rules: {
            'vue/multi-word-component-names': 'off',
            'no-restricted-imports': ['error', {
                paths: [{
                    name: '@nextcloud/l10n',
                    importNames: ['t', 'n', 'translate', 'translatePlural'],
                    message: "Import t and n from src/l10n.js, which turns off the HTML escaping and sanitising that Crate's text-only rendering doesn't want.",
                }, {
                    name: '@nextcloud/dialogs',
                    importNames: ['showError', 'showWarning', 'showInfo', 'showSuccess', 'showMessage'],
                    message: 'Use src/utils/toast.js, which shows the message as text rather than parsing it as HTML (add a wrapper there if you need another kind of toast).',
                }],
            }],
        },
    },
    {
        files: ['src/l10n.js', 'src/utils/toast.js'],
        rules: {
            'no-restricted-imports': 'off',
        },
    },
]
