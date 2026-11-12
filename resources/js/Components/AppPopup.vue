<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { popupState, confirmPopup, cancelPopup } from '@/utils/popup';

const promptInputRef = ref(null);
const confirmButtonRef = ref(null);

const variantStyle = computed(() => {
  switch (popupState.variant) {
    case 'success':
      return {
        icon: '✓',
        badge: 'bg-emerald-50 text-emerald-600 border-emerald-200 font-black',
        button: 'bg-emerald-600 hover:bg-emerald-700 shadow-emerald-600/25',
      };
    case 'danger':
      return {
        icon: '⚠️',
        badge: 'bg-rose-50 text-rose-600 border-rose-200',
        button: 'bg-rose-600 hover:bg-rose-700 shadow-rose-600/25',
      };
    case 'warning':
      return {
        icon: '⚠️',
        badge: 'bg-amber-50 text-amber-600 border-amber-200',
        button: 'bg-amber-600 hover:bg-amber-700 shadow-amber-600/25',
      };
    default:
      return {
        icon: 'ℹ️',
        badge: 'bg-blue-50 text-blue-600 border-blue-200',
        button: 'bg-blue-600 hover:bg-blue-700 shadow-blue-600/25',
      };
  }
});

watch(
  () => popupState.isOpen,
  async (open) => {
    if (!open) return;
    await nextTick();
    if (popupState.mode === 'prompt' && promptInputRef.value) {
      promptInputRef.value.focus();
      promptInputRef.value.select();
    } else if (confirmButtonRef.value) {
      confirmButtonRef.value.focus();
    }
  }
);

function handleBackdropClick() {
  if (popupState.mode === 'alert') {
    confirmPopup();
  } else {
    cancelPopup();
  }
}

function handleKeydown(e) {
  if (!popupState.isOpen) return;
  if (e.key === 'Escape') {
    e.preventDefault();
    e.stopPropagation();
    if (popupState.mode === 'alert') confirmPopup();
    else cancelPopup();
  } else if (e.key === 'Enter') {
    e.preventDefault();
    e.stopPropagation();
    confirmPopup();
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleKeydown, true);
});

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown, true);
});
</script>

<template>
  <Teleport to="body">
    <div
      v-if="popupState.isOpen"
      class="fixed inset-0 z-[9999] bg-slate-900/60 backdrop-blur-[2px] flex items-center justify-center p-4 select-none"
      @click.self="handleBackdropClick"
    >
      <div class="bg-white rounded-3xl p-6 w-full max-w-sm shadow-2xl border border-slate-200 flex flex-col items-center text-center gap-3">
        <div
          :class="variantStyle.badge"
          class="w-14 h-14 rounded-2xl border flex items-center justify-center text-2xl shadow-xs shrink-0"
        >
          {{ variantStyle.icon }}
        </div>

        <div class="w-full">
          <h3 class="text-base font-black text-slate-900 leading-snug">
            {{ popupState.title }}
          </h3>
          <p class="text-xs text-slate-600 mt-1.5 leading-relaxed whitespace-pre-line">
            {{ popupState.message }}
          </p>
        </div>

        <div v-if="popupState.mode === 'prompt'" class="w-full pt-1">
          <input
            ref="promptInputRef"
            type="text"
            v-model="popupState.inputValue"
            :placeholder="popupState.placeholder"
            class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm font-semibold text-slate-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition"
          />
        </div>

        <div class="flex items-center gap-2.5 w-full pt-2">
          <button
            v-if="popupState.mode !== 'alert'"
            type="button"
            @click="cancelPopup"
            class="flex-1 py-2.5 px-4 bg-slate-100 hover:bg-slate-200 active:scale-95 text-slate-700 font-bold text-xs uppercase tracking-wider rounded-xl transition cursor-pointer"
          >
            {{ popupState.cancelText }}
          </button>
          <button
            ref="confirmButtonRef"
            type="button"
            @click="confirmPopup"
            :class="variantStyle.button"
            class="flex-1 py-2.5 px-4 active:scale-95 text-white font-black text-xs uppercase tracking-wider rounded-xl shadow-md transition cursor-pointer flex items-center justify-center"
          >
            {{ popupState.confirmText }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

