<template>
  <div class="provider">
    <div class="provider__text">
      <span class="provider__name">{{ label }}</span>
      <p class="provider__description">
        <slot />
      </p>
      <!-- Only rendered when the provider's key is genuinely missing, so the
           row doesn't nag once everything is configured. -->
      <p
        v-if="hint"
        class="provider__hint"
      >
        {{ hint }}
      </p>
    </div>
    <NcButton
      class="provider__action"
      variant="secondary"
      :disabled="disabled"
      @click="$emit('enrich')"
    >
      {{ actionLabel }}
    </NcButton>
  </div>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'

defineProps({
  /** Category name, e.g. "Music". */
  label:       { type: String,  required: true },
  actionLabel: { type: String,  required: true },
  /** True while a queue is running, or while the provider has no key. */
  disabled:    { type: Boolean, default: false },
  /** Shown under the description when the provider still needs a key. */
  hint:        { type: String,  default: '' },
})

defineEmits(['enrich'])
</script>

<style scoped>
.provider {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
}

.provider__text {
  flex: 1 1 260px;
  min-width: 0;
}

.provider__name {
  display: block;
  font-size: 0.875em;
  font-weight: 500;
  margin-bottom: 2px;
}

.provider__description {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  line-height: 1.4;
  margin: 0;
}

.provider__description a {
  color: var(--color-primary-element);
}

.provider__hint {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  line-height: 1.4;
  margin: 4px 0 0;
  font-style: italic;
}

.provider__action {
  flex: 0 0 auto;
}
</style>
