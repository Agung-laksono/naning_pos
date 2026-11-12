import { reactive } from 'vue';

export const popupState = reactive({
  isOpen: false,
  mode: 'alert', // 'alert' | 'confirm' | 'prompt'
  variant: 'info', // 'info' | 'success' | 'warning' | 'danger'
  title: '',
  message: '',
  inputValue: '',
  placeholder: '',
  confirmText: 'OK',
  cancelText: 'Batal',
});

let activeResolver = null;
const queue = [];

function inferVariant(message, mode, explicitVariant) {
  if (explicitVariant) return explicitVariant;
  const text = String(message || '').toLowerCase();

  if (text.includes('berhasil') || text.startsWith('✓')) {
    return 'success';
  }
  if (
    text.includes('hapus') ||
    text.includes('batalkan') ||
    text.includes('ditolak') ||
    text.includes('dibatasi') ||
    text.includes('duplikat') ||
    text.includes('kunci akses') ||
    text.includes('putuskan koneksi') ||
    text.includes('tutup shift') ||
    text.includes('gagal')
  ) {
    return 'danger';
  }
  if (
    text.includes('kosong') ||
    text.includes('kurang') ||
    text.includes('wajib') ||
    text.includes('tidak valid') ||
    text.includes('tidak boleh') ||
    text.includes('minimal') ||
    text.includes('peringatan') ||
    text.includes('masukkan') ||
    text.includes('pilih ')
  ) {
    return 'warning';
  }
  return mode === 'confirm' ? 'warning' : 'info';
}

function defaultTitle(mode, variant) {
  if (mode === 'prompt') return 'Masukkan Data';
  if (mode === 'confirm') {
    return variant === 'danger' ? 'Konfirmasi Tindakan' : 'Konfirmasi';
  }
  switch (variant) {
    case 'success':
      return 'Berhasil';
    case 'danger':
      return 'Perhatian';
    case 'warning':
      return 'Peringatan';
    default:
      return 'Informasi';
  }
}

function openNext(entry) {
  const variant = inferVariant(entry.message, entry.mode, entry.options.variant);
  popupState.mode = entry.mode;
  popupState.variant = variant;
  popupState.title = entry.options.title || defaultTitle(entry.mode, variant);
  popupState.message = String(entry.message ?? '');
  popupState.inputValue = entry.mode === 'prompt' ? String(entry.defaultValue ?? '') : '';
  popupState.placeholder = entry.options.placeholder || 'Ketik di sini...';
  popupState.confirmText =
    entry.options.confirmText ||
    (entry.mode === 'confirm'
      ? variant === 'danger'
        ? 'Ya, Lanjutkan'
        : 'Ya, Benar'
      : entry.mode === 'prompt'
        ? 'Simpan'
        : 'Mengerti');
  popupState.cancelText = entry.options.cancelText || 'Batal';
  popupState.isOpen = true;
  activeResolver = entry.resolve;
}

function enqueue(mode, message, defaultValue = '', options = {}) {
  return new Promise((resolve) => {
    const entry = { mode, message, defaultValue, options, resolve };
    if (popupState.isOpen) {
      queue.push(entry);
    } else {
      openNext(entry);
    }
  });
}

function flushNext() {
  if (queue.length > 0) {
    openNext(queue.shift());
  }
}

export function showAlert(message, options = {}) {
  return enqueue('alert', message, '', options);
}

export function showConfirm(message, options = {}) {
  return enqueue('confirm', message, '', options);
}

export function showPrompt(message, defaultValue = '', options = {}) {
  return enqueue('prompt', message, defaultValue, options);
}

export function confirmPopup() {
  if (!popupState.isOpen) return;
  const resolve = activeResolver;
  const mode = popupState.mode;
  const val = popupState.inputValue;

  popupState.isOpen = false;
  activeResolver = null;

  if (resolve) {
    if (mode === 'confirm') resolve(true);
    else if (mode === 'prompt') resolve(val);
    else resolve(undefined);
  }
  flushNext();
}

export function cancelPopup() {
  if (!popupState.isOpen) return;
  const resolve = activeResolver;
  const mode = popupState.mode;

  popupState.isOpen = false;
  activeResolver = null;

  if (resolve) {
    if (mode === 'confirm') resolve(false);
    else if (mode === 'prompt') resolve(null);
    else resolve(undefined);
  }
  flushNext();
}

