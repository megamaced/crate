<template>
  <span
    v-if="readonly"
    class="star-rating star-rating--readonly"
    role="img"
    :aria-label="ratingSummary(modelValue ?? 0)"
  >
    <span
      v-for="star in MAX_RATING"
      :key="star"
      aria-hidden="true"
      :class="['star-rating__star', { 'star-rating__star--on': star <= (modelValue ?? 0) }]"
    >{{ star <= (modelValue ?? 0) ? '★' : '☆' }}</span>
  </span>

  <!-- Native radios, so the arrow keys move between the stars and the
       browser handles focus; the stars are their visible labels. -->
  <fieldset
    v-else
    class="star-rating"
    @mouseleave="hovered = 0"
  >
    <legend class="star-rating__legend">
      {{ legend }}
    </legend>
    <label
      v-for="star in MAX_RATING"
      :key="star"
      class="star-rating__option"
      @mouseenter="hovered = star"
    >
      <input
        type="radio"
        class="star-rating__input"
        :name="name"
        :value="star"
        :checked="modelValue === star"
        @change="select(star)"
      >
      <span
        aria-hidden="true"
        :class="['star-rating__star', { 'star-rating__star--on': star <= shown }]"
      >{{ star <= shown ? '★' : '☆' }}</span>
      <span class="star-rating__hidden">{{ starsLabel(star) }}</span>
    </label>
    <label
      class="star-rating__option star-rating__option--none"
      @mouseenter="hovered = 0"
    >
      <input
        type="radio"
        class="star-rating__input"
        :name="name"
        value="0"
        :checked="!modelValue"
        @change="select(null)"
      >
      <!-- TRANSLATORS: the choice that leaves an item without a star rating -->
      <span class="star-rating__none">{{ t('crate', 'Not rated') }}</span>
    </label>
  </fieldset>
</template>

<script setup>
import { computed, ref, useId } from 'vue'
import { t } from '../l10n.js'
import { MAX_RATING, ratingSummary, starsLabel } from '../utils/rating.js'

const props = defineProps({
  /** Whole stars from 1 to MAX_RATING, or null when unrated. */
  modelValue: { type: Number, default: null },
  /** Show the rating without letting it change. */
  readonly: { type: Boolean, default: false },
  /** The editable control's visible name. */
  legend: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

// Each control's radios need a group name of their own.
const name = `crate-rating-${useId()}`

// The star under the pointer, previewed in place of the rating; 0 for none.
const hovered = ref(0)
const shown = computed(() => hovered.value || (props.modelValue ?? 0))

// A choice ends the preview, or a pointer still over the stars (or a tap,
// which leaves no pointer to move away) would go on showing it instead.
function select(stars) {
  hovered.value = 0
  emit('update:modelValue', stars)
}
</script>

<style scoped>
.star-rating {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  margin: 0;
  padding: 0;
  border: 0;
}

.star-rating__legend {
  font-size: 0.8em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 6px;
  padding: 0;
  color: var(--color-text-maxcontrast);
}

.star-rating__star {
  font-size: 1.4em;
  line-height: 1;
  color: var(--color-text-maxcontrast);
}

.star-rating__star--on {
  color: var(--color-warning, #e9a700);
}

.star-rating--readonly .star-rating__star {
  font-size: 1.1em;
}

.star-rating__option {
  position: relative;
  display: inline-flex;
  align-items: center;
  padding: 2px;
  border-radius: var(--border-radius, 4px);
  cursor: pointer;
}

.star-rating__option--none {
  margin-left: 8px;
  font-size: 0.9em;
  color: var(--color-text-maxcontrast);
}

/* The radio stays in the accessibility tree and takes focus; its star shows it. */
.star-rating__input {
  position: absolute;
  opacity: 0;
  width: 1px;
  height: 1px;
  margin: 0;
}

.star-rating__input:focus-visible + .star-rating__star,
.star-rating__input:focus-visible + .star-rating__none {
  outline: 2px solid var(--color-primary-element);
  outline-offset: 2px;
  border-radius: var(--border-radius, 4px);
}

.star-rating__input:checked + .star-rating__none {
  font-weight: 600;
}

.star-rating__hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}
</style>
