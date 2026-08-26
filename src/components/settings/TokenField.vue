<template>
  <div class="token-field">
    <label
      class="token-field__label"
      :for="inputId"
    >{{ label }}</label>
    <p class="token-field__help">
      <slot />
    </p>
    <div class="token-field__row">
      <!-- A saved key is never sent back by the server, so the box always starts
           empty and the placeholder is the only "it's set" signal. type=password
           keeps a freshly pasted key off the screen and out of autofill. -->
      <input
        :id="inputId"
        type="password"
        autocomplete="off"
        :value="modelValue"
        :placeholder="hasValue ? '(saved — paste a new one to replace)' : 'Paste your API key here'"
        @input="$emit('update:modelValue', $event.target.value)"
      >
    </div>
    <div class="token-field__actions">
      <NcButton
        variant="primary"
        :disabled="saving || modelValue === ''"
        @click="$emit('save')"
      >
        {{ saving ? 'Saving…' : 'Save' }}
      </NcButton>
      <NcButton
        v-if="hasValue"
        variant="tertiary"
        :disabled="saving"
        @click="$emit('remove')"
      >
        Remove
      </NcButton>
      <span
        v-if="message"
        class="token-field__message"
      >{{ message }}</span>
    </div>
  </div>
</template>

<script setup>
import { NcButton } from '@nextcloud/vue'

defineProps({
  /** DOM id tying the label to the input. */
  inputId:    { type: String,  required: true },
  label:      { type: String,  required: true },
  /** The key the user is currently typing — never the stored one. */
  modelValue: { type: String,  required: true },
  /** True when the server already holds a key for this provider. */
  hasValue:   { type: Boolean, default: false },
  saving:     { type: Boolean, default: false },
  /** Transient "Saved!" / "… removed." feedback from useTokenSetting. */
  message:    { type: String,  default: '' },
})

defineEmits(['update:modelValue', 'save', 'remove'])
</script>

<style scoped>
.token-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.token-field__label {
  font-size: 0.875em;
  font-weight: 500;
}

.token-field__help {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  line-height: 1.4;
  margin: 0;
}

.token-field__help a {
  color: var(--color-primary-element);
}

.token-field__row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.token-field__row input {
  flex: 1;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 6px 10px;
  font-size: 1em;
  font-family: monospace;
}

.token-field__row input:focus {
  border-color: var(--color-primary-element);
  outline: none;
}

.token-field__actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.token-field__message {
  font-size: 0.875em;
  color: #4ade80;
}
</style>
