import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  // Compiles the single-file components the component tests mount.
  plugins: [vue()],
  resolve: {
    alias: {
      // The full build, which can compile the templates the tests mount.
      vue: 'vue/dist/vue.esm-bundler.js',
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/js/**/*.test.js'],
    server: {
      deps: {
        // Shipped with CSS imports, so they go through Vite rather than Node.
        inline: ['@nextcloud/dialogs', '@nextcloud/vue'],
      },
    },
  },
})
