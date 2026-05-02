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
        },
    },
]
