<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { ScannerService } from '@/services/scanner';
import { db } from '@/db';
import { generateDynamicQris } from '@/utils/qris';
import { generateQrDataUrl } from '@/utils/qr';
import { applyNumpadKey, parseRupiahInt } from '@/utils/currency';
import { getStoreInitials } from '@/utils/store';
import { showAlert, showConfirm, showPrompt } from '@/utils/popup';
import AppPopup from '@/Components/AppPopup.vue';
import RupiahInput from '@/Components/RupiahInput.vue';
import ProductGalleryMockup from '@/Components/POS/ProductGalleryMockup.vue';

const props = defineProps({
  products: {
    type: Array,
    default: () => []
  },
  customers: {
    type: Array,
    default: () => []
  },
  categories: {
    type: Array,
    default: () => []
  },
  settings: {
    type: Object,
    default: () => ({})
  },
  holdOrders: {
    type: Array,
    default: () => []
  },
  cashiers: {
    type: Array,
    default: () => []
  },
  activeShift: {
    type: Object,
    default: null
  },
  authCashier: {
    type: Object,
    default: () => ({ name: 'Siska Anggraeni', shift: 'Shift Pagi · Kasir Aktif' })
  },
  initialTransactions: {
    type: Array,
    default: () => []
  },
  initialSummary: {
    type: Object,
    default: () => ({})
  }
});

// ================= CLOCK =================
const currentTime = ref('');
let clockTimer = null;

function updateClock() {
  const now = new Date();
  const h = String(now.getHours()).padStart(2, '0');
  const m = String(now.getMinutes()).padStart(2, '0');
  const s = String(now.getSeconds()).padStart(2, '0');
  currentTime.value = `${h}:${m}:${s}`;
}

// ================= STATE KERANJANG & KATALOG =================
const localProducts = ref([...props.products]);
const localCustomers = ref([...props.customers]);

// Helper & Default Pelanggan Umum (Wajib Default Cash / Non-Member)
const DEFAULT_CUSTOMER = { id: 1, name: 'Pelanggan Umum', phone: '-', address: 'Non-Member / Cash', kasbon_balance: 0 };
function getDefaultCustomer() {
  const found = localCustomers.value.find(c => (c.name && c.name.toLowerCase().includes('umum')) || c.id === 1);
  return found || DEFAULT_CUSTOMER;
}

const selectedCustomer = ref(getDefaultCustomer());

function generateInvoiceNo() {
  const now = new Date();
  const d = String(now.getDate()).padStart(2, '0');
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const hh = String(now.getHours()).padStart(2, '0');
  const mm = String(now.getMinutes()).padStart(2, '0');
  const ss = String(now.getSeconds()).padStart(2, '0');
  const rand = Math.floor(100 + Math.random() * 900);
  return `TRX-${d}${m}-${hh}${mm}${ss}-${rand}`;
}
const invoiceNo = ref(generateInvoiceNo());
const isOnline = ref(navigator.onLine);

// Keranjang dimulai kosong & bersih untuk data riil
const cart = ref([]);

// ================= KALKULASI TAGIHAN =================
const globalDiscount = ref(0);
const globalDiscountNote = ref('');

const subtotal = computed(() => {
  return cart.value.reduce((sum, item) => sum + (item.price * item.qty), 0);
});

const itemDiscountTotal = computed(() => {
  return cart.value.reduce((sum, item) => sum + (item.discount || 0), 0);
});

const grandTotal = computed(() => {
  return Math.max(0, subtotal.value - itemDiscountTotal.value - globalDiscount.value);
});

const totalQty = computed(() => {
  return cart.value.reduce((sum, item) => sum + (item.qty || 0), 0);
});

// ================= KERANJANG ACTIONS =================
function addToCart(product, unitIndex = 0, initialQty = 1) {
  playBeepSound('scan');
  const qty = Math.max(1, parseInt(initialQty) || 1);
  const unit = product.units && product.units[unitIndex] 
    ? product.units[unitIndex] 
    : { name: 'Pcs', price: 0, ratio: 1 };

  const existing = cart.value.find(item => item.product_id === product.id && item.unit === unit.name);
  if (existing) {
    existing.qty += qty;
  } else {
    cart.value.push({
      product_id: product.id,
      name: product.name,
      barcode: product.barcode,
      icon: product.icon || '📦',
      unit: unit.name,
      price: unit.price,
      cost_price: Number(product.cost_price) || 0,
      qty: qty,
      discount: 0,
      units: product.units || [unit]
    });
  }
}

function updateQty(index, delta) {
  if (cart.value[index].qty + delta <= 0) {
    playBeepSound('delete');
  } else {
    playBeepSound('qty');
  }
  cart.value[index].qty += delta;
  if (cart.value[index].qty <= 0) {
    cart.value.splice(index, 1);
  }
}

function cycleItemUnit(index) {
  playBeepSound('click');
  const item = cart.value[index];
  if (!item || !item.units || item.units.length <= 1) return;

  const currentIdx = item.units.findIndex(u => u.name === item.unit);
  const nextIdx = (currentIdx + 1) % item.units.length;
  const nextUnit = item.units[nextIdx];

  item.unit = nextUnit.name;
  item.price = nextUnit.price;
}

function removeCartItem(index) {
  playBeepSound('delete');
  cart.value.splice(index, 1);
}

async function clearCart() {
  if (cart.value.length === 0) return;
  if (await showConfirm('Batalkan seluruh isi pesanan saat ini?')) {
    playBeepSound('delete');
    cart.value = [];
    globalDiscount.value = 0;
    globalDiscountNote.value = '';
    inlineCashGiven.value = '0';
    selectedCustomer.value = getDefaultCustomer();
  }
}

// ================= SEARCH & AUTO-SUGGEST DROPDOWN =================
const searchQuery = ref('');
const isSearchFocused = ref(false);
const isSearchDropdownOpen = ref(false);
const highlightedIndex = ref(-1);
const searchUnregisteredQuery = ref(null);
const scannerTarget = ref('cart'); // 'cart' | 'newProduct'
const showUnregisteredModal = ref(false);
const unregisteredBarcode = ref('');

// Status aktif untuk memperluas input memenuhi seluruh header pada tablet
const isSearchActive = computed(() => {
  return isSearchFocused.value || isSearchDropdownOpen.value || Boolean(searchQuery.value && String(searchQuery.value).trim().length > 0);
});

const searchSuggestions = computed(() => {
  const q = String(searchQuery.value || '').trim().toLowerCase();
  if (!q) return [];

  return localProducts.value.filter(p => {
    const matchName = (p.name || '').toLowerCase().includes(q);
    const matchBarcode = (p.barcode || '').toLowerCase().includes(q);
    return matchName || matchBarcode;
  }).slice(0, 8);
});

function onSearchInput() {
  const q = String(searchQuery.value || '').trim();
  if (q) {
    isSearchDropdownOpen.value = true;
    searchUnregisteredQuery.value = null;
    highlightedIndex.value = searchSuggestions.value.length > 0 ? 0 : -1;
  } else {
    isSearchDropdownOpen.value = false;
    highlightedIndex.value = -1;
    searchUnregisteredQuery.value = null;
  }
}

function onSearchFocus() {
  isSearchFocused.value = true;
  if (String(searchQuery.value || '').trim()) {
    isSearchDropdownOpen.value = true;
  }
}

function handleSearchKeydown(e) {
  if (!isSearchDropdownOpen.value) {
    if (e.key === 'ArrowDown' && searchSuggestions.value.length > 0) {
      isSearchDropdownOpen.value = true;
      highlightedIndex.value = 0;
      e.preventDefault();
    }
    return;
  }

  if (e.key === 'ArrowDown') {
    e.preventDefault();
    if (searchSuggestions.value.length > 0) {
      highlightedIndex.value = (highlightedIndex.value + 1) % searchSuggestions.value.length;
    }
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    if (searchSuggestions.value.length > 0) {
      highlightedIndex.value = highlightedIndex.value <= 0 
        ? searchSuggestions.value.length - 1 
        : highlightedIndex.value - 1;
    }
  } else if (e.key === 'Escape') {
    closeSearchDropdown();
  }
}

function handleSearchSubmit() {
  const query = String(searchQuery.value || '').trim();
  if (!query) return;
  const lowerQuery = query.toLowerCase();

  // 1. Cek apakah ada barcode yang cocok persis (misal dari scanner fisik tembak)
  const byBarcode = localProducts.value.find(p => (p.barcode || '').toLowerCase() === lowerQuery);
  if (byBarcode) {
    selectSuggestion(byBarcode);
    return;
  }

  // 2. Jika ada item yang dipilih / disorot dari auto-suggest
  if (highlightedIndex.value >= 0 && searchSuggestions.value[highlightedIndex.value]) {
    selectSuggestion(searchSuggestions.value[highlightedIndex.value]);
    return;
  }

  // 3. Jika hanya ada 1 hasil pencarian yang cocok
  if (searchSuggestions.value.length === 1) {
    selectSuggestion(searchSuggestions.value[0]);
    return;
  }

  // 4. Jika ada beberapa hasil pencarian, biarkan dropdown terbuka
  if (searchSuggestions.value.length > 1) {
    isSearchDropdownOpen.value = true;
    highlightedIndex.value = 0;
    return;
  }

  // 5. Jika TIDAK ditemukan sama sekali
  playBeepSound('warning');
  isSearchDropdownOpen.value = true;
  searchUnregisteredQuery.value = query;
}

function selectSuggestion(product) {
  addToCart(product);
  closeSearchDropdown();
}

function closeSearchDropdown() {
  searchQuery.value = '';
  isSearchDropdownOpen.value = false;
  isSearchFocused.value = false;
  highlightedIndex.value = -1;
  searchUnregisteredQuery.value = null;
  const inputEl = document.getElementById('pos-search-input');
  if (inputEl) inputEl.blur();
}

function clearSearchInput() {
  closeSearchDropdown();
}

function quickRegisterFromSearch(query) {
  closeSearchDropdown();
  openAddProductModal(query);
}

function handleClickOutsideSearch(event) {
  const el = document.getElementById('pos-search-container');
  if (el && !el.contains(event.target)) {
    isSearchDropdownOpen.value = false;
    isSearchFocused.value = false;
  }
}

// ================= GLOBAL HARDWARE SCANNER LISTENER (USB / BLUETOOTH / WIRELESS GUN) =================
let hardwareBarcodeBuffer = '';
let lastHardwareKeyTime = 0;

function handleGlobalHardwareScan(e) {
  // ================= GLOBAL FUNCTION KEY SHORTCUTS (F1 - F9 & ESCAPE) =================
  if (e.key === 'F1') {
    e.preventDefault();
    const searchInput = document.getElementById('pos-search-input');
    if (searchInput) {
      searchInput.focus();
      searchInput.select();
    }
    return;
  }
  if (e.key === 'F2') {
    e.preventDefault();
    showGalleryModal.value = !showGalleryModal.value;
    return;
  }
  if (e.key === 'F3') {
    e.preventDefault();
    showCustomerModal.value = !showCustomerModal.value;
    return;
  }
  if (e.key === 'F4') {
    e.preventDefault();
    if (cart.value.length > 0) {
      if (rightMode.value === 'cash') {
        confirmInlineCash(false);
      } else {
        openCashInline();
      }
    }
    return;
  }
  if (e.key === 'F6') {
    e.preventDefault();
    openWithdrawalModal();
    return;
  }
  if (e.key === 'F7') {
    e.preventDefault();
    if (cart.value.length > 0) {
      payQris();
    }
    return;
  }
  if (e.key === 'F8') {
    e.preventDefault();
    showHoldModal.value = !showHoldModal.value;
    return;
  }
  if (e.key === 'F9') {
    e.preventDefault();
    if (cart.value.length > 0) {
      holdCurrentTransaction();
    }
    return;
  }
  if (e.key === 'F10') {
    e.preventDefault();
    openTopUpModal();
    return;
  }
  if (e.key === 'Escape') {
    if (rightMode.value === 'cash') {
      closeCashInline();
      return;
    }
    if (showNavMenu.value) { showNavMenu.value = false; return; }
    if (showLogoutModal.value) { showLogoutModal.value = false; return; }
    if (qrUrlModal.value.isOpen) { qrUrlModal.value.isOpen = false; return; }
    if (showQrisModal.value) { showQrisModal.value = false; return; }
    if (showWithdrawalModal.value) { showWithdrawalModal.value = false; return; }
    if (showTopUpModal.value) { showTopUpModal.value = false; return; }
    if (showReceiptModal.value) { showReceiptModal.value = false; return; }
    if (showSettingsModal.value) { showSettingsModal.value = false; return; }
    if (showReportsDrawer.value) { showReportsDrawer.value = false; return; }
    if (showCustomerModal.value) { showCustomerModal.value = false; return; }
    if (showGalleryModal.value) { showGalleryModal.value = false; return; }
    if (showHoldModal.value) { showHoldModal.value = false; return; }
    if (showScannerModal.value) { stopCameraScanner(); return; }
    if (showPayDebtModal.value) { showPayDebtModal.value = false; return; }
    if (showUnregisteredModal.value) { showUnregisteredModal.value = false; return; }
    if (showQuickBarcodeModal.value) { showQuickBarcodeModal.value = false; return; }
    if (isSearchDropdownOpen.value) { isSearchDropdownOpen.value = false; return; }
  }

  const activeEl = document.activeElement;
  const isInput = activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA');
  const isSearchInput = activeEl && activeEl.id === 'pos-search-input';

  const now = Date.now();
  const timeDiff = now - lastHardwareKeyTime;
  lastHardwareKeyTime = now;

  // Scanner hardware mengirim tombol 'Enter' di akhir penembakan barcode
  if (e.key === 'Enter') {
    if (hardwareBarcodeBuffer.length >= 3) {
      const code = hardwareBarcodeBuffer.trim();
      hardwareBarcodeBuffer = '';

      // Jika fokus bukan di search input (karena search input punya handler sendiri)
      if (!isSearchInput) {
        e.preventDefault();
        handleScannedBarcode(code);
      }
    } else {
      hardwareBarcodeBuffer = '';
    }
    return;
  }

  // Scanner fisik mengetik rentetan angka sangat cepat (jeda < 70ms antar karakter).
  // Jika jeda > 70ms, ini ketikan manual tangan manusia, reset buffer.
  if (timeDiff > 70) {
    hardwareBarcodeBuffer = '';
  }

  // Rekam karakter alfanumerik biasa jika tidak sedang mengetik di input modal form lain
  if (e.key && e.key.length === 1 && (!isInput || isSearchInput)) {
    hardwareBarcodeBuffer += e.key;
  }
}

function promptUnregisteredBarcode(barcode) {
  unregisteredBarcode.value = barcode;
  showUnregisteredModal.value = true;
}

function confirmRegisterUnregistered() {
  const code = unregisteredBarcode.value;
  showUnregisteredModal.value = false;
  openAddProductModal(code);
}

// ================= KAMERA SCANNER BARCODE (FULLSCREEN TRANSPARAN / DOCK KANAN 35%) =================
const showScannerModal = ref(false);
const isCameraActive = ref(false);
const cameraError = ref('');
const lastScannedProduct = ref(null);
const scannerUnregisteredBarcode = ref('');
const availableCameras = ref([]);
const currentCameraIndex = ref(0);
const quickBarcodeInput = ref('');
const isScanFlashActive = ref(false);
const hasTorch = ref(false);
const isTorchOn = ref(false);
let html5QrCode = null;
let lastScannedBarcode = '';
let lastScannedTime = 0;
let sharedAudioCtx = null;
const SCAN_COOLDOWN_MS = 1500; // 1.5 detik cooldown untuk barcode sama

// Buka kunci AudioContext secara global pada gesture user agar browser tidak membisukan audio
function unlockAudioContext() {
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) return;
    if (!sharedAudioCtx) {
      sharedAudioCtx = new AudioCtx();
    }
    if (sharedAudioCtx.state === 'suspended') {
      sharedAudioCtx.resume();
    }
  } catch (e) {
    console.warn('Audio unlock error:', e);
  }
}

// ================= SISTEM EFEK SUARA RITEL KASIR (WEB AUDIO SYNTHESIZER) =================
function playBeepSound(type = 'scan') {
  try {
    unlockAudioContext();
    if (!sharedAudioCtx) return;

    const ctx = sharedAudioCtx;
    const now = ctx.currentTime;

    if (type === 'scan' || type === 'success') {
      // 1. Suara Laser Barcode Scanner Kasir Supermarket (1850 Hz, Pip! 90ms)
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(1850, now);
      
      gain.gain.setValueAtTime(0.35, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.09);

      osc.connect(gain);
      gain.connect(ctx.destination);

      osc.start(now);
      osc.stop(now + 0.09);

      if (typeof navigator !== 'undefined' && navigator.vibrate) {
        navigator.vibrate(70);
      }
    } else if (type === 'payment_success') {
      // 2. Melodi Kasir Sukses Bayar "Cha-ching!" (Arpeggio Melodis C5-E5-G5-C6)
      const freqs = [523.25, 659.25, 783.99, 1046.50];
      freqs.forEach((f, idx) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        const startT = now + idx * 0.075;
        osc.frequency.setValueAtTime(f, startT);
        gain.gain.setValueAtTime(0.28, startT);
        gain.gain.exponentialRampToValueAtTime(0.001, startT + 0.22);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start(startT);
        osc.stop(startT + 0.22);
      });

      if (typeof navigator !== 'undefined' && navigator.vibrate) {
        navigator.vibrate([60, 40, 100]);
      }
    } else if (type === 'click') {
      // 3. Ketukan Numpad Mekanikal Halus (Tik! 35ms)
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(950, now);
      osc.frequency.exponentialRampToValueAtTime(320, now + 0.035);

      gain.gain.setValueAtTime(0.14, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.035);

      osc.connect(gain);
      gain.connect(ctx.destination);

      osc.start(now);
      osc.stop(now + 0.035);
    } else if (type === 'qty') {
      // 4. Soft Bubble Pop untuk tombol Tambah/Kurang Qty (Pop! 60ms)
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(580, now);
      osc.frequency.exponentialRampToValueAtTime(980, now + 0.06);

      gain.gain.setValueAtTime(0.22, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.06);

      osc.connect(gain);
      gain.connect(ctx.destination);

      osc.start(now);
      osc.stop(now + 0.06);

      if (typeof navigator !== 'undefined' && navigator.vibrate) {
        navigator.vibrate(25);
      }
    } else if (type === 'delete') {
      // 5. Suara Hapus Item / Batalkan Keranjang (Low Tone Woosh)
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(280, now);
      osc.frequency.exponentialRampToValueAtTime(80, now + 0.15);

      gain.gain.setValueAtTime(0.25, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.15);

      osc.connect(gain);
      gain.connect(ctx.destination);

      osc.start(now);
      osc.stop(now + 0.15);

      if (typeof navigator !== 'undefined' && navigator.vibrate) {
        navigator.vibrate(50);
      }
    } else if (type === 'hold') {
      // 6. Suara Antrean Simpan / Muat Ulang (Slide Harmonis)
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(440, now);
      osc.frequency.exponentialRampToValueAtTime(680, now + 0.16);

      gain.gain.setValueAtTime(0.22, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.18);

      osc.connect(gain);
      gain.connect(ctx.destination);

      osc.start(now);
      osc.stop(now + 0.18);
    } else if (type === 'warning' || type === 'error') {
      // 7. Peringatan Uang Kurang / Error ("Tet-tot" Dua Nada Rendah)
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(380, now);
      osc.frequency.setValueAtTime(240, now + 0.12);

      gain.gain.setValueAtTime(0.28, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.32);

      osc.connect(gain);
      gain.connect(ctx.destination);

      osc.start(now);
      osc.stop(now + 0.32);

      if (typeof navigator !== 'undefined' && navigator.vibrate) {
        navigator.vibrate([100, 60, 100]);
      }
    }
  } catch (e) {
    console.warn('Gagal memutar audio feedback:', e);
  }
}

// Efek Visual Flash Hijau saat Barcode Berhasil Tertangkap Kamera
function triggerScanVisualFlash() {
  isScanFlashActive.value = true;
  setTimeout(() => {
    isScanFlashActive.value = false;
  }, 220);
}

// Terapkan Continuous Autofocus Hardware & Auto-Exposure ke driver kamera tablet
function applyCameraOptimizations() {
  try {
    const videoEl = document.querySelector('#scanner-viewfinder video');
    if (!videoEl || !videoEl.srcObject) return;

    const stream = videoEl.srcObject;
    const track = stream.getVideoTracks ? stream.getVideoTracks()[0] : null;
    if (!track) return;

    const caps = track.getCapabilities ? track.getCapabilities() : {};
    const advanced = {};

    // 1. Kunci Continuous Autofocus Hardware (fokus otomatis cepat & tajam)
    if (caps.focusMode && Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) {
      advanced.focusMode = 'continuous';
    }

    // 2. Kunci Auto Exposure Continuous (adaptasi cahaya cepat terhadap label barcode)
    if (caps.exposureMode && Array.isArray(caps.exposureMode) && caps.exposureMode.includes('continuous')) {
      advanced.exposureMode = 'continuous';
    }

    // 3. Kunci Continuous White Balance
    if (caps.whiteBalanceMode && Array.isArray(caps.whiteBalanceMode) && caps.whiteBalanceMode.includes('continuous')) {
      advanced.whiteBalanceMode = 'continuous';
    }

    if (Object.keys(advanced).length > 0 && track.applyConstraints) {
      track.applyConstraints({
        advanced: [advanced]
      }).then(() => {
        console.log('Rozak Mart: Hardware Continuous Autofocus aktif.');
      }).catch(err => {
        console.warn('Gagal kunci autofocus:', err);
      });
    }

    // Deteksi ketersediaan Lampu Flash (Torch)
    hasTorch.value = !!caps.torch;
    isTorchOn.value = false;
  } catch (e) {
    console.warn('Optimasi kamera dilewati:', e);
  }
}

// Fitur Tap-to-Focus: Sentuh layar untuk memicu motor fokus ulang kamera
function triggerManualFocus() {
  try {
    const videoEl = document.querySelector('#scanner-viewfinder video');
    if (!videoEl || !videoEl.srcObject) return;
    const track = videoEl.srcObject.getVideoTracks()[0];
    if (!track || !track.applyConstraints) return;

    const caps = track.getCapabilities ? track.getCapabilities() : {};
    if (caps.focusMode && Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) {
      track.applyConstraints({
        advanced: [{ focusMode: 'continuous' }]
      }).catch(() => {});
    }
  } catch (e) {}
}

// Fitur Toggle Lampu Flash
async function toggleTorch() {
  try {
    playBeepSound('click');
    const videoEl = document.querySelector('#scanner-viewfinder video');
    if (!videoEl || !videoEl.srcObject) return;
    const track = videoEl.srcObject.getVideoTracks()[0];
    if (!track || !track.applyConstraints || !hasTorch.value) return;

    isTorchOn.value = !isTorchOn.value;
    await track.applyConstraints({
      advanced: [{ torch: isTorchOn.value }]
    });
  } catch (e) {
    console.warn('Gagal toggle torch:', e);
  }
}

async function startCameraScanner(target = 'cart') {
  scannerTarget.value = target;
  cameraError.value = '';
  scannerUnregisteredBarcode.value = '';
  quickBarcodeInput.value = '';
  showScannerModal.value = true;
  hasTorch.value = false;
  isTorchOn.value = false;

  await nextTick();

  // 1. Bersihkan instance scanner lama jika ada
  if (html5QrCode) {
    try {
      if (isCameraActive.value) {
        await html5QrCode.stop();
      }
      html5QrCode.clear();
    } catch (e) {}
    html5QrCode = null;
  }

  // Jeda kecil (150ms) agar driver webcam OS melepaskan handle hardware jika baru saja ditutup
  await new Promise(r => setTimeout(r, 150));

  // 2. Format barcode yang didukung (Wajib 1D retail + QR)
  const supportedFormats = [
    Html5QrcodeSupportedFormats.EAN_13,
    Html5QrcodeSupportedFormats.EAN_8,
    Html5QrcodeSupportedFormats.CODE_128,
    Html5QrcodeSupportedFormats.CODE_39,
    Html5QrcodeSupportedFormats.UPC_A,
    Html5QrcodeSupportedFormats.UPC_E,
    Html5QrcodeSupportedFormats.QR_CODE
  ];

  // Konfigurasi scan dioptimalkan untuk barcode 1D retail:
  // - FPS 25 untuk respon instan
  // - Area kotak horizontal (92% lebar x 58% tinggi) memangkas 40% beban pixel CPU
  const scanConfig = {
    fps: 25,
    qrbox: (viewfinderWidth, viewfinderHeight) => {
      const w = Math.floor(viewfinderWidth * 0.92);
      const h = Math.floor(Math.min(viewfinderHeight * 0.58, 220));
      return { width: Math.max(260, w), height: Math.max(130, h) };
    },
    aspectRatio: 1.0,
    disableFlip: false
  };

  // Aktifkan engine akselerasi perangkat keras asli browser (BarcodeDetector API)
  const createScannerInstance = () => {
    return new Html5Qrcode("scanner-viewfinder", {
      formatsToSupport: supportedFormats,
      verbose: false,
      useBarCodeDetectorIfSupported: true,
      experimentalFeatures: {
        useBarCodeDetectorIfSupported: true
      }
    });
  };

  try {
    html5QrCode = createScannerInstance();

    // cameraIdOrConfig harus tepat 1 key: { facingMode: "..." } atau string id
    let cameraChoice = { facingMode: "environment" };
    if (availableCameras.value.length > 0 && availableCameras.value[currentCameraIndex.value]?.id) {
      cameraChoice = availableCameras.value[currentCameraIndex.value].id;
    }

    try {
      await html5QrCode.start(
        cameraChoice,
        scanConfig,
        (decodedText) => handleScannedBarcode(decodedText),
        () => {}
      );
    } catch (firstErr) {
      console.warn('Mode kamera pertama gagal, mencoba mode webcam user:', firstErr);
      try {
        if (html5QrCode) {
          html5QrCode.clear();
        }
      } catch (e) {}
      html5QrCode = createScannerInstance();
      await html5QrCode.start(
        { facingMode: "user" },
        scanConfig,
        (decodedText) => handleScannedBarcode(decodedText),
        () => {}
      );
    }

    isCameraActive.value = true;

    // Terapkan Continuous Autofocus Hardware segera setelah video aktif
    setTimeout(() => {
      applyCameraOptimizations();
    }, 250);

    // 3. Muat daftar kamera secara aman di background setelah stream aktif
    if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
      navigator.mediaDevices.enumerateDevices().then(devices => {
        const videoDevices = devices.filter(d => d.kind === 'videoinput');
        if (videoDevices.length > 0) {
          availableCameras.value = videoDevices.map((d, i) => ({
            id: d.deviceId,
            label: d.label || `Kamera ${i + 1}`
          }));
        }
      }).catch(() => {});
    }

  } catch (err) {
    console.error('Kamera gagal dimulai:', err);
    isCameraActive.value = false;
    const msg = (err && (err.message || err.name || String(err))) || '';
    if (msg.includes('NotReadableError') || msg.includes('Could not start video source')) {
      cameraError.value = 'Webcam sedang digunakan aplikasi lain atau sistem sedang melepaskan kamera. Silakan tekan Coba Lagi.';
    } else if (msg.includes('NotAllowedError') || msg.includes('Permission denied')) {
      cameraError.value = 'Izin kamera belum diberikan. Klik ikon gembok/kamera di samping URL browser untuk mengizinkan.';
    } else {
      cameraError.value = 'Tidak dapat mengakses kamera: ' + (msg || 'Pastikan browser mengizinkan kamera.');
    }
  }
}

async function stopCameraScanner() {
  if (isTorchOn.value) {
    isTorchOn.value = false;
  }
  hasTorch.value = false;

  if (html5QrCode && isCameraActive.value) {
    try {
      await html5QrCode.stop();
      html5QrCode.clear();
    } catch (e) {
      console.warn('Gagal stop html5QrCode:', e);
    }
  }
  html5QrCode = null;
  isCameraActive.value = false;
  showScannerModal.value = false;
  scannerUnregisteredBarcode.value = '';
}

async function switchCamera() {
  if (availableCameras.value.length <= 1) return;
  currentCameraIndex.value = (currentCameraIndex.value + 1) % availableCameras.value.length;
  await startCameraScanner(scannerTarget.value);
}

function submitQuickBarcode() {
  const code = (quickBarcodeInput.value || '').trim();
  if (!code) return;
  quickBarcodeInput.value = '';
  handleScannedBarcode(code);
}

function handleScannedBarcode(barcode) {
  if (!barcode) return;
  if (scannerUnregisteredBarcode.value) return; // Sedang ada alert belum terdaftar

  const now = Date.now();
  if (barcode === lastScannedBarcode && now - lastScannedTime < SCAN_COOLDOWN_MS) {
    return;
  }
  lastScannedBarcode = barcode;
  lastScannedTime = now;

  // Jika scanner dipanggil dari formulir Tambah Produk Baru
  if (scannerTarget.value === 'newProduct') {
    playBeepSound('success');
    triggerScanVisualFlash();
    newProd.value.barcode = barcode;
    scannerTarget.value = 'cart';
    stopCameraScanner();
    return;
  }

  // Jika scanner dipanggil untuk keranjang kasir (Continuous Scan)
  const found = localProducts.value.find(p => p.barcode === barcode);
  triggerScanVisualFlash();

  if (found) {
    playBeepSound('success');
    addToCart(found);
    lastScannedProduct.value = {
      name: found.name,
      price: found.units && found.units[0] ? found.units[0].price : 0,
      time: now
    };
    setTimeout(() => {
      if (lastScannedProduct.value && lastScannedProduct.value.time === now) {
        lastScannedProduct.value = null;
      }
    }, 3000);
  } else {
    playBeepSound('error');
    scannerUnregisteredBarcode.value = barcode;
  }
}

function registerFromScanner(barcode) {
  const code = barcode;
  stopCameraScanner();
  openAddProductModal(code);
}

function triggerBarcodeScanner() {
  startCameraScanner('cart');
}

function scanBarcodeForNewProduct() {
  startCameraScanner('newProduct');
}

function simulateScanBarcode(barcode) {
  handleScannedBarcode(barcode);
}

// ================= MODE PANEL KANAN (NORMAL vs TUNAI INLINE) =================
const rightMode = ref('normal'); // 'normal' | 'cash'
const inlineCashGiven = ref('0');

const inlineCashChange = computed(() => {
  const raw = parseFloat(inlineCashGiven.value.replace(/\./g, '')) || 0;
  return Math.max(0, raw - grandTotal.value);
});

function openCashInline() {
  if (cart.value.length === 0) {
    showAlert('Keranjang belanja masih kosong!');
    return;
  }
  inlineCashGiven.value = grandTotal.value.toLocaleString('id-ID');
  rightMode.value = 'cash';
}

function closeCashInline() {
  rightMode.value = 'normal';
  inlineCashGiven.value = '0';
  isEditingCashPresets.value = false;
  showCashPresetEditor.value = false;
}

watch(() => cart.value.length, (len) => {
  if (len === 0) {
    closeCashInline();
    globalDiscount.value = 0;
    globalDiscountNote.value = '';
    showDiscountModal.value = false;
    showItemEditModal.value = false;
  }
});

function inlineCashShortcut(val) {
  playBeepSound('click');
  let current = parseFloat(inlineCashGiven.value.replace(/\./g, '')) || 0;
  if (val === 'exact') {
    current = grandTotal.value;
  } else if (typeof val === 'number') {
    current += val;
  }
  inlineCashGiven.value = current.toLocaleString('id-ID');
}

function inlineCashNumpad(val) {
  playBeepSound('click');
  inlineCashGiven.value = applyNumpadKey(inlineCashGiven.value, val);
}

// Konfirmasi Pembayaran Tunai Inline
function confirmInlineCash(autoPrint = false) {
  const rawGiven = parseFloat(inlineCashGiven.value.replace(/\./g, '')) || 0;
  if (rawGiven < grandTotal.value) {
    playBeepSound('warning');
    showAlert('Uang yang diterima kurang dari total belanja!');
    return;
  }

  finalizeTransaction('TUNAI', rawGiven, inlineCashChange.value, autoPrint);
  rightMode.value = 'normal';
}

// ================= METODE PEMBAYARAN LAIN (QRIS DUA MODE & KASBON) =================
const showQrisModal = ref(false);
const showReceiptModal = ref(false);
const lastTransaction = ref(null);

// Overpayment & Kembalian Kasir QRIS
const qrisPaidAmount = ref(0);
const qrisAdminFee = ref(0);
const qrisCashChange = computed(() => {
  const paid = Number(qrisPaidAmount.value) || 0;
  const fee = Number(qrisAdminFee.value) || 0;
  const totalDue = grandTotal.value + fee;
  return Math.max(0, paid - totalDue);
});

// ================= MURNI TARIK TUNAI QRIS (CASH OUT) =================
const showWithdrawalModal = ref(false);
const withdrawalAmount = ref(100000);
const withdrawalAdminFee = ref(Number(props.settings?.qris_withdrawal_admin_fee || 2000));
const totalQrisWithdrawal = computed(() => {
  return (Number(withdrawalAmount.value) || 0) + (Number(withdrawalAdminFee.value) || 0);
});

const withdrawalEmvcoString = computed(() => {
  return generateDynamicQris(storeQrisPayload.value, totalQrisWithdrawal.value);
});

const withdrawalEmvcoQrUrl = computed(() => {
  return 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&margin=8&data=' + encodeURIComponent(withdrawalEmvcoString.value);
});

function openWithdrawalModal() {
  if (cart.value.length > 0) return;
  withdrawalAmount.value = 100000;
  withdrawalAdminFee.value = Number(settingsForm.value?.qris_withdrawal_admin_fee || props.settings?.qris_withdrawal_admin_fee || 2000);
  showWithdrawalModal.value = true;
}

function executeWithdrawal(autoPrint = true) {
  const wdAmount = Number(withdrawalAmount.value) || 0;
  const adminFee = Number(withdrawalAdminFee.value) || 0;
  const totalPaid = totalQrisWithdrawal.value;

  if (wdAmount <= 0) {
    showAlert('Masukkan nominal penarikan tunai yang valid!');
    return;
  }

  const wdInvoice = 'WD-' + new Date().toISOString().slice(2, 10).replace(/-/g, '') + '-' + Math.floor(1000 + Math.random() * 9000);

  finalizeTransaction('QRIS', totalPaid, wdAmount, autoPrint, {
    transactionType: 'CASH_WITHDRAWAL',
    invoiceNo: wdInvoice,
    adminFee: adminFee,
    withdrawalAmount: wdAmount,
    items: [
      {
        product_id: null,
        product_name: 'Layanan Tarik Tunai QRIS',
        unit_name: 'Transaksi',
        unit_price: wdAmount,
        cost_price: 0,
        ratio: 1,
        qty: 1,
        discount: 0,
        total: wdAmount
      }
    ]
  });

  showWithdrawalModal.value = false;
}

// ================= TOP-UP / PPOB / TRANSFER SALDO (CASH IN) =================
const showTopUpModal = ref(false);
const topUpService = ref('DANA');
const topUpTarget = ref('');
const topUpCapital = ref(50000);
const topUpAdminFee = ref(Number(props.settings?.qris_withdrawal_admin_fee || 2000));
const topUpPaymentMethod = ref('TUNAI'); // 'TUNAI' | 'QRIS' | 'KASBON'
const topUpCashPaid = ref(52000);
const topUpCashManualEdited = ref(false);

const topUpTotalDue = computed(() => {
  return (Number(topUpCapital.value) || 0) + (Number(topUpAdminFee.value) || 0);
});

const topUpChange = computed(() => {
  if (topUpPaymentMethod.value !== 'TUNAI') return 0;
  return Math.max(0, (Number(topUpCashPaid.value) || 0) - topUpTotalDue.value);
});

const topUpEmvcoQrUrl = computed(() => {
  return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=6&data=' + encodeURIComponent(generateDynamicQris(storeQrisPayload.value, topUpTotalDue.value));
});

watch(topUpTotalDue, (newTotal) => {
  if (!topUpCashManualEdited.value) {
    topUpCashPaid.value = newTotal;
  }
});

function setTopUpCashQuick(val) {
  topUpCashManualEdited.value = false;
  topUpCashPaid.value = val;
}

function openTopUpModal() {
  if (cart.value.length > 0) return;
  topUpService.value = 'DANA';
  topUpTarget.value = '';
  topUpCapital.value = 50000;
  topUpAdminFee.value = Number(settingsForm.value?.qris_withdrawal_admin_fee || props.settings?.qris_withdrawal_admin_fee || 2000);
  topUpPaymentMethod.value = 'TUNAI';
  topUpCashManualEdited.value = false;
  topUpCashPaid.value = topUpTotalDue.value;
  showTopUpModal.value = true;
}

function executeTopUp(autoPrint = true) {
  const capital = Number(topUpCapital.value) || 0;
  const adminFee = Number(topUpAdminFee.value) || 0;
  const totalDue = topUpTotalDue.value;

  if (capital <= 0) {
    showAlert('Masukkan nominal saldo Top-Up yang valid!');
    return;
  }

  if (topUpPaymentMethod.value === 'KASBON' && (!selectedCustomer.value || selectedCustomer.value.id === 1)) {
    showAlert('Pilih pelanggan/member terlebih dahulu di layar kasir untuk transaksi Kasbon!');
    showTopUpModal.value = false;
    showCustomerModal.value = true;
    return;
  }

  if (topUpPaymentMethod.value === 'TUNAI' && (Number(topUpCashPaid.value) || 0) < totalDue) {
    showAlert('Uang tunai diterima kurang dari total tagihan!');
    return;
  }

  const tuInvoice = 'TU-' + new Date().toISOString().slice(2, 10).replace(/-/g, '') + '-' + Math.floor(1000 + Math.random() * 9000);
  const serviceLabel = topUpTarget.value.trim()
    ? `${topUpService.value} (${topUpTarget.value.trim()})`
    : `Layanan ${topUpService.value}`;

  const paidAmt = topUpPaymentMethod.value === 'TUNAI' ? (Number(topUpCashPaid.value) || totalDue) : totalDue;
  const changeAmt = topUpPaymentMethod.value === 'TUNAI' ? topUpChange.value : 0;

  finalizeTransaction(topUpPaymentMethod.value, paidAmt, changeAmt, autoPrint, {
    transactionType: 'TOP_UP',
    invoiceNo: tuInvoice,
    adminFee: adminFee,
    subtotalOverride: capital,
    grandTotalOverride: totalDue,
    customerNameOverride: (selectedCustomer.value && selectedCustomer.value.id !== 1)
      ? selectedCustomer.value.name
      : 'Pelanggan Top-Up',
    items: [
      {
        product_id: null,
        product_name: serviceLabel,
        unit_name: 'Layanan',
        unit_price: totalDue,
        cost_price: capital,
        ratio: 1,
        qty: 1,
        discount: 0,
        total: totalDue
      }
    ]
  });

  showTopUpModal.value = false;
}

// Mode Switch: 'emvco' (Opsi 3: QRIS Toko Nominal Otomatis) vs 'gateway' (Opsi 2: Gateway Auto-Detect)
const qrisMode = ref(localStorage.getItem('qris_mode') || 'emvco');
const storeQrisPayload = ref(localStorage.getItem('store_qris_payload') || props.settings?.qris_static_payload || '');
const showQrisConfigModal = ref(false);
const tempQrisPayloadInput = ref('');

// Gateway State & Polling
const gatewayQrUrl = ref('');
const gatewayStatus = ref('idle'); // 'idle' | 'loading' | 'pending' | 'settlement' | 'error'
const gatewayMessage = ref('');
let gatewayPollTimer = null;

const dynamicEmvcoString = computed(() => {
  const nominal = Number(qrisPaidAmount.value) > 0 ? Number(qrisPaidAmount.value) : grandTotal.value;
  return generateDynamicQris(storeQrisPayload.value, nominal);
});

const dynamicEmvcoQrUrl = computed(() => {
  return 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&margin=8&data=' + encodeURIComponent(dynamicEmvcoString.value);
});

function setQrisMode(mode) {
  qrisMode.value = mode;
  localStorage.setItem('qris_mode', mode);
  if (mode === 'gateway') {
    startGatewayQris();
  } else {
    stopGatewayPolling();
  }
}

function stopGatewayPolling() {
  if (gatewayPollTimer) {
    clearInterval(gatewayPollTimer);
    gatewayPollTimer = null;
  }
}

async function startGatewayQris() {
  stopGatewayPolling();
  gatewayStatus.value = 'loading';
  gatewayMessage.value = 'Menghubungkan ke Gateway...';

  try {
    const res = await fetch('/pos/qris/generate', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      },
      body: JSON.stringify({
        order_id: invoiceNo.value,
        amount: grandTotal.value
      })
    });
    const data = await res.json();
    if (data.status === 'success') {
      gatewayQrUrl.value = data.qr_url;
      gatewayStatus.value = 'pending';
      gatewayMessage.value = data.provider === 'simulator' 
        ? 'Mode Simulasi Gateway (Menunggu transfer...)' 
        : 'Menunggu transfer pembeli...';
      
      // Polling status setiap 2.5 detik
      gatewayPollTimer = setInterval(async () => {
        if (!showQrisModal.value || qrisMode.value !== 'gateway') {
          stopGatewayPolling();
          return;
        }
        try {
          const checkRes = await fetch('/pos/qris/check/' + invoiceNo.value);
          const checkData = await checkRes.json();
          if (checkData.status === 'settlement') {
            stopGatewayPolling();
            gatewayStatus.value = 'settlement';
            gatewayMessage.value = '✓ Pembayaran Berhasil Diterima!';
            playBeepSound('payment_success');
            setTimeout(() => {
              finalizeTransaction('QRIS', grandTotal.value, 0, true);
            }, 800);
          }
        } catch (e) {
          console.warn('Polling status QRIS:', e);
        }
      }, 2500);
    } else {
      gatewayStatus.value = 'error';
      gatewayMessage.value = 'Gagal generate QRIS Gateway.';
    }
  } catch (err) {
    gatewayStatus.value = 'error';
    gatewayMessage.value = 'Koneksi Gateway bermasalah.';
  }
}

async function simulateGatewaySuccess() {
  try {
    const res = await fetch('/pos/qris/check/' + invoiceNo.value + '?simulate=true');
    const data = await res.json();
    if (data.status === 'settlement') {
      stopGatewayPolling();
      gatewayStatus.value = 'settlement';
      gatewayMessage.value = '✓ Pembayaran Berhasil (Simulasi)!';
      playBeepSound('payment_success');
      setTimeout(() => {
        finalizeTransaction('QRIS', grandTotal.value, 0, true);
      }, 800);
    }
  } catch (e) {
    finalizeTransaction('QRIS', grandTotal.value, 0, true);
  }
}

function openQrisConfigModal() {
  tempQrisPayloadInput.value = storeQrisPayload.value;
  showQrisConfigModal.value = true;
}

function saveQrisConfig() {
  storeQrisPayload.value = tempQrisPayloadInput.value.trim();
  localStorage.setItem('store_qris_payload', storeQrisPayload.value);
  router.post('/pos/settings', {
    settings: {
      qris_static_payload: storeQrisPayload.value
    }
  }, { preserveScroll: true, preserveState: true });
  showQrisConfigModal.value = false;
  playBeepSound('success');
}

function payQris() {
  if (cart.value.length === 0) {
    showAlert('Keranjang belanja masih kosong!');
    return;
  }
  qrisPaidAmount.value = grandTotal.value;
  qrisAdminFee.value = 0;
  showQrisModal.value = true;
  if (qrisMode.value === 'gateway') {
    startGatewayQris();
  }
}

function closeQrisModal() {
  stopGatewayPolling();
  showQrisModal.value = false;
}

async function payKasbon() {
  if (cart.value.length === 0) {
    showAlert('Keranjang belanja masih kosong!');
    return;
  }
  if (!selectedCustomer.value || selectedCustomer.value.id === 1) {
    showAlert('Pilih nama pelanggan/member toko terlebih dahulu untuk transaksi Kasbon!');
    showCustomerModal.value = true;
    return;
  }

  if (await showConfirm(`Catat belanjaan sebesar Rp ${grandTotal.value.toLocaleString('id-ID')} sebagai KASBON untuk "${selectedCustomer.value.name}"?`)) {
    finalizeTransaction('KASBON', grandTotal.value, 0, false);
  }
}

function finalizeTransaction(method, paidAmount, changeAmount, autoPrint = false, extra = {}) {
  playBeepSound('payment_success');
  const transactionType = extra.transactionType || 'SALE';
  const adminFee = Number(extra.adminFee) || 0;
  const invoiceNumber = extra.invoiceNo || invoiceNo.value;
  const isSpecialService = transactionType === 'CASH_WITHDRAWAL' || transactionType === 'TOP_UP';
  const subtotalValue = extra.subtotalOverride !== undefined
    ? extra.subtotalOverride
    : (transactionType === 'CASH_WITHDRAWAL' ? (extra.withdrawalAmount || 0) : subtotal.value);
  const grandTotalValue = extra.grandTotalOverride !== undefined
    ? extra.grandTotalOverride
    : (transactionType === 'CASH_WITHDRAWAL' ? 0 : grandTotal.value);

  const txUuid = (typeof crypto !== 'undefined' && crypto.randomUUID)
    ? crypto.randomUUID()
    : `uuid-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
  const activeCustomerId = selectedCustomer.value?.id || null;

  const payload = {
    uuid: txUuid,
    invoice_no: invoiceNumber,
    transaction_type: transactionType,
    cashier_name: props.authCashier.name,
    customer_id: activeCustomerId,
    customer_name: extra.customerNameOverride
      || (transactionType === 'CASH_WITHDRAWAL' ? 'Pelanggan Tarik Tunai' : (selectedCustomer.value?.name || 'Pelanggan Umum')),
    subtotal: subtotalValue,
    item_discount: isSpecialService ? 0 : itemDiscountTotal.value,
    global_discount: isSpecialService ? 0 : globalDiscount.value,
    global_discount_note: isSpecialService ? null : globalDiscountNote.value,
    grand_total: grandTotalValue,
    admin_fee: adminFee,
    payment_method: method,
    paid_amount: paidAmount,
    change_amount: changeAmount,
    items: extra.items || cart.value.map(item => {
      const activeUnitObj = item.units ? item.units.find(u => u.name === item.unit) : null;
      return {
        product_id: item.product_id,
        barcode: item.barcode || null,
        product_name: item.name,
        unit_name: item.unit,
        unit_price: item.price,
        cost_price: item.cost_price || 0,
        ratio: activeUnitObj?.ratio || 1,
        qty: item.qty,
        discount: item.discount || 0,
        total: (item.price * item.qty) - (item.discount || 0)
      };
    })
  };

  lastTransaction.value = {
    ...payload,
    created_at: new Date().toLocaleDateString('id-ID') + ' ' + currentTime.value
  };

  // Simpan ke IndexedDB Offline & riwayat transaksi lokal
  transactionsList.value.unshift({
    ...payload,
    created_at: new Date().toISOString(),
    items: payload.items
  });

  if (transactionType === 'SALE') {
    reportSummary.value.total_sales = (reportSummary.value.total_sales || 0) + grandTotalValue;
    if (method === 'TUNAI') {
      reportSummary.value.cash_sales = (reportSummary.value.cash_sales || 0) + grandTotalValue;
    }
    if (method === 'QRIS') {
      reportSummary.value.qris_sales = (reportSummary.value.qris_sales || 0) + grandTotalValue;
    }
  } else if (transactionType === 'TOP_UP') {
    reportSummary.value.topup_total = (reportSummary.value.topup_total || 0) + grandTotalValue;
    reportSummary.value.topup_capital_out = (reportSummary.value.topup_capital_out || 0) + subtotalValue;
    reportSummary.value.topup_count = (reportSummary.value.topup_count || 0) + 1;
    if (method === 'TUNAI') {
      reportSummary.value.topup_cash_in = (reportSummary.value.topup_cash_in || 0) + grandTotalValue;
    }
  }

  reportSummary.value.transaction_count = (reportSummary.value.transaction_count || 0) + 1;

  if (method === 'QRIS') {
    reportSummary.value.qris_settlement = (reportSummary.value.qris_settlement || 0) + paidAmount;
    if (transactionType !== 'TOP_UP') {
      reportSummary.value.qris_cash_out = (reportSummary.value.qris_cash_out || 0) + changeAmount;
    }
    if (transactionType === 'CASH_WITHDRAWAL') {
      reportSummary.value.qris_withdrawal_count = (reportSummary.value.qris_withdrawal_count || 0) + 1;
    }
  }
  if (adminFee > 0) {
    reportSummary.value.total_admin_fee = (reportSummary.value.total_admin_fee || 0) + adminFee;
  }
  reportSummary.value.estimated_cash_drawer =
    (reportSummary.value.cash_sales || 0)
    + (reportSummary.value.topup_cash_in || 0)
    - (reportSummary.value.qris_cash_out || 0);

  if (method === 'KASBON') {
    reportSummary.value.kasbon_sales = (reportSummary.value.kasbon_sales || 0) + grandTotalValue;
    reportSummary.value.total_receivable = (reportSummary.value.total_receivable || 0) + grandTotalValue;
  }

  const localDexiePromise = db.offline_transactions.add({
    ...payload,
    created_at: new Date().toISOString(),
    synced: isOnline.value ? 1 : 0
  }).catch(() => null);

  // Kirim ke Laravel jika online
  if (isOnline.value) {
    router.post(route('pos.checkout'), payload, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        if (method === 'KASBON' && activeCustomerId) {
          const targetCust = localCustomers.value.find(c => c.id === activeCustomerId);
          if (targetCust) {
            targetCust.kasbon_balance = (targetCust.kasbon_balance || 0) + grandTotalValue;
          }
        }
      },
      onError: async (errors) => {
        // Tandai belum tersinkron jika gagal jaringan atau error sementara
        try {
          const id = await localDexiePromise;
          if (id) {
            await db.offline_transactions.update(id, { synced: 0 });
          }
        } catch (e) {}
        const firstMsg = errors ? Object.values(errors)[0] : null;
        if (firstMsg) {
          showAlert(Array.isArray(firstMsg) ? firstMsg[0] : firstMsg);
        }
      }
    });
  }

  if (!isSpecialService) {
    // Sinyal sukses ke Layar Pembeli (Dual Display)
    syncCustomerDisplay('SUCCESS');
    setTimeout(() => {
      syncCustomerDisplay('IDLE');
    }, 7000);

    // Reset keranjang & kembalikan default pembeli ke Pelanggan Umum
    cart.value = [];
    selectedCustomer.value = getDefaultCustomer();
    globalDiscount.value = 0;
    globalDiscountNote.value = '';
    inlineCashGiven.value = '0';
    invoiceNo.value = generateInvoiceNo();
  }

  stopGatewayPolling();
  showQrisModal.value = false;

  showReceiptModal.value = true;
  if (autoPrint) {
    setTimeout(() => {
      window.print();
    }, 400);
  }
}

// ================= ANTREAN / HOLD ORDER =================
const showHoldModal = ref(false);
const holdOrdersList = ref([...props.holdOrders]);

async function holdCurrentTransaction() {
  if (cart.value.length === 0) {
    playBeepSound('warning');
    showAlert('Keranjang belanja kosong!');
    return;
  }
  playBeepSound('hold');
  const defaultName = `Antrean (${totalQty.value} item) - ${currentTime.value}`;
  const rawName = await showPrompt('Beri nama antrean ini:', defaultName, {
    title: 'Simpan Antrean',
    confirmText: 'Hold Pesanan',
  });
  const name = rawName ? rawName.trim() : '';
  if (!name) return;

  const orderData = {
    localId: Date.now(),
    name,
    customer: selectedCustomer.value,
    globalDiscount: globalDiscount.value,
    globalDiscountNote: globalDiscountNote.value,
    items: [...cart.value],
    created_at: currentTime.value
  };

  if (isOnline.value && window.axios) {
    try {
      const res = await window.axios.post('/pos/hold-orders', {
        name: orderData.name,
        data: {
          customer: orderData.customer,
          globalDiscount: orderData.globalDiscount,
          globalDiscountNote: orderData.globalDiscountNote,
          items: orderData.items,
          created_at: orderData.created_at
        }
      }, { headers: { Accept: 'application/json' } });
      if (res.data?.hold_order?.id) {
        orderData.id = res.data.hold_order.id;
        orderData.dbId = res.data.hold_order.id;
      }
    } catch (e) {
      console.warn('Hold order online fallback ke lokal:', e);
    }
  }

  if (!orderData.id) orderData.id = orderData.localId;

  holdOrdersList.value.unshift(orderData);
  db.offline_hold_orders.add(orderData).catch(() => {});

  cart.value = [];
  globalDiscount.value = 0;
  globalDiscountNote.value = '';
  showAlert(`Pesanan "${name}" berhasil di-hold.`);
}

async function restoreHoldOrder(index) {
  const order = holdOrdersList.value[index];
  if (!order) return;

  if (cart.value.length > 0) {
    if (!(await showConfirm('Keranjang saat ini masih terisi. Ganti dengan antrean terpilih?'))) return;
  }

  playBeepSound('hold');
  const dataObj = order.data || order;
  cart.value = [...(order.items || dataObj.items || [])];
  if (dataObj.customer) selectedCustomer.value = dataObj.customer;
  if (dataObj.globalDiscount !== undefined) globalDiscount.value = dataObj.globalDiscount || 0;
  if (dataObj.globalDiscountNote !== undefined) globalDiscountNote.value = dataObj.globalDiscountNote || '';

  holdOrdersList.value.splice(index, 1);
  showHoldModal.value = false;

  // Hapus dari DB & IndexedDB agar tidak muncul kembali saat refresh
  try {
    await db.offline_hold_orders.where('id').equals(order.id).delete();
  } catch (e) {}

  if (isOnline.value && order.id && (!order.localId || order.dbId)) {
    const targetId = order.dbId || order.id;
    if (window.axios) {
      window.axios.delete('/pos/hold-orders/' + targetId, { headers: { Accept: 'application/json' } }).catch(() => {});
    }
  }
}

async function deleteHoldOrder(index) {
  const order = holdOrdersList.value[index];
  if (!order) return;
  if (!(await showConfirm(`Hapus antrean "${order.name}"?`))) return;

  holdOrdersList.value.splice(index, 1);
  try {
    await db.offline_hold_orders.where('id').equals(order.id).delete();
  } catch (e) {}

  if (isOnline.value && order.id && (!order.localId || order.dbId)) {
    const targetId = order.dbId || order.id;
    if (window.axios) {
      window.axios.delete('/pos/hold-orders/' + targetId, { headers: { Accept: 'application/json' } }).catch(() => {});
    }
  }
}

// ================= MODAL EDIT ITEM & MULTI-SATUAN =================
const showItemEditModal = ref(false);
const currentEditIndex = ref(null);
const editDraft = ref(null);

function openItemEdit(index) {
  currentEditIndex.value = index;
  const item = cart.value[index];
  editDraft.value = {
    unit: item.unit,
    price: item.price,
    qty: item.qty,
    discount: item.discount || '',
    availableUnits: item.units || [{ name: item.unit, price: item.price, ratio: 1 }]
  };
  showItemEditModal.value = true;
}

function selectEditUnit(u) {
  if (!editDraft.value) return;
  editDraft.value.unit = u.name;
  editDraft.value.price = u.price;
}

function numpadPress(val) {
  if (!editDraft.value) return;
  let current = String(editDraft.value.qty);
  if (val === 'C') {
    editDraft.value.qty = 1;
  } else if (val === 'DEL') {
    current = current.slice(0, -1);
    editDraft.value.qty = current === '' ? 1 : Math.max(1, parseInt(current));
  } else {
    current = (current === '0' || current === '1') ? val : current + val;
    editDraft.value.qty = parseInt(current) || 1;
  }
}

function saveItemEdit() {
  if (currentEditIndex.value !== null && editDraft.value) {
    const item = cart.value[currentEditIndex.value];
    item.unit = editDraft.value.unit;
    item.price = editDraft.value.price;
    item.qty = Math.max(1, parseInt(editDraft.value.qty) || 1);
    const maxItemDiscount = (Number(editDraft.value.price) || 0) * item.qty;
    item.discount = Math.min(maxItemDiscount, Math.max(0, parseRupiahInt(editDraft.value.discount, 0)));
  }
  showItemEditModal.value = false;
}

function removeItemCurrentEdit() {
  if (currentEditIndex.value !== null) {
    cart.value.splice(currentEditIndex.value, 1);
  }
  showItemEditModal.value = false;
}

// ================= MODAL DISKON NOTA NUMPAD =================
const showDiscountModal = ref(false);
const tempDiscountInput = ref('0');
const tempDiscountNote = ref('');

function openGlobalDiscountModal() {
  tempDiscountInput.value = globalDiscount.value ? globalDiscount.value.toLocaleString('id-ID') : '0';
  tempDiscountNote.value = globalDiscountNote.value || '';
  showDiscountModal.value = true;
}

function discountNumpad(val) {
  playBeepSound('click');
  tempDiscountInput.value = applyNumpadKey(tempDiscountInput.value, val);
}

function setDiscountShortcut(val) {
  const currentSub = Math.max(0, subtotal.value - itemDiscountTotal.value);
  let current = parseFloat(tempDiscountInput.value.replace(/\./g, '')) || 0;

  if (val === 'round') {
    const remainder = currentSub % 1000;
    current = remainder > 0 ? remainder : 0;
    tempDiscountNote.value = 'Pembulatan';
  } else if (val === '5%') {
    current = Math.round(currentSub * 0.05);
    tempDiscountNote.value = 'Diskon 5%';
  } else if (val === '10%') {
    current = Math.round(currentSub * 0.10);
    tempDiscountNote.value = 'Diskon 10%';
  } else if (typeof val === 'number') {
    current += val;
  }
  current = Math.min(currentSub, Math.max(0, current));
  tempDiscountInput.value = current.toLocaleString('id-ID');
}

function saveGlobalDiscount() {
  const raw = tempDiscountInput.value.replace(/\./g, '');
  const maxAllowed = Math.max(0, subtotal.value - itemDiscountTotal.value);
  globalDiscount.value = Math.min(maxAllowed, Math.max(0, parseFloat(raw) || 0));
  globalDiscountNote.value = tempDiscountNote.value.trim();
  showDiscountModal.value = false;
}

function removeGlobalDiscount() {
  globalDiscount.value = 0;
  globalDiscountNote.value = '';
}

// ================= MASTER SATUAN & QUICK ADD SATUAN =================
const availableUnits = ref([
  'Pcs', 'Bungkus', 'Sachet', 'Botol', 'Kg', 'Kaleng', 'Dus', 'Renceng', 'Slop', 'Bal', 'Butir', 'Liter', 'Ikat'
]);
const showQuickAddUnitModal = ref(false);
const quickUnitInput = ref('');
const quickUnitInputRef = ref(null);
const quickUnitTargetType = ref('base');
const quickUnitTargetIndex = ref(null);
const unitPresetSuggestions = ['Pack', 'Strip', 'Box', 'Lusin', 'Kodi', 'Gram', 'Karung', 'Galon'];

function openQuickAddUnit(targetType = 'base', unitIndex = null) {
  quickUnitTargetType.value = targetType;
  quickUnitTargetIndex.value = unitIndex;
  quickUnitInput.value = '';
  showQuickAddUnitModal.value = true;
  nextTick(() => {
    quickUnitInputRef.value?.focus();
  });
}

function saveQuickUnit() {
  const u = quickUnitInput.value.trim();
  if (!u) {
    showAlert('Nama satuan tidak boleh kosong!');
    return;
  }

  const existing = availableUnits.value.find(item => item.toLowerCase() === u.toLowerCase());
  const selectedUnit = existing || u;
  if (!existing) {
    availableUnits.value.push(selectedUnit);
  }

  if (quickUnitTargetType.value === 'base') {
    newProd.value.unit = selectedUnit;
  } else if (
    quickUnitTargetType.value === 'multi' &&
    quickUnitTargetIndex.value !== null &&
    newProd.value.extraUnits[quickUnitTargetIndex.value]
  ) {
    newProd.value.extraUnits[quickUnitTargetIndex.value].name = selectedUnit;
  }

  quickUnitInput.value = '';
  showQuickAddUnitModal.value = false;
}

// ================= MASTER KATEGORI & QUICK ADD KATEGORI =================
const localCategories = ref(
  props.categories && props.categories.length > 0 
    ? [...props.categories] 
    : [
        { id: 1, name: 'Sembako', icon: '🌾' },
        { id: 2, name: 'Mie & Instan', icon: '🍜' },
        { id: 3, name: 'Minuman', icon: '🥤' },
        { id: 4, name: 'Rokok', icon: '🚬' },
        { id: 5, name: 'Sabun & Kebersihan', icon: '🧼' },
        { id: 6, name: 'Snack & Biskuit', icon: '🍪' },
        { id: 7, name: 'Lainnya', icon: '📦' },
      ]
);

const showQuickAddCategoryModal = ref(false);
const quickCatName = ref('');
const quickCatIcon = ref('📦');
const emojiOptions = ['🌾', '🍜', '🥤', '🚬', '🧼', '🍪', '🧂', '🥛', '🍞', '💊', '🧴', '🥩', '🧹', '🍫', '📦', '✏️'];

function openQuickAddCategory() {
  quickCatName.value = '';
  quickCatIcon.value = '📦';
  showQuickAddCategoryModal.value = true;
}

function saveQuickCategory() {
  const name = quickCatName.value.trim();
  if (!name) {
    showAlert('Nama kategori tidak boleh kosong!');
    return;
  }

  const existing = localCategories.value.find(c => c.name.toLowerCase() === name.toLowerCase());
  if (existing) {
    newProd.value.category = existing.name;
    showQuickAddCategoryModal.value = false;
    return;
  }

  const newCat = {
    id: Date.now(),
    name: name,
    icon: quickCatIcon.value || '📦'
  };

  localCategories.value.push(newCat);
  newProd.value.category = newCat.name;

  if (isOnline.value) {
    router.post(route('pos.categories.store'), {
      name: newCat.name,
      icon: newCat.icon,
    }, { preserveScroll: true, preserveState: true });
  }

  showQuickAddCategoryModal.value = false;
}

function getCategoryIcon(catName) {
  const found = localCategories.value.find(c => c.name.toLowerCase() === (catName || '').toLowerCase());
  if (found && found.icon) return found.icon;
  switch (catName) {
    case 'Sembako': return '🌾';
    case 'Mie & Instan': return '🍜';
    case 'Minuman': return '🥤';
    case 'Rokok': return '🚬';
    case 'Sabun & Kebersihan': return '🧼';
    case 'Snack & Biskuit': return '🍪';
    default: return '📦';
  }
}

// ================= MODAL TAMBAH PRODUK BARU & SMART NUMPAD =================
const showAddProductModal = ref(false);
const activeNumpadTarget = ref('base_price'); 
// 'base_price' | 'auto_qty' | 'ratio_0' | 'price_0' ...

const newProd = ref({
  name: '',
  barcode: '',
  unit: 'Pcs',
  category: 'Sembako',
  price: '',
  cost_price: '',
  stock: 100,
  autoAdd: true,
  autoAddQty: 1,
  enableMultiUnit: false,
  extraUnits: []
});

const isQtyModeActive = computed(() => {
  return (
    activeNumpadTarget.value === 'stock' ||
    activeNumpadTarget.value === 'auto_qty' ||
    activeNumpadTarget.value.startsWith('ratio_')
  );
});

const activeNumpadLabel = computed(() => {
  if (activeNumpadTarget.value === 'base_price') {
    return `Harga Jual (${newProd.value.unit})`;
  }
  if (activeNumpadTarget.value === 'cost_price') {
    return 'Harga Modal / HPP';
  }
  if (activeNumpadTarget.value === 'stock') {
    return `Stok Fisik (${newProd.value.unit})`;
  }
  if (activeNumpadTarget.value === 'auto_qty') {
    return `Qty Belanja (${newProd.value.unit})`;
  }
  if (activeNumpadTarget.value.startsWith('ratio_')) {
    const idx = parseInt(activeNumpadTarget.value.replace('ratio_', ''));
    const unitName = newProd.value.extraUnits[idx]?.name || 'Grosir';
    return `Isi (${newProd.value.unit}) per ${unitName}`;
  }
  if (activeNumpadTarget.value.startsWith('price_')) {
    const idx = parseInt(activeNumpadTarget.value.replace('price_', ''));
    const unitName = newProd.value.extraUnits[idx]?.name || 'Grosir';
    return `Harga Grosir (${unitName})`;
  }
  return 'Input Angka';
});

function getTargetValue() {
  if (activeNumpadTarget.value === 'base_price') {
    return String(newProd.value.price || '');
  } else if (activeNumpadTarget.value === 'cost_price') {
    return String(newProd.value.cost_price || '');
  } else if (activeNumpadTarget.value === 'stock') {
    return newProd.value.stock !== '' && newProd.value.stock !== null && newProd.value.stock !== undefined
      ? String(newProd.value.stock)
      : '0';
  } else if (activeNumpadTarget.value === 'auto_qty') {
    return String(newProd.value.autoAddQty || 1);
  } else if (activeNumpadTarget.value.startsWith('ratio_')) {
    const idx = parseInt(activeNumpadTarget.value.replace('ratio_', ''));
    return String(newProd.value.extraUnits[idx]?.ratio || '');
  } else if (activeNumpadTarget.value.startsWith('price_')) {
    const idx = parseInt(activeNumpadTarget.value.replace('price_', ''));
    return String(newProd.value.extraUnits[idx]?.price || '');
  }
  return '';
}

function setTargetValue(val) {
  if (activeNumpadTarget.value === 'base_price') {
    newProd.value.price = val > 0 ? val : '';
  } else if (activeNumpadTarget.value === 'cost_price') {
    newProd.value.cost_price = val > 0 ? val : '';
  } else if (activeNumpadTarget.value === 'stock') {
    newProd.value.stock = Math.max(0, val);
  } else if (activeNumpadTarget.value === 'auto_qty') {
    newProd.value.autoAddQty = Math.max(1, val);
  } else if (activeNumpadTarget.value.startsWith('ratio_')) {
    const idx = parseInt(activeNumpadTarget.value.replace('ratio_', ''));
    if (newProd.value.extraUnits[idx]) {
      newProd.value.extraUnits[idx].ratio = Math.max(1, val);
    }
  } else if (activeNumpadTarget.value.startsWith('price_')) {
    const idx = parseInt(activeNumpadTarget.value.replace('price_', ''));
    if (newProd.value.extraUnits[idx]) {
      newProd.value.extraUnits[idx].price = val > 0 ? val : '';
    }
  }
}

function cycleNumpadTarget() {
  playBeepSound('click');
  const targets = ['base_price', 'cost_price', 'stock'];
  if (Array.isArray(newProd.value.extraUnits)) {
    newProd.value.extraUnits.forEach((_, idx) => {
      targets.push(`ratio_${idx}`, `price_${idx}`);
    });
  }
  if (newProd.value.autoAdd) {
    targets.push('auto_qty');
  }
  const curIdx = targets.indexOf(activeNumpadTarget.value);
  activeNumpadTarget.value = targets[(curIdx + 1) % targets.length];
}

const DEFAULT_NUMPAD_PRESETS = {
  stock: [10, 20, 50, 100, 200],
  qty: [5, 10, 12, 20, 24],
  price: [1000, 2000, 5000, 10000, 20000, 50000],
  cash: [2000, 5000, 10000, 20000, 50000, 100000, 200000],
};

function loadNumpadPresets() {
  try {
    const raw = localStorage.getItem('pos_numpad_presets_v1');
    if (!raw) return { ...DEFAULT_NUMPAD_PRESETS };
    const parsed = JSON.parse(raw);
    return {
      stock: Array.isArray(parsed?.stock) ? parsed.stock.filter(n => Number(n) > 0) : [...DEFAULT_NUMPAD_PRESETS.stock],
      qty: Array.isArray(parsed?.qty) ? parsed.qty.filter(n => Number(n) > 0) : [...DEFAULT_NUMPAD_PRESETS.qty],
      price: Array.isArray(parsed?.price) ? parsed.price.filter(n => Number(n) > 0) : [...DEFAULT_NUMPAD_PRESETS.price],
      cash: Array.isArray(parsed?.cash) ? parsed.cash.filter(n => Number(n) > 0) : [...DEFAULT_NUMPAD_PRESETS.cash],
    };
  } catch (e) {
    return { ...DEFAULT_NUMPAD_PRESETS };
  }
}

const numpadPresets = ref(loadNumpadPresets());
const isEditingPresets = ref(false);
const showPresetEditor = ref(false);
const editingPresetIndex = ref(null);
const presetInputAmount = ref('');

const isEditingCashPresets = ref(false);
const showCashPresetEditor = ref(false);
const editingCashPresetIndex = ref(null);
const cashPresetInputAmount = ref('');

const activePresetGroup = computed(() => {
  if (activeNumpadTarget.value === 'stock') return 'stock';
  if (isQtyModeActive.value) return 'qty';
  return 'price';
});

const currentPresetList = computed(() => {
  return numpadPresets.value[activePresetGroup.value] || [];
});

function persistNumpadPresets() {
  try {
    localStorage.setItem('pos_numpad_presets_v1', JSON.stringify(numpadPresets.value));
  } catch (e) {}
}

function formatPresetChip(val, group) {
  const num = Number(val) || 0;
  if (group === 'cash') {
    if (num >= 1000 && num % 1000 === 0) return (num / 1000).toLocaleString('id-ID') + 'rb+';
    return num.toLocaleString('id-ID') + '+';
  }
  if (group === 'price') {
    if (num >= 1000 && num % 1000 === 0) return (num / 1000).toLocaleString('id-ID') + 'k+';
    return num.toLocaleString('id-ID') + '+';
  }
  return num.toLocaleString('id-ID') + '+';
}

function upsertGroupPreset(group, editIndex, rawVal) {
  const val = parseRupiahInt(rawVal, 0);
  if (val <= 0) {
    showAlert('Masukkan nominal lebih dari 0.');
    return false;
  }
  const list = [...(numpadPresets.value[group] || [])];
  if (editIndex !== null && editIndex >= 0 && editIndex < list.length) {
    list[editIndex] = val;
  } else {
    list.push(val);
  }
  numpadPresets.value = {
    ...numpadPresets.value,
    [group]: Array.from(new Set(list)).sort((a, b) => a - b),
  };
  persistNumpadPresets();
  return true;
}

function removeGroupPreset(group, idx) {
  const list = [...(numpadPresets.value[group] || [])];
  list.splice(idx, 1);
  numpadPresets.value = { ...numpadPresets.value, [group]: list };
  persistNumpadPresets();
}

function resetGroupPreset(group) {
  numpadPresets.value = {
    ...numpadPresets.value,
    [group]: [...DEFAULT_NUMPAD_PRESETS[group]],
  };
  persistNumpadPresets();
}

function openAddPreset() {
  editingPresetIndex.value = null;
  presetInputAmount.value = '';
  showPresetEditor.value = true;
}

function openEditPreset(idx, val) {
  editingPresetIndex.value = idx;
  presetInputAmount.value = Number(val) || '';
  showPresetEditor.value = true;
}

function savePresetAmount() {
  if (!upsertGroupPreset(activePresetGroup.value, editingPresetIndex.value, presetInputAmount.value)) return;
  showPresetEditor.value = false;
  editingPresetIndex.value = null;
  presetInputAmount.value = '';
}

function deletePresetAt(idx) {
  removeGroupPreset(activePresetGroup.value, idx);
  if (editingPresetIndex.value === idx) {
    showPresetEditor.value = false;
    editingPresetIndex.value = null;
  }
}

function resetDefaultPresets() {
  resetGroupPreset(activePresetGroup.value);
  showPresetEditor.value = false;
  editingPresetIndex.value = null;
}

function openAddCashPreset() {
  editingCashPresetIndex.value = null;
  cashPresetInputAmount.value = '';
  showCashPresetEditor.value = true;
}

function openEditCashPreset(idx, val) {
  editingCashPresetIndex.value = idx;
  cashPresetInputAmount.value = Number(val) || '';
  showCashPresetEditor.value = true;
}

function saveCashPresetAmount() {
  if (!upsertGroupPreset('cash', editingCashPresetIndex.value, cashPresetInputAmount.value)) return;
  showCashPresetEditor.value = false;
  editingCashPresetIndex.value = null;
  cashPresetInputAmount.value = '';
}

function deleteCashPresetAt(idx) {
  removeGroupPreset('cash', idx);
  if (editingCashPresetIndex.value === idx) {
    showCashPresetEditor.value = false;
    editingCashPresetIndex.value = null;
  }
}

function resetDefaultCashPresets() {
  resetGroupPreset('cash');
  showCashPresetEditor.value = false;
  editingCashPresetIndex.value = null;
}

function universalNumpad(val) {
  playBeepSound('click');
  const isQty = isQtyModeActive.value;
  const minOne = activeNumpadTarget.value === 'auto_qty' || activeNumpadTarget.value.startsWith('ratio_');
  const maxDigits = isQty ? 6 : 12;
  let current = getTargetValue().replace(/\D/g, '');

  if (val === 'C') {
    current = minOne ? '1' : '0';
  } else if (val === 'DEL') {
    current = current.slice(0, -1);
    if (!current) current = minOne ? '1' : '0';
  } else if (val === '000') {
    if (isQty) return;
    if (!current || current === '0') {
      current = '1000';
    } else if (current.length + 3 <= maxDigits) {
      current = current + '000';
    }
  } else if (typeof val === 'number') {
    const curNum = parseInt(current, 10) || 0;
    const nextStr = String(curNum + val);
    if (nextStr.length <= maxDigits) {
      current = nextStr;
    }
  } else {
    const cleanVal = String(val).replace(/\D/g, '');
    if (!cleanVal) return;
    if (!current || current === '0') {
      current = cleanVal;
    } else if (current.length + cleanVal.length <= maxDigits) {
      current = current + cleanVal;
    }
  }

  const numResult = parseInt(current, 10) || 0;
  setTargetValue(numResult);
}

function addExtraUnit() {
  newProd.value.enableMultiUnit = true;
  newProd.value.extraUnits.push({
    name: 'Dus',
    price: '',
    ratio: 10
  });
  activeNumpadTarget.value = 'ratio_' + (newProd.value.extraUnits.length - 1);
}

function removeExtraUnit(index) {
  newProd.value.extraUnits.splice(index, 1);
  if (newProd.value.extraUnits.length === 0) {
    newProd.value.enableMultiUnit = false;
    activeNumpadTarget.value = 'base_price';
  } else {
    activeNumpadTarget.value = 'base_price';
  }
}

const editingProductId = ref(null);
const duplicateProductWarning = ref(null);

// Deteksi instan sesaat setelah inputan barcode terisi (scan kamera / barcode gun / ketik manual)
watch(() => newProd.value.barcode, (newVal) => {
  const bc = (newVal || '').trim();
  if (!bc) {
    duplicateProductWarning.value = null;
    return;
  }

  const existing = localProducts.value.find(p => 
    p.barcode && 
    p.barcode.trim().toLowerCase() === bc.toLowerCase() && 
    p.id !== editingProductId.value
  );

  if (existing) {
    duplicateProductWarning.value = existing;
    playBeepSound('warning');
  } else {
    duplicateProductWarning.value = null;
  }
});

function openAddProductModal(prefill = '') {
  editingProductId.value = null;
  duplicateProductWarning.value = null;
  activeNumpadTarget.value = 'base_price';
  newProd.value = {
    name: '',
    barcode: '',
    unit: 'Pcs',
    category: localCategories.value[0]?.name || 'Sembako',
    price: '',
    cost_price: '',
    stock: 100,
    autoAdd: true,
    autoAddQty: 1,
    enableMultiUnit: false,
    extraUnits: []
  };

  if (prefill) {
    if (/^\d{6,}$/.test(prefill)) {
      newProd.value.barcode = prefill;
    } else {
      newProd.value.name = prefill;
    }
  }

  showAddProductModal.value = true;
}

function openEditProductModal(product) {
  if (!props.authCashier?.is_admin) {
    showAlert('Akses ditolak: Hanya Owner toko yang diperbolehkan mengubah data produk.');
    return;
  }
  editingProductId.value = product.id;
  duplicateProductWarning.value = null;
  activeNumpadTarget.value = 'base_price';

  const baseUnit = (product.units && product.units.find(u => u.is_base)) || 
                   (product.units && product.units[0]) || 
                   { name: product.base_unit || 'Pcs', price: product.price || 0 };
  const extraUnits = (product.units && product.units.filter(u => u !== baseUnit && !u.is_base)) || [];

  newProd.value = {
    name: product.name,
    barcode: product.barcode || '',
    unit: baseUnit.name,
    category: getProductCategoryName(product),
    price: baseUnit.price,
    cost_price: product.cost_price || '',
    stock: product.stock !== undefined ? product.stock : 100,
    autoAdd: false,
    autoAddQty: 1,
    enableMultiUnit: extraUnits.length > 0,
    extraUnits: extraUnits.map(eu => ({ name: eu.name, price: eu.price, ratio: eu.ratio || 1 }))
  };

  showAddProductModal.value = true;
}

async function deleteCurrentProduct() {
  if (!editingProductId.value) return;
  const prod = localProducts.value.find(p => p.id === editingProductId.value);
  if (!prod) return;

  if (!(await showConfirm(`Hapus produk "${prod.name}" secara permanen dari katalog toko?`))) return;

  playBeepSound('delete');
  router.delete('/pos/products/' + editingProductId.value, {
    preserveScroll: true,
    onSuccess: () => {
      localProducts.value = localProducts.value.filter(p => p.id !== editingProductId.value);
      showAddProductModal.value = false;
      editingProductId.value = null;
    }
  });
}

async function saveNewProduct() {
  if (!newProd.value.name.trim()) {
    showAlert('Nama produk tidak boleh kosong!');
    return;
  }
  const basePrice = parseFloat(newProd.value.price) || 0;
  if (basePrice <= 0) {
    showAlert('Harga jual produk harus lebih besar dari 0!');
    return;
  }

  const barcodeVal = newProd.value.barcode.trim();
  if (!barcodeVal) {
    showAlert('Barcode produk masih kosong! Silakan gunakan tombol [📷 Scan Barcode] untuk men-scan barcode kemasan barang.');
    return;
  }

  // Pengaman Pengecekan Duplikat Barcode
  const duplicateProd = localProducts.value.find(p => 
    p.barcode && 
    p.barcode.trim().toLowerCase() === barcodeVal.toLowerCase() && 
    p.id !== editingProductId.value
  );

  if (duplicateProd) {
    playBeepSound('warning');
    duplicateProductWarning.value = duplicateProd;
    showAlert(`Peringatan: Barcode "${barcodeVal}" sudah terdaftar untuk produk "${duplicateProd.name}". Barcode tidak boleh duplikat!`);
    return;
  }

  // Pengaman salah ketik (terlalu banyak nol)
  if (basePrice > 2000000) {
    if (!(await showConfirm(`Peringatan: Harga terdeteksi Rp ${basePrice.toLocaleString('id-ID')} (nominal cukup besar). Apakah angka ini sudah benar?`))) {
      return;
    }
  }

  const allUnits = [
    { name: newProd.value.unit, price: basePrice, ratio: 1, is_base: true }
  ];

  if (newProd.value.extraUnits.length > 0) {
    newProd.value.extraUnits.forEach(eu => {
      const p = parseFloat(eu.price) || 0;
      const r = parseInt(eu.ratio) || 1;
      if (eu.name && p > 0) {
        allUnits.push({
          name: eu.name,
          price: p,
          ratio: r,
          is_base: false
        });
      }
    });
  }

  // JIKA MODE EDIT PRODUK YANG SUDAH ADA
  if (editingProductId.value) {
    const payload = {
      barcode: barcodeVal,
      name: newProd.value.name.trim(),
      category: newProd.value.category,
      icon: getCategoryIcon(newProd.value.category),
      unit_name: allUnits[0].name,
      price: allUnits[0].price,
      cost_price: parseFloat(newProd.value.cost_price) || 0,
      stock: parseInt(newProd.value.stock) || 0,
      units: allUnits
    };

    router.put('/pos/products/' + editingProductId.value, payload, {
      preserveScroll: true,
      onSuccess: () => {
        const idx = localProducts.value.findIndex(p => p.id === editingProductId.value);
        if (idx !== -1) {
          localProducts.value[idx] = {
            ...localProducts.value[idx],
            ...payload,
            units: allUnits
          };
        }
        showAddProductModal.value = false;
        editingProductId.value = null;
        playBeepSound('success');
      }
    });
    return;
  }

  // JIKA MODE TAMBAH PRODUK BARU
  const tempId = Date.now();
  const createdProd = {
    id: tempId,
    barcode: barcodeVal,
    name: newProd.value.name.trim(),
    category: newProd.value.category,
    icon: getCategoryIcon(newProd.value.category),
    units: allUnits,
    cost_price: parseFloat(newProd.value.cost_price) || 0,
    stock: Number.isFinite(parseInt(newProd.value.stock, 10)) ? Math.max(0, parseInt(newProd.value.stock, 10)) : 100
  };

  localProducts.value.unshift(createdProd);
  const dexieProdPromise = db.offline_products.add({ ...createdProd, synced: isOnline.value ? 1 : 0 }).catch(() => null);

  if (isOnline.value) {
    router.post(route('pos.products.store'), {
      barcode: createdProd.barcode,
      name: createdProd.name,
      category: createdProd.category,
      icon: createdProd.icon,
      unit_name: allUnits[0].name,
      price: allUnits[0].price,
      cost_price: createdProd.cost_price,
      stock: createdProd.stock,
      units: allUnits,
    }, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: (page) => {
        const realProd = page?.props?.flash?.product;
        if (realProd && realProd.id) {
          const idx = localProducts.value.findIndex(p => p.id === tempId || p.barcode === createdProd.barcode);
          if (idx !== -1) {
            localProducts.value[idx] = realProd;
          }
          cart.value.forEach(cItem => {
            if (cItem.product_id === tempId || cItem.barcode === createdProd.barcode) {
              cItem.product_id = realProd.id;
            }
          });
        }
      },
      onError: async () => {
        try {
          const dId = await dexieProdPromise;
          if (dId) await db.offline_products.update(dId, { synced: 0 });
        } catch (e) {}
      }
    });
  }

  if (newProd.value.autoAdd) {
    addToCart(createdProd, 0, newProd.value.autoAddQty);
  }

  showAddProductModal.value = false;
}

// ================= MODAL GALERI KATALOG PRODUK (SIDE SHEET KIRI 65%) =================
const showGalleryModal = ref(false);
const selectedCategory = ref('Semua');
const gallerySearchQuery = ref('');

function clearGallerySearch() {
  gallerySearchQuery.value = '';
}

function getProductCategoryName(p) {
  if (!p) return 'Lainnya';

  // 1. Jika p.category berupa object (misal relation Category)
  if (typeof p.category === 'object' && p.category !== null) {
    return p.category.name || 'Lainnya';
  }

  // 2. Jika p.category berupa string (bisa JSON string atau string biasa)
  if (typeof p.category === 'string') {
    const trimmed = p.category.trim();
    if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
      try {
        const parsed = JSON.parse(trimmed);
        if (parsed && parsed.name) return parsed.name;
      } catch (e) {
        // Abaikan parse error
      }
    }
    if (trimmed && trimmed !== 'null' && trimmed !== 'undefined') {
      return trimmed;
    }
  }

  // 3. Fallback: cari dari localCategories via category_id
  if (p.category_id && localCategories.value?.length) {
    const matched = localCategories.value.find(c => c.id === p.category_id);
    if (matched && matched.name) return matched.name;
  }

  return 'Lainnya';
}

const filteredCatalogProducts = computed(() => {
  const query = gallerySearchQuery.value.trim().toLowerCase();

  // Jika kasir sedang mengetik di kolom pencarian galeri, cari otomatis ke seluruh produk toko (lintas kategori)
  if (query) {
    return localProducts.value.filter(p => {
      const matchName = p.name && p.name.toLowerCase().includes(query);
      const matchBarcode = p.barcode && p.barcode.toLowerCase().includes(query);
      const matchCategory = getProductCategoryName(p).toLowerCase().includes(query);
      return matchName || matchBarcode || matchCategory;
    });
  }

  // Jika tidak ada pencarian teks, filter berdasarkan tab kategori yang aktif
  if (selectedCategory.value === 'Semua') {
    return localProducts.value;
  }
  return localProducts.value.filter(p => {
    return getProductCategoryName(p).toLowerCase() === selectedCategory.value.toLowerCase();
  });
});



// ================= DRAWER RIWAYAT TRANSAKSI & LAPORAN KEUANGAN =================
const showReportsDrawer = ref(false);
const activeReportsTab = ref('history'); // 'history' | 'summary' | 'kasbon'

const transactionsList = ref([...(props.initialTransactions || [])]);
const reportSummary = ref({
  total_sales: props.initialSummary?.total_sales || 0,
  cash_sales: props.initialSummary?.cash_sales || 0,
  qris_sales: props.initialSummary?.qris_sales || 0,
  topup_cash_in: props.initialSummary?.topup_cash_in || 0,
  topup_total: props.initialSummary?.topup_total || 0,
  topup_capital_out: props.initialSummary?.topup_capital_out || 0,
  topup_count: props.initialSummary?.topup_count || 0,
  qris_settlement: props.initialSummary?.qris_settlement || 0,
  qris_cash_out: props.initialSummary?.qris_cash_out || 0,
  qris_withdrawal_count: props.initialSummary?.qris_withdrawal_count || 0,
  total_admin_fee: props.initialSummary?.total_admin_fee || 0,
  estimated_cash_drawer: props.initialSummary?.estimated_cash_drawer ?? ((props.initialSummary?.cash_sales || 0) + (props.initialSummary?.topup_cash_in || 0) - (props.initialSummary?.qris_cash_out || 0)),
  kasbon_sales: props.initialSummary?.kasbon_sales || 0,
  transaction_count: props.initialSummary?.transaction_count || 0,
  total_profit: props.initialSummary?.total_profit || 0,
  total_receivable: props.initialSummary?.total_receivable || 0,
});

const historySearchQuery = ref('');
const historyFilterMethod = ref('ALL'); // 'ALL' | 'TUNAI' | 'QRIS' | 'KASBON'
const historyFilterDate = ref('today'); // 'today' | 'yesterday' | 'this_week' | 'all'
const isHistoryLoading = ref(false);

// Modal Pembayaran Kasbon
const showPayDebtModal = ref(false);
const debtCustomerSelected = ref(null);
const debtPayAmount = ref(0);
const debtPayMethod = ref('TUNAI');
const debtPayNotes = ref('');

const filteredHistoryTransactions = computed(() => {
  return transactionsList.value.filter(trx => {
    // Filter Metode Bayar
    if (historyFilterMethod.value !== 'ALL' && trx.payment_method !== historyFilterMethod.value) {
      return false;
    }
    // Filter Search
    if (historySearchQuery.value.trim()) {
      const q = historySearchQuery.value.toLowerCase().trim();
      const matchInvoice = trx.invoice_no?.toLowerCase().includes(q);
      const matchCustomer = trx.customer_name?.toLowerCase().includes(q);
      const matchCashier = trx.cashier_name?.toLowerCase().includes(q);
      if (!matchInvoice && !matchCustomer && !matchCashier) return false;
    }
    return true;
  });
});

const customersWithDebt = computed(() => {
  return localCustomers.value.filter(c => (c.kasbon_balance || 0) > 0);
});

async function openReportsDrawer() {
  showReportsDrawer.value = true;
  await fetchReportSummary();
  await fetchHistoryTransactions();
}

async function fetchHistoryTransactions() {
  isHistoryLoading.value = true;
  try {
    const params = new URLSearchParams();
    if (historyFilterDate.value) params.append('date', historyFilterDate.value);
    if (historyFilterMethod.value !== 'ALL') params.append('payment_method', historyFilterMethod.value);
    if (historySearchQuery.value.trim()) params.append('search', historySearchQuery.value.trim());

    const res = await fetch('/pos/transactions?' + params.toString());
    const data = await res.json();
    if (data.status === 'success') {
      transactionsList.value = data.transactions;
    }
  } catch (err) {
    console.warn('Gagal memuat riwayat transaksi:', err);
  } finally {
    isHistoryLoading.value = false;
  }
}

async function fetchReportSummary() {
  try {
    const res = await fetch('/pos/reports/summary?period=today');
    const data = await res.json();
    if (data.status === 'success') {
      reportSummary.value = data.summary;
    }
  } catch (err) {
    console.warn('Gagal memuat ringkasan keuangan:', err);
  }
}

function reprintReceipt(trx) {
  lastTransaction.value = {
    invoice_no: trx.invoice_no,
    transaction_type: trx.transaction_type || 'SALE',
    admin_fee: trx.admin_fee || 0,
    cashier_name: trx.cashier_name,
    customer_name: trx.customer_name,
    subtotal: trx.subtotal,
    item_discount: trx.item_discount || 0,
    global_discount: trx.global_discount || 0,
    global_discount_note: trx.global_discount_note || '',
    grand_total: trx.grand_total,
    payment_method: trx.payment_method,
    paid_amount: trx.paid_amount,
    change_amount: trx.change_amount,
    created_at: new Date(trx.created_at).toLocaleDateString('id-ID') + ' ' + new Date(trx.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
    items: (trx.items || []).map(it => ({
      product_name: it.product_name,
      qty: it.qty,
      unit_name: it.unit_name,
      unit_price: it.unit_price,
      total: it.total
    }))
  };
  showReceiptModal.value = true;
  playBeepSound('success');
}

function openPayDebtModal(cust) {
  debtCustomerSelected.value = cust;
  debtPayAmount.value = cust.kasbon_balance || 0;
  debtPayMethod.value = 'TUNAI';
  debtPayNotes.value = 'Pelunasan Kasbon ' + cust.name;
  showPayDebtModal.value = true;
}

function submitPayDebt() {
  const maxBalance = Number(debtCustomerSelected.value?.kasbon_balance || 0);
  const payAmount = Math.min(maxBalance, Math.max(0, parseRupiahInt(debtPayAmount.value, 0)));
  if (!debtCustomerSelected.value || payAmount <= 0) {
    showAlert('Nominal pembayaran kasbon tidak valid!');
    return;
  }

  router.post('/pos/customers/' + debtCustomerSelected.value.id + '/pay-debt', {
    amount: payAmount,
    payment_method: debtPayMethod.value,
    notes: debtPayNotes.value
  }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      const idx = localCustomers.value.findIndex(c => c.id === debtCustomerSelected.value.id);
      if (idx !== -1) {
        localCustomers.value[idx].kasbon_balance = Math.max(0, localCustomers.value[idx].kasbon_balance - payAmount);
      }
      showPayDebtModal.value = false;
      playBeepSound('payment_success');
      fetchReportSummary();
    }
  });
}

// ================= MODAL QR BARCODE URL (DUAL DISPLAY & MOBILE) =================
const qrUrlModal = ref({
  isOpen: false,
  type: '',
  title: '',
  subtitle: '',
  url: '',
  icon: '',
  badge: '',
  actionText: '',
  isCopied: false
});

const mobileTokenData = ref({
  token: '',
  is_enabled: true,
  expires_human: '',
  remaining_minutes: 0,
  url: '',
  isLoading: false
});

async function fetchMobileTokenStatus() {
  try {
    mobileTokenData.value.isLoading = true;
    const http = window.axios || (await import('axios')).default;
    const res = await http.get('/pos/mobile-token/status');
    if (res.data) {
      mobileTokenData.value = {
        token: res.data.token,
        is_enabled: res.data.is_enabled,
        expires_human: res.data.expires_human,
        remaining_minutes: res.data.remaining_minutes,
        url: res.data.url,
        isLoading: false
      };
      const fullUrl = res.data.url.startsWith('http') 
        ? res.data.url 
        : `${window.location.origin}${res.data.url}`;
      qrUrlModal.value.url = fullUrl;
    }
  } catch (e) {
    console.warn('Gagal memuat token mobile:', e);
  } finally {
    mobileTokenData.value.isLoading = false;
  }
}

async function refreshMobileToken(hours = 2) {
  try {
    mobileTokenData.value.isLoading = true;
    const http = window.axios || (await import('axios')).default;
    const res = await http.post('/pos/mobile-token/generate', { duration_hours: hours });
    if (res.data) {
      mobileTokenData.value = {
        token: res.data.token,
        is_enabled: true,
        expires_human: res.data.expires_human,
        remaining_minutes: res.data.remaining_minutes,
        url: res.data.url,
        isLoading: false
      };
      const fullUrl = res.data.url.startsWith('http') 
        ? res.data.url 
        : `${window.location.origin}${res.data.url}`;
      qrUrlModal.value.url = fullUrl;
      playBeepSound('scan');
    }
  } catch (e) {
    console.warn('Gagal perbarui token mobile:', e);
  } finally {
    mobileTokenData.value.isLoading = false;
  }
}

async function revokeMobileAccess() {
  if (!(await showConfirm('Kunci akses input HP pribadi karyawan sekarang? Karyawan tidak akan bisa menyimpan barang lagi.'))) return;
  try {
    mobileTokenData.value.isLoading = true;
    const http = window.axios || (await import('axios')).default;
    const res = await http.post('/pos/mobile-token/revoke');
    if (res.data) {
      mobileTokenData.value.is_enabled = false;
      playBeepSound('delete');
    }
  } catch (e) {
    console.warn('Gagal cabut akses mobile:', e);
  } finally {
    mobileTokenData.value.isLoading = false;
  }
}

function openQrUrlModal(type) {
  showNavMenu.value = false;
  const origin = window.location.origin;
  qrUrlModal.value.type = type;
  if (type === 'display') {
    qrUrlModal.value = {
      isOpen: true,
      type: 'display',
      title: 'Barcode Layar Pembeli (Dual Display)',
      subtitle: 'Arahkan kamera tablet / monitor pelanggan ke barcode ini untuk menghubungkan Layar Kedua (Dual Display) secara nirkabel.',
      url: `${origin}/pos/display?code=100001`,
      icon: '🖥️',
      badge: 'CFD Dual Display',
      actionText: 'Buka di Tab Baru',
      isCopied: false
    };
  } else if (type === 'mobile') {
    qrUrlModal.value = {
      isOpen: true,
      type: 'mobile',
      title: 'Barcode Input Barang HP Pribadi',
      subtitle: 'Karyawan cukup scan barcode ini menggunakan kamera HP pribadinya untuk langsung masuk tanpa perlu password toko.',
      url: `${origin}/pos/products/mobile`,
      icon: '📱',
      badge: 'Akses Karyawan',
      actionText: 'Buka di Perangkat Ini',
      isCopied: false
    };
    fetchMobileTokenStatus();
  }
}

function copyQrUrl() {
  if (!qrUrlModal.value.url) return;
  navigator.clipboard.writeText(qrUrlModal.value.url).then(() => {
    qrUrlModal.value.isCopied = true;
    setTimeout(() => {
      qrUrlModal.value.isCopied = false;
    }, 2500);
  }).catch(() => {});
}

// ================= MODAL KONFIRMASI LOGOUT =================
const showLogoutModal = ref(false);

function confirmLogout() {
  showLogoutModal.value = false;
  playBeepSound('click');
  router.post(route('logout'));
}

// ================= MASTER SETTINGS MODAL (MULTI-TAB) =================
const showNavMenu = ref(false);
const showSettingsModal = ref(false);
const activeSettingsTab = ref('profile'); // 'profile' | 'payment' | 'printer' | 'features' | 'data'

const page = usePage();

const settingsForm = ref({
  store_name: props.settings?.store_name || page.props.store?.name || 'Rozak Mart',
  store_description: props.settings?.store_description || page.props.store?.description || '',
  store_address: props.settings?.store_address || 'Jl. Melati No. 12, Kel. Sukamaju',
  store_phone: props.settings?.store_phone || '0812-3456-7890',
  receipt_footer: props.settings?.receipt_footer || 'Terima Kasih Atas Kunjungan Anda!\nBarang yang sudah dibeli tidak dapat ditukar.',
  qris_mode: localStorage.getItem('qris_mode') || props.settings?.qris_mode || 'emvco',
  qris_static_payload: localStorage.getItem('store_qris_payload') || props.settings?.qris_static_payload || '',
  qris_withdrawal_admin_fee: props.settings?.qris_withdrawal_admin_fee || '2000',
  midtrans_server_key: props.settings?.midtrans_server_key || '',
  midtrans_is_production: props.settings?.midtrans_is_production === 'true',
  receipt_paper_size: localStorage.getItem('receipt_paper_size') || props.settings?.receipt_paper_size || '80mm',
  receipt_auto_print: localStorage.getItem('receipt_auto_print') === 'true' || props.settings?.receipt_auto_print === 'true',
  enable_stock_tracking: props.settings?.enable_stock_tracking === 'true',
  enable_cashier_shifts: props.settings?.enable_cashier_shifts === 'true',
  enable_hpp_profit: props.settings?.enable_hpp_profit === 'true',
  cashier_can_manage_products: props.settings?.cashier_can_manage_products === 'true',
  cashier_can_view_financial_summary: props.settings?.cashier_can_view_financial_summary === 'true',
});

const storeName = computed(() => settingsForm.value.store_name || page.props.store?.name || 'Rozak Mart');
const storeDescription = computed(
  () => settingsForm.value.store_description || page.props.store?.description || settingsForm.value.store_address || ''
);
const storeInitials = computed(() => getStoreInitials(storeName.value));

const offlineStats = ref({
  transactionsCount: 0,
  unsyncedTransactionsCount: 0,
  productsCount: 0,
  isSyncing: false,
});

async function loadOfflineStats() {
  try {
    offlineStats.value.transactionsCount = await db.offline_transactions.count();
    offlineStats.value.unsyncedTransactionsCount = await db.offline_transactions.where('synced').equals(0).count();
    offlineStats.value.productsCount = await db.offline_products.count();
  } catch (e) {
    console.warn('Error reading offline stats:', e);
  }
}

async function syncOfflineTransactions(silent = false) {
  if (!isOnline.value || offlineStats.value.isSyncing) {
    if (!silent && !isOnline.value) showAlert('Perangkat sedang offline. Sambungkan ke WiFi/internet untuk menyinkronkan data.');
    return;
  }

  offlineStats.value.isSyncing = true;
  try {
    const http = window.axios || (await import('axios')).default;
    let totalSyncedCount = 0;

    // 1. Sync produk baru yang dibuat saat offline terlebih dahulu
    const unsyncedProds = await db.offline_products.where('synced').equals(0).toArray();
    if (unsyncedProds.length > 0) {
      await http.post('/api/sync/products', { products: unsyncedProds }, { headers: { Accept: 'application/json' } });
      for (const p of unsyncedProds) {
        if (p.id) await db.offline_products.update(p.id, { synced: 1 });
      }
      totalSyncedCount += unsyncedProds.length;
    }

    // 2. Sync transaksi offline ke server
    const unsyncedTrxs = await db.offline_transactions.where('synced').equals(0).toArray();
    if (unsyncedTrxs.length > 0) {
      await http.post('/api/sync/transactions', { transactions: unsyncedTrxs }, { headers: { Accept: 'application/json' } });
      for (const t of unsyncedTrxs) {
        if (t.id) await db.offline_transactions.update(t.id, { synced: 1 });
      }
      totalSyncedCount += unsyncedTrxs.length;
    }

    await loadOfflineStats();
    if (!silent) {
      playBeepSound('success');
      showAlert(totalSyncedCount > 0 ? `✓ Berhasil menyinkronkan ${totalSyncedCount} data offline ke server!` : 'Semua data transaksi sudah sinkron dengan server.');
    }
  } catch (e) {
    console.warn('Gagal sinkronisasi offline:', e);
    if (!silent) showAlert('Gagal menyinkronkan data offline. Silakan periksa koneksi server.');
  } finally {
    offlineStats.value.isSyncing = false;
  }
}

function openSettingsModal() {
  settingsForm.value = {
    store_name: props.settings?.store_name || page.props.store?.name || 'Rozak Mart',
    store_description: props.settings?.store_description || page.props.store?.description || '',
    store_address: props.settings?.store_address || 'Jl. Melati No. 12, Kel. Sukamaju',
    store_phone: props.settings?.store_phone || '0812-3456-7890',
    receipt_footer: props.settings?.receipt_footer || 'Terima Kasih Atas Kunjungan Anda!\nBarang yang sudah dibeli tidak dapat ditukar.',
    qris_mode: localStorage.getItem('qris_mode') || props.settings?.qris_mode || 'emvco',
    qris_static_payload: localStorage.getItem('store_qris_payload') || props.settings?.qris_static_payload || '',
    qris_withdrawal_admin_fee: props.settings?.qris_withdrawal_admin_fee || '2000',
    midtrans_server_key: props.settings?.midtrans_server_key || '',
    midtrans_is_production: props.settings?.midtrans_is_production === 'true',
    receipt_paper_size: localStorage.getItem('receipt_paper_size') || props.settings?.receipt_paper_size || '80mm',
    receipt_auto_print: localStorage.getItem('receipt_auto_print') === 'true' || props.settings?.receipt_auto_print === 'true',
    enable_stock_tracking: props.settings?.enable_stock_tracking === 'true',
    enable_cashier_shifts: props.settings?.enable_cashier_shifts === 'true',
    enable_hpp_profit: props.settings?.enable_hpp_profit === 'true',
    cashier_can_manage_products: props.settings?.cashier_can_manage_products === 'true',
    cashier_can_view_financial_summary: props.settings?.cashier_can_view_financial_summary === 'true',
  };
  loadOfflineStats();
  if (props.authCashier?.is_admin) {
    fetchBackups();
  } else if (['profile', 'payment', 'features'].includes(activeSettingsTab.value)) {
    activeSettingsTab.value = 'printer';
  }
  showSettingsModal.value = true;
}

function saveMasterSettings() {
  // Simpan ke localStorage untuk akses cepat instan di browser
  localStorage.setItem('qris_mode', settingsForm.value.qris_mode);
  localStorage.setItem('store_qris_payload', settingsForm.value.qris_static_payload);
  localStorage.setItem('receipt_paper_size', settingsForm.value.receipt_paper_size);
  localStorage.setItem('receipt_auto_print', settingsForm.value.receipt_auto_print ? 'true' : 'false');
  
  // Update state lokal
  qrisMode.value = settingsForm.value.qris_mode;
  storeQrisPayload.value = settingsForm.value.qris_static_payload;

  // Simpan ke Backend Database Laravel
  router.post('/pos/settings', {
    settings: {
      store_name: settingsForm.value.store_name,
      store_description: settingsForm.value.store_description,
      store_address: settingsForm.value.store_address,
      store_phone: settingsForm.value.store_phone,
      receipt_footer: settingsForm.value.receipt_footer,
      qris_mode: settingsForm.value.qris_mode,
      qris_static_payload: settingsForm.value.qris_static_payload,
      qris_withdrawal_admin_fee: settingsForm.value.qris_withdrawal_admin_fee,
      midtrans_server_key: settingsForm.value.midtrans_server_key,
      midtrans_is_production: settingsForm.value.midtrans_is_production,
      receipt_paper_size: settingsForm.value.receipt_paper_size,
      receipt_auto_print: settingsForm.value.receipt_auto_print,
      enable_stock_tracking: settingsForm.value.enable_stock_tracking,
      enable_cashier_shifts: settingsForm.value.enable_cashier_shifts,
      enable_hpp_profit: settingsForm.value.enable_hpp_profit,
      cashier_can_manage_products: settingsForm.value.cashier_can_manage_products,
      cashier_can_view_financial_summary: settingsForm.value.cashier_can_view_financial_summary,
    }
  }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      showSettingsModal.value = false;
      playBeepSound('success');
    }
  });
}

function triggerPrintReceipt() {
  if (typeof window !== 'undefined' && typeof window.print === 'function') {
    window.print();
  }
}

function reloadApp() {
  if (typeof window !== 'undefined') {
    window.location.reload();
  }
}

const isUpdatingSystem = ref(false);
const systemUpdateLog = ref('');

async function runSystemUpdate(mode = 'apply') {
  if (!props.authCashier?.is_admin || isUpdatingSystem.value) return;

  const confirmMsg = mode === 'git_pull'
    ? 'Tarik update terbaru dari GitHub (branch main), jalankan migrasi database, dan bersihkan cache server sekarang?'
    : 'Terapkan update server (jalankan migrasi database & bersihkan cache Laravel) sekarang?';

  if (!(await showConfirm(confirmMsg, { title: 'Update Aplikasi', variant: 'info', confirmText: 'Ya, Jalankan' }))) {
    return;
  }

  isUpdatingSystem.value = true;
  systemUpdateLog.value = '';
  try {
    const http = window.axios || (await import('axios')).default;
    const res = await http.post('/pos/system/update', { mode }, { headers: { Accept: 'application/json' } });
    const data = res?.data || {};
    const parts = [data.git_output, data.migrate_output, data.cache_output].filter(Boolean);
    systemUpdateLog.value = parts.join('\n\n');
    playBeepSound('success');
    await showAlert(data.message || 'Update server berhasil diterapkan!', { title: 'Update Berhasil', variant: 'success' });

    if ('caches' in window) {
      try {
        const names = await caches.keys();
        await Promise.all(names.map(n => caches.delete(n)));
      } catch (e) {}
    }
    reloadApp();
  } catch (err) {
    const errData = err?.response?.data || {};
    if (errData.git_output) {
      systemUpdateLog.value = errData.git_output;
    }
    playBeepSound('warning');
    await showAlert(errData.message || 'Gagal menjalankan update sistem di server.', { title: 'Update Gagal', variant: 'danger' });
  } finally {
    isUpdatingSystem.value = false;
  }
}

const backupsList = ref([]);
const dbDriver = ref('');
const isBackupBusy = ref(false);
const backupFileInputRef = ref(null);
const uploadRestoreImmediately = ref(false);

async function fetchBackups() {
  if (!props.authCashier?.is_admin) return;
  try {
    const http = window.axios || (await import('axios')).default;
    const res = await http.get('/pos/system/backups', { headers: { Accept: 'application/json' } });
    backupsList.value = res?.data?.backups || [];
    dbDriver.value = res?.data?.driver || '';
  } catch (e) {
    console.warn('Gagal memuat daftar backup:', e);
  }
}

async function createServerBackup(downloadAfter = false) {
  if (!props.authCashier?.is_admin || isBackupBusy.value) return;
  isBackupBusy.value = true;
  try {
    const http = window.axios || (await import('axios')).default;
    const res = await http.post('/pos/system/backups', {}, { headers: { Accept: 'application/json' } });
    const data = res?.data || {};
    backupsList.value = data.backups || backupsList.value;
    playBeepSound('success');
    if (downloadAfter && data.backup?.filename) {
      downloadBackupFile(data.backup.filename);
    }
    await showAlert(data.message || 'Backup database berhasil dibuat.', { title: 'Backup Berhasil', variant: 'success' });
  } catch (err) {
    playBeepSound('warning');
    await showAlert(err?.response?.data?.message || 'Gagal membuat backup database.', { title: 'Backup Gagal', variant: 'danger' });
  } finally {
    isBackupBusy.value = false;
  }
}

function downloadBackupFile(filename) {
  if (!filename || typeof window === 'undefined') return;
  window.location.href = `/pos/system/backups/download/${encodeURIComponent(filename)}`;
}

function triggerBackupUpload(restoreNow = false) {
  uploadRestoreImmediately.value = restoreNow;
  if (backupFileInputRef.value) {
    backupFileInputRef.value.value = '';
    backupFileInputRef.value.click();
  }
}

async function handleBackupFileSelected(e) {
  const file = e?.target?.files?.[0];
  if (!file || isBackupBusy.value) return;

  if (uploadRestoreImmediately.value) {
    const ok = await showConfirm(
      `Unggah file "${file.name}" dan langsung TIMPA (Restore) seluruh isi database saat ini?\n\nSnapshot pengaman otomatis akan disimpan sebelum restore.`,
      { title: 'Konfirmasi Unggah & Restore', variant: 'danger', confirmText: 'Ya, Unggah & Restore' }
    );
    if (!ok) return;
  }

  isBackupBusy.value = true;
  try {
    const http = window.axios || (await import('axios')).default;
    const formData = new FormData();
    formData.append('file', file);
    formData.append('restore_now', uploadRestoreImmediately.value ? '1' : '0');

    const res = await http.post('/pos/system/backups/upload', formData, {
      headers: { Accept: 'application/json', 'Content-Type': 'multipart/form-data' },
    });
    const data = res?.data || {};
    backupsList.value = data.backups || backupsList.value;
    playBeepSound('success');
    await showAlert(data.message || 'File backup berhasil diunggah.', { title: 'Berhasil', variant: 'success' });
    if (uploadRestoreImmediately.value) {
      reloadApp();
    }
  } catch (err) {
    playBeepSound('warning');
    await showAlert(err?.response?.data?.message || 'Gagal mengunggah file backup.', { title: 'Unggah Gagal', variant: 'danger' });
  } finally {
    isBackupBusy.value = false;
    if (backupFileInputRef.value) backupFileInputRef.value.value = '';
  }
}

async function restoreBackupFile(filename) {
  if (!props.authCashier?.is_admin || isBackupBusy.value) return;
  const ok = await showConfirm(
    `Restore database dari "${filename}"?\n\nSeluruh data aktif saat ini akan diganti dengan isi backup tersebut. Snapshot pengaman otomatis akan dibuat terlebih dahulu.`,
    { title: 'Restore Database', variant: 'danger', confirmText: 'Ya, Restore Sekarang' }
  );
  if (!ok) return;

  isBackupBusy.value = true;
  try {
    const http = window.axios || (await import('axios')).default;
    const res = await http.post('/pos/system/backups/restore', { filename }, { headers: { Accept: 'application/json' } });
    const data = res?.data || {};
    backupsList.value = data.backups || backupsList.value;
    playBeepSound('success');
    await showAlert(data.message || 'Database berhasil di-restore!', { title: 'Restore Berhasil', variant: 'success' });
    reloadApp();
  } catch (err) {
    playBeepSound('warning');
    await showAlert(err?.response?.data?.message || 'Gagal melakukan restore database.', { title: 'Restore Gagal', variant: 'danger' });
  } finally {
    isBackupBusy.value = false;
  }
}

async function deleteBackupFile(filename) {
  if (!props.authCashier?.is_admin || isBackupBusy.value) return;
  const ok = await showConfirm(`Hapus file backup "${filename}" dari server?`, { title: 'Hapus Backup', variant: 'danger', confirmText: 'Hapus' });
  if (!ok) return;

  isBackupBusy.value = true;
  try {
    const http = window.axios || (await import('axios')).default;
    const res = await http.delete(`/pos/system/backups/${encodeURIComponent(filename)}`, { headers: { Accept: 'application/json' } });
    backupsList.value = res?.data?.backups || backupsList.value;
    playBeepSound('delete');
  } catch (err) {
    await showAlert(err?.response?.data?.message || 'Gagal menghapus file backup.', { title: 'Gagal', variant: 'danger' });
  } finally {
    isBackupBusy.value = false;
  }
}

// ================= MANAJEMEN AKUN KASIR (OWNER ONLY) =================
const cashierForm = ref({
  name: '',
  password: '',
  role: 'cashier',
});
const isSavingCashier = ref(false);

function saveNewCashier() {
  if (!cashierForm.value.name.trim() || !cashierForm.value.password) {
    showAlert('Nama dan password kasir wajib diisi.');
    return;
  }
  isSavingCashier.value = true;
  router.post('/pos/cashiers', {
    name: cashierForm.value.name.trim(),
    password: cashierForm.value.password,
    role: cashierForm.value.role,
  }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      cashierForm.value = { name: '', password: '', role: 'cashier' };
      playBeepSound('success');
    },
    onFinish: () => {
      isSavingCashier.value = false;
    }
  });
}

async function removeCashier(cashier) {
  if (!cashier?.id) return;
  if (!(await showConfirm(`Hapus akun "${cashier.name}"?`))) return;
  router.delete(`/pos/cashiers/${cashier.id}`, {
    preserveScroll: true,
    preserveState: true,
  });
}

// ================= BUKU KAS & SHIFT KASIR =================
const currentShift = ref(props.activeShift || null);
watch(() => props.activeShift, (val) => {
  currentShift.value = val || null;
});
const shiftStartingCash = ref('0');
const shiftActualCash = ref('');
const shiftNotes = ref('');
const cashMovementForm = ref({ type: 'CASH_OUT', amount: '', reason: '' });

function submitOpenShift() {
  const starting = parseInt(String(shiftStartingCash.value).replace(/\D/g, '')) || 0;
  router.post('/pos/shifts/open', {
    starting_cash: starting,
    notes: shiftNotes.value.trim() || null,
  }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: (page) => {
      if (page?.props?.activeShift) {
        currentShift.value = page.props.activeShift;
      } else if (page?.props?.flash?.active_shift) {
        currentShift.value = page.props.flash.active_shift;
      }
      shiftStartingCash.value = '0';
      shiftNotes.value = '';
      playBeepSound('success');
    }
  });
}

function submitCashMovement() {
  const amt = parseInt(String(cashMovementForm.value.amount).replace(/\D/g, '')) || 0;
  if (amt <= 0 || !cashMovementForm.value.reason.trim()) {
    showAlert('Masukkan nominal dan keterangan arus kas laci!');
    return;
  }
  router.post('/pos/shifts/movement', {
    type: cashMovementForm.value.type,
    amount: amt,
    reason: cashMovementForm.value.reason.trim(),
  }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: (page) => {
      if (page?.props?.activeShift) currentShift.value = page.props.activeShift;
      else if (page?.props?.flash?.active_shift) currentShift.value = page.props.flash.active_shift;
      cashMovementForm.value = { type: 'CASH_OUT', amount: '', reason: '' };
      playBeepSound('success');
    }
  });
}

async function submitCloseShift() {
  const actual = parseInt(String(shiftActualCash.value).replace(/\D/g, ''));
  if (isNaN(actual) || actual < 0) {
    showAlert('Masukkan jumlah uang fisik yang dihitung di laci kasir saat tutup shift.');
    return;
  }
  if (!(await showConfirm('Yakin tutup shift sekarang?'))) return;
  router.post('/pos/shifts/close', {
    actual_cash: actual,
    notes: shiftNotes.value.trim() || null,
  }, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      currentShift.value = null;
      shiftActualCash.value = '';
      shiftNotes.value = '';
      playBeepSound('success');
      fetchReportSummary();
    }
  });
}

// ================= MODAL GALERI PELANGGAN (SIDE SHEET KANAN 35%) =================
const showCustomerModal = ref(false);
const customerSearchQuery = ref('');

const filteredCustomers = computed(() => {
  const q = customerSearchQuery.value.trim().toLowerCase();
  if (!q) return localCustomers.value;
  return localCustomers.value.filter(c => 
    c.name.toLowerCase().includes(q) || 
    (c.phone && c.phone.includes(q))
  );
});

function selectCustomer(cust) {
  selectedCustomer.value = cust || getDefaultCustomer();
  showCustomerModal.value = false;
}

// ================= MANAJEMEN PELANGGAN / MEMBER =================
const showAddCustomerModal = ref(false);
const editingCustomer = ref(null);
const customerForm = ref({
  name: '',
  phone: '',
  address: '',
  credit_limit: 500000
});

function openAddCustomerModal() {
  editingCustomer.value = null;
  customerForm.value = {
    name: '',
    phone: '',
    address: '',
    credit_limit: 500000
  };
  showAddCustomerModal.value = true;
}

function openEditCustomerModal(cust) {
  editingCustomer.value = cust;
  customerForm.value = {
    name: cust.name,
    phone: cust.phone !== '-' ? (cust.phone || '') : '',
    address: cust.address !== '-' ? (cust.address || '') : '',
    credit_limit: cust.credit_limit || 500000
  };
  showAddCustomerModal.value = true;
}

function saveCustomer() {
  if (!customerForm.value.name.trim()) {
    playBeepSound('warning');
    showAlert('Nama pelanggan wajib diisi!');
    return;
  }

  playBeepSound('click');
  if (editingCustomer.value) {
    // Mode Update Pelanggan
    router.put('/pos/customers/' + editingCustomer.value.id, customerForm.value, {
      preserveScroll: true,
      onSuccess: () => {
        const idx = localCustomers.value.findIndex(c => c.id === editingCustomer.value.id);
        if (idx !== -1) {
          localCustomers.value[idx] = { ...localCustomers.value[idx], ...customerForm.value };
          if (selectedCustomer.value?.id === editingCustomer.value.id) {
            selectedCustomer.value = { ...selectedCustomer.value, ...customerForm.value };
          }
        }
        showAddCustomerModal.value = false;
        playBeepSound('success');
      }
    });
  } else {
    // Mode Tambah Pelanggan Baru
    router.post('/pos/customers', customerForm.value, {
      preserveScroll: true,
      onSuccess: (page) => {
        const newCust = page.props.flash?.customer || {
          id: Date.now(),
          ...customerForm.value,
          kasbon_balance: 0
        };
        localCustomers.value.push(newCust);
        selectedCustomer.value = newCust;
        showAddCustomerModal.value = false;
        showCustomerModal.value = false;
        playBeepSound('success');
      }
    });
  }
}

async function deleteCustomer(id) {
  if (id === 1) {
    showAlert('Pelanggan Umum tidak boleh dihapus.');
    return;
  }
  const cust = localCustomers.value.find(c => c.id === id);
  if (!cust) return;

  if (cust.kasbon_balance > 0) {
    showAlert(`Pelanggan "${cust.name}" masih memiliki catatan kasbon Rp ${cust.kasbon_balance.toLocaleString('id-ID')}. Lunasi terlebih dahulu sebelum menghapus.`);
    return;
  }

  if (!(await showConfirm(`Hapus data pelanggan "${cust.name}" dari sistem?`))) return;

  router.delete('/pos/customers/' + id, {
    preserveScroll: true,
    onSuccess: () => {
      localCustomers.value = localCustomers.value.filter(c => c.id !== id);
      if (selectedCustomer.value?.id === id) {
        selectedCustomer.value = getDefaultCustomer();
      }
      showAddCustomerModal.value = false;
      playBeepSound('delete');
    }
  });
}

// ================= FULLSCREEN & PWA INSTALL =================
const isFullscreen = ref(false);
const deferredInstallPrompt = ref(null);
const canInstallPwa = ref(false);
const isPwaStandalone = ref(false);

function checkPwaStandalone() {
  if (typeof window !== 'undefined') {
    isPwaStandalone.value = 
      window.matchMedia('(display-mode: standalone)').matches || 
      window.matchMedia('(display-mode: fullscreen)').matches || 
      window.navigator.standalone === true;
  }
}

function toggleFullscreen() {
  if (!document.fullscreenElement) {
    const el = document.documentElement;
    if (el.requestFullscreen) {
      el.requestFullscreen().catch(() => {});
    } else if (el.webkitRequestFullscreen) {
      el.webkitRequestFullscreen();
    }
    isFullscreen.value = true;
  } else {
    if (document.exitFullscreen) {
      document.exitFullscreen().catch(() => {});
    } else if (document.webkitExitFullscreen) {
      document.webkitExitFullscreen();
    }
    isFullscreen.value = false;
  }
}

async function triggerPwaInstall() {
  playBeepSound('click');
  if (deferredInstallPrompt.value) {
    deferredInstallPrompt.value.prompt();
    const choice = await deferredInstallPrompt.value.userChoice;
    if (choice && choice.outcome === 'accepted') {
      canInstallPwa.value = false;
      isPwaStandalone.value = true;
    }
    deferredInstallPrompt.value = null;
  } else {
    showAlert(
      `Petunjuk Pemasangan PWA ${storeName.value}:\n\n` +
      '1. Di browser Chrome tablet/HP, ketuk ikon titik tiga (⋮) di pojok kanan atas.\n' +
      '2. Pilih menu "Tambahkan ke Layar Utama" (Add to Home screen) atau "Pasang Aplikasi".\n' +
      '3. Setelah terpasang, buka aplikasi langsung dari ikon Layar Utama tablet agar tampil Fullscreen tanpa address bar Chrome.',
      { title: `Pasang Aplikasi ${storeName.value}`, variant: 'info' }
    );
  }
}

function handleFullscreenChange() {
  isFullscreen.value = !!document.fullscreenElement;
}

function handleBeforeInstallPrompt(e) {
  e.preventDefault();
  deferredInstallPrompt.value = e;
  canInstallPwa.value = true;
}

function handleAppInstalled() {
  canInstallPwa.value = false;
  deferredInstallPrompt.value = null;
  isPwaStandalone.value = true;
}

// ================= DUAL DISPLAY (CUSTOMER FACING DISPLAY) =================
let displayChannel = null;
let displayChannelLegacy = null;
let backendSyncTimer = null;
const CASHIER_DISPLAY_CODE = '100001';

function debouncedBackendDisplaySync(payload) {
  if (backendSyncTimer) clearTimeout(backendSyncTimer);
  backendSyncTimer = setTimeout(() => {
    fetch('/pos/display/sync', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      },
      body: JSON.stringify(payload)
    }).catch(() => {});
  }, 400);
}

function syncCustomerDisplay(overrideState = null) {
  let state = 'IDLE';
  if (overrideState) {
    state = overrideState;
  } else if (showQrisModal.value) {
    state = 'PAYING_QRIS';
  } else if (rightMode.value === 'cash') {
    state = 'PAYING_CASH';
  } else if (cart.value.length > 0) {
    state = 'ACTIVE';
  }

  const payload = {
    code: CASHIER_DISPLAY_CODE,
    state,
    store: {
      name: settingsForm.value?.store_name || 'Rozak Mart',
      description: settingsForm.value?.store_description || '',
      address: settingsForm.value?.store_address || '',
      phone: settingsForm.value?.store_phone || '',
      footer: settingsForm.value?.receipt_footer || ''
    },
    cashier: {
      name: props.authCashier?.name || 'Siska Anggraeni',
      shift: props.authCashier?.shift || 'Shift Pagi · Kasir Aktif'
    },
    customer: selectedCustomer.value,
    cart: cart.value.map(item => ({
      name: item.name,
      qty: item.qty,
      price: item.price,
      unit: item.unit,
      discount: item.discount,
      subtotal: (item.price * item.qty) - (item.discount || 0),
      icon: item.icon || '📦'
    })),
    totalQty: totalQty.value,
    subtotal: subtotal.value,
    discount: itemDiscountTotal.value + globalDiscount.value,
    grandTotal: grandTotal.value,
    cash: {
      given: parseFloat(inlineCashGiven.value.replace(/\./g, '')) || 0,
      change: inlineCashChange.value
    },
    qris: {
      mode: qrisMode.value,
      qrUrl: qrisMode.value === 'gateway' ? gatewayQrUrl.value : dynamicEmvcoQrUrl.value,
      status: gatewayStatus.value,
      message: gatewayMessage.value
    },
    lastTransaction: lastTransaction.value,
    timestamp: Date.now()
  };

  // 1. BroadcastChannel API lokal (Nol latensi untuk multi-monitor / dual-screen)
  try {
    if (displayChannel) displayChannel.postMessage(payload);
    if (displayChannelLegacy) displayChannelLegacy.postMessage(payload);
  } catch (e) {}

  // 2. localStorage Event fallback
  try {
    localStorage.setItem('rozak_dual_display_state_' + CASHIER_DISPLAY_CODE, JSON.stringify(payload));
    localStorage.setItem('rozak_dual_display_state', JSON.stringify(payload));
  } catch (e) {}

  // 3. Debounced sync ke backend untuk tablet kedua nirkabel
  debouncedBackendDisplaySync(payload);
}

function openCustomerDisplay() {
  const url = '/pos/display?code=' + CASHIER_DISPLAY_CODE;
  window.open(url, 'RozakMartCustomerDisplay', 'width=1024,height=600,menubar=no,toolbar=no,location=no,status=no');
  setTimeout(() => syncCustomerDisplay(), 500);
}

// Watchers untuk update otomatis layar pembeli secara realtime
watch(cart, () => syncCustomerDisplay(), { deep: true });
watch(selectedCustomer, () => syncCustomerDisplay(), { deep: true });
watch([globalDiscount, rightMode, inlineCashGiven, showQrisModal, qrisMode, gatewayStatus, gatewayQrUrl], () => {
  syncCustomerDisplay();
});

const handleNetworkOnline = () => {
  isOnline.value = true;
  syncOfflineTransactions(true);
};
const handleNetworkOffline = () => {
  isOnline.value = false;
};

function handleClickOutsideNavMenu(e) {
  const container = document.getElementById('pos-nav-menu-container');
  if (container && !container.contains(e.target)) {
    showNavMenu.value = false;
  }
}

onMounted(async () => {
  updateClock();
  clockTimer = setInterval(updateClock, 1000);

  window.addEventListener('online', handleNetworkOnline);
  window.addEventListener('offline', handleNetworkOffline);
  loadOfflineStats();

  // Muat antrean offline dari IndexedDB jika belum masuk ke list server
  try {
    const localHolds = await db.offline_hold_orders.toArray();
    if (localHolds.length > 0) {
      localHolds.forEach(lh => {
        if (!holdOrdersList.value.some(h => h.id === lh.id || (lh.dbId && h.id === lh.dbId))) {
          holdOrdersList.value.unshift(lh);
        }
      });
    }
  } catch (e) {}

  // Inisialisasi BroadcastChannel untuk Dual Display (Kompatibel kode pairing 100001)
  try {
    if ('BroadcastChannel' in window) {
      displayChannel = new BroadcastChannel('pos_display_' + CASHIER_DISPLAY_CODE);
      displayChannelLegacy = new BroadcastChannel('rozak_pos_display');
      syncCustomerDisplay();
    }
  } catch (e) {}

  checkPwaStandalone();

  // Tangkap prompt PWA jika sudah ditangkap lebih awal oleh app.blade.php
  if (typeof window !== 'undefined') {
    if (window.__pwaInstallPrompt) {
      deferredInstallPrompt.value = window.__pwaInstallPrompt;
      canInstallPwa.value = true;
    }
    window.addEventListener('pwa-prompt-ready', () => {
      if (window.__pwaInstallPrompt) {
        deferredInstallPrompt.value = window.__pwaInstallPrompt;
        canInstallPwa.value = true;
      }
    });
  }

  document.addEventListener('fullscreenchange', handleFullscreenChange);
  window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
  window.addEventListener('appinstalled', handleAppInstalled);
  document.addEventListener('click', handleClickOutsideSearch);
  document.addEventListener('click', handleClickOutsideNavMenu);

  window.addEventListener('keydown', handleGlobalHardwareScan);

  // Buka kunci AudioContext otomatis saat klik/interaksi pertama pengguna
  const unlockAudioOnce = () => {
    unlockAudioContext();
    window.removeEventListener('click', unlockAudioOnce);
    window.removeEventListener('keydown', unlockAudioOnce);
    window.removeEventListener('touchstart', unlockAudioOnce);
  };
  window.addEventListener('click', unlockAudioOnce, { passive: true });
  window.addEventListener('keydown', unlockAudioOnce, { passive: true });
  window.addEventListener('touchstart', unlockAudioOnce, { passive: true });
});

onUnmounted(() => {
  if (clockTimer) clearInterval(clockTimer);
  document.removeEventListener('fullscreenchange', handleFullscreenChange);
  window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
  window.removeEventListener('appinstalled', handleAppInstalled);
  document.removeEventListener('click', handleClickOutsideSearch);
  document.removeEventListener('click', handleClickOutsideNavMenu);
  window.removeEventListener('keydown', handleGlobalHardwareScan);
  window.removeEventListener('online', handleNetworkOnline);
  window.removeEventListener('offline', handleNetworkOffline);
  if (html5QrCode && isCameraActive.value) {
    html5QrCode.stop().catch(() => {});
  }
  if (displayChannel) {
    displayChannel.close();
  }
  if (displayChannelLegacy) {
    displayChannelLegacy.close();
  }
});
</script>

<template>
  <Head :title="storeDescription ? `${storeName} - ${storeDescription}` : `${storeName} - Kasir Pintar Android Tablet`" />

  <!-- ROOT CONTAINER: Mengisi Seluruh Layar (Fullscreen Edge-to-Edge - QubePOS Style) -->
  <div class="flex flex-row w-screen h-screen max-w-full max-h-screen overflow-hidden text-slate-800 bg-white select-none relative font-sans shadow-none">
    
    <!-- ================================================================
         PANEL KIRI (65%): KERANJANG BELANJA (QubePOS FLAT INDUSTRIAL)
         Header: Logo Toko + Search + Galeri + Scanner
         Body:   List Item Belanja Full Height (Clean Grid)
         Footer: LED-Style POS Grand Total Display
    ================================================================= -->
    <div class="w-[65%] bg-white flex flex-col border-r border-slate-200 overflow-hidden">
      
      <!-- Header Keranjang: Logo + Search + Tombol Galeri & Scanner (MODERN POS FLAT) -->
      <div class="bg-white border-b border-slate-200 px-4 py-2.5 shrink-0 flex items-center gap-3">
        <!-- Logo Toko & Tombol Dropdown Menu Navigasi Utama (Disembunyikan saat form pencarian aktif melebar) -->
        <div v-show="!isSearchActive" id="pos-nav-menu-container" class="relative shrink-0">
          <button 
            type="button" 
            @click="showNavMenu = !showNavMenu" 
            title="Buka menu kasir (Pengaturan, Pelanggan, Barcode Display & HP)" 
            class="flex items-center gap-2.5 p-1 -ml-1 rounded-xl hover:bg-slate-100 transition cursor-pointer select-none group border border-transparent hover:border-slate-200"
          >
            <!-- Logo Badge Toko dengan Panah Dropdown -->
            <div class="w-9 h-9 rounded-xl bg-blue-600 group-hover:bg-blue-700 text-white font-black text-xs flex items-center justify-center shadow-xs transition shrink-0">
              {{ storeInitials }}
            </div>
            <div class="text-left leading-tight pr-1">
              <div class="flex items-center gap-1.5">
                <span class="text-xs font-black text-slate-900 uppercase tracking-wide">{{ settingsForm.store_name || 'Rozak Mart' }}</span>
                <span class="text-[9px] text-slate-400 group-hover:text-blue-600 transition" :class="showNavMenu ? 'rotate-180' : ''">▼</span>
              </div>
              <div class="text-[10px] text-slate-500 font-medium mt-0.5">Kasir: <strong class="text-slate-700">{{ props.authCashier?.name || 'Kasir' }}</strong></div>
            </div>
          </button>

          <!-- Dropdown Popover Menu (Modern POS Flat) -->
          <transition 
            enter-active-class="transition duration-150 ease-out" 
            enter-from-class="transform -translate-y-2 opacity-0 scale-95" 
            enter-to-class="transform translate-y-0 opacity-100 scale-100" 
            leave-active-class="transition duration-100 ease-in" 
            leave-from-class="transform translate-y-0 opacity-100 scale-100" 
            leave-to-class="transform -translate-y-2 opacity-0 scale-95"
          >
            <div 
              v-if="showNavMenu" 
              class="absolute left-0 top-full mt-2 w-64 bg-white rounded-2xl border border-slate-200 shadow-2xl p-2 z-[60] flex flex-col gap-0.5"
            >
              <!-- Info Header Popover -->
              <div class="px-3 py-2 border-b border-slate-100 mb-1">
                <div class="text-xs font-black text-slate-900 uppercase tracking-wider">{{ settingsForm.store_name || 'Rozak Mart' }}</div>
                <div class="text-[10px] text-slate-500 font-medium">Menu Sistem & Navigasi Kasir</div>
              </div>

              <!-- Item 1: Pengaturan Sistem & Toko -->
              <button 
                type="button" 
                @click="showNavMenu = false; openSettingsModal()" 
                class="w-full text-left px-3 py-2 rounded-xl text-xs text-slate-700 hover:bg-blue-50 hover:text-blue-700 font-bold flex items-center gap-2.5 transition cursor-pointer"
              >
                <span class="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xs">⚙️</span>
                <span>Pengaturan Sistem & Toko</span>
              </button>

              <!-- Item 2: Manajemen Pelanggan -->
              <button 
                type="button" 
                @click="showNavMenu = false; showCustomerModal = true" 
                class="w-full text-left px-3 py-2 rounded-xl text-xs text-slate-700 hover:bg-blue-50 hover:text-blue-700 font-bold flex items-center justify-between transition cursor-pointer"
              >
                <div class="flex items-center gap-2.5">
                  <span class="w-6 h-6 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs">👥</span>
                  <span>Manajemen Pelanggan</span>
                </div>
                <span class="text-[9px] bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded font-mono font-bold">F3</span>
              </button>

              <div class="h-px bg-slate-100 my-1"></div>

              <!-- Item 3: Layar Pembeli (Dual Display / CFD) - Buka Barcode URL -->
              <button 
                type="button" 
                @click="openQrUrlModal('display')" 
                class="w-full text-left px-3 py-2 rounded-xl text-xs text-slate-700 hover:bg-teal-50 hover:text-teal-700 font-bold flex items-center justify-between transition cursor-pointer"
              >
                <div class="flex items-center gap-2.5">
                  <span class="w-6 h-6 rounded-lg bg-teal-50 text-teal-600 flex items-center justify-center text-xs">🖥️</span>
                  <span>Layar Pembeli (Dual Display)</span>
                </div>
                <span class="text-[9px] bg-teal-100 text-teal-800 px-1.5 py-0.5 rounded font-mono font-bold">QR CFD</span>
              </button>

              <!-- Item 4: Input Barang HP (Mobile) - Buka Barcode URL -->
              <button 
                type="button" 
                @click="openQrUrlModal('mobile')" 
                class="w-full text-left px-3 py-2 rounded-xl text-xs text-slate-700 hover:bg-violet-50 hover:text-violet-700 font-bold flex items-center justify-between transition cursor-pointer"
              >
                <div class="flex items-center gap-2.5">
                  <span class="w-6 h-6 rounded-lg bg-violet-50 text-violet-600 flex items-center justify-center text-xs">📱</span>
                  <span>Input Barang HP (Mobile)</span>
                </div>
                <span class="text-[9px] bg-violet-100 text-violet-800 px-1.5 py-0.5 rounded font-mono font-bold">QR HP</span>
              </button>
            </div>
          </transition>
        </div>

        <div v-show="!isSearchActive" class="w-px h-6 bg-slate-200 shrink-0"></div>

        <!-- Tombol Scanner Kamera Terpisah di Sebelah Kiri Search (Touch Target Besar Tablet) -->
        <button 
          @click="triggerBarcodeScanner" 
          type="button"
          title="Buka Scanner Barcode Kamera (Sentuh untuk Memindai)" 
          class="shrink-0 h-10 px-3 rounded-xl bg-blue-600 hover:bg-blue-700 active:bg-blue-800 active:scale-95 text-white flex items-center justify-center gap-1.5 transition-all duration-150 cursor-pointer shadow-xs"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
          <span class="text-[11px] font-black tracking-wide uppercase">Scan</span>
        </button>

        <!-- Search Bar & Auto-Suggest Dropdown (MODERN POS FLAT) -->
        <div id="pos-search-container" class="relative flex-1 w-full transition-all duration-200">
          <!-- Tombol Tutup / Kembali saat Form Pencarian Aktif & Melebar Memenuhi Header -->
          <button 
            v-if="isSearchActive"
            @click="closeSearchDropdown" 
            type="button"
            title="Tutup / Batalkan Pencarian" 
            class="absolute left-1.5 top-1.5 bottom-1.5 w-8 rounded-lg bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 flex items-center justify-center transition cursor-pointer z-10"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
          </button>

          <input 
            id="pos-search-input"
            type="text"
            inputmode="search"
            v-model="searchQuery" 
            @input="onSearchInput"
            @focus="onSearchFocus"
            @keydown="handleSearchKeydown"
            @keydown.enter.prevent="handleSearchSubmit"
            placeholder="Scan barcode atau ketik nama produk..."
            autocomplete="off"
            :class="isSearchActive ? 'pl-11 pr-9' : 'pl-3.5 pr-9'"
            class="w-full bg-slate-100/90 focus:bg-white text-slate-800 text-xs font-semibold rounded-xl py-2.5 focus:outline-none transition-all duration-150 border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 placeholder-slate-400"
          />

          <!-- Tombol Clear (✕) di Bagian Kanan Form Search -->
          <button 
            v-if="searchQuery"
            @click="clearSearchInput" 
            type="button"
            title="Bersihkan pencarian"
            class="absolute right-2.5 top-1/2 -translate-y-1/2 w-5 h-5 rounded-full bg-slate-200 hover:bg-slate-300 text-slate-600 flex items-center justify-center text-[10px] font-bold transition cursor-pointer z-10"
          >
            ✕
          </button>

          <!-- Dropdown Hasil Pencarian Live & Auto-Suggest (MODERN POS FLAT) -->
          <div 
            v-if="isSearchDropdownOpen && (searchSuggestions.length > 0 || searchUnregisteredQuery)" 
            class="absolute left-0 right-0 top-full mt-1.5 bg-white rounded-xl shadow-xl border border-slate-200 overflow-hidden z-50 flex flex-col divide-y divide-slate-100"
          >
            <!-- Header Ringkas Info Hasil -->
            <div class="px-3.5 py-2 bg-slate-50 flex items-center justify-between text-[11px] text-slate-700 font-bold uppercase tracking-wider">
              <span>Hasil Pencarian ({{ searchSuggestions.length }})</span>
              <span class="text-[10px] text-slate-400 font-normal">Pilih item atau tekan Enter</span>
            </div>

            <!-- List Hasil Cocok (Tablet Viewport-Safe Height) -->
            <div v-if="searchSuggestions.length > 0" class="overflow-y-auto max-h-[220px] divide-y divide-slate-100">
              <div 
                v-for="(item, idx) in searchSuggestions" 
                :key="(item.id || item.barcode || '') + '-' + idx"
                @click="selectSuggestion(item)"
                @mouseenter="highlightedIndex = idx"
                :class="[
                  'px-3.5 py-2.5 flex items-center justify-between cursor-pointer transition select-none',
                  highlightedIndex === idx ? 'bg-blue-600 text-white' : 'hover:bg-slate-50 text-slate-800'
                ]"
              >
                <div class="flex items-center gap-2.5 min-w-0 pr-2">
                  <span class="text-lg p-1.5 bg-slate-100 rounded-lg shrink-0 border border-slate-200 text-slate-900">{{ item.icon || '📦' }}</span>
                  <div class="truncate">
                    <div class="text-xs font-bold truncate" :class="highlightedIndex === idx ? 'text-white' : 'text-slate-900'">{{ item.name }}</div>
                    <div class="flex items-center gap-2 text-[10px] font-mono mt-0.5" :class="highlightedIndex === idx ? 'text-blue-100' : 'text-slate-400'">
                      <span v-if="item.barcode">#{{ item.barcode }}</span>
                      <span v-if="item.barcode">•</span>
                      <span>{{ getProductCategoryName(item) }}</span>
                    </div>
                  </div>
                </div>

                <div class="text-right shrink-0">
                  <div class="text-xs font-mono font-black" :class="highlightedIndex === idx ? 'text-white' : 'text-slate-900'">
                    Rp {{ (item.units && item.units[0] ? item.units[0].price : (item.price || 0)).toLocaleString('id-ID') }}
                  </div>
                  <span 
                    :class="highlightedIndex === idx ? 'bg-white text-blue-700' : 'bg-blue-50 text-blue-700 border border-blue-200'"
                    class="text-[9px] px-1.5 py-0.5 rounded-md font-bold uppercase inline-block mt-0.5"
                  >
                    {{ item.units && item.units[0] ? item.units[0].name : (item.unit || 'Pcs') }}
                  </span>
                </div>
              </div>
            </div>

            <!-- State Jika Tidak Ditemukan / Tombol Daftarkan Barang Baru -->
            <div v-if="searchSuggestions.length === 0 && searchUnregisteredQuery" class="p-3.5 text-center bg-amber-50/70">
              <div class="text-xs font-bold text-slate-800 mb-0.5">
                Produk "{{ searchUnregisteredQuery }}" Belum Terdaftar
              </div>
              <p class="text-[10px] text-slate-500 mb-2.5">
                Barcode atau nama barang tidak ditemukan di katalog toko
              </p>
              <button 
                @click="quickRegisterFromSearch(searchUnregisteredQuery)"
                type="button"
                class="w-full bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold text-xs py-2 px-3 rounded-lg flex items-center justify-center gap-1.5 transition cursor-pointer shadow-xs"
              >
                <span>➕</span>
                <span>Daftarkan Produk Baru</span>
              </button>
            </div>
          </div>
        </div>

        <!-- Tombol Galeri Produk (Disembunyikan saat form pencarian aktif melebar) -->
        <button v-show="!isSearchActive" @click="showGalleryModal = true" class="shrink-0 flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs px-3.5 py-2 rounded-xl border border-slate-200 transition cursor-pointer uppercase">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
          <span>Galeri</span>
          <span class="text-[9px] bg-slate-200 text-slate-600 px-1 py-0.5 rounded font-mono font-bold">F2</span>
        </button>
      </div>

      <!-- Daftar Belanjaan (MODERN POS FLAT Table - Full Height) -->
      <div v-if="cart.length > 0" class="flex-1 overflow-y-auto divide-y divide-slate-100 p-1">
        <div 
          v-for="(item, index) in cart" 
          :key="item.barcode + '-' + item.unit" 
          class="flex items-center justify-between py-2.5 px-3 hover:bg-slate-50/80 transition rounded-xl bg-white"
        >
          <!-- Info Nama & Satuan -->
          <div class="flex items-center gap-2.5 flex-1 min-w-0 pr-2 cursor-pointer" @click="openItemEdit(index)">
            <span class="text-lg p-2 bg-slate-50 rounded-lg border border-slate-200 shrink-0">{{ item.icon || '📦' }}</span>
            <div class="truncate">
              <div class="text-xs font-bold text-slate-900 truncate leading-snug">{{ item.name }}</div>
              <div class="flex items-center gap-1.5 mt-0.5">
                <!-- Multi-Satuan Toggle Button: klik langsung berganti satuan -->
                <button 
                  v-if="item.units && item.units.length > 1"
                  type="button"
                  @click.stop="cycleItemUnit(index)"
                  class="bg-blue-600 hover:bg-blue-700 active:scale-95 text-white text-[10px] font-bold px-2 py-0.5 rounded-md flex items-center gap-1 transition"
                  title="Klik untuk ganti satuan (Multi-Satuan)"
                >
                  <span>{{ item.unit }}</span>
                  <span class="text-[8px] opacity-90">🔄</span>
                </button>
                <span v-else class="bg-slate-100 text-slate-700 border border-slate-200 text-[10px] font-bold px-1.5 py-0.5 rounded-md">
                  {{ item.unit }}
                </span>
                <span class="text-[10px] text-slate-500 font-mono">Rp {{ item.price.toLocaleString('id-ID') }}</span>
                <span v-if="item.discount > 0" class="text-[10px] text-amber-600 font-mono font-bold">(-{{ item.discount.toLocaleString('id-ID') }})</span>
              </div>
            </div>
          </div>

          <!-- Qty Adjuster & Subtotal Item (MODERN POS FLAT - TOUCH ERGONOMIC 1097x686) -->
          <div class="flex items-center gap-3 shrink-0">
            <!-- Counter Touch (Modern Micro-Rounded Stepper) -->
            <div class="flex items-center bg-slate-100 rounded-xl p-0.5 border border-slate-200">
              <button @click.stop="updateQty(index, -1)" class="w-8 h-8 rounded-lg bg-white text-slate-800 hover:bg-slate-200 active:scale-90 flex items-center justify-center font-bold text-base shadow-2xs transition cursor-pointer">
                -
              </button>
              <span @click.stop="openItemEdit(index)" class="w-9 text-center font-mono font-black text-xs text-slate-900 cursor-pointer">
                {{ item.qty }}
              </span>
              <button @click.stop="updateQty(index, 1)" class="w-8 h-8 rounded-lg bg-white text-slate-800 hover:bg-slate-200 active:scale-90 flex items-center justify-center font-bold text-base shadow-2xs transition cursor-pointer">
                +
              </button>
            </div>

            <!-- Total Per Baris -->
            <div class="text-right w-24">
              <div class="text-xs font-mono font-black text-slate-900">
                Rp {{ ((item.price * item.qty) - (item.discount || 0)).toLocaleString('id-ID') }}
              </div>
            </div>

            <!-- Tombol Hapus (Touch Target 32x32) -->
            <button @click.stop="removeCartItem(index)" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:text-rose-600 hover:bg-rose-50 active:scale-90 transition cursor-pointer font-bold text-sm">
              ✕
            </button>
          </div>
        </div>
      </div>

      <!-- State Keranjang Kosong (MODERN POS FLAT) -->
      <div v-else class="flex-1 flex flex-col items-center justify-center p-6 text-center text-slate-400">
        <div class="w-16 h-16 rounded-2xl bg-blue-50 text-blue-600 border border-blue-200 flex items-center justify-center text-3xl mb-3 shadow-xs">🛒</div>
        <p class="text-xs font-black text-slate-800 uppercase tracking-wide">Keranjang Belanja Kosong</p>
        <p class="text-[11px] text-slate-500 mt-0.5 mb-3.5">Scan barcode produk, buka galeri atau tambahkan barang baru</p>
        <div class="flex items-center gap-2">
          <!-- Tombol Utama: Scan Barcode Kamera -->
          <button 
            @click="triggerBarcodeScanner" 
            type="button"
            class="bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-bold text-xs px-4 py-2.5 rounded-xl flex items-center gap-1.5 transition cursor-pointer uppercase tracking-wider shadow-xs"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            <span>Scan Barcode</span>
          </button>
          <button @click="showGalleryModal = true" type="button" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs px-4 py-2.5 rounded-xl border border-slate-200 transition cursor-pointer uppercase tracking-wider">
            <span>📂</span> Buka Galeri
          </button>
          <button @click="openAddProductModal()" type="button" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs px-4 py-2.5 rounded-xl flex items-center gap-1.5 transition cursor-pointer uppercase tracking-wider shadow-xs">
            <span>➕</span> Tambah Barang
          </button>
        </div>
      </div>

      <!-- Footer Keranjang: Subtotal & Grand Total + Diskon Nota (MODERN POS DISPLAY) -->
      <div class="border-t border-slate-200 bg-slate-900 text-white shrink-0">
        <!-- Baris atas: info subtotal + tombol diskon nota -->
        <div class="px-4 py-2 flex items-center justify-between text-xs border-b border-slate-800">
          <div class="flex items-center gap-3 text-slate-300">
            <span>Subtotal: <strong class="text-white font-mono">Rp {{ subtotal.toLocaleString('id-ID') }}</strong></span>
            <span class="w-px h-3 bg-slate-700"></span>
            <span>Diskon Item: <strong class="text-amber-400 font-mono">Rp {{ itemDiscountTotal.toLocaleString('id-ID') }}</strong></span>
          </div>
          <!-- Tombol Diskon Nota -->
          <div class="flex items-center gap-2">
            <button 
              @click="openGlobalDiscountModal"
              class="flex items-center gap-1.5 bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border border-amber-500/30 font-bold text-[11px] px-3 py-1 rounded-lg transition cursor-pointer uppercase"
            >
              🏷️ Diskon Nota: <strong class="font-mono">Rp {{ globalDiscount.toLocaleString('id-ID') }}</strong>
            </button>
            <div v-if="globalDiscount > 0" class="flex items-center gap-1.5 bg-amber-400 text-slate-950 px-2 py-0.5 rounded-md text-[10px] font-bold">
              <span class="italic">{{ globalDiscountNote || 'Diskon' }}</span>
              <button @click="removeGlobalDiscount" class="font-black text-slate-950 leading-none">✕</button>
            </div>
          </div>
        </div>
        <!-- Baris bawah: Grand Total Display (Modern Digital POS Screen) -->
        <div class="px-5 py-3 flex items-center justify-between bg-black/60">
          <div>
            <div class="text-[10px] font-black text-emerald-400 uppercase tracking-widest font-mono">TOTAL TAGIHAN</div>
            <div class="text-[9px] text-slate-400 font-medium">{{ cart.length }} jenis barang ({{ totalQty }} pcs)</div>
          </div>
          <div class="flex items-baseline gap-1.5">
            <span class="text-sm font-bold text-emerald-500 font-mono">Rp</span>
            <span class="text-emerald-400 font-black text-3xl font-mono tracking-tight">
              {{ grandTotal.toLocaleString('id-ID') }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- ================================================================
         PANEL KANAN (35%): KASIR CHECKOUT (MODERN POS TILES)
         Header: Identitas Kasir + Jam
         Body:   Pelanggan, Total Belanjaan
         Footer: Tombol Bayar (Tiles Tunai, QRIS, Kasbon)
    ================================================================= -->
    <div class="w-[35%] bg-white flex flex-col border-l border-slate-200 overflow-hidden">
      
      <!-- Header Panel Kanan: Baris 1 (User Kasir + Jam Detik) & Baris 2 (Antrean + Riwayat) -->
      <div class="bg-white border-b border-slate-200 shrink-0 flex flex-col">
        <!-- Baris 1: Identitas User Kasir & Jam Realtime (dengan detik) -->
        <div class="px-4 py-2 flex items-center justify-between gap-2 border-b border-slate-100">
          <!-- Tombol User Kasir (Klik untuk Konfirmasi Log-Out) -->
          <button 
            type="button" 
            @click="showLogoutModal = true"
            title="Klik untuk keluar / log-out kasir"
            class="flex items-center gap-2.5 p-1 -ml-1 rounded-xl hover:bg-slate-100 active:bg-slate-200 transition cursor-pointer select-none text-left border border-transparent hover:border-slate-200 group min-w-0"
          >
            <div class="w-8 h-8 shrink-0 rounded-full bg-blue-600 group-hover:bg-blue-700 text-white font-black text-xs flex items-center justify-center shadow-xs transition">
              {{ (props.authCashier?.name || 'K').charAt(0).toUpperCase() }}
            </div>
            <div class="leading-tight pr-1 min-w-0">
              <div class="text-xs font-black text-slate-900 leading-none uppercase tracking-wide flex items-center gap-1 truncate">
                <span class="truncate">{{ props.authCashier?.name || 'Kasir' }}</span>
                <span class="text-[9px] text-slate-400 group-hover:text-blue-600 transition shrink-0">▼</span>
              </div>
              <div class="text-[9px] text-slate-500 mt-0.5 font-medium truncate">
                {{ props.authCashier?.shift || (props.authCashier?.is_admin ? '👑 Owner / Admin' : 'Shift Kasir Aktif') }}
              </div>
            </div>
          </button>

          <!-- Jam Realtime dengan Detik -->
          <span class="shrink-0 text-xs font-mono font-bold text-slate-700 bg-slate-100 px-3 py-1.5 rounded-lg border border-slate-200 tracking-wider">
            {{ currentTime }}
          </span>
        </div>

        <!-- Baris 2: Tombol Akses Cepat Antrean & Riwayat (Di bawah User & Jam) -->
        <div class="px-3 py-2 bg-slate-50/70 grid grid-cols-2 gap-2">
          <button 
            type="button"
            @click="showHoldModal = true" 
            title="Lihat Daftar Antrean Pesanan (F8)"
            class="flex items-center justify-between bg-amber-50 hover:bg-amber-100 active:scale-[0.99] border border-amber-200 text-amber-900 text-xs font-bold px-3 py-1.5 rounded-xl transition cursor-pointer uppercase shadow-2xs"
          >
            <span class="flex items-center gap-1.5">
              <svg class="w-3.5 h-3.5 text-amber-700" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
              <span>Antrean</span>
            </span>
            <span class="bg-amber-600 text-white text-[10px] font-black rounded-full px-1.5 h-4 min-w-[18px] flex items-center justify-center font-mono">
              {{ holdOrdersList.length }}
            </span>
          </button>

          <button 
            type="button" 
            @click="openReportsDrawer" 
            title="Buka Riwayat Nota, Rekap Laci Kasir & Buku Kasbon"
            class="flex items-center justify-between bg-blue-50 hover:bg-blue-100 active:scale-[0.99] border border-blue-200 text-blue-900 text-xs font-bold px-3 py-1.5 rounded-xl transition cursor-pointer uppercase shadow-2xs"
          >
            <span class="flex items-center gap-1.5">
              <span>📊</span>
              <span>Riwayat</span>
            </span>
            <span class="bg-blue-600 text-white text-[10px] font-black rounded-full px-1.5 h-4 min-w-[18px] flex items-center justify-center font-mono">
              {{ reportSummary.transaction_count || 0 }}
            </span>
          </button>
        </div>
      </div>

      <!-- ═══ MODE NORMAL (DEFAULT) ═══ -->
      <template v-if="rightMode === 'normal'">
        <!-- Body Panel Kanan: Pelanggan + Info Total (MODERN POS) -->
        <div class="flex-1 overflow-y-auto flex flex-col gap-2.5 p-3">
          <!-- Kartu Pelanggan Aktif (Modern POS Tile - Touch Friendly) -->
          <div @click="showCustomerModal = true" class="bg-slate-50 hover:bg-blue-50/50 active:scale-[0.99] rounded-xl p-3 border border-slate-200 flex items-center justify-between cursor-pointer transition select-none group">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-xl bg-blue-100 group-hover:bg-blue-200 text-blue-700 flex items-center justify-center font-bold text-sm transition">👤</div>
              <div class="flex flex-col">
                <span class="text-xs font-black text-slate-900 leading-tight uppercase tracking-wide group-hover:text-blue-700 transition">{{ selectedCustomer?.name || 'Pelanggan Umum' }}</span>
                <span class="text-[10px] text-slate-500 font-medium">{{ selectedCustomer?.id === 1 ? 'Non-Member / Cash' : `Member (${selectedCustomer?.phone || '-'})` }}</span>
              </div>
            </div>
            <button type="button" class="bg-white border border-slate-200 group-hover:bg-blue-600 group-hover:text-white text-blue-700 text-xs font-bold px-3 py-1.5 rounded-lg transition pointer-events-none uppercase shadow-2xs">
              Pilih
            </button>
          </div>

          <!-- Total Qty Info (Modern POS Bar) -->
          <div class="bg-slate-50 rounded-xl px-3.5 py-2.5 border border-slate-200 flex justify-between items-center">
            <span class="text-xs text-slate-600 font-bold uppercase tracking-wide">Total Belanjaan</span>
            <span class="text-xs font-black text-slate-900 font-mono">{{ totalQty }} pcs</span>
          </div>

          <!-- Blok Layanan Toko & Kasir (Disabled saat keranjang terisi) -->
          <div class="pt-1 flex flex-col gap-2">
            <div class="flex items-center justify-between px-0.5">
              <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Layanan Toko & Kasir</span>
              <span v-if="cart.length > 0" class="text-[9px] text-amber-600 font-bold">Aktif saat keranjang kosong</span>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <!-- Tile 1: Tarik Tunai QRIS -->
              <button 
                type="button" 
                @click="openWithdrawalModal"
                :disabled="cart.length > 0"
                title="Layanan Tarik Tunai QRIS (Shortcut: F6)"
                class="bg-indigo-50/80 hover:bg-indigo-100 active:scale-[0.98] border border-indigo-200 rounded-xl p-3 text-left flex flex-col justify-between gap-2 transition cursor-pointer group shadow-2xs disabled:opacity-40 disabled:cursor-not-allowed disabled:grayscale disabled:pointer-events-none"
              >
                <div class="flex items-center justify-between w-full">
                  <span class="w-8 h-8 rounded-lg bg-indigo-600 text-white flex items-center justify-center text-base shadow-xs">🏧</span>
                  <span class="text-[9px] bg-indigo-200/70 text-indigo-900 px-1.5 py-0.5 rounded font-mono font-black">F6</span>
                </div>
                <div>
                  <div class="text-xs font-black text-indigo-950 uppercase tracking-wide group-hover:text-indigo-700 leading-tight">Tarik Tunai</div>
                  <div class="text-[10px] text-indigo-700/90 font-medium leading-tight mt-0.5">Cash Out QRIS</div>
                </div>
              </button>

              <!-- Tile 2: Top-Up & Jasa Saldo (Samping Tarik Tunai) -->
              <button 
                type="button" 
                @click="openTopUpModal"
                :disabled="cart.length > 0"
                title="Layanan Top-Up e-Wallet, Transfer Bank & Pulsa (Shortcut: F10)"
                class="bg-teal-50/80 hover:bg-teal-100 active:scale-[0.98] border border-teal-200 rounded-xl p-3 text-left flex flex-col justify-between gap-2 transition cursor-pointer group shadow-2xs disabled:opacity-40 disabled:cursor-not-allowed disabled:grayscale disabled:pointer-events-none"
              >
                <div class="flex items-center justify-between w-full">
                  <span class="w-8 h-8 rounded-lg bg-teal-600 text-white flex items-center justify-center text-base shadow-xs">⚡</span>
                  <span class="text-[9px] bg-teal-200/70 text-teal-900 px-1.5 py-0.5 rounded font-mono font-black">F10</span>
                </div>
                <div>
                  <div class="text-xs font-black text-teal-950 uppercase tracking-wide group-hover:text-teal-700 leading-tight">Top-Up & PPOB</div>
                  <div class="text-[10px] text-teal-700/90 font-medium leading-tight mt-0.5">Saldo, Bank & Pulsa</div>
                </div>
              </button>
            </div>
          </div>
        </div>

        <!-- Footer Panel Kanan: Tombol Pembayaran (MODERN POS TILES) -->
        <div class="border-t border-slate-200 shrink-0 bg-slate-50/50">
          <!-- Aksi Sekunder: Hold & Batalkan (Disabled jika keranjang kosong) -->
          <div class="px-3 pt-2.5 pb-1.5 grid grid-cols-2 gap-2">
            <button 
              @click="holdCurrentTransaction" 
              :disabled="cart.length === 0"
              class="bg-amber-500 hover:bg-amber-600 active:bg-amber-700 text-slate-950 text-xs font-bold py-2.5 px-3 rounded-xl flex items-center justify-center gap-1.5 transition cursor-pointer uppercase tracking-wider shadow-2xs disabled:opacity-40 disabled:cursor-not-allowed disabled:grayscale disabled:pointer-events-none"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>
              <span>Hold Order</span>
              <span class="text-[9px] bg-amber-600/30 text-slate-900 px-1 py-0.5 rounded font-mono font-black">F9</span>
            </button>
            <button 
              @click="clearCart" 
              :disabled="cart.length === 0"
              class="bg-rose-50 hover:bg-rose-100 active:bg-rose-200 text-rose-700 border border-rose-200 text-xs font-bold py-2.5 px-3 rounded-xl flex items-center justify-center gap-1.5 transition cursor-pointer uppercase tracking-wider disabled:opacity-40 disabled:cursor-not-allowed disabled:grayscale disabled:pointer-events-none"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
              <span>Batalkan</span>
              <span class="text-[9px] bg-rose-200/60 text-rose-800 px-1 py-0.5 rounded font-mono font-bold">Esc</span>
            </button>
          </div>

          <!-- Tombol Bayar Utama (Disabled jika keranjang kosong) -->
          <div class="px-3 pb-3 flex flex-col gap-2">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Metode Pembayaran</span>

            <!-- Bayar Tunai (Modern Vibrant Emerald Tile) -->
            <button 
              @click="openCashInline" 
              :disabled="cart.length === 0"
              class="bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white font-bold py-3.5 px-4 rounded-xl flex items-center justify-between text-xs tracking-wider transition cursor-pointer shadow-xs uppercase disabled:opacity-40 disabled:cursor-not-allowed disabled:grayscale disabled:pointer-events-none"
            >
              <span class="flex items-center gap-2 text-sm font-black">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                BAYAR TUNAI (CASH)
              </span>
              <span class="bg-emerald-800/80 px-2 py-1 rounded-lg text-[10px] font-black font-mono tracking-widest flex items-center gap-1.5">
                <span>UANG PAS</span>
                <span class="bg-emerald-950/70 text-emerald-200 px-1 rounded text-[9px] font-mono">F4</span>
              </span>
            </button>

            <div class="grid grid-cols-2 gap-2">
              <!-- QRIS (Modern Blue Tile) -->
              <button 
                @click="payQris" 
                :disabled="cart.length === 0"
                class="bg-blue-600 hover:bg-blue-500 active:bg-blue-700 text-white font-bold py-3 px-3 rounded-xl flex items-center justify-between text-xs transition cursor-pointer shadow-xs uppercase tracking-wider disabled:opacity-40 disabled:cursor-not-allowed disabled:grayscale disabled:pointer-events-none"
              >
                <div class="flex items-center gap-1.5">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                  <span>QRIS</span>
                </div>
                <span class="bg-blue-800/70 text-blue-100 px-1 py-0.5 rounded text-[9px] font-mono font-bold">F7</span>
              </button>
              <!-- Kasbon (Modern Amber Tile) -->
              <button 
                @click="payKasbon" 
                :disabled="cart.length === 0"
                class="bg-amber-600 hover:bg-amber-500 active:bg-amber-700 text-white font-bold py-3 px-3 rounded-xl flex items-center justify-center gap-1.5 text-xs transition cursor-pointer shadow-xs uppercase tracking-wider disabled:opacity-40 disabled:cursor-not-allowed disabled:grayscale disabled:pointer-events-none"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                <span>Kasbon Toko</span>
              </button>
            </div>
          </div>
        </div>
      </template>

      <!-- ═══ MODE TUNAI INLINE (MODERN POS CASH CALCULATOR) ═══ -->
      <template v-else-if="rightMode === 'cash'">
        <div class="flex-1 flex flex-col overflow-hidden bg-slate-50">
          <!-- Sub-header mode tunai (MODERN POS) -->
          <div class="bg-emerald-600 px-4 py-2.5 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-2 text-white">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
              <span class="font-black text-sm tracking-wider uppercase">BAYAR TUNAI (CASH)</span>
            </div>
            <button @click="closeCashInline" class="bg-emerald-700/80 hover:bg-emerald-800 text-white text-[10px] font-bold px-3 py-1.5 rounded-lg flex items-center gap-1 transition cursor-pointer uppercase">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
              Kembali
            </button>
          </div>

          <!-- Total + Input uang (MODERN POS) -->
          <div class="px-3 pt-3 pb-2 flex flex-col gap-2 shrink-0">
            <!-- Total Tagihan -->
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-3.5 py-2.5 flex justify-between items-center">
              <span class="text-xs font-black text-emerald-900 uppercase">Total Tagihan</span>
              <span class="font-mono font-black text-emerald-700 text-xl">Rp {{ grandTotal.toLocaleString('id-ID') }}</span>
            </div>
            <!-- Input uang diterima -->
            <div>
              <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block mb-1">Uang Diterima (Rp)</label>
              <div class="bg-white border-2 border-slate-300 focus-within:border-emerald-600 rounded-xl px-3.5 py-2 transition shadow-2xs">
                <input 
                  type="text" 
                  :value="inlineCashGiven" 
                  readonly 
                  placeholder="0"
                  class="w-full bg-transparent border-0 p-0 text-right text-2xl font-mono font-black text-slate-900 focus:outline-none focus:ring-0 tracking-tight"
                />
              </div>
            </div>
            <!-- Kembalian -->
            <div class="bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 flex justify-between items-center">
              <span class="text-xs font-bold text-slate-600 uppercase tracking-wide">Kembalian</span>
              <span class="font-mono font-black text-xl text-amber-600">Rp {{ inlineCashChange.toLocaleString('id-ID') }}</span>
            </div>
          </div>

          <!-- Chip pecahan uang berbasis cache (Uang Pas + 2rb+, 5rb+, dst + Tambah/Edit/Hapus) -->
          <div class="px-3 pb-2 shrink-0 flex flex-col gap-1.5">
            <div class="flex flex-wrap items-center gap-1.5 font-mono text-[10px]">
              <button @click="inlineCashShortcut('exact')" class="px-2.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg transition cursor-pointer uppercase shadow-2xs shrink-0">Uang Pas</button>

              <template v-if="!isEditingCashPresets">
                <button
                  v-for="(amt, idx) in (numpadPresets.cash || [])"
                  :key="'cash-' + idx + '-' + amt"
                  type="button"
                  @click="inlineCashShortcut(amt)"
                  class="flex-1 min-w-[54px] bg-white hover:bg-slate-100 border border-slate-200 text-slate-800 font-bold py-2 px-1.5 rounded-lg transition cursor-pointer shadow-2xs text-center"
                >
                  {{ formatPresetChip(amt, 'cash') }}
                </button>
              </template>
              <template v-else>
                <div
                  v-for="(amt, idx) in (numpadPresets.cash || [])"
                  :key="'edit-cash-' + idx + '-' + amt"
                  class="inline-flex items-center bg-amber-50 border border-amber-300 rounded-lg overflow-hidden shadow-2xs"
                >
                  <button
                    type="button"
                    @click="openEditCashPreset(idx, amt)"
                    class="px-2 py-1.5 text-[10px] font-bold text-amber-900 hover:bg-amber-100 cursor-pointer"
                    title="Ubah nominal pecahan ini"
                  >
                    {{ formatPresetChip(amt, 'cash') }}
                  </button>
                  <button
                    type="button"
                    @click="deleteCashPresetAt(idx)"
                    class="px-1.5 py-1.5 text-[10px] font-black text-rose-600 hover:bg-rose-100 border-l border-amber-200 cursor-pointer"
                    title="Hapus pecahan ini"
                  >
                    ✕
                  </button>
                </div>
              </template>

              <button
                type="button"
                @click="openAddCashPreset"
                class="px-2.5 py-2 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 font-black text-xs transition cursor-pointer shrink-0 shadow-2xs"
                title="Tambah nominal uang cepat (tersimpan di cache)"
              >
                +
              </button>
              <button
                type="button"
                @click="isEditingCashPresets = !isEditingCashPresets; if (!isEditingCashPresets) showCashPresetEditor = false"
                :class="isEditingCashPresets ? 'bg-amber-500 text-white border-amber-600' : 'bg-slate-100 hover:bg-slate-200 text-slate-600 border-slate-200'"
                class="px-2 py-2 rounded-lg border font-bold text-[10px] transition cursor-pointer shrink-0 shadow-2xs"
                :title="isEditingCashPresets ? 'Selesai atur pecahan' : 'Edit / Hapus pecahan uang'"
              >
                {{ isEditingCashPresets ? '✓' : '✏️' }}
              </button>
            </div>

            <!-- Form Inline Tambah / Edit Pecahan Tunai Cache -->
            <div v-if="showCashPresetEditor" class="p-2 bg-white border border-blue-200 rounded-xl flex items-center gap-1.5 shadow-xs">
              <div class="flex-1">
                <RupiahInput
                  v-model="cashPresetInputAmount"
                  :allow-empty="true"
                  prefix="Rp"
                  placeholder="Nominal pecahan..."
                  input-class="w-full bg-slate-50 border border-slate-200 rounded-lg pl-8 pr-2 py-1 text-xs font-mono font-bold text-slate-900 focus:outline-none focus:border-blue-500"
                />
              </div>
              <button type="button" @click="saveCashPresetAmount" class="px-2.5 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-[10px] font-bold cursor-pointer">
                {{ editingCashPresetIndex !== null ? 'Ubah' : 'Simpan' }}
              </button>
              <button type="button" @click="showCashPresetEditor = false; editingCashPresetIndex = null" class="px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg text-[10px] font-bold cursor-pointer">
                ✕
              </button>
            </div>

            <div v-if="isEditingCashPresets" class="flex items-center justify-between text-[9px] text-amber-800 px-1">
              <span>Tap nominal untuk edit, tap ✕ untuk hapus</span>
              <button type="button" @click="resetDefaultCashPresets" class="underline font-bold hover:text-rose-700 cursor-pointer">Reset Default</button>
            </div>
          </div>

          <!-- Numpad Industrial Grid (MODERN POS) -->
          <div class="px-3 pb-2 flex flex-col gap-1.5 font-mono flex-1">
            <div class="grid grid-cols-3 gap-1.5 flex-1">
              <button v-for="n in ['1','2','3','4','5','6','7','8','9']" :key="n" @click="inlineCashNumpad(n)" class="bg-white hover:bg-slate-100 active:bg-slate-200 border border-slate-200 rounded-xl text-xl font-bold text-slate-800 transition cursor-pointer shadow-2xs">
                {{ n }}
              </button>
            </div>
            <div class="grid grid-cols-4 gap-1.5">
              <button @click="inlineCashNumpad('C')"   class="bg-rose-50 hover:bg-rose-100 active:bg-rose-200 text-rose-700 border border-rose-200 rounded-xl py-2.5 text-base font-bold transition cursor-pointer">C</button>
              <button @click="inlineCashNumpad('0')"   class="bg-white hover:bg-slate-100 active:bg-slate-200 border border-slate-200 rounded-xl py-2.5 text-xl font-bold text-slate-800 transition cursor-pointer shadow-2xs">0</button>
              <button @click="inlineCashNumpad('000')" class="bg-white hover:bg-slate-100 active:bg-slate-200 border border-slate-200 rounded-xl py-2.5 text-sm font-bold text-slate-700 transition cursor-pointer shadow-2xs">000</button>
              <button @click="inlineCashNumpad('DEL')" class="bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 border border-slate-200 rounded-xl py-2.5 text-base font-bold transition cursor-pointer shadow-2xs" title="Hapus 1 digit">⌫</button>
            </div>
          </div>

          <!-- Tombol Konfirmasi Pembayaran (MODERN POS Dual Tiles) -->
          <div class="px-3 pb-3 shrink-0 grid grid-cols-2 gap-2">
            <button @click="confirmInlineCash(false)" class="bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold py-3.5 px-2 text-xs flex items-center justify-center gap-1.5 transition rounded-xl cursor-pointer shadow-xs uppercase tracking-wider">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              SELESAI (CEPAT)
            </button>
            <button @click="confirmInlineCash(true)" class="bg-emerald-800 hover:bg-emerald-900 active:bg-black text-white font-bold py-3.5 px-2 text-xs flex items-center justify-center gap-1.5 transition rounded-xl cursor-pointer shadow-xs uppercase tracking-wider">
              <span>🖨️</span>
              SELESAI & STRUK
            </button>
          </div>
        </div>
      </template>

    </div>

    <!-- ================================================================
         ANDROID SIDE SHEETS & MODALS (PERSIS SEPERTI MOCKUP)
    ================================================================= -->

    <!-- 1. ANDROID SIDE SHEET 1: GALERI PRODUK (MODERN POS DOCK DI PANEL KIRI 65%) -->
    <div v-if="showGalleryModal" class="fixed inset-0 z-50 flex items-stretch justify-start">
      <div class="absolute inset-0 bg-black/40 backdrop-blur-2xs" @click="showGalleryModal = false"></div>
      <div class="relative w-[65%] h-full bg-white border-r border-slate-200 shadow-2xl flex flex-col overflow-hidden z-10">
        <ProductGalleryMockup
          :products="localProducts"
          :categories="localCategories"
          :active-cart-items="cart"
          :can-edit="Boolean(props.authCashier?.is_admin)"
          initial-style="standard"
          @add-to-cart="(prod, unitIdx) => addToCart(prod, unitIdx)"
          @edit-product="(prod) => openEditProductModal(prod)"
          @add-product="(prefill) => openAddProductModal(prefill)"
          @add-category="openQuickAddCategory"
          @close="showGalleryModal = false"
        />
      </div>
    </div>

    <!-- 2. ANDROID SIDE SHEET 2: PILIH PELANGGAN (MODERN POS DOCK DI PANEL KANAN 35%) -->
    <div v-if="showCustomerModal" class="fixed inset-0 z-50 flex items-stretch justify-end">
      <div class="absolute inset-0 bg-black/40 backdrop-blur-2xs" @click="showCustomerModal = false"></div>
      <div class="relative w-[35%] h-full bg-white border-l border-slate-200 shadow-2xl flex flex-col overflow-hidden z-10">
        <!-- Header (MODERN POS) -->
        <div class="bg-white border-b border-slate-200 px-4 py-2.5 flex justify-between items-center shrink-0">
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-xl bg-blue-600 text-white flex items-center justify-center font-bold text-xs shadow-xs">👥</div>
            <div>
              <h3 class="font-black text-xs text-slate-900 leading-tight uppercase tracking-wide">Pilih Pelanggan / Member</h3>
              <p class="text-[10px] text-slate-500 mt-0.5">Pilih pembeli untuk kasbon & riwayat</p>
            </div>
          </div>
          <div class="flex items-center gap-1.5">
            <button 
              @click="openAddCustomerModal" 
              type="button"
              class="bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-[11px] font-bold px-3 py-1.5 rounded-lg flex items-center gap-1 transition cursor-pointer uppercase shadow-xs"
            >
              <span>➕</span>
              <span>Baru</span>
            </button>
            <button @click="showCustomerModal = false" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
          </div>
        </div>

        <!-- Search (MODERN POS) -->
        <div class="p-3 border-b border-slate-200 bg-slate-50/70 shrink-0">
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none">🔍</span>
            <input 
              type="text" 
              v-model="customerSearchQuery" 
              placeholder="Cari nama atau nomor HP..." 
              class="w-full bg-white border border-slate-200 rounded-xl pl-8 pr-3 py-1.5 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-blue-600 transition font-medium"
            />
          </div>
        </div>

        <!-- List Pelanggan (MODERN POS Table) -->
        <div class="flex flex-col gap-1.5 p-3 overflow-y-auto flex-1 divide-y divide-slate-100">
          <!-- Pilihan Cepat: Pelanggan Umum (Default Cash) -->
          <div 
            @click="selectCustomer(getDefaultCustomer())"
            :class="(selectedCustomer?.id === 1 || selectedCustomer?.name?.toLowerCase()?.includes('umum')) ? 'border-2 border-blue-600 bg-blue-50/60' : 'border border-slate-200 bg-white hover:bg-slate-50'"
            class="p-2.5 rounded-xl flex items-center justify-between transition cursor-pointer mb-1 shadow-2xs"
          >
            <div class="flex items-center gap-2.5">
              <div class="w-8 h-8 rounded-lg bg-blue-600 text-white flex items-center justify-center font-bold text-xs">
                🛒
              </div>
              <div>
                <div class="font-black text-xs text-slate-900 uppercase">Pelanggan Umum</div>
                <div class="text-[10px] text-slate-500 mt-0.5">Non-Member · Transaksi Tunai Langsung</div>
              </div>
            </div>
            <span v-if="selectedCustomer?.id === 1 || selectedCustomer?.name?.toLowerCase()?.includes('umum')" class="text-[10px] bg-blue-600 text-white font-bold px-2.5 py-1 rounded-lg uppercase shadow-2xs">
              ✓ Aktif
            </span>
            <span v-else class="text-[10px] text-blue-700 font-bold bg-blue-50 px-2.5 py-1 rounded-lg border border-blue-200 uppercase">
              Pilih
            </span>
          </div>

          <!-- List Member Terdaftar Lainnya (QubePOS) -->
          <div 
            v-for="c in filteredCustomers.filter(c => c.id !== 1 && !c.name.toLowerCase().includes('umum'))" 
            :key="c.id" 
            @click="selectCustomer(c)"
            class="pt-2 flex items-center justify-between p-2.5 rounded-xl hover:bg-blue-50/50 cursor-pointer transition border border-transparent hover:border-blue-200 group"
          >
            <div class="flex items-center gap-2.5 min-w-0 pr-2">
              <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center font-bold text-slate-700 text-xs shrink-0 border border-slate-200">
                {{ c.name.charAt(0) }}
              </div>
              <div class="min-w-0">
                <div class="font-bold text-xs text-slate-900 truncate">{{ c.name }}</div>
                <div class="text-[10px] text-slate-500 font-mono">{{ c.phone || '-' }}</div>
              </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <div class="text-right">
                <span class="text-[9px] text-slate-400 block font-bold uppercase">Kasbon:</span>
                <span class="text-xs font-mono font-bold" :class="c.kasbon_balance > 0 ? 'text-rose-600' : 'text-slate-400'">
                  Rp {{ (c.kasbon_balance || 0).toLocaleString('id-ID') }}
                </span>
              </div>
              <button 
                type="button"
                @click.stop="openEditCustomerModal(c)"
                title="Edit Data Pelanggan / Member"
                class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-amber-100 hover:text-amber-800 text-slate-600 border border-slate-200 flex items-center justify-center text-xs transition cursor-pointer"
              >
                ✏️
              </button>
            </div>
          </div>
        </div>

        <!-- Footer Pelanggan (QubePOS) -->
        <div class="p-3 border-t border-slate-200 bg-slate-50 shrink-0 flex justify-between items-center">
          <span class="text-[10px] text-slate-400 font-medium">Panel keranjang kiri tetap aktif</span>
          <button @click="showCustomerModal = false" class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs px-4 py-1.5 rounded-lg font-bold transition cursor-pointer uppercase">Tutup</button>
        </div>
      </div>
    </div>

    <!-- 2b. ANDROID DIALOG: TAMBAH / EDIT PELANGGAN & MEMBER (QubePOS) -->
    <div v-if="showAddCustomerModal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-[480px] max-w-full border border-slate-200 overflow-hidden flex flex-col shadow-2xl">
        <!-- Header -->
        <div class="bg-white px-5 py-3.5 border-b border-slate-200 flex justify-between items-center">
          <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-xl flex items-center justify-center text-sm font-bold shadow-xs" :class="editingCustomer ? 'bg-amber-500 text-white' : 'bg-blue-600 text-white'">
              {{ editingCustomer ? '✏️' : '👤' }}
            </div>
            <div>
              <h3 class="font-black text-sm text-slate-900 leading-tight uppercase tracking-wide">
                {{ editingCustomer ? 'Edit Data Pelanggan' : 'Tambah Pelanggan Baru' }}
              </h3>
              <p class="text-[11px] text-slate-500">
                {{ editingCustomer ? 'Perbarui informasi kontak & limit kasbon' : 'Daftarkan pembeli / pelanggan tetap toko' }}
              </p>
            </div>
          </div>
          <button @click="showAddCustomerModal = false" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center text-xs font-bold transition cursor-pointer">
            ✕
          </button>
        </div>

        <!-- Form Body -->
        <div class="p-5 flex flex-col gap-3">
          <!-- Nama Pelanggan -->
          <div>
            <label class="text-[10px] font-bold text-slate-500 uppercase tracking-wider block mb-1">
              Nama Lengkap Pelanggan: <span class="text-rose-500">*</span>
            </label>
            <input 
              type="text" 
              v-model="customerForm.name" 
              placeholder="Contoh: Bu Hj. Maryam, Pak RT, dsb."
              class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-900 font-bold focus:outline-none focus:bg-white focus:border-blue-600 transition"
              @keydown.enter.prevent="saveCustomer"
            />
          </div>

          <!-- Nomor WhatsApp / HP -->
          <div>
            <label class="text-[10px] font-bold text-slate-500 uppercase tracking-wider block mb-1">
              Nomor WhatsApp / HP:
            </label>
            <input 
              type="tel" 
              inputmode="tel"
              v-model="customerForm.phone" 
              placeholder="Contoh: 081234567890 (bisa dikosongkan)"
              class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:bg-white focus:border-blue-600 transition"
            />
          </div>

          <!-- Alamat / Keterangan Rumah -->
          <div>
            <label class="text-[10px] font-bold text-slate-500 uppercase tracking-wider block mb-1">
              Alamat / Catatan Lokasi:
            </label>
            <input 
              type="text" 
              v-model="customerForm.address" 
              placeholder="Contoh: RT 03 RW 02 (Depan masjid)"
              class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-900 focus:outline-none focus:bg-white focus:border-blue-600 transition"
            />
          </div>

          <!-- Limit Kasbon -->
          <div>
            <label class="text-[10px] font-bold text-slate-500 uppercase tracking-wider block mb-1">
              Batas Maksimal Kasbon (Limit Hutang):
            </label>
            <RupiahInput
              v-model="customerForm.credit_limit"
              prefix="Rp"
              placeholder="500.000"
              input-class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-9 pr-3 py-2 text-xs text-slate-900 font-mono font-bold focus:outline-none focus:bg-white focus:border-blue-600 transition"
            />
            <div class="flex flex-wrap gap-1 mt-1.5">
              <button v-for="lim in [200000, 500000, 1000000, 2000000]" :key="lim" type="button" @click="customerForm.credit_limit = lim" class="px-2 py-0.5 rounded bg-slate-100 hover:bg-blue-50 text-slate-700 border border-slate-200 text-[10px] font-mono font-bold cursor-pointer">
                Rp {{ (lim / 1000).toLocaleString('id-ID') }}rb
              </button>
            </div>
            <span class="text-[10px] text-slate-400 mt-1 block">Pelanggan tidak dapat kasbon jika total hutang melebihi limit ini.</span>
          </div>
        </div>

        <!-- Footer -->
        <div class="bg-slate-50 border-t border-slate-200 px-5 py-3 flex items-center justify-between">
          <div>
            <button 
              v-if="editingCustomer && editingCustomer.id !== 1" 
              type="button" 
              @click="deleteCustomer(editingCustomer.id)" 
              class="text-rose-600 hover:text-white hover:bg-rose-600 border border-rose-200 text-xs font-bold px-3.5 py-2 rounded-xl transition flex items-center gap-1 cursor-pointer uppercase"
            >
              <span>🗑️</span> Hapus
            </button>
          </div>
          <div class="flex items-center gap-2">
            <button 
              type="button" 
              @click="showAddCustomerModal = false" 
              class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold px-4 py-2 rounded-xl transition cursor-pointer uppercase"
            >
              Batal
            </button>
            <button 
              type="button" 
              @click="saveCustomer" 
              class="bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-xs font-bold px-5 py-2 rounded-xl transition flex items-center gap-1.5 cursor-pointer uppercase shadow-xs"
            >
              <span>💾</span>
              <span>{{ editingCustomer ? 'Simpan Perubahan' : 'Daftarkan Pelanggan' }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- 3. ANDROID DIALOG: TAMBAH PRODUK BARU (DUAL-CARD NO-SCROLL LAYOUT 1097x686) -->
    <div v-if="showAddProductModal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-3">
      <div class="bg-white rounded-2xl w-[880px] max-w-[96vw] min-h-[500px] max-h-[92vh] flex shadow-2xl overflow-hidden border border-slate-200">
        
        <!-- ================= KOLOM KIRI: FORMULIR PRODUK TANPA SCROLL (58%) ================= -->
        <div class="w-[58%] border-r border-slate-200 p-4 flex flex-col justify-between overflow-y-auto">
          <div>
            <!-- Formulir Fields Padat (Tanpa Header & Tanpa Scroll) -->
            <div class="flex flex-col gap-2">
              <!-- Baris 1: Nama Produk + Barcode -->
              <div class="grid grid-cols-12 gap-2">
                <div class="col-span-7">
                  <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-0.5">Nama Barang / Produk:</label>
                  <input 
                    type="text" 
                    v-model="newProd.name" 
                    placeholder="Contoh: Kecap Bango 550ml..."
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-900 font-bold focus:outline-none focus:border-emerald-600 focus:bg-white focus:ring-2 focus:ring-emerald-100 transition"
                  />
                </div>
                <div class="col-span-5">
                  <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-0.5">Barcode:</label>
                  <div class="relative">
                    <input 
                      type="text" 
                      v-model="newProd.barcode" 
                      placeholder="Scan / ketik barcode..."
                      :class="[
                        'w-full rounded-xl pl-2.5 py-2 text-xs font-mono focus:outline-none transition border',
                        newProd.barcode ? 'pr-16' : 'pr-10',
                        duplicateProductWarning 
                          ? 'bg-rose-50 border-rose-500 text-rose-950 font-black' 
                          : 'bg-[#f1f3f4] border-slate-300 text-slate-900 focus:border-emerald-600 focus:bg-white'
                      ]"
                    />
                    <div class="absolute right-1.5 top-1/2 -translate-y-1/2 flex items-center gap-1">
                      <button v-if="newProd.barcode" type="button" @click="newProd.barcode = ''" class="w-5 h-5 rounded-full bg-slate-200/80 hover:bg-slate-300 text-slate-600 flex items-center justify-center text-[10px] font-bold cursor-pointer transition" title="Hapus barcode">
                        ✕
                      </button>
                      <button type="button" @click="scanBarcodeForNewProduct" class="w-8 h-7 rounded-lg bg-emerald-50 hover:bg-emerald-100 active:scale-95 text-emerald-700 border border-emerald-200 flex items-center justify-center transition cursor-pointer" title="Scan Barcode via Kamera">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Notifikasi Barcode Duplikat Sesaat Setelah Terisi -->
              <div 
                v-if="duplicateProductWarning" 
                class="p-2.5 bg-rose-50 border border-rose-200 rounded-xl flex items-start gap-2 text-rose-950 shadow-2xs"
              >
                <div class="w-6 h-6 rounded-lg bg-rose-200 text-rose-800 flex items-center justify-center text-xs font-bold shrink-0">
                  ⚠️
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-[10px] font-black text-rose-700 flex items-center justify-between">
                    <span>BARCODE SUDAH TERDAFTAR: {{ duplicateProductWarning.name }}</span>
                    <span class="text-[9px] bg-rose-200 text-rose-900 font-bold px-1.5 py-0.5 rounded uppercase">Duplikat</span>
                  </div>
                  <div class="text-[10px] text-slate-600 flex items-center gap-2 mt-0.5">
                    <span>Harga: <strong class="text-emerald-700">Rp {{ (duplicateProductWarning.units?.[0]?.price || duplicateProductWarning.price || 0).toLocaleString('id-ID') }}</strong></span>
                    <span>•</span>
                    <span>Kategori: <strong>{{ getProductCategoryName(duplicateProductWarning) }}</strong></span>
                  </div>
                  <div class="mt-1.5 flex items-center gap-2">
                    <button 
                      v-if="props.authCashier?.is_admin"
                      type="button" 
                      @click="openEditProductModal(duplicateProductWarning)" 
                      class="bg-amber-500 hover:bg-amber-600 active:bg-amber-700 text-white font-bold text-[10px] px-2.5 py-1 rounded-lg flex items-center gap-1 cursor-pointer transition uppercase shadow-xs"
                    >
                      <span>✏️</span> Buka &amp; Edit Produk Tersebut
                    </button>
                    <button 
                      type="button" 
                      @click="newProd.barcode = ''" 
                      class="bg-white hover:bg-rose-50 text-rose-700 border border-rose-200 font-bold text-[10px] px-2.5 py-1 rounded-lg cursor-pointer transition uppercase"
                    >
                      Ganti Barcode
                    </button>
                  </div>
                </div>
              </div>

              <!-- Baris 2: Kategori Produk & Satuan Dasar (1 Baris Rapi Tanpa Scroll) -->
              <div class="grid grid-cols-12 gap-2">
                <div class="col-span-7">
                  <div class="flex justify-between items-center mb-0.5">
                    <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Kategori Produk:</label>
                    <button type="button" @click="openQuickAddCategory" class="text-[9px] text-blue-600 font-bold hover:underline flex items-center gap-0.5 uppercase cursor-pointer">
                      <span>➕</span> Kategori Baru
                    </button>
                  </div>
                  <select v-model="newProd.category" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-2 text-xs text-slate-800 font-bold focus:outline-none focus:border-emerald-600 focus:bg-white uppercase">
                    <option v-for="cat in localCategories" :key="cat.id || cat.name" :value="cat.name">
                      {{ cat.icon || '📦' }} {{ cat.name }}
                    </option>
                  </select>
                </div>
                <div class="col-span-5">
                  <div class="flex justify-between items-center mb-0.5">
                    <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Satuan Dasar:</label>
                    <button type="button" @click="openQuickAddUnit('base')" class="text-[9px] text-blue-600 font-bold hover:underline flex items-center gap-0.5 uppercase cursor-pointer">
                      <span>➕</span> Satuan
                    </button>
                  </div>
                  <select v-model="newProd.unit" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-2 text-xs text-slate-800 font-bold focus:outline-none focus:border-emerald-600 focus:bg-white uppercase">
                    <option v-for="u in availableUnits" :key="u" :value="u">{{ u }}</option>
                  </select>
                </div>
              </div>

              <!-- Baris 3: Harga Eceran (Numpad), Harga Modal/HPP, & Stok Fisik (Sejajar 1 Baris) -->
              <div class="grid grid-cols-12 gap-2">
                <div class="col-span-5">
                  <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-0.5 truncate">Harga Jual ({{ newProd.unit }}):</label>
                  <div 
                    @click="activeNumpadTarget = 'base_price'" 
                    :class="activeNumpadTarget === 'base_price' ? 'border-2 border-emerald-500 bg-emerald-50/50 ring-2 ring-emerald-100' : 'bg-slate-50 border border-slate-200'"
                    class="rounded-xl px-2.5 py-1.5 flex items-center justify-between cursor-pointer transition shadow-2xs h-[36px]"
                  >
                    <span class="text-[11px] font-bold text-slate-400 mr-1">Rp</span>
                    <input 
                      type="text" 
                      :value="newProd.price ? Number(newProd.price).toLocaleString('id-ID') : '0'" 
                      readonly
                      class="w-full bg-transparent border-0 p-0 text-right text-slate-900 font-mono font-black text-sm focus:outline-none focus:ring-0 pointer-events-none"
                    />
                  </div>
                </div>
                <div class="col-span-4">
                  <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-0.5 truncate">Harga Modal / HPP:</label>
                  <div 
                    @click="activeNumpadTarget = 'cost_price'" 
                    :class="activeNumpadTarget === 'cost_price' ? 'border-2 border-emerald-500 bg-emerald-50/50 ring-2 ring-emerald-100' : 'bg-slate-50 border border-slate-200'"
                    class="rounded-xl px-2.5 py-1.5 flex items-center justify-between cursor-pointer transition shadow-2xs h-[36px]"
                  >
                    <span class="text-[11px] font-bold text-slate-400 mr-1">Rp</span>
                    <input 
                      type="text" 
                      :value="newProd.cost_price ? Number(newProd.cost_price).toLocaleString('id-ID') : '0'" 
                      readonly
                      class="w-full bg-transparent border-0 p-0 text-right text-slate-800 font-mono font-bold text-xs focus:outline-none focus:ring-0 pointer-events-none"
                    />
                  </div>
                </div>
                <div class="col-span-3">
                  <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-0.5 truncate">Stok ({{ newProd.unit }}):</label>
                  <div 
                    @click="activeNumpadTarget = 'stock'" 
                    :class="activeNumpadTarget === 'stock' ? 'border-2 border-emerald-500 bg-emerald-50/50 ring-2 ring-emerald-100' : 'bg-slate-50 border border-slate-200'"
                    class="rounded-xl px-2.5 py-1.5 flex items-center justify-center cursor-pointer transition shadow-2xs h-[36px]"
                  >
                    <input 
                      type="text" 
                      :value="Number(newProd.stock || 0).toLocaleString('id-ID')" 
                      readonly
                      class="w-full bg-transparent border-0 p-0 text-center text-slate-800 font-mono font-bold text-xs focus:outline-none focus:ring-0 pointer-events-none"
                    />
                  </div>
                </div>
              </div>

              <!-- Multi-Satuan Grosir (Dus/Renceng) -->
              <div class="border border-slate-200 rounded-xl p-2.5 bg-slate-50/60">
                <div class="flex justify-between items-center mb-1">
                  <div class="flex items-center gap-1.5">
                    <span class="text-[10px] font-black text-slate-800 uppercase">Multi-Satuan / Grosir</span>
                    <span class="text-[9px] text-slate-500">(Dus / Renceng / Slop)</span>
                  </div>
                  <button 
                    type="button" 
                    @click="addExtraUnit" 
                    class="bg-blue-600 hover:bg-blue-700 text-white text-[9px] font-bold px-2.5 py-1 rounded-lg flex items-center gap-1 transition cursor-pointer uppercase shadow-xs"
                  >
                    <span>➕</span> Tambah Grosir
                  </button>
                </div>

                <div v-if="newProd.extraUnits.length === 0" class="text-[9px] text-slate-400 italic py-1.5 text-center bg-white rounded-lg border border-dashed border-slate-200">
                  Hanya satuan eceran ({{ newProd.unit }}). Klik "+ Tambah Grosir" jika ada penjualan grosir.
                </div>

                <!-- List Extra Units -->
                <div v-else class="flex flex-col gap-1 max-h-24 overflow-y-auto">
                  <div 
                    v-for="(eu, idx) in newProd.extraUnits" 
                    :key="idx" 
                    class="bg-white border border-slate-200 rounded-xl p-2 flex items-center gap-2 shadow-2xs"
                  >
                    <!-- Satuan Grosir -->
                    <div class="w-1/3">
                      <span class="text-[8px] font-bold text-slate-400 uppercase block mb-0.5">Grosir:</span>
                      <select v-model="eu.name" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-1.5 py-1 text-[10px] font-bold text-slate-800 uppercase">
                        <option v-for="u in availableUnits" :key="u" :value="u">{{ u }}</option>
                      </select>
                    </div>

                    <!-- Isi / Rasio (Smart Numpad Touch) -->
                    <div class="w-1/4">
                      <div class="flex justify-between items-center mb-0.5">
                        <span class="text-[8px] font-bold text-slate-400 uppercase">Isi:</span>
                        <span v-if="activeNumpadTarget === 'ratio_' + idx" class="text-[7px] text-emerald-600 font-bold">●</span>
                      </div>
                      <div 
                        @click="activeNumpadTarget = 'ratio_' + idx"
                        :class="activeNumpadTarget === 'ratio_' + idx ? 'border-2 border-emerald-500 bg-emerald-50/50' : 'bg-slate-50 border border-slate-200'"
                        class="rounded-lg px-1.5 py-1 flex items-center justify-center cursor-pointer transition"
                      >
                        <input 
                          type="text" 
                          :value="eu.ratio || 1" 
                          readonly
                          class="w-full bg-transparent border-0 p-0 text-center text-[11px] font-bold text-slate-900 font-mono focus:ring-0 pointer-events-none"
                        />
                      </div>
                    </div>

                    <!-- Harga Grosir (Smart Focus) -->
                    <div class="flex-1">
                      <div class="flex justify-between items-center mb-0.5">
                        <span class="text-[8px] font-bold text-slate-400 uppercase">Harga:</span>
                        <span v-if="activeNumpadTarget === 'price_' + idx" class="text-[7px] text-emerald-600 font-bold">●</span>
                      </div>
                      <div 
                        @click="activeNumpadTarget = 'price_' + idx" 
                        :class="activeNumpadTarget === 'price_' + idx ? 'border-2 border-emerald-500 bg-emerald-50/50' : 'bg-slate-50 border border-slate-200'"
                        class="rounded-lg px-2 py-1 flex items-center justify-between cursor-pointer transition"
                      >
                        <input 
                          type="text" 
                          :value="eu.price ? Number(eu.price).toLocaleString('id-ID') : '0'" 
                          readonly
                          placeholder="0" 
                          class="w-full bg-transparent border-0 p-0 text-right text-[11px] font-black text-emerald-700 font-mono focus:ring-0 pointer-events-none"
                        />
                      </div>
                    </div>

                    <!-- Hapus Extra Unit -->
                    <button 
                      type="button" 
                      @click="removeExtraUnit(idx)" 
                      class="w-6 h-6 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-100 border border-rose-200 flex items-center justify-center font-bold text-[10px] shrink-0 self-end mb-0.5 cursor-pointer"
                    >
                      ✕
                    </button>
                  </div>
                </div>
              </div>

              <!-- Opsi Langsung Keranjang + Counter Pcs -->
              <div class="p-2 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center justify-between shadow-2xs">
                <label class="flex items-center gap-1.5 cursor-pointer select-none">
                  <input type="checkbox" v-model="newProd.autoAdd" class="w-4 h-4 text-emerald-600 rounded focus:ring-0" />
                  <span class="text-[11px] font-black text-emerald-950 uppercase">Langsung masuk keranjang:</span>
                </label>
                <div v-if="newProd.autoAdd" class="flex items-center gap-1">
                  <button type="button" @click="newProd.autoAddQty = Math.max(1, newProd.autoAddQty - 1)" class="w-7 h-7 rounded-lg bg-white border border-emerald-200 text-emerald-800 font-bold text-sm flex items-center justify-center hover:bg-emerald-100 cursor-pointer shadow-2xs">
                    -
                  </button>
                  <div 
                    @click="activeNumpadTarget = 'auto_qty'" 
                    :class="activeNumpadTarget === 'auto_qty' ? 'border-2 border-emerald-500 bg-white' : 'bg-emerald-100/60 border border-emerald-200'"
                    class="px-3 py-1 rounded-lg cursor-pointer text-center font-mono font-bold text-xs text-emerald-950 min-w-10 shadow-2xs"
                    title="Sentuh untuk ubah qty dengan numpad"
                  >
                    {{ newProd.autoAddQty }} {{ newProd.unit }}
                  </div>
                  <button type="button" @click="newProd.autoAddQty += 1" class="w-7 h-7 rounded-lg bg-white border border-emerald-200 text-emerald-800 font-bold text-sm flex items-center justify-center hover:bg-emerald-100 cursor-pointer shadow-2xs">
                    +
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer Tombol Kiri -->
          <div class="flex gap-2 pt-2.5 border-t border-slate-200 mt-2">
            <button 
              v-if="editingProductId && props.authCashier?.is_admin"
              type="button"
              @click="deleteCurrentProduct" 
              class="bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs px-3.5 py-2.5 rounded-xl font-bold border border-rose-200 transition flex items-center gap-1 cursor-pointer uppercase"
              title="Hapus produk permanen"
            >
              <span>🗑️</span>
              <span>Hapus</span>
            </button>
            <button @click="showAddProductModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-xl font-bold border border-slate-200 transition cursor-pointer uppercase">
              Batal
            </button>
            <button 
              @click="saveNewProduct" 
              :disabled="!!duplicateProductWarning"
              :class="duplicateProductWarning 
                ? 'bg-slate-200 text-slate-400 cursor-not-allowed border border-slate-200' 
                : 'bg-emerald-600 hover:bg-emerald-700 text-white cursor-pointer shadow-xs'"
              class="flex-1 text-xs py-2.5 rounded-xl font-bold transition flex items-center justify-center gap-1 uppercase"
            >
              <span>💾</span>
              <span>{{ duplicateProductWarning ? '⚠️ Barcode Duplikat' : (editingProductId ? 'Simpan Perubahan' : 'Simpan Produk') }}</span>
            </button>
          </div>
        </div>

        <!-- ================= KOLOM KANAN: DEDICATED SMART NUMPAD (42% - QubePOS) ================= -->
        <div class="w-[42%] bg-slate-50/70 p-4 flex flex-col justify-between select-none">
          <div>
            <!-- Header Numpad -->
            <div class="flex justify-between items-center pb-2.5 border-b border-slate-200">
              <span class="text-xs font-black text-slate-700 flex items-center gap-1.5 uppercase">
                <span>🔢</span>
                <span>Numpad Kasir Standby</span>
              </span>
              <button @click="showAddProductModal = false" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">
                ✕
              </button>
            </div>

            <!-- Display Numpad Terbaca Jelas (QubePOS) -->
            <div class="mt-2.5 bg-white border border-emerald-500 rounded-xl p-3 text-right shadow-2xs">
              <div class="flex justify-between items-center text-[10px] font-black text-emerald-700 uppercase tracking-wider mb-0.5">
                <span>Target:</span>
                <span class="truncate ml-1">{{ activeNumpadLabel }}</span>
              </div>
              <div class="text-2xl font-mono font-black text-slate-900 tracking-tight">
                <template v-if="!isQtyModeActive">
                  <span class="text-sm font-bold text-slate-400 mr-1">Rp</span>
                  {{ getTargetValue() ? Number(getTargetValue()).toLocaleString('id-ID') : '0' }}
                </template>
                <template v-else>
                  {{ getTargetValue() ? Number(getTargetValue()).toLocaleString('id-ID') : (activeNumpadTarget === 'stock' ? '0' : '1') }} <span class="text-sm font-bold text-emerald-700">{{ newProd.unit }}</span>
                </template>
              </div>
            </div>

            <!-- Shortcut Baris Cepat Berbasis Cache (10+, 20+, dst + Tambah/Edit/Hapus) -->
            <div class="mt-2 flex flex-col gap-1.5">
              <div class="flex flex-wrap items-center gap-1 font-mono text-[10px]">
                <template v-if="!isEditingPresets">
                  <button
                    v-for="(amt, idx) in currentPresetList"
                    :key="activePresetGroup + '-' + idx + '-' + amt"
                    type="button"
                    @click="universalNumpad(amt)"
                    class="flex-1 min-w-[44px] bg-white hover:bg-emerald-50 active:bg-emerald-100 border border-slate-200 py-1.5 px-1.5 rounded-lg font-bold text-slate-700 transition text-center cursor-pointer shadow-2xs"
                    :title="`Tambah +${amt.toLocaleString('id-ID')}`"
                  >
                    {{ formatPresetChip(amt, activePresetGroup) }}
                  </button>
                </template>
                <template v-else>
                  <div
                    v-for="(amt, idx) in currentPresetList"
                    :key="'edit-' + activePresetGroup + '-' + idx + '-' + amt"
                    class="inline-flex items-center bg-amber-50 border border-amber-300 rounded-lg overflow-hidden shadow-2xs"
                  >
                    <button
                      type="button"
                      @click="openEditPreset(idx, amt)"
                      class="px-2 py-1 text-[10px] font-bold text-amber-900 hover:bg-amber-100 cursor-pointer"
                      title="Klik untuk ubah nominal preset ini"
                    >
                      {{ formatPresetChip(amt, activePresetGroup) }}
                    </button>
                    <button
                      type="button"
                      @click="deletePresetAt(idx)"
                      class="px-1.5 py-1 text-[10px] font-black text-rose-600 hover:bg-rose-100 border-l border-amber-200 cursor-pointer"
                      title="Hapus preset ini"
                    >
                      ✕
                    </button>
                  </div>
                </template>

                <!-- Tombol + Tambah Jumlah Preset Baru -->
                <button
                  type="button"
                  @click="openAddPreset"
                  class="px-2.5 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 active:scale-95 text-blue-700 border border-blue-200 font-black text-xs transition cursor-pointer shrink-0 shadow-2xs"
                  title="Tambah tombol cepat baru (tersimpan di cache)"
                >
                  +
                </button>

                <!-- Tombol Mode Edit / Hapus Preset -->
                <button
                  type="button"
                  @click="isEditingPresets = !isEditingPresets; if (!isEditingPresets) showPresetEditor = false"
                  :class="isEditingPresets ? 'bg-amber-500 text-white border-amber-600' : 'bg-slate-100 hover:bg-slate-200 text-slate-600 border-slate-200'"
                  class="px-2 py-1.5 rounded-lg border font-bold text-[10px] transition cursor-pointer shrink-0 shadow-2xs"
                  :title="isEditingPresets ? 'Selesai atur preset' : 'Edit / Hapus tombol preset'"
                >
                  {{ isEditingPresets ? '✓' : '✏️' }}
                </button>
              </div>

              <!-- Form Inline Tambah / Edit Preset Cache -->
              <div v-if="showPresetEditor" class="p-2 bg-white border border-blue-200 rounded-xl flex items-center gap-1.5 shadow-xs">
                <div class="flex-1">
                  <RupiahInput
                    v-model="presetInputAmount"
                    :allow-empty="true"
                    :max-digits="activePresetGroup === 'price' ? 9 : 5"
                    placeholder="Nominal baru..."
                    input-class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-1 text-xs font-mono font-bold text-slate-900 focus:outline-none focus:border-blue-500"
                  />
                </div>
                <button type="button" @click="savePresetAmount" class="px-2.5 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-[10px] font-bold cursor-pointer">
                  {{ editingPresetIndex !== null ? 'Ubah' : 'Simpan' }}
                </button>
                <button type="button" @click="showPresetEditor = false; editingPresetIndex = null" class="px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg text-[10px] font-bold cursor-pointer">
                  ✕
                </button>
              </div>

              <!-- Reset ke Default (Tampil saat mode Edit aktif) -->
              <div v-if="isEditingPresets" class="flex items-center justify-between text-[9px] text-amber-800 px-1">
                <span>Tap angka untuk edit, tap ✕ untuk hapus</span>
                <button type="button" @click="resetDefaultPresets" class="underline font-bold hover:text-rose-700 cursor-pointer">Reset Default</button>
              </div>
            </div>
          </div>

          <!-- Numpad Touch Grid Berukuran Besar (QubePOS Industrial Grid) -->
          <div class="grid grid-cols-4 gap-1.5 font-mono text-sm mt-2">
            <button type="button" v-for="n in ['1', '2', '3']" :key="n" @click="universalNumpad(n)" class="bg-white hover:bg-slate-100 active:bg-slate-200 py-3 rounded-xl font-bold text-slate-900 text-center border border-slate-200 transition cursor-pointer text-base shadow-2xs">
              {{ n }}
            </button>
            <button type="button" @click="universalNumpad('DEL')" class="bg-amber-50 hover:bg-amber-100 active:bg-amber-200 text-amber-800 py-3 rounded-xl font-bold flex items-center justify-center border border-amber-200 transition cursor-pointer shadow-2xs">
              ⌫
            </button>

            <button type="button" v-for="n in ['4', '5', '6']" :key="n" @click="universalNumpad(n)" class="bg-white hover:bg-slate-200 active:bg-slate-300 py-3 rounded-xl font-bold text-slate-900 text-center border border-slate-200 transition cursor-pointer text-base shadow-2xs">
              {{ n }}
            </button>
            <button type="button" @click="universalNumpad('C')" class="bg-rose-50 hover:bg-rose-100 active:bg-rose-200 text-rose-700 py-3 rounded-xl font-bold border border-rose-200 transition cursor-pointer shadow-2xs">
              C
            </button>

            <button type="button" v-for="n in ['7', '8', '9']" :key="n" @click="universalNumpad(n)" class="bg-white hover:bg-slate-200 active:bg-slate-300 py-3 rounded-xl font-bold text-slate-900 text-center border border-slate-200 transition cursor-pointer text-base shadow-2xs">
              {{ n }}
            </button>
            <button type="button" @click="universalNumpad(isQtyModeActive ? 1 : '000')" :class="isQtyModeActive ? 'opacity-30 pointer-events-none' : ''" class="bg-white hover:bg-slate-100 active:bg-slate-200 py-3 rounded-xl font-bold text-slate-900 text-xs border border-slate-200 transition cursor-pointer shadow-2xs">
              {{ isQtyModeActive ? '-' : '000' }}
            </button>

            <button type="button" @click="universalNumpad('0')" class="col-span-2 bg-white hover:bg-slate-100 active:bg-slate-200 py-3 rounded-xl font-bold text-slate-900 text-center border border-slate-200 transition cursor-pointer text-base shadow-2xs">
              0
            </button>
            <button type="button" @click="cycleNumpadTarget" class="col-span-2 bg-blue-50 hover:bg-blue-100 active:bg-blue-200 text-blue-900 py-3 rounded-xl font-bold text-xs border border-blue-200 transition cursor-pointer uppercase shadow-2xs">
              Target ⇄
            </button>
          </div>

        </div>

      </div>
    </div>

    <!-- MODAL QUICK ADD KATEGORI BARU (TRUE FLAT) -->
    <div v-if="showQuickAddCategoryModal" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-[70] flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-sm flex flex-col shadow-2xl p-5 gap-3.5 border border-slate-200">
        <div class="flex justify-between items-center border-b border-slate-200 pb-2">
          <div class="flex items-center gap-2">
            <span class="text-xl p-1.5 bg-blue-50 rounded-xl border border-blue-200 shadow-2xs">{{ quickCatIcon }}</span>
            <div>
              <h3 class="font-bold text-xs text-slate-900 leading-tight">Tambah Kategori Baru</h3>
              <p class="text-[10px] text-slate-500">Kategori langsung aktif di kasir & katalog</p>
            </div>
          </div>
          <button @click="showQuickAddCategoryModal = false" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
        </div>

        <div class="flex flex-col gap-2.5">
          <div>
            <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-0.5">Nama Kategori:</label>
            <input 
              type="text" 
              v-model="quickCatName" 
              @keydown.enter.prevent="saveQuickCategory"
              placeholder="Contoh: Bumbu Dapur, Snack, Obat..." 
              class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs text-slate-800 font-bold focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500 focus:bg-white transition"
            />
          </div>

          <div>
            <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-1">Pilih Icon Emoji:</label>
            <div class="grid grid-cols-8 gap-1 p-2 bg-slate-50 rounded-xl border border-slate-200">
              <button 
                v-for="ico in emojiOptions" 
                :key="ico"
                type="button"
                @click="quickCatIcon = ico"
                :class="quickCatIcon === ico ? 'bg-blue-600 text-white border-blue-600 shadow-2xs' : 'bg-white hover:bg-slate-100 text-slate-800 border-slate-200 shadow-2xs'"
                class="w-8 h-8 rounded-lg border flex items-center justify-center text-sm transition cursor-pointer"
              >
                {{ ico }}
              </button>
            </div>
          </div>
        </div>

        <div class="flex gap-2.5 pt-2 border-t border-slate-200">
          <button @click="showQuickAddCategoryModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-xl font-bold border border-slate-200 transition cursor-pointer">Batal</button>
          <button @click="saveQuickCategory" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-xs py-2.5 rounded-xl font-bold border border-blue-600 transition cursor-pointer shadow-xs">Simpan Kategori</button>
        </div>
      </div>
    </div>

    <!-- MODAL QUICK ADD SATUAN BARU (SELARAS DENGAN KATEGORI) -->
    <div v-if="showQuickAddUnitModal" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-[70] flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-sm flex flex-col shadow-2xl p-5 gap-3.5 border border-slate-200">
        <div class="flex justify-between items-center border-b border-slate-200 pb-2">
          <div class="flex items-center gap-2">
            <span class="text-xl p-1.5 bg-blue-50 rounded-xl border border-blue-200 shadow-2xs">📏</span>
            <div>
              <h3 class="font-bold text-xs text-slate-900 leading-tight">Tambah Satuan Baru</h3>
              <p class="text-[10px] text-slate-500">Satuan langsung aktif di form produk</p>
            </div>
          </div>
          <button type="button" @click="showQuickAddUnitModal = false" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
        </div>

        <div class="flex flex-col gap-2.5">
          <div>
            <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-0.5">Nama Satuan:</label>
            <input 
              ref="quickUnitInputRef"
              type="text" 
              v-model="quickUnitInput" 
              @keydown.enter.prevent="saveQuickUnit"
              placeholder="Contoh: Pack, Strip, Box, Karung..." 
              class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs text-slate-800 font-bold focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-500 focus:bg-white transition"
            />
          </div>

          <div>
            <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-1">Saran Cepat:</label>
            <div class="flex flex-wrap gap-1.5 p-2 bg-slate-50 rounded-xl border border-slate-200">
              <button 
                v-for="u in unitPresetSuggestions" 
                :key="u"
                type="button"
                @click="quickUnitInput = u"
                :class="quickUnitInput.trim().toLowerCase() === u.toLowerCase() ? 'bg-blue-600 text-white border-blue-600 shadow-2xs' : 'bg-white hover:bg-slate-100 text-slate-800 border-slate-200 shadow-2xs'"
                class="px-2.5 py-1 rounded-lg border text-[11px] font-bold transition cursor-pointer"
              >
                {{ u }}
              </button>
            </div>
          </div>
        </div>

        <div class="flex gap-2.5 pt-2 border-t border-slate-200">
          <button type="button" @click="showQuickAddUnitModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-xl font-bold border border-slate-200 transition cursor-pointer">Batal</button>
          <button type="button" @click="saveQuickUnit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-xs py-2.5 rounded-xl font-bold border border-blue-600 transition cursor-pointer shadow-xs">Simpan Satuan</button>
        </div>
      </div>
    </div>

    <!-- MODAL KONFIRMASI PRODUK BELUM TERDAFTAR (TRUE FLAT) -->
    <div v-if="showUnregisteredModal" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-60 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-sm flex flex-col shadow-2xl p-5 gap-3.5 border border-slate-200">
        <div class="flex items-center gap-2.5 border-b border-slate-200 pb-2">
          <div class="w-9 h-9 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center text-lg font-bold border border-amber-200 shadow-2xs">
            ⚠️
          </div>
          <div>
            <h3 class="font-bold text-xs text-slate-900 leading-tight">Produk Belum Terdaftar!</h3>
            <p class="text-[10px] text-slate-500">Barcode belum ada di katalog toko {{ storeName }}</p>
          </div>
        </div>

        <div class="bg-amber-50/70 border border-amber-200 rounded-xl p-3.5 text-center">
          <span class="text-[10px] text-amber-700 font-bold block mb-1">Kode Barcode Terdeteksi:</span>
          <div class="font-mono font-black text-base text-slate-800 tracking-wider bg-white py-1 px-3.5 rounded-lg border border-amber-200 inline-block shadow-2xs">
            {{ unregisteredBarcode }}
          </div>
          <p class="text-[11px] text-slate-600 mt-2 font-medium">
            Apakah Anda ingin mendaftarkan produk ini ke katalog toko sekarang?
          </p>
        </div>

        <div class="flex gap-2 pt-1 border-t border-slate-200">
          <button @click="showUnregisteredModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-xl font-bold border border-slate-200 transition cursor-pointer">
            Batal
          </button>
          <button @click="confirmRegisterUnregistered" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs py-2.5 rounded-xl font-bold border border-emerald-600 flex items-center justify-center gap-1.5 transition cursor-pointer shadow-xs">
            <span>➕</span>
            <span>Daftarkan Sekarang</span>
          </button>
        </div>
      </div>
    </div>

    <!-- 4. ANDROID DIALOG 2: EDIT ITEM & MULTI-SATUAN (TRUE FLAT) -->
    <div v-if="showItemEditModal && editDraft" class="fixed inset-0 bg-black/50 backdrop-blur-2xs z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-sm flex flex-col shadow-2xl p-5 gap-3.5 border border-slate-200">
        <div class="flex justify-between items-center border-b border-slate-200 pb-2">
          <div class="truncate pr-2">
            <span class="text-[9px] text-blue-600 font-bold uppercase tracking-wider block">Ubah Detail Produk</span>
            <h3 class="font-bold text-sm text-slate-900 truncate">{{ cart[currentEditIndex]?.name }}</h3>
          </div>
          <div class="flex items-center gap-1.5 shrink-0">
            <button @click="removeItemCurrentEdit" class="bg-rose-50 hover:bg-rose-100 text-rose-600 px-2.5 py-1.5 rounded-lg text-[11px] font-bold flex items-center gap-1 border border-rose-200 transition cursor-pointer">
              <span>🗑️</span> Hapus
            </button>
            <button @click="showItemEditModal = false" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
          </div>
        </div>

        <div class="flex flex-col gap-2.5">
          <!-- Pilihan Satuan Penjualan -->
          <div>
            <label class="text-[10px] text-slate-500 block mb-1 font-bold uppercase">Satuan Penjualan:</label>
            <div class="grid grid-cols-3 gap-1.5">
              <button 
                v-for="u in editDraft.availableUnits" 
                :key="u.name"
                @click="selectEditUnit(u)"
                :class="editDraft.unit === u.name ? 'bg-blue-600 border-blue-600 text-white font-bold shadow-xs' : 'bg-slate-50 border-slate-200 text-slate-700 hover:bg-slate-100 shadow-2xs'"
                class="p-2 rounded-xl border text-center transition text-xs cursor-pointer"
              >
                <div class="text-[10px] leading-tight font-bold truncate">{{ u.name }}</div>
                <div class="text-[9px] font-mono mt-0.5 opacity-90">Rp {{ u.price.toLocaleString('id-ID') }}</div>
              </button>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="text-[10px] text-slate-500 block mb-0.5 font-bold uppercase">Jumlah Qty:</label>
              <input type="text" :value="editDraft.qty" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-2 py-2 text-base text-slate-900 font-mono font-bold text-center focus:outline-none focus:ring-2 focus:ring-blue-100 focus:bg-white" readonly />
            </div>
            <div>
              <label class="text-[10px] text-slate-500 block mb-0.5 font-bold uppercase">Diskon Item (Rp):</label>
              <RupiahInput
                v-model="editDraft.discount"
                :max="(editDraft.price || 0) * (editDraft.qty || 1)"
                prefix="Rp"
                placeholder="0"
                input-class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-8 pr-2.5 py-2 text-xs text-amber-700 font-mono font-bold focus:outline-none focus:ring-2 focus:ring-amber-100 focus:bg-white"
              />
            </div>
          </div>

          <!-- Numpad Touchpad Virtual (TRUE FLAT) -->
          <div class="grid grid-cols-3 gap-1.5 font-mono pt-0.5">
            <button v-for="n in ['1','2','3','4','5','6','7','8','9']" :key="n" @click="numpadPress(n)" class="bg-white hover:bg-slate-100 active:bg-slate-200 py-2.5 rounded-xl text-base font-bold text-slate-800 border border-slate-200 transition cursor-pointer shadow-2xs">
              {{ n }}
            </button>
            <button @click="numpadPress('C')" class="bg-rose-50 hover:bg-rose-100 active:bg-rose-200 text-rose-700 py-2.5 rounded-xl text-sm font-bold border border-rose-200 transition cursor-pointer shadow-2xs">C</button>
            <button @click="numpadPress('0')" class="bg-white hover:bg-slate-100 active:bg-slate-200 py-2.5 rounded-xl text-base font-bold text-slate-800 border border-slate-200 transition cursor-pointer shadow-2xs">0</button>
            <button @click="numpadPress('DEL')" class="bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 py-2.5 rounded-xl text-sm font-bold border border-slate-200 flex items-center justify-center transition cursor-pointer shadow-2xs">⌫</button>
          </div>
        </div>

        <div class="flex gap-2 pt-1 border-t border-slate-200">
          <button @click="showItemEditModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-xl font-bold border border-slate-200 transition cursor-pointer">Batal</button>
          <button @click="saveItemEdit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-xs py-2.5 rounded-xl font-bold border border-blue-600 transition cursor-pointer shadow-xs">Simpan</button>
        </div>
      </div>
    </div>

    <!-- 5. ANDROID DIALOG 3: DISKON NOTA DENGAN NUMPAD (TRUE FLAT) -->
    <div v-if="showDiscountModal" class="fixed inset-0 bg-black/50 backdrop-blur-2xs z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-sm flex flex-col shadow-2xl p-5 gap-3 border border-slate-200">
        <div class="flex justify-between items-center border-b border-slate-200 pb-2">
          <div class="flex items-center gap-1.5">
            <span class="text-amber-500 text-sm">🏷️</span>
            <h3 class="font-bold text-xs text-slate-900">Potongan Diskon Nota</h3>
          </div>
          <button @click="showDiscountModal = false" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
        </div>

        <!-- Display Potongan -->
        <div class="bg-amber-50/70 border border-amber-200 rounded-xl p-3 text-center shadow-2xs">
          <span class="text-[9px] text-amber-700 uppercase font-bold block">Nominal Potongan:</span>
          <div class="text-xl font-mono font-black text-amber-900">Rp {{ tempDiscountInput }}</div>
        </div>

        <!-- Tombol Shortcut Diskon (TRUE FLAT) -->
        <div class="grid grid-cols-4 gap-1 font-mono text-[9px]">
          <button @click="setDiscountShortcut('round')" class="bg-white hover:bg-amber-50 py-1.5 rounded-lg font-bold text-amber-900 border border-slate-200 transition cursor-pointer shadow-2xs">Bulat</button>
          <button @click="setDiscountShortcut('5%')" class="bg-white hover:bg-amber-50 py-1.5 rounded-lg font-bold text-amber-900 border border-slate-200 transition cursor-pointer shadow-2xs">5%</button>
          <button @click="setDiscountShortcut('10%')" class="bg-white hover:bg-amber-50 py-1.5 rounded-lg font-bold text-amber-900 border border-slate-200 transition cursor-pointer shadow-2xs">10%</button>
          <button @click="setDiscountShortcut(1000)" class="bg-white hover:bg-amber-50 py-1.5 rounded-lg font-bold text-slate-700 border border-slate-200 transition cursor-pointer shadow-2xs">+1rb</button>
          <button @click="setDiscountShortcut(2000)" class="bg-white hover:bg-amber-50 py-1.5 rounded-lg font-bold text-slate-700 border border-slate-200 transition cursor-pointer shadow-2xs">+2rb</button>
          <button @click="setDiscountShortcut(5000)" class="bg-white hover:bg-amber-50 py-1.5 rounded-lg font-bold text-slate-700 border border-slate-200 transition cursor-pointer shadow-2xs">+5rb</button>
          <button @click="setDiscountShortcut(10000)" class="bg-white hover:bg-amber-50 py-1.5 rounded-lg font-bold text-slate-700 border border-slate-200 transition cursor-pointer shadow-2xs">+10rb</button>
          <button @click="setDiscountShortcut(20000)" class="bg-white hover:bg-amber-50 py-1.5 rounded-lg font-bold text-slate-700 border border-slate-200 transition cursor-pointer shadow-2xs">+20rb</button>
        </div>

        <!-- Numpad Virtual Diskon (TRUE FLAT) -->
        <div class="flex flex-col gap-1 font-mono pt-0.5">
          <div class="grid grid-cols-3 gap-1">
            <button v-for="n in ['1','2','3','4','5','6','7','8','9']" :key="n" @click="discountNumpad(n)" class="bg-white hover:bg-slate-100 active:bg-slate-200 py-2 rounded-xl text-base font-bold text-slate-800 border border-slate-200 transition cursor-pointer shadow-2xs">
              {{ n }}
            </button>
          </div>
          <div class="grid grid-cols-4 gap-1">
            <button @click="discountNumpad('C')" class="bg-rose-50 hover:bg-rose-100 text-rose-700 py-2 rounded-xl text-xs font-bold border border-rose-200 transition cursor-pointer shadow-2xs">C</button>
            <button @click="discountNumpad('0')" class="bg-white hover:bg-slate-100 active:bg-slate-200 py-2 rounded-xl text-base font-bold text-slate-800 border border-slate-200 transition cursor-pointer shadow-2xs">0</button>
            <button @click="discountNumpad('000')" class="bg-slate-50 hover:bg-slate-100 active:bg-slate-200 py-2 rounded-xl text-xs font-bold text-slate-700 border border-slate-200 transition cursor-pointer shadow-2xs">000</button>
            <button @click="discountNumpad('DEL')" class="bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 py-2 rounded-xl text-sm font-bold border border-slate-200 transition cursor-pointer shadow-2xs" title="Hapus 1 digit">⌫</button>
          </div>
        </div>

        <!-- Alasan Diskon (TRUE FLAT) -->
        <div class="flex flex-col gap-1">
          <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block">Alasan Diskon:</label>
          <div class="flex gap-1 overflow-x-auto text-[10px]">
            <button v-for="r in ['Pembulatan', 'Pelanggan Tetap', 'Promo Toko']" :key="r" @click="tempDiscountNote = r" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2.5 py-1 rounded-lg border border-slate-200 shrink-0 transition cursor-pointer shadow-2xs">
              {{ r }}
            </button>
          </div>
          <input type="text" v-model="tempDiscountNote" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-100 focus:bg-white focus:border-amber-400 transition" placeholder="Alasan lain (opsional)..." />
        </div>

        <div class="flex gap-2 pt-1 border-t border-slate-200">
          <button @click="showDiscountModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-xl font-bold border border-slate-200 transition cursor-pointer">Batal</button>
          <button @click="saveGlobalDiscount" class="flex-1 bg-amber-600 hover:bg-amber-700 text-white text-xs py-2.5 rounded-xl font-bold border border-amber-600 transition cursor-pointer shadow-xs">Terapkan Diskon</button>
        </div>
      </div>
    </div>

    <!-- 6. ANDROID DIALOG 5: ANTREAN TRANSAKSI (HOLD - TRUE FLAT) -->
    <div v-if="showHoldModal" class="fixed inset-0 bg-black/50 backdrop-blur-2xs z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-lg flex flex-col shadow-2xl p-5 gap-3.5 border border-slate-200">
        <div class="flex justify-between items-center border-b border-slate-200 pb-2">
          <h3 class="font-bold text-xs text-slate-900 flex items-center gap-1.5">⏸️ Antrean Order Tersimpan (Hold)</h3>
          <button @click="showHoldModal = false" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
        </div>

        <div class="flex flex-col gap-2 max-h-[280px] overflow-y-auto">
          <div v-if="holdOrdersList.length === 0" class="text-center py-6 text-slate-400 text-xs">
            Belum ada antrean order yang tersimpan.
          </div>
          <div 
            v-for="(order, idx) in holdOrdersList" 
            :key="order.id"
            class="flex items-center justify-between p-3 bg-slate-50 border border-slate-200 rounded-xl shadow-2xs"
          >
            <div>
              <div class="text-xs font-bold text-slate-900">{{ order.name }}</div>
              <div class="text-[10px] text-slate-500 mt-0.5">
                {{ (order.items || order.data?.items || []).length }} item · Disimpan: {{ order.created_at }}
              </div>
            </div>
            <div class="flex items-center gap-2">
              <button @click="restoreHoldOrder(idx)" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-3.5 py-1.5 rounded-lg border border-blue-600 transition cursor-pointer shadow-xs">
                Buka
              </button>
              <button @click="deleteHoldOrder(idx)" class="text-rose-500 hover:text-rose-700 text-xs p-1 cursor-pointer">
                🗑️
              </button>
            </div>
          </div>
        </div>

        <div class="flex justify-end pt-2 border-t border-slate-200">
          <button @click="showHoldModal = false" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs px-4 py-2 rounded-xl font-bold border border-slate-200 transition cursor-pointer">Tutup</button>
        </div>
      </div>
    </div>

    <!-- 7. ANDROID RIGHT DOCK (35% LEBAR LAYAR): SCANNER BARCODE KAMERA (TRUE FLAT) -->
    <div v-if="showScannerModal" class="fixed inset-0 z-50 flex justify-end select-none">
      <!-- Backdrop Halus di Sisi Kiri (65%) - Klik untuk Tutup -->
      <div class="flex-1 bg-black/25 backdrop-blur-2xs transition" @click="stopCameraScanner"></div>

      <!-- Panel Scanner di Sisi Kanan (35% Lebar Layar) -->
      <div class="w-[35%] h-full bg-slate-900 text-white border-l border-slate-800 shadow-2xl flex flex-col justify-between p-4 z-10">
        
        <!-- Header Panel Scanner: Judul, Status & Tombol Switch / Tutup di Kanan Atas -->
        <div class="flex items-center justify-between pb-2 border-b border-slate-800 shrink-0">
          <div class="flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full bg-rose-500 animate-ping"></span>
            <div>
              <h3 class="font-bold text-xs text-white leading-tight">Scanner Barcode</h3>
              <p class="text-[9px] text-emerald-400 font-medium truncate max-w-[170px]">
                {{ availableCameras.length > 1 ? (availableCameras[currentCameraIndex]?.label || 'Kamera ' + (currentCameraIndex + 1)) : 'Arahkan barcode ke kamera' }}
              </p>
            </div>
          </div>

          <div class="flex items-center gap-1.5">
            <!-- Tombol Senter / Flash jika didukung hardware kamera tablet -->
            <button 
              v-if="hasTorch"
              @click="toggleTorch" 
              type="button"
              :title="isTorchOn ? 'Matikan Lampu Flash' : 'Nyalakan Lampu Flash Kamera'"
              :class="[
                'px-2.5 py-1.5 rounded-lg flex items-center gap-1 text-[10px] font-semibold transition cursor-pointer border shadow-2xs',
                isTorchOn ? 'bg-amber-400 text-slate-950 font-black border-amber-500' : 'bg-slate-800 hover:bg-slate-700 text-slate-200 border-slate-700'
              ]"
            >
              <span>🔦</span>
              <span>{{ isTorchOn ? 'Nyala' : 'Flash' }}</span>
            </button>

            <!-- Tombol Tes Suara Beep -->
            <button 
              @click="playBeepSound('success')" 
              type="button"
              title="Tes Bunyi Beep Kasir"
              class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 active:bg-slate-600 text-slate-300 hover:text-emerald-400 border border-slate-700 flex items-center gap-1 text-[10px] font-semibold transition cursor-pointer shadow-2xs"
            >
              <span>🔊</span>
              <span>Tes</span>
            </button>

            <!-- Tombol Ganti Kamera jika ada lebih dari 1 kamera / webcam -->
            <button 
              v-if="availableCameras.length > 1"
              @click="switchCamera" 
              type="button"
              title="Ganti Kamera"
              class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 active:bg-slate-600 text-slate-200 hover:text-white border border-slate-700 flex items-center gap-1 text-[10px] font-semibold transition cursor-pointer shadow-2xs"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
              <span>Ganti</span>
            </button>

            <!-- Tombol Tutup di Kanan Atas -->
            <button 
              @click="stopCameraScanner" 
              class="w-7 h-7 rounded-lg bg-slate-800 hover:bg-slate-700 active:bg-slate-600 text-slate-300 hover:text-white border border-slate-700 flex items-center justify-center font-bold text-xs transition cursor-pointer"
            >
              ✕
            </button>
          </div>
        </div>

        <!-- Area Kamera Scanner Melebar 100% Panel Kanan -->
        <div class="flex-1 flex flex-col items-center justify-center my-auto w-full relative">
          <div 
            @click="triggerManualFocus" 
            title="Sentuh layar video untuk memicu fokus ulang instan"
            class="relative w-full aspect-square max-h-[290px] bg-slate-950 rounded-2xl overflow-hidden border-2 border-emerald-500 shadow-lg flex items-center justify-center cursor-pointer group"
          >
            
            <!-- Elemen Pembaca Kamera HTML5-QRCode -->
            <div id="scanner-viewfinder" class="w-full h-full object-cover rounded-2xl overflow-hidden"></div>

            <!-- Efek Flash Hijau & Tanda "✓ TERDETEKSI" Saat Barcode Berhasil Discan -->
            <div 
              v-if="isScanFlashActive" 
              class="absolute inset-0 bg-emerald-500/30 border-4 border-emerald-400 rounded-2xl z-30 pointer-events-none transition-all duration-150 flex items-center justify-center"
            >
              <div class="px-4 py-2 bg-emerald-600 text-white rounded-xl text-xs font-bold tracking-wider flex items-center gap-1.5 border border-emerald-300 shadow-lg animate-bounce">
                <span class="text-sm">✓</span>
                <span>TERDETEKSI</span>
              </div>
            </div>

            <!-- Laser Line Merah Beranimasi (Sinar Pemindai) -->
            <div class="absolute inset-x-4 top-1/2 -translate-y-1/2 h-0.5 bg-rose-500 animate-pulse pointer-events-none z-10"></div>

            <!-- Sudut Target Viewfinder -->
            <div class="absolute top-2.5 left-2.5 w-5 h-5 border-t-3 border-l-3 border-emerald-400 pointer-events-none z-10 rounded-sm"></div>
            <div class="absolute top-2.5 right-2.5 w-5 h-5 border-t-3 border-r-3 border-emerald-400 pointer-events-none z-10 rounded-sm"></div>
            <div class="absolute bottom-2.5 left-2.5 w-5 h-5 border-b-3 border-l-3 border-emerald-400 pointer-events-none z-10 rounded-sm"></div>
            <div class="absolute bottom-2.5 right-2.5 w-5 h-5 border-b-3 border-r-3 border-emerald-400 pointer-events-none z-10 rounded-sm"></div>

            <!-- Pesan Error Kamera jika ada -->
            <div v-if="cameraError" class="absolute inset-0 bg-slate-900/95 p-4 flex flex-col items-center justify-center text-center text-white z-20">
              <span class="text-2xl mb-1">📷</span>
              <p class="text-xs font-bold text-rose-400 mb-0.5">Akses Kamera Gagal</p>
              <p class="text-[10px] text-slate-300 max-w-[220px] mb-2.5 leading-relaxed">{{ cameraError }}</p>
              <button 
                @click="startCameraScanner(scannerTarget)" 
                type="button"
                class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white font-bold text-[11px] rounded-xl border border-emerald-500 transition cursor-pointer shadow-xs"
              >
                🔄 Coba Lagi
              </button>
            </div>

            <!-- Dialog Melayang Barcode Belum Terdaftar (TRUE FLAT) -->
            <div v-if="scannerUnregisteredBarcode" class="absolute inset-0 bg-slate-900/95 backdrop-blur-md p-4 flex flex-col items-center justify-center text-center z-30 rounded-2xl border border-amber-400/50 shadow-xl">
              <div class="w-9 h-9 rounded-xl bg-amber-500/20 text-amber-400 border border-amber-500/30 flex items-center justify-center text-lg mb-1.5">
                ⚠️
              </div>
              <h4 class="text-xs font-bold text-white mb-0.5">Produk Belum Terdaftar</h4>
              <p class="text-[10px] text-slate-300 font-mono bg-slate-800 px-3 py-1 rounded-lg mb-2.5 border border-slate-700">
                {{ scannerUnregisteredBarcode }}
              </p>
              <div class="flex flex-col gap-1.5 w-full max-w-[200px]">
                <button 
                  @click="registerFromScanner(scannerUnregisteredBarcode)" 
                  class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[11px] py-2 rounded-xl border border-emerald-600 transition flex items-center justify-center gap-1 cursor-pointer shadow-xs"
                >
                  <span>➕</span>
                  <span>Daftarkan Produk</span>
                </button>
                <button 
                  @click="scannerUnregisteredBarcode = ''" 
                  class="w-full bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-[11px] py-2 rounded-xl border border-slate-700 transition cursor-pointer"
                >
                  Abaikan & Lanjut Scan
                </button>
              </div>
            </div>
          </div>

          <!-- Status Indikator Autofokus & Tap to Focus -->
          <div class="mt-2 text-center text-slate-400 text-[9px] flex items-center justify-center gap-1.5 select-none">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
            <span>Autofokus Cepat Aktif • Sentuh kamera untuk fokus ulang</span>
          </div>

          <!-- Input Alternatif Cepat Manual Barcode / Testing Laptop (TRUE FLAT) -->
          <div class="w-full mt-2 shrink-0">
            <div class="relative">
              <input 
                v-model="quickBarcodeInput"
                @keydown.enter="submitQuickBarcode"
                type="text" 
                placeholder="Ketik barcode lalu tekan Enter..."
                class="w-full bg-slate-950/90 border border-slate-700 focus:border-emerald-500 rounded-xl px-3.5 py-2 pr-14 text-xs text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 font-mono transition"
              />
              <button 
                @click="submitQuickBarcode"
                type="button"
                class="absolute right-1 top-1 bottom-1 px-2.5 bg-emerald-600 hover:bg-emerald-500 text-white text-[10px] font-bold rounded-lg border border-emerald-500 transition cursor-pointer flex items-center gap-0.5 shadow-xs"
              >
                <span>Enter</span>
              </button>
            </div>
            <p class="text-[9px] text-slate-400 text-center mt-1 flex items-center justify-center gap-1">
              <span>💡</span>
              <span>Tips Laptop: Beri jarak 15–25 cm & pencahayaan terang agar barcode tajam</span>
            </p>
          </div>
        </div>

        <!-- Footer Panel Scanner: Hanya Notifikasi Terakhir Ter-Scan (TRUE FLAT) -->
        <div class="shrink-0 pt-2 min-h-[44px] flex items-center justify-center">
          <transition enter-active-class="transition duration-200 ease-out" enter-from-class="transform translate-y-2 opacity-0" enter-to-class="transform translate-y-0 opacity-100" leave-active-class="transition duration-150 ease-in" leave-from-class="transform translate-y-0 opacity-100" leave-to-class="transform translate-y-2 opacity-0">
            <div v-if="lastScannedProduct" class="bg-emerald-600 text-white px-3.5 py-2 rounded-xl flex items-center gap-2.5 border border-emerald-500 w-full shadow-xs">
              <span class="text-sm font-bold">✓</span>
              <div class="leading-tight text-left truncate flex-1">
                <div class="text-[11px] font-bold truncate">{{ lastScannedProduct.name }}</div>
                <div class="text-[9px] text-emerald-100 font-mono">+1 pcs · Rp {{ lastScannedProduct.price.toLocaleString('id-ID') }}</div>
              </div>
            </div>
            <div v-else class="text-[10px] text-slate-500 font-medium text-center">
              Scan barcode terus-menerus tanpa jeda
            </div>
          </transition>
        </div>

      </div>
    </div>

    <!-- 8. ANDROID DIALOG 7: QRIS DUA MODE DENGAN SWITCH TOGGLE (FLAT MODERN) -->
    <div v-if="showQrisModal" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-md flex flex-col items-center shadow-2xl p-5 gap-3 border border-slate-200">
        <!-- Header Dialog -->
        <div class="flex justify-between items-center w-full border-b border-slate-200 pb-2.5">
          <div class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-base border border-blue-200 font-bold shadow-2xs">📱</span>
            <div>
              <h3 class="font-bold text-xs text-slate-900 leading-tight">Pembayaran QRIS Toko</h3>
              <p class="text-[10px] text-slate-500">Scan via BCA, Mandiri, BRI, GoPay, Dana, OVO, ShopeePay</p>
            </div>
          </div>
          <button @click="closeQrisModal" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
        </div>

        <!-- SWITCH TOMBOL OPSI 2 (GATEWAY) VS OPSI 3 (QRIS TOKO) -->
        <div class="w-full bg-slate-100 p-1 rounded-xl border border-slate-200 flex items-center">
          <button 
            type="button"
            @click="setQrisMode('emvco')" 
            :class="qrisMode === 'emvco' ? 'bg-white text-blue-700 shadow-2xs font-bold border border-slate-200' : 'text-slate-600 hover:text-slate-900 font-medium'"
            class="flex-1 py-1.5 px-2 rounded-lg text-[11px] transition flex items-center justify-center gap-1.5 cursor-pointer"
          >
            <span>🏷️</span>
            <span>QRIS Toko (Nominal Pas)</span>
          </button>
          <button 
            type="button"
            @click="setQrisMode('gateway')" 
            :class="qrisMode === 'gateway' ? 'bg-white text-emerald-700 shadow-2xs font-bold border border-slate-200' : 'text-slate-600 hover:text-slate-900 font-medium'"
            class="flex-1 py-1.5 px-2 rounded-lg text-[11px] transition flex items-center justify-center gap-1.5 cursor-pointer"
          >
            <span>⚡</span>
            <span>QRIS Gateway (Auto-Detect)</span>
          </button>
        </div>

        <!-- ISI TAMPILAN BERDASARKAN MODE -->
        
        <!-- OPSI 3: QRIS TOKO EMVCo (Nominal Terisi Otomatis + Opsi Kembalian Kasir) -->
        <div v-if="qrisMode === 'emvco'" class="w-full text-center flex flex-col items-center gap-2">
          <div class="flex items-center justify-between w-full px-1">
            <span class="text-[10px] text-slate-500 font-medium">QRIS Resmi Toko {{ settingsForm.store_name || 'Rozak Mart' }}</span>
            <button 
              type="button" 
              @click="openQrisConfigModal" 
              class="text-[10px] text-blue-600 hover:text-blue-800 font-bold flex items-center gap-1 cursor-pointer hover:underline"
            >
              <span>⚙️</span> Atur String QR Toko
            </button>
          </div>

          <!-- Kotak QR Code -->
          <div class="bg-white p-2 rounded-xl w-44 h-44 flex flex-col items-center justify-center border border-slate-200 shadow-2xs relative">
            <img :src="dynamicEmvcoQrUrl" alt="QRIS Dinamis Toko" class="w-38 h-38 object-contain" />
            <span class="absolute bottom-1 text-[8px] font-bold text-slate-500 font-mono tracking-wider bg-slate-50 px-2 py-0.5 rounded-md border border-slate-200">
              NOMINAL: Rp {{ (Number(qrisPaidAmount) || grandTotal).toLocaleString('id-ID') }}
            </span>
          </div>

          <div class="bg-blue-50/70 border border-blue-200 rounded-xl px-3 py-1.5 w-full flex items-center justify-between">
            <div class="text-left">
              <span class="text-[9px] text-blue-700 uppercase font-bold block">Total Belanjaan:</span>
              <span class="text-[10px] text-slate-500">Bisa terima lebih jika numpang tarik tunai</span>
            </div>
            <span class="font-mono font-black text-slate-900 text-base">Rp {{ grandTotal.toLocaleString('id-ID') }}</span>
          </div>

          <!-- Input Overpayment QRIS & Biaya Admin Opsional -->
          <div class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 flex flex-col gap-2 text-left">
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="text-[9px] text-slate-500 font-bold uppercase block mb-0.5">Nominal QRIS Diterima (Rp):</label>
                <RupiahInput
                  v-model="qrisPaidAmount"
                  prefix="Rp"
                  input-class="w-full bg-white border border-slate-300 focus:border-blue-500 rounded-lg pl-8 pr-2.5 py-1.5 text-xs font-mono font-black text-slate-900 focus:outline-none"
                />
              </div>
              <div>
                <label class="text-[9px] text-slate-500 font-bold uppercase block mb-0.5">Biaya Admin Jasa (Opsional):</label>
                <RupiahInput
                  v-model="qrisAdminFee"
                  prefix="Rp"
                  placeholder="0"
                  input-class="w-full bg-white border border-slate-300 focus:border-blue-500 rounded-lg pl-8 pr-2.5 py-1.5 text-xs font-mono font-bold text-slate-900 focus:outline-none"
                />
              </div>
            </div>

            <!-- Shortcut Nominal Cepat -->
            <div class="flex items-center gap-1 flex-wrap">
              <span class="text-[9px] text-slate-400 font-bold mr-1">Cepat:</span>
              <button type="button" @click="qrisPaidAmount = grandTotal" :class="qrisPaidAmount === grandTotal ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-100'" class="px-2 py-0.5 rounded text-[10px] font-bold transition cursor-pointer">Pas</button>
              <button v-for="amt in [50000, 100000, 150000, 200000]" :key="amt" v-show="amt > grandTotal" type="button" @click="qrisPaidAmount = amt" :class="qrisPaidAmount === amt ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-100'" class="px-2 py-0.5 rounded text-[10px] font-mono font-bold transition cursor-pointer">
                {{ (amt/1000) }}rb
              </button>
            </div>

            <!-- Banner Kembalian Tunai Laci -->
            <div v-if="qrisCashChange > 0" class="bg-emerald-50 border border-emerald-300 rounded-lg px-2.5 py-1.5 flex items-center justify-between">
              <div>
                <span class="text-[9px] text-emerald-800 font-black uppercase block">💵 Kembalian Tunai dari Laci:</span>
                <span class="text-[9px] text-emerald-700">Uang cash laci otomatis dipotong sesuai nominal ini</span>
              </div>
              <span class="font-mono font-black text-emerald-700 text-sm">Rp {{ qrisCashChange.toLocaleString('id-ID') }}</span>
            </div>
            <div v-else-if="Number(qrisPaidAmount) < (grandTotal + Number(qrisAdminFee || 0))" class="bg-rose-50 border border-rose-200 rounded-lg px-2.5 py-1 text-[10px] text-rose-700 font-bold">
              ⚠️ Nominal QRIS diterima kurang dari total tagihan!
            </div>
          </div>

          <div class="grid grid-cols-3 gap-2 w-full pt-1 border-t border-slate-200">
            <button @click="closeQrisModal" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-xl font-bold border border-slate-200 transition cursor-pointer">Batal</button>
            <button 
              @click="finalizeTransaction('QRIS', Number(qrisPaidAmount) || grandTotal, qrisCashChange, false, { adminFee: Number(qrisAdminFee) || 0, transactionType: 'SALE' })" 
              :disabled="Number(qrisPaidAmount) < (grandTotal + Number(qrisAdminFee || 0))"
              class="bg-blue-600 hover:bg-blue-700 text-white text-xs py-2.5 rounded-xl font-bold border border-blue-600 transition cursor-pointer shadow-xs disabled:opacity-40 disabled:cursor-not-allowed"
            >
              Selesai
            </button>
            <button 
              @click="finalizeTransaction('QRIS', Number(qrisPaidAmount) || grandTotal, qrisCashChange, true, { adminFee: Number(qrisAdminFee) || 0, transactionType: 'SALE' })" 
              :disabled="Number(qrisPaidAmount) < (grandTotal + Number(qrisAdminFee || 0))"
              class="bg-blue-700 hover:bg-blue-800 text-white text-xs py-2.5 rounded-xl font-bold border border-blue-700 flex items-center justify-center gap-1.5 transition cursor-pointer shadow-xs disabled:opacity-40 disabled:cursor-not-allowed"
            >
              <span>🖨️</span> Selesai & Cetak
            </button>
          </div>
        </div>

        <!-- OPSI 2: QRIS GATEWAY AUTO-DETECT (Realtime Polling) -->
        <div v-else class="w-full text-center flex flex-col items-center gap-2">
          <div class="flex items-center justify-between w-full px-1">
            <span class="text-[10px] text-emerald-700 font-bold flex items-center gap-1">
              <span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span>
              Realtime Payment Auto-Detect
            </span>
            <span class="text-[9px] bg-slate-100 text-slate-600 px-2 py-0.5 rounded border border-slate-200 font-mono">Invoice: {{ invoiceNo }}</span>
          </div>

          <!-- Kotak QR Code Gateway -->
          <div class="bg-white p-3 rounded-xl w-52 h-52 flex flex-col items-center justify-center border border-slate-200 shadow-2xs relative overflow-hidden">
            <div v-if="gatewayStatus === 'loading'" class="flex flex-col items-center justify-center gap-2">
              <div class="w-8 h-8 border-3 border-emerald-500 border-t-transparent rounded-full animate-spin"></div>
              <span class="text-[10px] text-slate-500 font-medium">Menghubungi gateway...</span>
            </div>
            <template v-else-if="gatewayStatus === 'pending'">
              <img :src="gatewayQrUrl" alt="QRIS Gateway" class="w-44 h-44 object-contain" />
              <span class="absolute bottom-1 text-[8px] font-bold text-emerald-700 font-mono tracking-wider bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200">
                AUTO-SETTLEMENT
              </span>
            </template>
            <div v-else-if="gatewayStatus === 'settlement'" class="flex flex-col items-center justify-center gap-1.5 p-4 text-emerald-600">
              <span class="text-4xl">✓</span>
              <span class="text-xs font-black">LUNAS!</span>
              <span class="text-[10px] text-slate-500">Mencetak struk belanja...</span>
            </div>
            <div v-else class="flex flex-col items-center justify-center gap-2 p-3">
              <span class="text-xl">⚠️</span>
              <span class="text-[10px] text-rose-600 font-bold leading-tight">{{ gatewayMessage }}</span>
              <button @click="startGatewayQris" class="text-[10px] px-2 py-1 bg-slate-100 hover:bg-slate-200 rounded border border-slate-200">🔄 Coba Lagi</button>
            </div>
          </div>

          <!-- Status Banner Gateway -->
          <div 
            :class="gatewayStatus === 'settlement' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-slate-50 border-slate-200 text-slate-700'"
            class="border rounded-xl px-3 py-1.5 w-full flex items-center justify-between"
          >
            <div class="text-left flex items-center gap-2">
              <span v-if="gatewayStatus === 'pending'" class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
              <span class="text-[10px] font-medium">{{ gatewayMessage }}</span>
            </div>
            <span class="font-mono font-black text-emerald-600 text-base">Rp {{ grandTotal.toLocaleString('id-ID') }}</span>
          </div>

          <!-- Tombol Aksi Gateway -->
          <div class="grid grid-cols-2 gap-2 w-full pt-1 border-t border-slate-200">
            <button @click="closeQrisModal" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-xl font-bold border border-slate-200 transition cursor-pointer">Batal</button>
            <button 
              @click="simulateGatewaySuccess" 
              class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs py-2.5 rounded-xl font-bold border border-emerald-600 flex items-center justify-center gap-1.5 transition cursor-pointer shadow-xs"
              title="Klik untuk memverifikasi pembayaran secara manual / simulasi lunas"
            >
              <span>⚡</span> Konfirmasi Lunas
            </button>
          </div>
        </div>

      </div>
    </div>

    <!-- 8B. DIALOG LAYANAN MURNI TARIK TUNAI QRIS (CASH OUT) -->
    <div v-if="showWithdrawalModal" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-xl flex flex-col shadow-2xl p-5 gap-3.5 border border-slate-200 max-h-[92vh] overflow-y-auto">
        <!-- Header Dialog -->
        <div class="flex justify-between items-center border-b border-slate-200 pb-2.5 shrink-0">
          <div class="flex items-center gap-2.5">
            <span class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-700 flex items-center justify-center text-lg border border-indigo-200 font-bold shadow-2xs">🏧</span>
            <div>
              <h3 class="font-bold text-xs text-slate-900 leading-tight uppercase tracking-wide">Layanan Murni Tarik Tunai QRIS</h3>
              <p class="text-[10px] text-slate-500">Pelanggan scan QRIS, kasir serahkan uang fisik dari laci tanpa memotong stok barang</p>
            </div>
          </div>
          <button @click="showWithdrawalModal = false" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-center">
          <!-- KIRI: Barcode QRIS Nominal Total (Penarikan + Admin Fee) -->
          <div class="md:col-span-5 flex flex-col items-center justify-center bg-slate-50 border border-slate-200 rounded-2xl p-3 gap-2">
            <div class="bg-white p-2 rounded-xl w-44 h-44 flex items-center justify-center border border-slate-200 shadow-2xs">
              <img :src="withdrawalEmvcoQrUrl" alt="QRIS Tarik Tunai" class="w-40 h-40 object-contain" />
            </div>
            <div class="text-center">
              <span class="text-[9px] text-slate-500 uppercase font-bold block">Total Scan QRIS Pembeli:</span>
              <span class="font-mono font-black text-indigo-700 text-lg">Rp {{ totalQrisWithdrawal.toLocaleString('id-ID') }}</span>
            </div>
          </div>

          <!-- KANAN: Input Nominal Penarikan & Biaya Admin -->
          <div class="md:col-span-7 flex flex-col gap-3">
            <div>
              <label class="text-[10px] text-slate-600 font-bold uppercase tracking-wider block mb-1">1. Nominal Uang Ditarik (Keluar Laci):</label>
              <RupiahInput
                v-model="withdrawalAmount"
                prefix="Rp"
                input-class="w-full bg-white border-2 border-indigo-200 focus:border-indigo-600 rounded-xl pl-9 pr-3 py-2 text-base font-mono font-black text-slate-900 focus:outline-none transition"
              />
              <!-- Chips Nominal Cepat -->
              <div class="flex flex-wrap gap-1 mt-1.5">
                <button v-for="amt in [50000, 100000, 150000, 200000, 300000, 500000]" :key="amt" type="button" @click="withdrawalAmount = amt" :class="withdrawalAmount === amt ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-slate-100 text-slate-700 border-slate-200 hover:bg-slate-200'" class="px-2 py-1 rounded-lg text-[10px] font-mono font-bold border transition cursor-pointer">
                  {{ (amt / 1000) }}rb
                </button>
              </div>
            </div>

            <div>
              <div class="flex items-center justify-between mb-1">
                <label class="text-[10px] text-slate-600 font-bold uppercase tracking-wider">2. Biaya Admin Jasa Toko:</label>
                <button type="button" @click="withdrawalAdminFee = 0" class="text-[9px] text-blue-600 hover:underline font-bold cursor-pointer">Set Gratis (Rp 0)</button>
              </div>
              <RupiahInput
                v-model="withdrawalAdminFee"
                prefix="Rp"
                input-class="w-full bg-slate-50 border border-slate-200 focus:bg-white focus:border-indigo-500 rounded-xl pl-9 pr-3 py-1.5 text-xs font-mono font-black text-slate-900 focus:outline-none transition"
              />
            </div>

            <!-- Ringkasan Dampak Laci & Saldo -->
            <div class="bg-emerald-50/70 border border-emerald-200 rounded-xl p-2.5 flex flex-col gap-1 text-[11px]">
              <div class="flex justify-between items-center text-slate-700">
                <span>Uang Masuk QRIS Bank:</span>
                <span class="font-mono font-bold text-blue-700">+ Rp {{ totalQrisWithdrawal.toLocaleString('id-ID') }}</span>
              </div>
              <div class="flex justify-between items-center text-slate-700">
                <span>Uang Fisik Diserahkan (Laci):</span>
                <span class="font-mono font-bold text-rose-700">- Rp {{ Number(withdrawalAmount || 0).toLocaleString('id-ID') }}</span>
              </div>
              <div class="flex justify-between items-center pt-1 border-t border-emerald-200/80 font-bold text-emerald-900">
                <span>Keuntungan Jasa Toko:</span>
                <span class="font-mono font-black text-emerald-700">+ Rp {{ Number(withdrawalAdminFee || 0).toLocaleString('id-ID') }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Tombol Aksi Modal Tarik Tunai -->
        <div class="grid grid-cols-3 gap-2 pt-2 border-t border-slate-200 shrink-0">
          <button type="button" @click="showWithdrawalModal = false" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-xl font-bold border border-slate-200 transition cursor-pointer">Batal</button>
          <button 
            type="button" 
            @click="executeWithdrawal(false)" 
            :disabled="!withdrawalAmount || withdrawalAmount <= 0"
            class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs py-2.5 rounded-xl font-bold border border-indigo-600 transition cursor-pointer shadow-xs disabled:opacity-40"
          >
            ✓ Selesai (Tanpa Struk)
          </button>
          <button 
            type="button" 
            @click="executeWithdrawal(true)" 
            :disabled="!withdrawalAmount || withdrawalAmount <= 0"
            class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs py-2.5 rounded-xl font-bold border border-emerald-600 flex items-center justify-center gap-1.5 transition cursor-pointer shadow-xs disabled:opacity-40"
          >
            <span>🖨️</span> Selesai & Cetak Bukti
          </button>
        </div>
      </div>
    </div>

    <!-- 8C. DIALOG LAYANAN TOP-UP / TRANSFER / PPOB (CASH IN) -->
    <div v-if="showTopUpModal" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-xl flex flex-col shadow-2xl p-5 gap-3.5 border border-slate-200 max-h-[92vh] overflow-y-auto">
        <!-- Header Dialog -->
        <div class="flex justify-between items-center border-b border-slate-200 pb-2.5 shrink-0">
          <div class="flex items-center gap-2.5">
            <span class="w-9 h-9 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center text-lg border border-teal-200 font-bold shadow-2xs">⚡</span>
            <div>
              <h3 class="font-bold text-xs text-slate-900 leading-tight uppercase tracking-wide">Layanan Top-Up, Transfer & PPOB</h3>
              <p class="text-[10px] text-slate-500">Catat penjualan saldo e-Wallet, Transfer Bank, Pulsa & Token PLN tanpa memotong stok barang</p>
            </div>
          </div>
          <button @click="showTopUpModal = false" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
        </div>

        <!-- Jenis Layanan (Quick Pills) -->
        <div>
          <label class="text-[10px] text-slate-600 font-bold uppercase tracking-wider block mb-1.5">1. Pilih Jenis Layanan:</label>
          <div class="flex flex-wrap gap-1.5">
            <button 
              v-for="svc in [
                { id: 'DANA', l: '💙 DANA' },
                { id: 'GoPay', l: '💚 GoPay' },
                { id: 'OVO', l: '💜 OVO' },
                { id: 'ShopeePay', l: '🧡 ShopeePay' },
                { id: 'Transfer Bank', l: '🏦 Transfer Bank' },
                { id: 'Pulsa / Data', l: '📱 Pulsa / Data' },
                { id: 'Token PLN', l: '⚡ Token PLN' }
              ]" 
              :key="svc.id" 
              type="button" 
              @click="topUpService = svc.id"
              :class="topUpService === svc.id ? 'bg-teal-600 text-white border-teal-600 shadow-2xs' : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100'"
              class="px-2.5 py-1.5 rounded-xl text-[11px] font-bold border transition cursor-pointer"
            >
              {{ svc.l }}
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-12 gap-3.5 items-start">
          <!-- KIRI: Input Nomor, Nominal Saldo & Biaya Admin -->
          <div class="md:col-span-7 flex flex-col gap-2.5">
            <div>
              <label class="text-[10px] text-slate-600 font-bold uppercase tracking-wider block mb-1">2. Nomor Tujuan / Rekening / ID Meter (Opsional):</label>
              <input 
                type="tel" 
                inputmode="tel"
                v-model="topUpTarget"
                placeholder="Contoh: 0812-3456-7890 atau BCA 123xxx"
                class="w-full bg-slate-50 border border-slate-200 focus:bg-white focus:border-teal-500 rounded-xl px-3 py-1.5 text-xs font-mono text-slate-900 focus:outline-none transition"
              />
            </div>

            <div>
              <label class="text-[10px] text-slate-600 font-bold uppercase tracking-wider block mb-1">3. Nominal Saldo / Modal Terpakai (Rp):</label>
              <RupiahInput
                v-model="topUpCapital"
                prefix="Rp"
                input-class="w-full bg-white border-2 border-teal-200 focus:border-teal-600 rounded-xl pl-9 pr-3 py-1.5 text-base font-mono font-black text-slate-900 focus:outline-none transition"
              />
              <div class="flex flex-wrap gap-1 mt-1.5">
                <button v-for="amt in [10000, 20000, 25000, 50000, 100000, 200000, 500000]" :key="amt" type="button" @click="topUpCapital = amt" :class="topUpCapital === amt ? 'bg-teal-600 text-white border-teal-600' : 'bg-slate-100 text-slate-700 border-slate-200 hover:bg-slate-200'" class="px-2 py-0.5 rounded-lg text-[10px] font-mono font-bold border transition cursor-pointer">
                  {{ (amt / 1000) }}rb
                </button>
              </div>
            </div>

            <div>
              <div class="flex items-center justify-between mb-1">
                <label class="text-[10px] text-slate-600 font-bold uppercase tracking-wider">4. Biaya Admin / Margin Jasa Toko (Rp):</label>
                <div class="flex items-center gap-1.5">
                  <button v-for="f in [0, 2000, 3000, 5000]" :key="f" type="button" @click="topUpAdminFee = f" class="text-[9px] text-teal-700 hover:underline font-bold cursor-pointer">
                    {{ f === 0 ? 'Gratis' : (f/1000) + 'rb' }}
                  </button>
                </div>
              </div>
              <RupiahInput
                v-model="topUpAdminFee"
                prefix="Rp"
                input-class="w-full bg-slate-50 border border-slate-200 focus:bg-white focus:border-teal-500 rounded-xl pl-9 pr-3 py-1.5 text-xs font-mono font-black text-slate-900 focus:outline-none transition"
              />
            </div>
          </div>

          <!-- KANAN: Metode Bayar Pembeli & Kalkulasi -->
          <div class="md:col-span-5 flex flex-col gap-2.5 bg-slate-50 border border-slate-200 rounded-2xl p-3">
            <div class="text-center border-b border-slate-200 pb-2">
              <span class="text-[9px] text-slate-500 uppercase font-bold block">Total Tagihan Pembeli:</span>
              <span class="font-mono font-black text-teal-700 text-xl">Rp {{ topUpTotalDue.toLocaleString('id-ID') }}</span>
            </div>

            <div>
              <label class="text-[9px] text-slate-500 font-bold uppercase block mb-1">Metode Bayar Pembeli:</label>
              <div class="grid grid-cols-3 gap-1">
                <button type="button" @click="topUpPaymentMethod = 'TUNAI'" :class="topUpPaymentMethod === 'TUNAI' ? 'bg-emerald-600 text-white font-bold shadow-2xs' : 'bg-white text-slate-700 border border-slate-200'" class="py-1.5 rounded-lg text-[10px] transition cursor-pointer">💵 Tunai</button>
                <button type="button" @click="topUpPaymentMethod = 'QRIS'" :class="topUpPaymentMethod === 'QRIS' ? 'bg-blue-600 text-white font-bold shadow-2xs' : 'bg-white text-slate-700 border border-slate-200'" class="py-1.5 rounded-lg text-[10px] transition cursor-pointer">📱 QRIS</button>
                <button type="button" @click="topUpPaymentMethod = 'KASBON'" :class="topUpPaymentMethod === 'KASBON' ? 'bg-amber-600 text-white font-bold shadow-2xs' : 'bg-white text-slate-700 border border-slate-200'" class="py-1.5 rounded-lg text-[10px] transition cursor-pointer">📝 Kasbon</button>
              </div>
            </div>

            <!-- Jika bayar TUNAI: Input Uang Diterima & Kembalian -->
            <div v-if="topUpPaymentMethod === 'TUNAI'" class="flex flex-col gap-1.5">
              <label class="text-[9px] text-slate-500 font-bold uppercase block">Uang Tunai Diterima (Rp):</label>
              <RupiahInput
                :model-value="topUpCashPaid"
                @update:model-value="(val) => { topUpCashManualEdited = true; topUpCashPaid = val; }"
                prefix="Rp"
                input-class="w-full bg-white border border-emerald-300 focus:border-emerald-600 rounded-lg pl-8 pr-2.5 py-1.5 text-sm font-mono font-black text-slate-900 focus:outline-none"
              />
              <div class="flex flex-wrap gap-1">
                <button type="button" @click="setTopUpCashQuick(topUpTotalDue)" class="px-1.5 py-0.5 bg-emerald-100 text-emerald-800 rounded text-[9px] font-bold cursor-pointer">Pas</button>
                <button v-for="c in [50000, 100000, 200000]" :key="c" v-show="c >= topUpTotalDue" type="button" @click="setTopUpCashQuick(c)" class="px-1.5 py-0.5 bg-white border border-slate-200 text-slate-700 rounded text-[9px] font-mono font-bold cursor-pointer">{{ c/1000 }}rb</button>
              </div>
              <div class="bg-emerald-100/70 border border-emerald-200 rounded-lg px-2.5 py-1.5 flex justify-between items-center text-[11px]">
                <span class="font-bold text-emerald-950">Kembalian:</span>
                <span class="font-mono font-black text-emerald-700">Rp {{ topUpChange.toLocaleString('id-ID') }}</span>
              </div>
            </div>

            <!-- Jika bayar QRIS: Barcode QRIS Dinamis -->
            <div v-else-if="topUpPaymentMethod === 'QRIS'" class="flex flex-col items-center gap-1.5 pt-1">
              <div class="bg-white p-1.5 rounded-xl border border-slate-200 w-32 h-32 flex items-center justify-center">
                <img :src="topUpEmvcoQrUrl" alt="QRIS Top Up" class="w-28 h-28 object-contain" />
              </div>
              <span class="text-[9px] text-blue-700 font-bold text-center">Scan QRIS senilai Rp {{ topUpTotalDue.toLocaleString('id-ID') }}</span>
            </div>

            <!-- Jika bayar KASBON -->
            <div v-else class="bg-amber-50 border border-amber-200 rounded-xl p-2 text-[10px] text-amber-900 leading-snug">
              <strong class="block mb-0.5">Pelanggan Kasbon:</strong>
              <span>{{ (selectedCustomer && selectedCustomer.id !== 1) ? selectedCustomer.name : '⚠️ Pilih pelanggan di layar kasir dulu!' }}</span>
            </div>

            <!-- Ringkasan Dampak Laba -->
            <div class="bg-teal-50 border border-teal-200 rounded-lg px-2.5 py-1.5 flex justify-between items-center text-[10px] mt-auto">
              <span class="font-bold text-teal-900">Laba Bersih Jasa:</span>
              <span class="font-mono font-black text-teal-700">+ Rp {{ Number(topUpAdminFee || 0).toLocaleString('id-ID') }}</span>
            </div>
          </div>
        </div>

        <!-- Tombol Aksi Modal Top-Up -->
        <div class="grid grid-cols-3 gap-2 pt-2 border-t border-slate-200 shrink-0">
          <button type="button" @click="showTopUpModal = false" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-xl font-bold border border-slate-200 transition cursor-pointer">Batal</button>
          <button 
            type="button" 
            @click="executeTopUp(false)" 
            :disabled="!topUpCapital || topUpCapital <= 0"
            class="bg-teal-600 hover:bg-teal-700 text-white text-xs py-2.5 rounded-xl font-bold border border-teal-600 transition cursor-pointer shadow-xs disabled:opacity-40"
          >
            ✓ Selesai (Tanpa Struk)
          </button>
          <button 
            type="button" 
            @click="executeTopUp(true)" 
            :disabled="!topUpCapital || topUpCapital <= 0"
            class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs py-2.5 rounded-xl font-bold border border-emerald-600 flex items-center justify-center gap-1.5 transition cursor-pointer shadow-xs disabled:opacity-40"
          >
            <span>🖨️</span> Selesai & Cetak Bukti
          </button>
        </div>
      </div>
    </div>

    <!-- MODAL PENGATURAN QRIS TOKO STATIS / STRING ASPI -->
    <div v-if="showQrisConfigModal" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-[70] flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-md flex flex-col shadow-2xl p-5 gap-3.5 border border-slate-200">
        <div class="flex justify-between items-center border-b border-slate-200 pb-2.5">
          <div class="flex items-center gap-2">
            <span class="text-lg p-1.5 bg-blue-50 rounded-xl border border-blue-200">⚙️</span>
            <div>
              <h3 class="font-bold text-xs text-slate-900 leading-tight">Pengaturan String QRIS Toko</h3>
              <p class="text-[10px] text-slate-500">Salin teks QRIS dari stiker bank atau merchant Anda</p>
            </div>
          </div>
          <button @click="showQrisConfigModal = false" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
        </div>

        <div class="flex flex-col gap-2">
          <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block">String Payload QRIS Statis Toko (EMVCo):</label>
          <textarea 
            v-model="tempQrisPayloadInput" 
            rows="4" 
            placeholder="Contoh: 00020101021126630014ID.GO.IDC.WWW..." 
            class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-xs font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-100 focus:bg-white focus:border-blue-500 transition"
          ></textarea>
          <p class="text-[10px] text-slate-400 leading-relaxed">
            💡 <strong>Cara mendapatkan:</strong> Buka aplikasi pemindai barcode / kamera HP Anda, scan stiker QRIS toko Anda, lalu salin teks panjangnya dan tempel di sini. Sistem akan otomatis mengisi nominal belanjaan ke dalam QR tersebut.
          </p>
        </div>

        <div class="flex gap-2 pt-2 border-t border-slate-200">
          <button 
            type="button" 
            @click="tempQrisPayloadInput = ''" 
            class="bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs py-2.5 px-3 rounded-xl font-bold border border-slate-200 transition cursor-pointer"
          >
            Reset Bawaan
          </button>
          <button @click="showQrisConfigModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-xl font-bold border border-slate-200 transition cursor-pointer">Batal</button>
          <button @click="saveQrisConfig" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-xs py-2.5 rounded-xl font-bold border border-blue-600 transition cursor-pointer shadow-xs">Simpan QRIS</button>
        </div>
      </div>
    </div>

    <!-- 9. ANDROID DIALOG 9: PREVIEW CETAK STRUK THERMAL 80MM / 58MM (TRUE FLAT) -->
    <div id="receipt-modal" v-if="showReceiptModal && lastTransaction" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-sm flex flex-col shadow-2xl p-5 gap-3.5 max-h-[90vh] border border-slate-200">
        <div class="flex justify-between items-center border-b border-slate-200 pb-2">
          <h3 class="font-bold text-xs text-slate-900 flex items-center gap-1.5">🖨️ Struk Belanja Pelanggan</h3>
          <button @click="showReceiptModal = false" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
        </div>

        <div
          id="printable-receipt"
          :class="settingsForm.receipt_paper_size === '58mm' ? 'max-w-[58mm] mx-auto text-[11px] p-3' : 'p-4 text-xs'"
          class="thermal-receipt rounded-xl overflow-y-auto max-h-[440px] border border-slate-200 select-text font-mono shadow-inner bg-[#fffdfa]"
        >
          <div class="text-center font-bold text-sm tracking-widest uppercase">{{ settingsForm.store_name || 'ROZAK MART' }}</div>
          <div class="text-center text-[10px] leading-tight mt-0.5 text-gray-600">
            {{ settingsForm.store_address || 'Jl. Melati No. 12, Kel. Sukamaju' }}<br>
            Telp: {{ settingsForm.store_phone || '0812-3456-7890' }}
          </div>
          <div class="border-b border-dashed border-gray-400 my-2"></div>
          
          <div class="text-[9px] flex justify-between text-gray-600">
            <span>{{ lastTransaction.created_at }}</span>
            <span>Kasir: {{ lastTransaction.cashier_name }}</span>
          </div>
          <div class="text-[9px] flex justify-between text-gray-600">
            <span>No: {{ lastTransaction.invoice_no }}</span>
            <span>Cust: {{ lastTransaction.customer_name }}</span>
          </div>

          <div v-if="lastTransaction.transaction_type === 'CASH_WITHDRAWAL'" class="text-center font-bold text-[11px] bg-slate-100 py-1 rounded my-1.5 tracking-wide">
            *** BUKTI TARIK TUNAI QRIS ***
          </div>
          <div v-else-if="lastTransaction.transaction_type === 'TOP_UP'" class="text-center font-bold text-[11px] bg-teal-50 text-teal-900 py-1 rounded my-1.5 tracking-wide">
            *** BUKTI TOP-UP & TRANSFER ***
          </div>

          <div class="border-b border-dashed border-gray-400 my-2"></div>

          <!-- Items -->
          <div class="flex flex-col gap-1 text-[10px]">
            <div v-for="it in lastTransaction.items" :key="it.product_name" class="flex justify-between">
              <div class="truncate pr-2">
                <div>{{ it.product_name }}</div>
                <div class="text-[9px] text-gray-500">{{ it.qty }} x Rp {{ Number(it.unit_price).toLocaleString('id-ID') }}</div>
              </div>
              <span class="font-bold">Rp {{ Number(it.total).toLocaleString('id-ID') }}</span>
            </div>
          </div>

          <div class="border-b border-dashed border-gray-400 my-2"></div>

          <!-- Summary untuk Murni Tarik Tunai QRIS -->
          <div v-if="lastTransaction.transaction_type === 'CASH_WITHDRAWAL'" class="flex flex-col gap-0.5 text-[10px]">
            <div class="flex justify-between">
              <span>Nominal Tarik Tunai</span>
              <span>Rp {{ Number(lastTransaction.change_amount).toLocaleString('id-ID') }}</span>
            </div>
            <div class="flex justify-between text-gray-600">
              <span>Biaya Admin Jasa</span>
              <span>Rp {{ Number(lastTransaction.admin_fee || 0).toLocaleString('id-ID') }}</span>
            </div>
            <div class="flex justify-between font-bold text-xs pt-1 border-t border-gray-300 mt-1">
              <span>TOTAL BAYAR QRIS</span>
              <span>Rp {{ Number(lastTransaction.paid_amount).toLocaleString('id-ID') }}</span>
            </div>
            <div class="flex justify-between pt-1 font-bold text-emerald-800 bg-emerald-50 px-1 rounded">
              <span>Uang Fisik Diserahkan</span>
              <span>Rp {{ Number(lastTransaction.change_amount).toLocaleString('id-ID') }}</span>
            </div>
          </div>

          <!-- Summary untuk Top-Up / Transfer / PPOB -->
          <div v-else-if="lastTransaction.transaction_type === 'TOP_UP'" class="flex flex-col gap-0.5 text-[10px]">
            <div class="flex justify-between">
              <span>Nominal Saldo Top-Up</span>
              <span>Rp {{ Number(lastTransaction.subtotal).toLocaleString('id-ID') }}</span>
            </div>
            <div class="flex justify-between text-gray-600">
              <span>Biaya Admin Jasa</span>
              <span>Rp {{ Number(lastTransaction.admin_fee || 0).toLocaleString('id-ID') }}</span>
            </div>
            <div class="flex justify-between font-bold text-xs pt-1 border-t border-gray-300 mt-1">
              <span>TOTAL BAYAR</span>
              <span>Rp {{ Number(lastTransaction.grand_total).toLocaleString('id-ID') }}</span>
            </div>
            <div class="flex justify-between pt-1">
              <span>Bayar ({{ lastTransaction.payment_method }})</span>
              <span>Rp {{ Number(lastTransaction.paid_amount).toLocaleString('id-ID') }}</span>
            </div>
            <div class="flex justify-between">
              <span>Kembali</span>
              <span>Rp {{ Number(lastTransaction.change_amount).toLocaleString('id-ID') }}</span>
            </div>
          </div>

          <!-- Summary untuk Transaksi Belanja Normal / Overpayment QRIS -->
          <div v-else class="flex flex-col gap-0.5 text-[10px]">
            <div class="flex justify-between">
              <span>Subtotal</span>
              <span>Rp {{ Number(lastTransaction.subtotal).toLocaleString('id-ID') }}</span>
            </div>
            <div v-if="lastTransaction.global_discount > 0" class="flex justify-between text-amber-700">
              <span>Diskon Nota ({{ lastTransaction.global_discount_note || 'Promo' }})</span>
              <span>- Rp {{ Number(lastTransaction.global_discount).toLocaleString('id-ID') }}</span>
            </div>
            <div class="flex justify-between font-bold text-xs pt-1 border-t border-gray-300 mt-1">
              <span>TOTAL BELANJA</span>
              <span>Rp {{ Number(lastTransaction.grand_total).toLocaleString('id-ID') }}</span>
            </div>
            <div v-if="lastTransaction.admin_fee > 0" class="flex justify-between text-gray-600">
              <span>Biaya Admin Jasa</span>
              <span>Rp {{ Number(lastTransaction.admin_fee).toLocaleString('id-ID') }}</span>
            </div>
            <div class="flex justify-between pt-1">
              <span>Bayar ({{ lastTransaction.payment_method }})</span>
              <span>Rp {{ Number(lastTransaction.paid_amount).toLocaleString('id-ID') }}</span>
            </div>
            <div 
              class="flex justify-between" 
              :class="lastTransaction.payment_method === 'QRIS' && lastTransaction.change_amount > 0 ? 'font-bold text-emerald-800 bg-emerald-50 px-1 rounded mt-0.5' : ''"
            >
              <span>{{ lastTransaction.payment_method === 'QRIS' && lastTransaction.change_amount > 0 ? 'Kembalian Tunai (Kasir)' : 'Kembali' }}</span>
              <span>Rp {{ Number(lastTransaction.change_amount).toLocaleString('id-ID') }}</span>
            </div>
          </div>

          <div class="border-b border-dashed border-gray-400 my-2"></div>

          <div class="text-center text-[9px] leading-tight text-gray-600 whitespace-pre-line">
            {{ settingsForm.receipt_footer || 'Terima Kasih Atas Kunjungan Anda!\nBarang yang sudah dibeli tidak dapat ditukar.' }}
          </div>
        </div>

        <div class="flex gap-2 pt-1 border-t border-slate-200">
          <button @click="showReceiptModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-xl font-bold border border-slate-200 transition cursor-pointer">Tutup</button>
          <button @click="triggerPrintReceipt" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-xs py-2.5 rounded-xl font-bold border border-blue-600 flex items-center justify-center gap-1.5 transition cursor-pointer shadow-xs">
            <span>🖨️</span> Cetak Struk
          </button>
        </div>
      </div>
    </div>


    <!-- ================================================================
         10. MASTER SETTINGS MODAL (MODERN POS MULTI-TAB)
         Tab 1: Profil Toko (Nama, Alamat, Telp, Struk Footer)
         Tab 2: QRIS & Pembayaran (Mode Switch, String EMVCo, Midtrans)
         Tab 3: Printer Struk (Ukuran Kertas 58mm/80mm, Auto-Print)
         Tab 4: Saklar Fitur (Stok, Shift Kasir, Laba HPP)
         Tab 5: Database & Offline Dexie (Sync Status, PWA)
    ================================================================= -->
    <div v-if="showSettingsModal" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-[80] flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-2xl h-[560px] max-h-[90vh] flex flex-col shadow-2xl border border-slate-200 overflow-hidden">
        
        <!-- Header Dialog Pengaturan -->
        <div class="bg-white px-5 py-3.5 border-b border-slate-200 flex justify-between items-center shrink-0">
          <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 border border-blue-200 flex items-center justify-center text-sm font-bold shadow-xs">
              ⚙️
            </div>
            <div>
              <h3 class="font-bold text-sm text-slate-900 leading-tight">Pengaturan Sistem {{ storeName }}</h3>
              <p class="text-[10px] text-slate-500">
                {{ props.authCashier?.is_admin ? 'Sesuaikan profil toko, QRIS, printer struk, dan saklar fitur' : 'Sesuaikan printer struk dan status offline perangkat' }}
              </p>
            </div>
          </div>
          <button @click="showSettingsModal = false" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
        </div>

        <!-- Body: Split Sidebar Tab (Kiri) + Form Isi (Kanan) -->
        <div class="flex-1 flex overflow-hidden">
          
          <!-- Sidebar Navigasi Tab Kiri (28%) -->
          <div class="w-[28%] bg-slate-50 border-r border-slate-200 p-2.5 flex flex-col gap-1 shrink-0 overflow-y-auto">
            <button 
              v-if="props.authCashier?.is_admin"
              type="button" 
              @click="activeSettingsTab = 'profile'"
              :class="activeSettingsTab === 'profile' ? 'bg-white text-blue-600 font-bold shadow-2xs border border-slate-200' : 'text-slate-600 hover:bg-slate-100 border border-transparent'"
              class="w-full text-left px-3 py-2.5 rounded-xl text-xs flex items-center gap-2 transition cursor-pointer"
            >
              <span>🏬</span>
              <span>Profil Toko</span>
            </button>
            <button 
              v-if="props.authCashier?.is_admin"
              type="button" 
              @click="activeSettingsTab = 'payment'"
              :class="activeSettingsTab === 'payment' ? 'bg-white text-blue-600 font-bold shadow-2xs border border-slate-200' : 'text-slate-600 hover:bg-slate-100 border border-transparent'"
              class="w-full text-left px-3 py-2.5 rounded-xl text-xs flex items-center gap-2 transition cursor-pointer"
            >
              <span>💳</span>
              <span>QRIS & Bayar</span>
            </button>
            <button 
              type="button" 
              @click="activeSettingsTab = 'printer'"
              :class="activeSettingsTab === 'printer' ? 'bg-white text-blue-600 font-bold shadow-2xs border border-slate-200' : 'text-slate-600 hover:bg-slate-100 border border-transparent'"
              class="w-full text-left px-3 py-2.5 rounded-xl text-xs flex items-center gap-2 transition cursor-pointer"
            >
              <span>🖨️</span>
              <span>Printer Struk</span>
            </button>
            <button 
              v-if="props.authCashier?.is_admin"
              type="button" 
              @click="activeSettingsTab = 'features'"
              :class="activeSettingsTab === 'features' ? 'bg-white text-blue-600 font-bold shadow-2xs border border-slate-200' : 'text-slate-600 hover:bg-slate-100 border border-transparent'"
              class="w-full text-left px-3 py-2.5 rounded-xl text-xs flex items-center gap-2 transition cursor-pointer"
            >
              <span>🎛️</span>
              <span>Saklar Fitur</span>
            </button>
            <button 
              type="button" 
              @click="activeSettingsTab = 'data'"
              :class="activeSettingsTab === 'data' ? 'bg-white text-blue-600 font-bold shadow-2xs border border-slate-200' : 'text-slate-600 hover:bg-slate-100 border border-transparent'"
              class="w-full text-left px-3 py-2.5 rounded-xl text-xs flex items-center gap-2 transition cursor-pointer"
            >
              <span>💾</span>
              <span>Data & Offline</span>
            </button>
          </div>

          <!-- Konten Tab Sisi Kanan (72%) -->
          <div class="flex-1 p-5 overflow-y-auto">
            
            <!-- TAB 1: PROFIL TOKO (OWNER ONLY) -->
            <div v-if="activeSettingsTab === 'profile' && props.authCashier?.is_admin" class="flex flex-col gap-3.5">
              <div class="border-b border-slate-100 pb-2">
                <h4 class="font-bold text-xs text-slate-900 uppercase tracking-wider">Identitas & Kontak Toko</h4>
                <p class="text-[11px] text-slate-500">Informasi ini dicetak pada bagian atas (header) struk belanja pelanggan</p>
              </div>

              <div>
                <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-1">Nama Toko:</label>
                <input 
                  type="text" 
                  v-model="settingsForm.store_name" 
                  placeholder="Rozak Mart" 
                  class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-900 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition"
                />
              </div>

              <div>
                <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-1">Deskripsi / Slogan Toko:</label>
                <input 
                  type="text" 
                  v-model="settingsForm.store_description" 
                  placeholder="Sistem Kasir & Belanja Kebutuhan Sehari-hari" 
                  class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition"
                />
              </div>

              <div>
                <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-1">Alamat Lengkap Toko:</label>
                <input 
                  type="text" 
                  v-model="settingsForm.store_address" 
                  placeholder="Jl. Melati No. 12, Kel. Sukamaju" 
                  class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition"
                />
              </div>

              <div>
                <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-1">Nomor WhatsApp / Telepon Toko:</label>
                <input 
                  type="tel" 
                  inputmode="tel"
                  v-model="settingsForm.store_phone" 
                  placeholder="0812-3456-7890" 
                  class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-slate-800 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition"
                />
              </div>

              <div>
                <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-1">Pesan Kaki Struk (Footer):</label>
                <textarea 
                  v-model="settingsForm.receipt_footer" 
                  rows="2" 
                  placeholder="Terima Kasih Atas Kunjungan Anda!&#10;Barang yang sudah dibeli tidak dapat ditukar." 
                  class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition"
                ></textarea>
              </div>
            </div>

            <!-- TAB 2: QRIS & PEMBAYARAN (OWNER ONLY) -->
            <div v-if="activeSettingsTab === 'payment' && props.authCashier?.is_admin" class="flex flex-col gap-3.5">
              <div class="border-b border-slate-100 pb-2">
                <h4 class="font-bold text-xs text-slate-900 uppercase tracking-wider">Metode Pembayaran QRIS</h4>
                <p class="text-[11px] text-slate-500">Konfigurasi dual mode QRIS Toko & Integrasi Payment Gateway</p>
              </div>

              <div>
                <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-1.5">Pilihan Mode Default Saat Membuka QRIS:</label>
                <div class="grid grid-cols-2 gap-2">
                  <button 
                    type="button" 
                    @click="settingsForm.qris_mode = 'emvco'"
                    :class="settingsForm.qris_mode === 'emvco' ? 'border-2 border-blue-600 bg-blue-50/50 text-blue-900 font-bold shadow-2xs' : 'border border-slate-200 bg-slate-50 text-slate-700'"
                    class="p-2.5 rounded-xl text-left transition cursor-pointer"
                  >
                    <div class="text-xs flex items-center gap-1.5 mb-0.5">
                      <span>🏷️</span>
                      <strong>QRIS Toko Mandiri</strong>
                    </div>
                    <p class="text-[10px] text-slate-500 leading-tight">Nominal belanjaan terkunci otomatis, tanpa potongan gateway.</p>
                  </button>

                  <button 
                    type="button" 
                    @click="settingsForm.qris_mode = 'gateway'"
                    :class="settingsForm.qris_mode === 'gateway' ? 'border-2 border-emerald-600 bg-emerald-50/50 text-emerald-900 font-bold shadow-2xs' : 'border border-slate-200 bg-slate-50 text-slate-700'"
                    class="p-2.5 rounded-xl text-left transition cursor-pointer"
                  >
                    <div class="text-xs flex items-center gap-1.5 mb-0.5">
                      <span>⚡</span>
                      <strong>QRIS Gateway</strong>
                    </div>
                    <p class="text-[10px] text-slate-500 leading-tight">Realtime auto-detect lunas & langsung cetak otomatis.</p>
                  </button>
                </div>
              </div>

              <div>
                <div class="flex justify-between items-center mb-1">
                  <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">String Payload QRIS Statis Toko (EMVCo):</label>
                  <button 
                    type="button" 
                    @click="settingsForm.qris_static_payload = ''" 
                    class="text-[9px] text-blue-600 hover:underline cursor-pointer"
                  >
                    Reset Bawaan Rozak Mart
                  </button>
                </div>
                <textarea 
                  v-model="settingsForm.qris_static_payload" 
                  rows="3" 
                  placeholder="00020101021126630014ID.GO.IDC.WWW..." 
                  class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-xs font-mono text-slate-800 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition"
                ></textarea>
                <p class="text-[10px] text-slate-400 mt-0.5">
                  Scan stiker QRIS toko Anda menggunakan kamera HP, lalu salin teks panjangnya ke sini.
                </p>
              </div>

              <div class="p-3 bg-indigo-50/70 border border-indigo-200 rounded-xl flex items-center justify-between gap-3">
                <div>
                  <span class="text-xs font-bold text-indigo-950 flex items-center gap-1.5">
                    <span>🏧</span> Biaya Admin Default Tarik Tunai QRIS (Rp)
                  </span>
                  <p class="text-[10px] text-indigo-700 leading-tight mt-0.5">Nilai standar biaya jasa tarik tunai di layar kasir (tetap bisa diedit manual oleh kasir).</p>
                </div>
                <div class="w-32 shrink-0">
                  <RupiahInput
                    v-model="settingsForm.qris_withdrawal_admin_fee"
                    prefix="Rp"
                    input-class="w-full bg-white border border-indigo-300 rounded-lg pl-8 pr-2.5 py-1.5 text-xs font-mono font-black text-indigo-900 text-right"
                  />
                </div>
              </div>

              <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 flex flex-col gap-2">
                <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                  <span>⚡</span> Pengaturan Gateway (Midtrans)
                </span>
                <div>
                  <label class="text-[10px] text-slate-500 font-bold block mb-0.5">Midtrans Server Key (Opsional):</label>
                  <input 
                    type="password" 
                    v-model="settingsForm.midtrans_server_key" 
                    placeholder="SB-Mid-server-xxxxxxxxxxxx" 
                    class="w-full bg-white border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-mono text-slate-800"
                  />
                </div>
                <label class="flex items-center gap-2 cursor-pointer pt-0.5">
                  <input type="checkbox" v-model="settingsForm.midtrans_is_production" class="w-4 h-4 text-emerald-600 rounded" />
                  <span class="text-xs text-slate-700 font-medium">Aktifkan Mode Produksi / Live (Bukan Sandbox)</span>
                </label>
              </div>
            </div>

            <!-- TAB 3: PRINTER & STRUK -->
            <div v-if="activeSettingsTab === 'printer'" class="flex flex-col gap-3.5">
              <div class="border-b border-slate-100 pb-2">
                <h4 class="font-bold text-xs text-slate-900 uppercase tracking-wider">Format Printer Thermal</h4>
                <p class="text-[11px] text-slate-500">Sesuaikan lebar kertas struk dan opsi cetak otomatis</p>
              </div>

              <div>
                <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-1.5">Lebar Kertas Thermal:</label>
                <div class="grid grid-cols-2 gap-2">
                  <button 
                    type="button" 
                    @click="settingsForm.receipt_paper_size = '80mm'"
                    :class="settingsForm.receipt_paper_size === '80mm' ? 'border-2 border-blue-600 bg-blue-50/50 text-blue-900 font-bold shadow-2xs' : 'border border-slate-200 bg-slate-50 text-slate-700'"
                    class="p-3 rounded-xl text-left transition cursor-pointer"
                  >
                    <div class="text-xs font-bold mb-0.5">80 mm (Standar Kasir Desktop)</div>
                    <p class="text-[10px] text-slate-500 leading-tight">Kertas lebar standar Epson, Xprinter, Matrix Point.</p>
                  </button>

                  <button 
                    type="button" 
                    @click="settingsForm.receipt_paper_size = '58mm'"
                    :class="settingsForm.receipt_paper_size === '58mm' ? 'border-2 border-blue-600 bg-blue-50/50 text-blue-900 font-bold shadow-2xs' : 'border border-slate-200 bg-slate-50 text-slate-700'"
                    class="p-3 rounded-xl text-left transition cursor-pointer"
                  >
                    <div class="text-xs font-bold mb-0.5">58 mm (Mini / Bluetooth)</div>
                    <p class="text-[10px] text-slate-500 leading-tight">Printer thermal mini portable Bluetooth Android.</p>
                  </button>
                </div>
              </div>

              <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between">
                <div>
                  <div class="text-xs font-bold text-slate-900">Cetak Struk Otomatis (Auto-Print)</div>
                  <div class="text-[10px] text-slate-500 mt-0.5">Langsung membuka dialog printer sesaat setelah kasir klik "Selesai"</div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input type="checkbox" v-model="settingsForm.receipt_auto_print" class="sr-only peer">
                  <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
              </div>
            </div>

            <!-- TAB 4: SAKLAR FITUR TOKO -->
            <div v-if="activeSettingsTab === 'features'" class="flex flex-col gap-3">
              <div class="border-b border-slate-100 pb-2">
                <h4 class="font-bold text-xs text-slate-900 uppercase tracking-wider">Saklar On/Off Fitur Kasir</h4>
                <p class="text-[11px] text-slate-500">Aktifkan atau matikan modul sistem sesuai kebutuhan operasional warung/toko</p>
              </div>

              <!-- Saklar 1: Stok Fisik -->
              <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between">
                <div class="pr-3">
                  <div class="text-xs font-bold text-slate-900 flex items-center gap-1.5">
                    <span>📦</span> Pelacakan Stok Fisik Barang
                  </div>
                  <div class="text-[10px] text-slate-500 mt-0.5 leading-relaxed">
                    Jika aktif, penjualan akan memotong stok fisik dan menampilkan peringatan jika stok habis.
                  </div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                  <input type="checkbox" v-model="settingsForm.enable_stock_tracking" class="sr-only peer">
                  <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                </label>
              </div>

              <!-- Saklar 2: Shift Kasir -->
              <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between">
                <div class="pr-3">
                  <div class="text-xs font-bold text-slate-900 flex items-center gap-1.5">
                    <span>⏱️</span> Buku Kas & Shift Kerja Karyawan
                  </div>
                  <div class="text-[10px] text-slate-500 mt-0.5 leading-relaxed">
                    Mencatat uang modal awal di laci kasir dan menghitung selisih uang saat tutup shift.
                  </div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                  <input type="checkbox" v-model="settingsForm.enable_cashier_shifts" class="sr-only peer">
                  <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                </label>
              </div>

              <!-- Saklar 3: HPP / Profit -->
              <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between">
                <div class="pr-3">
                  <div class="text-xs font-bold text-slate-900 flex items-center gap-1.5">
                    <span>📈</span> Hitung HPP & Laba Bersih
                  </div>
                  <div class="text-[10px] text-slate-500 mt-0.5 leading-relaxed">
                    Mencatat harga modal produk untuk menghasilkan laporan estimasi keuntungan toko.
                  </div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                  <input type="checkbox" v-model="settingsForm.enable_hpp_profit" class="sr-only peer">
                  <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                </label>
              </div>

              <!-- Saklar 4: Layar Kedua Pembeli (Customer Dual Display) -->
              <div class="p-3 bg-teal-50/80 rounded-xl border border-teal-200 flex flex-col gap-2">
                <div class="flex items-center justify-between">
                  <div class="pr-3">
                    <div class="text-xs font-bold text-teal-950 flex items-center gap-1.5">
                      <span>🖥️</span> Layar Kedua Pembeli (Customer Facing Display)
                    </div>
                    <div class="text-[10px] text-teal-700 mt-0.5 leading-relaxed">
                      Menampilkan barang belanjaan, total tagihan, dan kode QRIS dinamis langsung menghadap pembeli.
                    </div>
                  </div>
                  <button 
                    type="button" 
                    @click="openCustomerDisplay" 
                    class="bg-teal-600 hover:bg-teal-700 active:bg-teal-800 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition cursor-pointer shadow-2xs shrink-0"
                  >
                    Buka Layar
                  </button>
                </div>
                <div class="bg-white p-2 rounded-lg border border-teal-200 text-[10px] font-mono text-slate-600 flex items-center justify-between">
                  <span>URL Layar Nirkabel: <strong class="text-teal-800">/pos/display?code=100001</strong></span>
                  <span class="text-[9px] bg-teal-100 text-teal-800 px-1.5 py-0.5 rounded font-bold">WiFi / Secondary Monitor</span>
                </div>
              </div>

              <!-- Saklar & Manajemen Akun Kasir (Khusus Owner / Admin) -->
              <div v-if="props.authCashier?.is_admin" class="pt-2 border-t border-slate-200 flex flex-col gap-2.5">
                <span class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Hak Akses & Akun Karyawan (Khusus Owner)</span>

                <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between">
                  <div class="pr-3">
                    <div class="text-xs font-bold text-slate-900">Izinkan Kasir Kelola Produk & Stok</div>
                    <div class="text-[10px] text-slate-500 mt-0.5">Jika nonaktif, hanya Owner/Admin yang dapat menambah, mengubah, atau menghapus produk.</div>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="checkbox" v-model="settingsForm.cashier_can_manage_products" class="sr-only peer">
                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                  </label>
                </div>

                <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between">
                  <div class="pr-3">
                    <div class="text-xs font-bold text-slate-900">Izinkan Kasir Lihat Rekap Omzet & Laci</div>
                    <div class="text-[10px] text-slate-500 mt-0.5">Jika nonaktif, total omzet harian, estimasi HPP, dan ringkasan kas laci disembunyikan dari kasir.</div>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="checkbox" v-model="settingsForm.cashier_can_view_financial_summary" class="sr-only peer">
                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                  </label>
                </div>

                <div class="p-3 bg-white rounded-xl border border-slate-200 flex flex-col gap-2">
                  <div class="text-xs font-bold text-slate-900">👥 Daftar Akun Kasir & Owner ({{ props.cashiers.length }})</div>
                  <div class="flex flex-col gap-1.5 max-h-32 overflow-y-auto">
                    <div v-for="c in props.cashiers" :key="c.id" class="flex items-center justify-between px-2.5 py-1.5 bg-slate-50 rounded-lg border border-slate-200 text-xs">
                      <div class="flex items-center gap-2 min-w-0">
                        <span class="font-bold text-slate-900 truncate">{{ c.name }}</span>
                        <span :class="c.role === 'admin' ? 'bg-blue-100 text-blue-700' : 'bg-slate-200 text-slate-700'" class="text-[9px] font-bold px-1.5 py-0.5 rounded uppercase">{{ c.role === 'admin' ? 'Owner' : 'Kasir' }}</span>
                      </div>
                      <button
                        v-if="c.id !== props.authCashier?.id"
                        type="button"
                        @click="removeCashier(c)"
                        class="text-rose-600 hover:text-rose-800 text-[10px] font-bold px-2 py-0.5 rounded hover:bg-rose-50 cursor-pointer"
                      >
                        Hapus
                      </button>
                      <span v-else class="text-[9px] text-emerald-700 font-bold">Sedang Aktif</span>
                    </div>
                  </div>
                  <div class="grid grid-cols-12 gap-1.5 pt-1 border-t border-slate-100">
                    <input v-model="cashierForm.name" type="text" placeholder="Nama kasir..." class="col-span-4 bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-[11px] font-medium" />
                    <input v-model="cashierForm.password" type="password" placeholder="Password min 4..." class="col-span-4 bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-[11px] font-medium" />
                    <select v-model="cashierForm.role" class="col-span-2 bg-slate-50 border border-slate-200 rounded-lg px-1 py-1.5 text-[11px] font-bold">
                      <option value="cashier">Kasir</option>
                      <option value="admin">Owner</option>
                    </select>
                    <button type="button" :disabled="isSavingCashier" @click="saveNewCashier" class="col-span-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg text-[10px] font-bold cursor-pointer">+ Tambah</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- TAB 5: DATABASE & OFFLINE DEXIE -->
            <div v-if="activeSettingsTab === 'data'" class="flex flex-col gap-3.5">
              <div class="border-b border-slate-100 pb-2">
                <h4 class="font-bold text-xs text-slate-900 uppercase tracking-wider">Status Offline & Sinkronisasi</h4>
                <p class="text-[11px] text-slate-500">Penyimpanan IndexedDB browser & status cache aplikasi PWA</p>
              </div>

              <div class="grid grid-cols-2 gap-2">
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-200">
                  <span class="text-[10px] text-slate-500 uppercase font-bold block mb-0.5">Status Koneksi Internet:</span>
                  <div class="flex items-center gap-2">
                    <span :class="isOnline ? 'bg-emerald-500' : 'bg-rose-500'" class="w-2.5 h-2.5 rounded-full"></span>
                    <span class="text-xs font-bold text-slate-900">{{ isOnline ? 'Online (Terhubung Server)' : 'Offline (Mode Mandiri)' }}</span>
                  </div>
                </div>

                <div class="p-3 bg-slate-50 rounded-xl border border-slate-200">
                  <span class="text-[10px] text-slate-500 uppercase font-bold block mb-0.5">Versi Service Worker:</span>
                  <span class="text-xs font-mono font-bold text-slate-900">rozak-mart-pos-v83</span>
                </div>
              </div>

              <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between">
                <div>
                  <div class="text-xs font-bold text-slate-900">Antrean Transaksi Belum Ter-Sync</div>
                  <div class="text-[10px] text-slate-500 mt-0.5">{{ offlineStats.unsyncedTransactionsCount }} nota menunggu sync ({{ offlineStats.transactionsCount }} tersimpan di memori perangkat)</div>
                </div>
                <button 
                  type="button" 
                  :disabled="offlineStats.isSyncing"
                  @click="syncOfflineTransactions(false)" 
                  class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-xs px-3 py-1.5 rounded-lg font-bold shadow-xs cursor-pointer flex items-center gap-1"
                >
                  <span>🔄</span> {{ offlineStats.isSyncing ? 'Menyinkronkan...' : 'Sinkronkan' }}
                </button>
              </div>

              <div class="p-3 bg-amber-50 rounded-xl border border-amber-200 flex items-center justify-between">
                <div>
                  <div class="text-xs font-bold text-amber-900">Perbarui Cache & Muat Ulang PWA</div>
                  <div class="text-[10px] text-amber-700 mt-0.5">Paksa browser memuat tampilan dan update program terbaru</div>
                </div>
                <button 
                  type="button" 
                  @click="reloadApp" 
                  class="bg-amber-600 hover:bg-amber-700 text-white text-xs px-3 py-1.5 rounded-lg font-bold shadow-xs cursor-pointer"
                >
                  Reload
                </button>
              </div>

              <!-- Update Aplikasi di Server Hosting (Khusus Owner) -->
              <div v-if="props.authCashier?.is_admin" class="p-3.5 bg-blue-50/70 rounded-xl border border-blue-200 flex flex-col gap-2.5">
                <div class="flex items-start justify-between gap-2">
                  <div>
                    <div class="text-xs font-bold text-blue-950">Update Aplikasi Server (Branch: main)</div>
                    <div class="text-[10px] text-blue-700 mt-0.5 leading-relaxed">
                      Tarik kode & build terbaru dari Git atau terapkan migrasi + bersihkan cache Laravel setelah Pull dari cPanel.
                    </div>
                  </div>
                </div>
                <div class="flex flex-wrap gap-2">
                  <button
                    type="button"
                    :disabled="isUpdatingSystem"
                    @click="runSystemUpdate('git_pull')"
                    class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-[11px] px-3 py-1.5 rounded-lg font-bold shadow-xs cursor-pointer flex items-center gap-1"
                  >
                    <span>⬇️</span> {{ isUpdatingSystem ? 'Memproses...' : 'Pull Git (main) & Terapkan' }}
                  </button>
                  <button
                    type="button"
                    :disabled="isUpdatingSystem"
                    @click="runSystemUpdate('apply')"
                    class="bg-white hover:bg-blue-100 disabled:opacity-50 text-blue-800 border border-blue-300 text-[11px] px-3 py-1.5 rounded-lg font-bold shadow-2xs cursor-pointer flex items-center gap-1"
                  >
                    <span>⚙️</span> {{ isUpdatingSystem ? 'Memproses...' : 'Terapkan Update (Migrate & Clear Cache)' }}
                  </button>
                </div>
                <pre v-if="systemUpdateLog" class="text-[10px] font-mono bg-slate-900 text-emerald-300 p-2.5 rounded-lg overflow-x-auto max-h-28 whitespace-pre-wrap">{{ systemUpdateLog }}</pre>
              </div>

              <!-- Backup, Unggah & Restore Database (Khusus Owner) -->
              <div v-if="props.authCashier?.is_admin" class="p-3.5 bg-emerald-50/60 rounded-xl border border-emerald-200 flex flex-col gap-2.5">
                <div class="flex items-start justify-between gap-2">
                  <div>
                    <div class="text-xs font-bold text-emerald-950">Backup, Unggah & Restore Database</div>
                    <div class="text-[10px] text-emerald-700 mt-0.5 leading-relaxed">
                      Format <span class="font-mono font-bold">.json</span> kompatibel lintas SQLite & MySQL<span v-if="dbDriver"> (Server aktif: <strong class="uppercase">{{ dbDriver }}</strong>)</span>. Restore mendukung file <span class="font-mono font-bold">.json</span> atau <span class="font-mono font-bold">.sqlite</span>.
                    </div>
                  </div>
                </div>

                <input
                  ref="backupFileInputRef"
                  type="file"
                  accept=".json,.sqlite,.db"
                  class="hidden"
                  @change="handleBackupFileSelected"
                />

                <div class="flex flex-wrap gap-1.5">
                  <button
                    type="button"
                    :disabled="isBackupBusy"
                    @click="createServerBackup(false)"
                    class="bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-[11px] px-2.5 py-1.5 rounded-lg font-bold shadow-xs cursor-pointer flex items-center gap-1"
                  >
                    <span>💾</span> {{ isBackupBusy ? 'Memproses...' : 'Backup di Server' }}
                  </button>
                  <button
                    type="button"
                    :disabled="isBackupBusy"
                    @click="createServerBackup(true)"
                    class="bg-white hover:bg-emerald-100 disabled:opacity-50 text-emerald-800 border border-emerald-300 text-[11px] px-2.5 py-1.5 rounded-lg font-bold shadow-2xs cursor-pointer flex items-center gap-1"
                  >
                    <span>⬇️</span> Unduh Backup (.json)
                  </button>
                  <button
                    type="button"
                    :disabled="isBackupBusy"
                    @click="triggerBackupUpload(false)"
                    class="bg-white hover:bg-slate-100 disabled:opacity-50 text-slate-700 border border-slate-300 text-[11px] px-2.5 py-1.5 rounded-lg font-bold shadow-2xs cursor-pointer flex items-center gap-1"
                  >
                    <span>📤</span> Unggah File
                  </button>
                  <button
                    type="button"
                    :disabled="isBackupBusy"
                    @click="triggerBackupUpload(true)"
                    class="bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white text-[11px] px-2.5 py-1.5 rounded-lg font-bold shadow-xs cursor-pointer flex items-center gap-1"
                  >
                    <span>♻️</span> Unggah & Restore
                  </button>
                </div>

                <div v-if="backupsList.length > 0" class="flex flex-col gap-1.5 max-h-40 overflow-y-auto pr-0.5">
                  <div
                    v-for="b in backupsList"
                    :key="b.filename"
                    class="bg-white border border-emerald-200/80 rounded-lg px-2.5 py-2 flex items-center justify-between gap-2"
                  >
                    <div class="min-w-0">
                      <div class="text-[11px] font-mono font-bold text-slate-800 truncate">{{ b.filename }}</div>
                      <div class="text-[10px] text-slate-500">{{ b.type }} · {{ b.size_human }} · {{ b.created_at }}</div>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                      <button
                        type="button"
                        :disabled="isBackupBusy"
                        @click="downloadBackupFile(b.filename)"
                        class="px-2 py-1 text-[10px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-md cursor-pointer"
                        title="Unduh Backup"
                      >
                        Unduh
                      </button>
                      <button
                        type="button"
                        :disabled="isBackupBusy"
                        @click="restoreBackupFile(b.filename)"
                        class="px-2 py-1 text-[10px] font-bold bg-amber-100 hover:bg-amber-200 text-amber-900 rounded-md cursor-pointer"
                        title="Restore Database"
                      >
                        Restore
                      </button>
                      <button
                        type="button"
                        :disabled="isBackupBusy"
                        @click="deleteBackupFile(b.filename)"
                        class="px-2 py-1 text-[10px] font-bold bg-rose-50 hover:bg-rose-100 text-rose-700 rounded-md cursor-pointer"
                        title="Hapus File Backup"
                      >
                        ✕
                      </button>
                    </div>
                  </div>
                </div>
                <div v-else class="text-[10px] text-emerald-700/80 italic">
                  Belum ada file backup tersimpan di server. Klik "Backup di Server" atau "Unggah File".
                </div>
              </div>
            </div>

          </div>

        </div>

        <!-- Footer Dialog: Tombol Aksi Simpan -->
        <div class="bg-slate-50 px-5 py-3 border-t border-slate-200 flex items-center justify-between shrink-0">
          <span class="text-[11px] text-slate-500">Pengaturan langsung aktif di kasir</span>
          <div class="flex items-center gap-2">
            <button 
              type="button" 
              @click="showSettingsModal = false" 
              class="bg-white hover:bg-slate-100 text-slate-700 text-xs px-4 py-2 rounded-xl font-bold border border-slate-200 transition cursor-pointer"
            >
              Batal
            </button>
            <button 
              type="button" 
              @click="saveMasterSettings" 
              class="bg-blue-600 hover:bg-blue-700 text-white text-xs px-5 py-2 rounded-xl font-bold border border-blue-600 transition cursor-pointer shadow-xs flex items-center gap-1.5"
            >
              <span>💾</span>
              <span>Simpan Pengaturan</span>
            </button>
          </div>
        </div>

      </div>
    </div>


    <!-- ================================================================
         11. DRAWER RIWAYAT TRANSAKSI & LAPORAN KEUANGAN (MODERN FLAT)
         Tab 1: 📜 Riwayat Nota Transaksi (Cari, Filter, Cetak Ulang)
         Tab 2: 💰 Rekap Keuangan Hari Ini (Omzet, Tunai, QRIS, Kasbon)
         Tab 3: 👥 Buku Kasbon Pelanggan (Catat Bayar / Pelunasan)
    ================================================================= -->
    <div v-if="showReportsDrawer" class="fixed inset-0 z-[70] flex items-stretch justify-end select-none">
      <!-- Backdrop klik untuk tutup -->
      <div class="flex-1 bg-black/40 backdrop-blur-2xs transition" @click="showReportsDrawer = false"></div>

      <!-- Panel Drawer (46% Lebar Layar) -->
      <div class="w-[46%] max-w-full h-full bg-white flex flex-col border-l border-slate-200 shadow-2xl z-10 overflow-hidden">
        
        <!-- Header Drawer -->
        <div class="bg-white px-5 py-3.5 border-b border-slate-200 flex justify-between items-center shrink-0">
          <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-xl bg-blue-600 text-white flex items-center justify-center text-sm font-bold shadow-xs">
              📊
            </div>
            <div>
              <h3 class="font-bold text-xs text-slate-900 uppercase tracking-wide">Riwayat Transaksi & Rekap Kasir</h3>
              <p class="text-[10px] text-slate-500 font-medium">{{ storeName }} · {{ storeDescription || 'Rekapitulasi Pembayaran Realtime' }}</p>
            </div>
          </div>
          <button @click="showReportsDrawer = false" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
        </div>

        <!-- Segmented Tab Navigasi Drawer -->
        <div class="bg-slate-50 p-2 border-b border-slate-200 shrink-0 flex items-center gap-1.5">
          <button 
            type="button" 
            @click="activeReportsTab = 'history'" 
            :class="activeReportsTab === 'history' ? 'bg-white text-blue-700 font-bold shadow-2xs border border-slate-200' : 'text-slate-600 hover:text-slate-900 font-medium'"
            class="flex-1 py-1.5 px-2 rounded-xl text-xs transition flex items-center justify-center gap-1 cursor-pointer"
          >
            <span>📜</span>
            <span>Riwayat Nota</span>
          </button>
          <button 
            type="button" 
            @click="activeReportsTab = 'summary'" 
            :class="activeReportsTab === 'summary' ? 'bg-white text-emerald-700 font-bold shadow-2xs border border-slate-200' : 'text-slate-600 hover:text-slate-900 font-medium'"
            class="flex-1 py-1.5 px-2 rounded-xl text-xs transition flex items-center justify-center gap-1 cursor-pointer"
          >
            <span>💰</span>
            <span>Rekap Keuangan</span>
          </button>
          <button 
            type="button" 
            @click="activeReportsTab = 'kasbon'" 
            :class="activeReportsTab === 'kasbon' ? 'bg-white text-amber-700 font-bold shadow-2xs border border-slate-200' : 'text-slate-600 hover:text-slate-900 font-medium'"
            class="flex-1 py-1.5 px-2 rounded-xl text-xs transition flex items-center justify-center gap-1 cursor-pointer"
          >
            <span>👥</span>
            <span>Buku Kasbon</span>
            <span v-if="customersWithDebt.length > 0" class="bg-amber-600 text-white text-[9px] px-1.5 py-0.2 rounded-full font-mono">
              {{ customersWithDebt.length }}
            </span>
          </button>
        </div>

        <!-- ═══ TAB 1: RIWAYAT NOTA TRANSAKSI ═══ -->
        <div v-if="activeReportsTab === 'history'" class="flex-1 flex flex-col overflow-hidden">
          
          <!-- Filter & Search Bar Riwayat -->
          <div class="p-3 border-b border-slate-200 bg-white flex flex-col gap-2 shrink-0">
            <div class="relative">
              <input 
                type="text" 
                v-model="historySearchQuery" 
                @input="fetchHistoryTransactions"
                placeholder="Cari nomor invoice (TRX-), nama pembeli..." 
                class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-8 pr-3 py-1.5 text-xs text-slate-800 font-medium focus:bg-white focus:border-blue-500 focus:outline-none transition"
              />
              <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs">🔍</span>
            </div>

            <div class="flex items-center justify-between gap-1 text-[10px]">
              <!-- Filter Periode Tanggal -->
              <div class="flex items-center gap-1">
                <button 
                  v-for="d in [{ id: 'today', l: 'Hari Ini' }, { id: 'yesterday', l: 'Kemarin' }, { id: 'this_week', l: 'Minggu Ini' }, { id: 'all', l: 'Semua' }]" 
                  :key="d.id"
                  @click="historyFilterDate = d.id; fetchHistoryTransactions()"
                  :class="historyFilterDate === d.id ? 'bg-slate-800 text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 border border-slate-200'"
                  class="px-2 py-1 rounded-lg transition cursor-pointer"
                >
                  {{ d.l }}
                </button>
              </div>

              <!-- Filter Metode Bayar -->
              <div class="flex items-center gap-1">
                <button 
                  v-for="m in ['ALL', 'TUNAI', 'QRIS', 'KASBON']" 
                  :key="m"
                  @click="historyFilterMethod = m"
                  :class="historyFilterMethod === m ? 'bg-blue-600 text-white font-bold shadow-2xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 border border-slate-200'"
                  class="px-2 py-1 rounded-lg transition cursor-pointer uppercase font-mono"
                >
                  {{ m === 'ALL' ? 'Semua' : m }}
                </button>
              </div>
            </div>
          </div>

          <!-- List Kartu Riwayat Transaksi -->
          <div class="flex-1 overflow-y-auto p-3 flex flex-col gap-2.5">
            <div v-if="filteredHistoryTransactions.length === 0" class="text-center py-12 text-slate-400 text-xs flex flex-col items-center gap-2">
              <span class="text-3xl">📜</span>
              <span>Belum ada transaksi pada kriteria filter ini.</span>
            </div>

            <div 
              v-for="trx in filteredHistoryTransactions" 
              :key="trx.id || trx.invoice_no"
              class="bg-white border border-slate-200 rounded-xl p-3 shadow-2xs hover:border-blue-400 transition flex flex-col gap-2"
            >
              <!-- Baris Atas: Invoice, Jam & Metode Bayar -->
              <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                <div class="flex items-center gap-2">
                  <span class="font-mono font-black text-xs text-slate-900">{{ trx.invoice_no }}</span>
                  <span 
                    v-if="trx.transaction_type === 'CASH_WITHDRAWAL'"
                    class="text-[9px] font-mono font-bold px-2 py-0.5 rounded-md border uppercase bg-indigo-50 text-indigo-700 border-indigo-200"
                  >
                    🏧 TARIK TUNAI QRIS
                  </span>
                  <span 
                    v-else-if="trx.transaction_type === 'TOP_UP'"
                    class="text-[9px] font-mono font-bold px-2 py-0.5 rounded-md border uppercase bg-teal-50 text-teal-700 border-teal-200"
                  >
                    ⚡ TOP-UP ({{ trx.payment_method }})
                  </span>
                  <span 
                    v-else
                    :class="trx.payment_method === 'TUNAI' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : (trx.payment_method === 'QRIS' ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-amber-50 text-amber-700 border-amber-200')"
                    class="text-[9px] font-mono font-bold px-2 py-0.5 rounded-md border uppercase"
                  >
                    {{ trx.payment_method }}
                  </span>
                </div>
                <span class="text-[10px] text-slate-400 font-mono">
                  {{ new Date(trx.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) }}
                </span>
              </div>

              <!-- Baris Tengah: Info Pembeli & Rincian Item -->
              <div class="flex justify-between items-start text-xs">
                <div>
                  <div class="text-[11px] font-bold text-slate-800">{{ trx.customer_name }}</div>
                  <div class="text-[10px] text-slate-500 mt-0.5">
                    Kasir: {{ trx.cashier_name }} · {{ trx.transaction_type === 'CASH_WITHDRAWAL' ? 'Penarikan Tunai' : (trx.transaction_type === 'TOP_UP' ? ((trx.items && trx.items[0] ? trx.items[0].product_name : 'Top-Up Saldo')) : ((trx.items || []).length + ' macam barang')) }}
                  </div>
                </div>
                <div class="text-right">
                  <template v-if="trx.transaction_type === 'CASH_WITHDRAWAL'">
                    <div class="font-mono font-black text-sm text-indigo-700">
                      - Rp {{ Number(trx.change_amount).toLocaleString('id-ID') }}
                    </div>
                    <div class="text-[9px] text-emerald-700 font-bold">
                      QRIS: Rp {{ Number(trx.paid_amount).toLocaleString('id-ID') }} (Fee Rp {{ Number(trx.admin_fee || 0).toLocaleString('id-ID') }})
                    </div>
                  </template>
                  <template v-else-if="trx.transaction_type === 'TOP_UP'">
                    <div class="font-mono font-black text-sm text-teal-700">
                      Rp {{ Number(trx.grand_total).toLocaleString('id-ID') }}
                    </div>
                    <div class="text-[9px] text-emerald-700 font-bold">
                      Laba Jasa: +Rp {{ Number(trx.admin_fee || 0).toLocaleString('id-ID') }}
                    </div>
                  </template>
                  <template v-else>
                    <div class="font-mono font-black text-sm text-slate-900">
                      Rp {{ Number(trx.grand_total).toLocaleString('id-ID') }}
                    </div>
                    <div v-if="trx.payment_method === 'QRIS' && trx.change_amount > 0" class="text-[9px] text-rose-600 font-bold">
                      Kembalian Tunai: -Rp {{ Number(trx.change_amount).toLocaleString('id-ID') }}
                    </div>
                    <div v-else-if="trx.global_discount > 0" class="text-[9px] text-amber-600 font-medium">
                      Disc -Rp {{ Number(trx.global_discount).toLocaleString('id-ID') }}
                    </div>
                  </template>
                </div>
              </div>

              <!-- Baris Bawah: Tombol Cetak Ulang Struk -->
              <div class="flex justify-between items-center pt-1.5 border-t border-slate-100 mt-0.5">
                <span class="text-[9px] text-slate-400 font-mono truncate max-w-[220px]">
                  {{ (trx.items || []).map(i => i.product_name + ' (' + i.qty + ')').join(', ') }}
                </span>
                <button 
                  type="button" 
                  @click="reprintReceipt(trx)" 
                  class="bg-slate-100 hover:bg-blue-50 text-slate-700 hover:text-blue-700 text-[10px] font-bold px-3 py-1 rounded-lg border border-slate-200 transition flex items-center gap-1 cursor-pointer shadow-2xs"
                >
                  <span>🖨️</span>
                  <span>Cetak Ulang</span>
                </button>
              </div>
            </div>
          </div>

        </div>

        <!-- ═══ TAB 2: REKAP KEUANGAN KASIR HARI INI ═══ -->
        <div v-else-if="activeReportsTab === 'summary'" class="flex-1 overflow-y-auto p-4 flex flex-col gap-3.5">
          
          <div class="flex justify-between items-center border-b border-slate-200 pb-2">
            <div>
              <h4 class="font-bold text-xs text-slate-900 uppercase tracking-wider">Rekapitulasi Penjualan Hari Ini</h4>
              <p class="text-[10px] text-slate-500">Total pendapatan & rincian uang masuk laci kasir</p>
            </div>
            <button @click="fetchReportSummary" class="text-[10px] text-blue-600 hover:underline flex items-center gap-1 font-bold cursor-pointer">
              <span>🔄</span> Refresh
            </button>
          </div>

          <!-- Buku Kas & Shift Kerja Karyawan (Aktif saat Saklar Shift ON) -->
          <div v-if="settingsForm.enable_cashier_shifts" class="p-3 bg-indigo-50/70 border border-indigo-200 rounded-2xl flex flex-col gap-2.5">
            <div class="flex items-center justify-between">
              <div class="text-xs font-bold text-indigo-950 flex items-center gap-1.5">
                <span>⏱️</span> Buku Kas & Shift Kasir
              </div>
              <span v-if="currentShift" class="text-[10px] bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-full font-bold">
                ● Shift Aktif ({{ currentShift.cashier_name }}) · Modal Awal Rp {{ Number(currentShift.starting_cash || 0).toLocaleString('id-ID') }}
              </span>
              <span v-else class="text-[10px] bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full font-bold">Belum Buka Shift</span>
            </div>

            <div v-if="!currentShift" class="flex gap-2 items-center">
              <div class="flex-1">
                <RupiahInput
                  v-model="shiftStartingCash"
                  prefix="Rp"
                  placeholder="Modal awal laci (Rp)..."
                  input-class="w-full bg-white border border-indigo-200 rounded-xl pl-9 pr-3 py-1.5 text-xs font-mono font-bold"
                />
              </div>
              <button type="button" @click="submitOpenShift" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3.5 py-1.5 rounded-xl cursor-pointer">Buka Shift</button>
            </div>

            <div v-else class="flex flex-col gap-2">
              <div class="grid grid-cols-12 gap-1.5">
                <select v-model="cashMovementForm.type" class="col-span-3 bg-white border border-indigo-200 rounded-lg px-2 py-1 text-[11px] font-bold">
                  <option value="CASH_OUT">Kas Keluar (-)</option>
                  <option value="CASH_IN">Kas Masuk (+)</option>
                </select>
                <div class="col-span-3">
                  <RupiahInput
                    v-model="cashMovementForm.amount"
                    prefix="Rp"
                    placeholder="Nominal..."
                    input-class="w-full bg-white border border-indigo-200 rounded-lg pl-7 pr-2 py-1 text-[11px] font-mono font-bold"
                  />
                </div>
                <input v-model="cashMovementForm.reason" type="text" placeholder="Keterangan (mis: Beli es/setoran)..." class="col-span-4 bg-white border border-indigo-200 rounded-lg px-2 py-1 text-[11px]" />
                <button type="button" @click="submitCashMovement" class="col-span-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-[10px] font-bold cursor-pointer">Catat</button>
              </div>
              <div class="flex gap-2 items-center pt-1 border-t border-indigo-200/70">
                <div class="flex-1">
                  <RupiahInput
                    v-model="shiftActualCash"
                    :allow-empty="true"
                    prefix="Rp"
                    placeholder="Hitung uang fisik laci saat tutup shift..."
                    input-class="w-full bg-white border border-indigo-200 rounded-xl pl-9 pr-3 py-1.5 text-xs font-mono font-bold"
                  />
                </div>
                <button type="button" @click="submitCloseShift" class="bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold px-3.5 py-1.5 rounded-xl cursor-pointer">Tutup Shift</button>
              </div>
            </div>
          </div>

          <!-- Total Omzet Banner -->
          <div class="p-4 bg-slate-900 text-white rounded-2xl border border-slate-800 flex items-center justify-between shadow-xs">
            <div>
              <span class="text-[10px] text-slate-400 uppercase font-bold tracking-widest block mb-0.5">TOTAL OMZET PENJUALAN</span>
              <div class="text-2xl font-mono font-black text-emerald-400">
                Rp {{ reportSummary.total_sales.toLocaleString('id-ID') }}
              </div>
            </div>
            <div class="text-right">
              <span class="text-[10px] text-slate-400 block font-medium">Total Struk:</span>
              <span class="font-mono font-black text-lg text-white">{{ reportSummary.transaction_count }} Nota</span>
            </div>
          </div>

          <!-- Rincian Metode Pembayaran (Paling Krusial Saat Tutup Kasir) -->
          <div class="flex flex-col gap-2">
            <span class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Rincian Fisik Uang di Laci Kasir:</span>

            <!-- Uang Tunai Masuk dari Penjualan -->
            <div class="p-3 bg-emerald-50/60 border border-emerald-200 rounded-xl flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-lg bg-emerald-600 text-white flex items-center justify-center text-sm font-bold shadow-2xs">💵</span>
                <div>
                  <div class="text-xs font-bold text-emerald-950">Penjualan Tunai (Cash Masuk)</div>
                  <div class="text-[10px] text-emerald-700">Uang tunai masuk dari belanja pelanggan</div>
                </div>
              </div>
              <span class="font-mono font-black text-base text-emerald-700">
                + Rp {{ (reportSummary.cash_sales || 0).toLocaleString('id-ID') }}
              </span>
            </div>

            <!-- Uang Tunai Masuk dari Layanan Top-Up / Transfer -->
            <div v-if="(reportSummary.topup_cash_in || 0) > 0" class="p-3 bg-teal-50/60 border border-teal-200 rounded-xl flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-lg bg-teal-600 text-white flex items-center justify-center text-sm font-bold shadow-2xs">⚡</span>
                <div>
                  <div class="text-xs font-bold text-teal-950">Tunai Masuk Layanan Top-Up</div>
                  <div class="text-[10px] text-teal-700">Dari {{ reportSummary.topup_count || 0 }}x transaksi Top-Up/Transfer (Modal Saldo: Rp {{ (reportSummary.topup_capital_out || 0).toLocaleString('id-ID') }})</div>
                </div>
              </div>
              <span class="font-mono font-black text-base text-teal-700">
                + Rp {{ (reportSummary.topup_cash_in || 0).toLocaleString('id-ID') }}
              </span>
            </div>

            <!-- Uang Tunai Keluar karena Kembalian QRIS / Murni Tarik Tunai QRIS -->
            <div class="p-3 bg-rose-50/60 border border-rose-200 rounded-xl flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-lg bg-rose-600 text-white flex items-center justify-center text-sm font-bold shadow-2xs">🏧</span>
                <div>
                  <div class="text-xs font-bold text-rose-950">Kembalian & Tarik Tunai QRIS</div>
                  <div class="text-[10px] text-rose-700">Uang fisik laci yang diserahkan ke pembeli via QRIS ({{ reportSummary.qris_withdrawal_count || 0 }}x murni tarik tunai)</div>
                </div>
              </div>
              <span class="font-mono font-black text-base text-rose-700">
                - Rp {{ (reportSummary.qris_cash_out || 0).toLocaleString('id-ID') }}
              </span>
            </div>

            <!-- Saldo Bersih Uang Fisik di Laci -->
            <div class="p-3 bg-emerald-900 text-white rounded-xl flex items-center justify-between shadow-xs">
              <div>
                <div class="text-[10px] text-emerald-300 font-bold uppercase tracking-wider">ESTIMASI FISIK UANG CASH DI LACI:</div>
                <div class="text-[10px] text-emerald-100">Penjualan + Top-Up Tunai dikurangi Kembalian/Tarik Tunai QRIS</div>
              </div>
              <span class="font-mono font-black text-lg text-emerald-300">
                Rp {{ (reportSummary.estimated_cash_drawer ?? ((reportSummary.cash_sales || 0) + (reportSummary.topup_cash_in || 0) - (reportSummary.qris_cash_out || 0))).toLocaleString('id-ID') }}
              </span>
            </div>

            <span class="text-[10px] text-slate-500 font-bold uppercase tracking-wider mt-1">Rincian Rekening Digital & Jasa:</span>

            <!-- Uang Masuk Rekening QRIS (Total Settlement) -->
            <div class="p-3 bg-blue-50/60 border border-blue-200 rounded-xl flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-lg bg-blue-600 text-white flex items-center justify-center text-sm font-bold shadow-2xs">📱</span>
                <div>
                  <div class="text-xs font-bold text-blue-950">Total Masuk Rekening QRIS Bank</div>
                  <div class="text-[10px] text-blue-700">Belanja QRIS (Rp {{ (reportSummary.qris_sales || 0).toLocaleString('id-ID') }}) + Tarik Tunai/Overpay</div>
                </div>
              </div>
              <span class="font-mono font-black text-base text-blue-700">
                + Rp {{ (reportSummary.qris_settlement ?? reportSummary.qris_sales ?? 0).toLocaleString('id-ID') }}
              </span>
            </div>

            <!-- Pendapatan Jasa / Admin Fee -->
            <div v-if="(reportSummary.total_admin_fee || 0) > 0" class="p-3 bg-indigo-50/60 border border-indigo-200 rounded-xl flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-lg bg-indigo-600 text-white flex items-center justify-center text-sm font-bold shadow-2xs">💰</span>
                <div>
                  <div class="text-xs font-bold text-indigo-950">Pendapatan Biaya Admin Jasa</div>
                  <div class="text-[10px] text-indigo-700">Keuntungan jasa tarik tunai & tukar QRIS</div>
                </div>
              </div>
              <span class="font-mono font-black text-base text-indigo-700">
                + Rp {{ (reportSummary.total_admin_fee || 0).toLocaleString('id-ID') }}
              </span>
            </div>

            <!-- Kasbon / Piutang Belanja Baru -->
            <div class="p-3 bg-amber-50/60 border border-amber-200 rounded-xl flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-lg bg-amber-600 text-white flex items-center justify-center text-sm font-bold shadow-2xs">📝</span>
                <div>
                  <div class="text-xs font-bold text-amber-950">Transaksi Kasbon Baru</div>
                  <div class="text-[10px] text-amber-700">Belanjaan yang dicatat sebagai utang hari ini</div>
                </div>
              </div>
              <span class="font-mono font-black text-base text-amber-700">
                Rp {{ (reportSummary.kasbon_sales || 0).toLocaleString('id-ID') }}
              </span>
            </div>
          </div>

          <!-- Laba Kotor & Total Piutang Toko -->
          <div class="grid grid-cols-2 gap-2 pt-1">
            <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl">
              <span class="text-[10px] text-slate-500 uppercase font-bold block mb-0.5">Estimasi Laba Kotor:</span>
              <span class="font-mono font-black text-sm text-slate-900">
                Rp {{ reportSummary.total_profit.toLocaleString('id-ID') }}
              </span>
            </div>

            <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl">
              <span class="text-[10px] text-slate-500 uppercase font-bold block mb-0.5">Total Semua Kasbon Toko:</span>
              <span class="font-mono font-black text-sm text-amber-700">
                Rp {{ reportSummary.total_receivable.toLocaleString('id-ID') }}
              </span>
            </div>
          </div>

        </div>

        <!-- ═══ TAB 3: BUKU KASBON PELANGGAN ═══ -->
        <div v-else-if="activeReportsTab === 'kasbon'" class="flex-1 flex flex-col overflow-hidden">
          
          <div class="p-3 bg-amber-50/70 border-b border-amber-200 flex justify-between items-center shrink-0">
            <div>
              <span class="text-[10px] text-amber-800 font-bold uppercase block">TOTAL SELURUH PIUTANG KASBON:</span>
              <div class="font-mono font-black text-base text-amber-950">
                Rp {{ reportSummary.total_receivable.toLocaleString('id-ID') }}
              </div>
            </div>
            <span class="text-[10px] bg-amber-200 text-amber-900 px-2 py-1 rounded-lg font-bold">
              {{ customersWithDebt.length }} Orang Berutang
            </span>
          </div>

          <div class="flex-1 overflow-y-auto p-3 flex flex-col gap-2">
            <div v-if="customersWithDebt.length === 0" class="text-center py-12 text-slate-400 text-xs flex flex-col items-center gap-2">
              <span class="text-3xl">🎉</span>
              <span>Luar biasa! Tidak ada pelanggan yang memiliki tunggakan kasbon.</span>
            </div>

            <div 
              v-for="c in customersWithDebt" 
              :key="c.id"
              class="bg-white border border-slate-200 rounded-xl p-3 shadow-2xs flex items-center justify-between"
            >
              <div>
                <div class="text-xs font-black text-slate-900">{{ c.name }}</div>
                <div class="text-[10px] text-slate-500 mt-0.5">
                  Telp: {{ c.phone || '-' }} · Alamat: {{ c.address || '-' }}
                </div>
                <div class="text-[9px] text-slate-400 mt-0.5">
                  Plafon Limit: Rp {{ (c.credit_limit || 500000).toLocaleString('id-ID') }}
                </div>
              </div>

              <div class="flex flex-col items-end gap-1.5 shrink-0">
                <span class="font-mono font-black text-xs text-amber-700">
                  Rp {{ (c.kasbon_balance || 0).toLocaleString('id-ID') }}
                </span>
                <button 
                  type="button" 
                  @click="openPayDebtModal(c)" 
                  class="bg-emerald-600 hover:bg-emerald-700 text-white text-[10px] font-bold px-2.5 py-1 rounded-lg transition cursor-pointer shadow-xs flex items-center gap-1"
                >
                  <span>💵</span>
                  <span>Catat Bayar</span>
                </button>
              </div>
            </div>
          </div>

        </div>

      </div>
    </div>

    <!-- MODAL CATAT PEMBAYARAN / PELUNASAN KASBON -->
    <div v-if="showPayDebtModal && debtCustomerSelected" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-[85] flex items-center justify-center p-4">
      <div class="bg-white rounded-2xl w-full max-w-sm flex flex-col shadow-2xl p-5 gap-3 border border-slate-200">
        <div class="flex justify-between items-center border-b border-slate-200 pb-2">
          <div>
            <h3 class="font-bold text-xs text-slate-900">Catat Pelunasan Kasbon</h3>
            <p class="text-[10px] text-slate-500">Pelanggan: <strong class="text-slate-800">{{ debtCustomerSelected.name }}</strong></p>
          </div>
          <button @click="showPayDebtModal = false" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 flex items-center justify-center font-bold text-xs transition cursor-pointer">✕</button>
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 flex justify-between items-center">
          <span class="text-[10px] text-amber-800 font-bold uppercase">Sisa Kasbon Saat Ini:</span>
          <span class="font-mono font-black text-amber-900 text-sm">
            Rp {{ (debtCustomerSelected.kasbon_balance || 0).toLocaleString('id-ID') }}
          </span>
        </div>

        <div class="flex flex-col gap-2.5">
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="text-[10px] text-slate-500 font-bold uppercase">Nominal yang Dibayarkan (Rp):</label>
              <div class="flex items-center gap-1">
                <button type="button" @click="debtPayAmount = Math.round((debtCustomerSelected.kasbon_balance || 0) / 2)" class="px-1.5 py-0.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded text-[9px] font-bold cursor-pointer">50%</button>
                <button type="button" @click="debtPayAmount = debtCustomerSelected.kasbon_balance || 0" class="px-1.5 py-0.5 bg-emerald-100 hover:bg-emerald-200 text-emerald-800 rounded text-[9px] font-bold cursor-pointer">Lunas</button>
              </div>
            </div>
            <RupiahInput
              v-model="debtPayAmount"
              :max="debtCustomerSelected.kasbon_balance || 0"
              prefix="Rp"
              placeholder="0"
              input-class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-9 pr-3 py-2 text-sm font-mono font-black text-emerald-700 focus:bg-white focus:border-emerald-500 focus:outline-none"
            />
          </div>

          <div>
            <label class="text-[10px] text-slate-500 font-bold uppercase block mb-1">Metode Penerimaan Uang:</label>
            <div class="grid grid-cols-2 gap-2">
              <button 
                type="button" 
                @click="debtPayMethod = 'TUNAI'" 
                :class="debtPayMethod === 'TUNAI' ? 'bg-emerald-600 text-white font-bold' : 'bg-slate-100 text-slate-700'"
                class="py-1.5 rounded-lg text-xs transition"
              >
                💵 Tunai (Cash)
              </button>
              <button 
                type="button" 
                @click="debtPayMethod = 'TRANSFER'" 
                :class="debtPayMethod === 'TRANSFER' ? 'bg-blue-600 text-white font-bold' : 'bg-slate-100 text-slate-700'"
                class="py-1.5 rounded-lg text-xs transition"
              >
                🏦 Transfer / QRIS
              </button>
            </div>
          </div>

          <div>
            <label class="text-[10px] text-slate-500 font-bold uppercase block mb-1">Catatan Keterangan:</label>
            <input 
              type="text" 
              v-model="debtPayNotes" 
              placeholder="Cicilan ke-1 / Lunas..." 
              class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5 text-xs text-slate-800 focus:bg-white focus:outline-none"
            />
          </div>
        </div>

        <div class="flex gap-2 pt-2 border-t border-slate-200">
          <button @click="showPayDebtModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-xl font-bold border border-slate-200 transition cursor-pointer">Batal</button>
          <button @click="submitPayDebt" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs py-2.5 rounded-xl font-bold border border-emerald-600 transition cursor-pointer shadow-xs">Simpan Pembayaran</button>
        </div>
      </div>
    </div>

    <!-- ================= MODAL QR BARCODE URL (DUAL DISPLAY & MOBILE) ================= -->
    <div 
      v-if="qrUrlModal.isOpen" 
      class="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150"
      @click.self="qrUrlModal.isOpen = false"
    >
      <div class="bg-white rounded-3xl p-6 w-full max-w-sm shadow-2xl border border-slate-200 flex flex-col items-center text-center gap-3">
        <!-- Header Modal -->
        <div class="flex items-center justify-between w-full border-b border-slate-100 pb-2">
          <div class="flex items-center gap-2">
            <span class="text-xl">{{ qrUrlModal.icon }}</span>
            <div class="text-left">
              <h3 class="text-sm font-black text-slate-900 leading-tight">{{ qrUrlModal.title }}</h3>
              <span class="text-[10px] font-bold text-teal-700 bg-teal-50 px-2 py-0.5 rounded border border-teal-200 uppercase tracking-wider inline-block mt-0.5">
                {{ qrUrlModal.badge }}
              </span>
            </div>
          </div>
          <button 
            type="button" 
            @click="qrUrlModal.isOpen = false" 
            class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-800 text-sm font-black flex items-center justify-center transition cursor-pointer"
          >
            ✕
          </button>
        </div>

        <p class="text-xs text-slate-500 text-center leading-relaxed px-1">
          {{ qrUrlModal.subtitle }}
        </p>

        <!-- Status Khusus Akses HP Pribadi Karyawan -->
        <div v-if="qrUrlModal.type === 'mobile'" class="w-full">
          <div 
            v-if="mobileTokenData.is_enabled" 
            class="flex items-center justify-between w-full bg-emerald-50 border border-emerald-200 text-emerald-900 rounded-xl px-3 py-1.5 text-xs font-bold"
          >
            <div class="flex items-center gap-1.5">
              <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
              <span>Akses Karyawan Aktif</span>
            </div>
            <span class="text-[10px] text-emerald-700 font-mono">s/d {{ mobileTokenData.expires_human }} ({{ mobileTokenData.remaining_minutes }} mnt)</span>
          </div>
          <div 
            v-else 
            class="flex items-center justify-between w-full bg-rose-50 border border-rose-200 text-rose-900 rounded-xl px-3 py-1.5 text-xs font-bold"
          >
            <div class="flex items-center gap-1.5">
              <span class="w-2 h-2 rounded-full bg-rose-500"></span>
              <span>Akses HP Sedang Dikunci</span>
            </div>
            <span class="text-[10px] text-rose-700 font-bold uppercase tracking-wider">Nonaktif</span>
          </div>
        </div>

        <!-- Kotak QR Code Barcode Bersih & High Resolution -->
        <div class="p-3.5 bg-white rounded-2xl border-2 border-slate-200 shadow-sm flex items-center justify-center my-1 relative">
          <img 
            :src="generateQrDataUrl(qrUrlModal.url, 240)" 
            :alt="qrUrlModal.title" 
            class="w-56 h-56 object-contain rounded-lg transition"
            :class="qrUrlModal.type === 'mobile' && !mobileTokenData.is_enabled ? 'opacity-30 grayscale' : ''"
          />
          <div 
            v-if="qrUrlModal.type === 'mobile' && !mobileTokenData.is_enabled" 
            class="absolute inset-0 flex flex-col items-center justify-center p-4 text-rose-900 font-black text-xs text-center"
          >
            <span class="text-3xl mb-1">🔒</span>
            <span>Akses HP Dikunci</span>
            <span class="text-[10px] font-normal text-slate-500 mt-1">Tekan tombol di bawah untuk membuka kembali barcode baru</span>
          </div>
        </div>

        <!-- Kontrol Akses Owner (Khusus HP Pribadi Karyawan) -->
        <div v-if="qrUrlModal.type === 'mobile'" class="w-full grid grid-cols-2 gap-1.5">
          <button 
            type="button" 
            @click="refreshMobileToken(2)"
            class="py-2 px-2 bg-slate-100 hover:bg-slate-200 active:scale-95 text-slate-800 font-bold text-[11px] rounded-xl border border-slate-200 transition cursor-pointer flex items-center justify-center gap-1 shadow-2xs"
            title="Perbarui QR Code dan perpanjang masa aktif 2 jam"
          >
            <span>🔄</span>
            <span>QR Baru (2 Jam)</span>
          </button>
          <button 
            type="button" 
            @click="revokeMobileAccess"
            class="py-2 px-2 bg-rose-50 hover:bg-rose-100 active:scale-95 text-rose-700 font-bold text-[11px] rounded-xl border border-rose-200 transition cursor-pointer flex items-center justify-center gap-1 shadow-2xs"
            title="Kunci / cabut izin akses input HP seketika"
          >
            <span>🔒</span>
            <span>Kunci Akses</span>
          </button>
        </div>

        <!-- Box URL & Tombol Salin -->
        <div class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2 flex items-center justify-between gap-2">
          <span class="text-[11px] font-mono text-slate-600 truncate flex-1 text-left px-1">
            {{ qrUrlModal.url }}
          </span>
          <button 
            type="button" 
            @click="copyQrUrl" 
            class="shrink-0 px-2.5 py-1 text-[10px] font-bold rounded-lg border transition cursor-pointer active:scale-95"
            :class="qrUrlModal.isCopied ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white hover:bg-slate-100 text-slate-700 border-slate-200'"
          >
            {{ qrUrlModal.isCopied ? '✓ Tersalin!' : 'Salin URL' }}
          </button>
        </div>

        <!-- Tombol Aksi Bawah -->
        <div class="flex items-center gap-2 w-full pt-1">
          <button 
            type="button" 
            @click="qrUrlModal.isOpen = false"
            class="flex-1 py-2.5 px-3 bg-slate-100 hover:bg-slate-200 active:scale-95 text-slate-700 font-bold text-xs uppercase tracking-wider rounded-xl transition cursor-pointer"
          >
            Tutup
          </button>
          <a 
            :href="qrUrlModal.url" 
            target="_blank"
            class="flex-1 py-2.5 px-3 bg-blue-600 hover:bg-blue-700 active:scale-95 text-white font-black text-xs uppercase tracking-wider rounded-xl shadow-xs transition cursor-pointer flex items-center justify-center gap-1.5"
          >
            <span>{{ qrUrlModal.actionText }}</span>
            <span>↗</span>
          </a>
        </div>
      </div>
    </div>

    <!-- ================= MODAL KONFIRMASI LOGOUT ================= -->
    <div 
      v-if="showLogoutModal" 
      class="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150"
      @click.self="showLogoutModal = false"
    >
      <div class="bg-white rounded-3xl p-6 w-full max-w-sm shadow-2xl border border-slate-200 flex flex-col items-center text-center gap-3">
        <div class="w-14 h-14 rounded-2xl bg-rose-50 text-rose-600 border border-rose-200 flex items-center justify-center text-2xl shadow-xs">
          🚪
        </div>

        <div>
          <h3 class="text-base font-black text-slate-900">Konfirmasi Log Out</h3>
          <p class="text-xs text-slate-500 mt-1 leading-relaxed">
            Apakah Anda yakin ingin keluar dari sesi kasir <strong>{{ props.authCashier?.name || 'Kasir' }}</strong>?
          </p>
        </div>

        <div class="flex items-center gap-2.5 w-full pt-2">
          <button 
            type="button" 
            @click="showLogoutModal = false"
            class="flex-1 py-2.5 px-4 bg-slate-100 hover:bg-slate-200 active:scale-95 text-slate-700 font-bold text-xs uppercase tracking-wider rounded-xl transition cursor-pointer"
          >
            Batal
          </button>
          <button 
            type="button" 
            @click="confirmLogout"
            class="flex-1 py-2.5 px-4 bg-rose-600 hover:bg-rose-700 active:scale-95 text-white font-black text-xs uppercase tracking-wider rounded-xl shadow-md shadow-rose-600/30 transition cursor-pointer flex items-center justify-center gap-1.5"
          >
            <span>Ya, Keluar</span>
          </button>
        </div>
      </div>
    </div>

    <AppPopup />
  </div>
</template>

<style>
#scanner-viewfinder {
  border: none !important;
}
#scanner-viewfinder video {
  object-fit: cover !important;
  width: 100% !important;
  height: 100% !important;
  border-radius: 1rem !important;
}
#scanner-viewfinder img[alt="Info icon"] {
  display: none !important;
}
</style>
