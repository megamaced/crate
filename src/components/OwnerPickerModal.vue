<template>
  <NcModal
    :show="show"
    size="small"
    label-id="owner-picker-title"
    @close="$emit('close')"
  >
    <div class="owner-picker">
      <h2 id="owner-picker-title">
        {{ title }}
      </h2>
      <p class="owner-picker-hint">
        This category is shared with you by several people. Choose whose
        collection to add to.
      </p>
      <ul class="owner-picker-list">
        <li
          v-for="owner in owners"
          :key="owner"
        >
          <button
            type="button"
            class="owner-picker-row"
            @click="$emit('select', owner)"
          >
            {{ owner }}
          </button>
        </li>
      </ul>
      <div class="owner-picker-actions">
        <NcButton
          variant="tertiary"
          @click="$emit('close')"
        >
          Cancel
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script setup>
import { NcModal, NcButton } from '@nextcloud/vue'

defineProps({
  show:   { type: Boolean, required: true },
  owners: { type: Array,   default: () => [] },
  title:  { type: String,  default: 'Add to whose collection?' },
})

defineEmits(['select', 'close'])
</script>

<style scoped>
.owner-picker {
  padding: 24px 28px 28px;
  width: 100%;
  box-sizing: border-box;
}

.owner-picker h2 {
  margin: 0 0 8px;
  font-size: 1.25em;
  font-weight: 700;
}

.owner-picker-hint {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
  margin: 0 0 16px;
  line-height: 1.5;
}

.owner-picker-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.owner-picker-row {
  width: 100%;
  text-align: left;
  background: var(--color-background-dark);
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius-large);
  color: var(--color-main-text);
  padding: 12px 14px;
  font-size: 0.95em;
  font-weight: 500;
  cursor: pointer;
  transition: border-color 0.1s, background 0.1s;
}

.owner-picker-row:hover {
  border-color: var(--color-primary-element);
  background: var(--color-background-hover);
}

.owner-picker-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}
</style>
