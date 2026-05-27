<template>
  <div class="photo-slot">
    <div
      class="photo-slot__thumb"
      :style="previewStyle"
    >
      <span
        v-if="!hasPhoto"
        class="photo-slot__placeholder"
      >Slot {{ slotNum }}</span>
    </div>
    <div class="photo-slot__controls">
      <input
        ref="fileInput"
        type="file"
        accept="image/jpeg,image/png,image/webp,image/gif"
        style="display:none"
        @change="onFileSelected"
      >
      <NcButton
        type="button"
        variant="tertiary"
        native-type="button"
        @click="fileInput.click()"
      >
        {{ hasPhoto ? 'Replace' : 'Upload' }}
      </NcButton>
      <NcButton
        v-if="hasPhoto"
        type="button"
        variant="tertiary"
        native-type="button"
        @click="onRemove"
      >
        Remove
      </NcButton>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onBeforeUnmount, watch } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { photoGet } from '../api.js'

const props = defineProps({
  slotNum:    { type: Number,  required: true },
  /** Local File object the user has chosen (or null). */
  file:       { type: File,    default: null },
  /** True when the user has clicked Remove and there's no replacement file. */
  remove:     { type: Boolean, default: false },
  /** True when the server already has a photo for this slot. */
  existing:   { type: Boolean, default: false },
  itemId:     { type: Number,  default: null },
  /** Used to bust the artwork cache when the item was just re-uploaded. */
  updatedAt:  { type: String,  default: null },
})

const emit = defineEmits(['pick', 'remove'])

const fileInput = ref(null)
const previewUrl = ref(null)

// Rebuild the preview blob URL whenever the pending file changes; revoke
// the prior URL to avoid leaking blobs across re-renders. Watch covers
// the case where the parent clears the file (Remove clicked after Pick).
watch(
  () => props.file,
  (newFile, oldFile) => {
    if (previewUrl.value) {
      URL.revokeObjectURL(previewUrl.value)
      previewUrl.value = null
    }
    if (newFile && newFile !== oldFile) {
      previewUrl.value = URL.createObjectURL(newFile)
    }
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
})

const hasPhoto = computed(() => {
  if (props.remove) return false
  if (props.file) return true
  return props.existing && !!props.itemId
})

const previewStyle = computed(() => {
  if (previewUrl.value) {
    return { backgroundImage: `url(${previewUrl.value})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  if (props.existing && !props.remove && props.itemId) {
    // Cache-bust on item.updatedAt; null/undefined is fine, just no buster.
    const bust = props.updatedAt ? `?_=${encodeURIComponent(props.updatedAt)}` : ''
    return {
      backgroundImage: `url(${photoGet(props.itemId, props.slotNum)}${bust})`,
      backgroundSize:  'cover',
      backgroundPosition: 'center',
    }
  }
  return {}
})

function onFileSelected(event) {
  const file = event.target?.files?.[0]
  if (!file) return
  emit('pick', file)
  // Reset the input so the same file can be picked again after Remove.
  event.target.value = ''
}

function onRemove() {
  emit('remove')
}
</script>

<style scoped>
.photo-slot {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  width: 88px;
}

.photo-slot__thumb {
  width: 80px;
  height: 80px;
  border-radius: var(--border-radius);
  border: 2px solid var(--color-border-dark);
  background: var(--color-background-dark);
  display: flex;
  align-items: center;
  justify-content: center;
}

.photo-slot__placeholder {
  font-size: 0.75em;
  opacity: 0.4;
  text-align: center;
  pointer-events: none;
}

.photo-slot__controls {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 4px;
  width: 100%;
}
</style>
