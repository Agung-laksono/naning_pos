<script setup>
import { computed, nextTick, ref } from 'vue';
import { formatThousandId, parseRupiahInt, processCurrencyInput } from '@/utils/currency';

const props = defineProps({
  modelValue: {
    type: [Number, String],
    default: 0,
  },
  placeholder: {
    type: String,
    default: '0',
  },
  prefix: {
    type: String,
    default: '',
  },
  max: {
    type: Number,
    default: null,
  },
  maxDigits: {
    type: Number,
    default: 12,
  },
  allowEmpty: {
    type: Boolean,
    default: false,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  readonly: {
    type: Boolean,
    default: false,
  },
  inputClass: {
    type: String,
    default: 'w-full bg-white border border-slate-200 rounded-xl px-3 py-1.5 text-xs font-mono font-bold text-slate-900 focus:outline-none focus:border-blue-500',
  },
});

const emit = defineEmits(['update:modelValue']);
const inputEl = ref(null);
const isCleared = ref(false);

const displayValue = computed(() => {
  if (props.allowEmpty && (props.modelValue === '' || props.modelValue === null || props.modelValue === undefined)) {
    return '';
  }
  const num = parseRupiahInt(props.modelValue, 0);
  if (num === 0 && (isCleared.value || (props.allowEmpty && props.modelValue === ''))) {
    return '';
  }
  return formatThousandId(num, false);
});

async function handleInput(e) {
  const { numericValue, isEmpty, formatted, nextCaret } = processCurrencyInput(e, {
    maxDigits: props.maxDigits,
    max: props.max,
  });

  isCleared.value = isEmpty;

  if (isEmpty && props.allowEmpty) {
    emit('update:modelValue', '');
  } else {
    emit('update:modelValue', numericValue);
  }

  await nextTick();
  if (inputEl.value) {
    if (inputEl.value.value !== formatted) {
      inputEl.value.value = formatted;
    }
    try {
      inputEl.value.setSelectionRange(nextCaret, nextCaret);
    } catch (err) {}
  }
}

function handleBlur() {
  isCleared.value = false;
}

function selectOnFocus(e) {
  if (!props.readonly && e?.target?.select) {
    e.target.select();
  }
}
</script>

<template>
  <div v-if="prefix" class="relative w-full">
    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs font-mono font-bold text-slate-400 pointer-events-none select-none">
      {{ prefix }}
    </span>
    <input
      ref="inputEl"
      type="text"
      inputmode="numeric"
      :value="displayValue"
      :placeholder="placeholder"
      :disabled="disabled"
      :readonly="readonly"
      @input="handleInput"
      @focus="selectOnFocus"
      @blur="handleBlur"
      :class="inputClass"
    />
  </div>
  <input
    v-else
    ref="inputEl"
    type="text"
    inputmode="numeric"
    :value="displayValue"
    :placeholder="placeholder"
    :disabled="disabled"
    :readonly="readonly"
    @input="handleInput"
    @focus="selectOnFocus"
    @blur="handleBlur"
    :class="inputClass"
  />
</template>
