<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { ScannerService } from '@/services/scanner';
import { db } from '@/db';

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
  authCashier: {
    type: Object,
    default: () => ({ name: 'Siska Anggraeni', shift: 'Shift Pagi · Kasir Aktif' })
  }
});

// ================= CLOCK =================
const currentTime = ref('');
let clockTimer = null;

function updateClock() {
  const now = new Date();
  const h = String(now.getHours()).padStart(2, '0');
  const m = String(now.getMinutes()).padStart(2, '0');
  currentTime.value = `${h}:${m}`;
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
  const rand = Math.floor(1000 + Math.random() * 9000);
  return `TRX-${d}${m}-${rand}`;
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

function clearCart() {
  if (cart.value.length === 0) return;
  if (confirm('Batalkan seluruh isi pesanan saat ini?')) {
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
const isSearchDropdownOpen = ref(false);
const highlightedIndex = ref(-1);
const searchUnregisteredQuery = ref(null);
const scannerTarget = ref('cart'); // 'cart' | 'newProduct'
const showUnregisteredModal = ref(false);
const unregisteredBarcode = ref('');

const searchSuggestions = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  if (!q) return [];

  return localProducts.value.filter(p => {
    const matchName = (p.name || '').toLowerCase().includes(q);
    const matchBarcode = (p.barcode || '').toLowerCase().includes(q);
    return matchName || matchBarcode;
  }).slice(0, 8);
});

function onSearchInput() {
  const q = searchQuery.value.trim();
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
  if (searchQuery.value.trim()) {
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
  const query = searchQuery.value.trim();
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
  highlightedIndex.value = -1;
  searchUnregisteredQuery.value = null;
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

async function startCameraScanner(target = 'cart') {
  scannerTarget.value = target;
  cameraError.value = '';
  scannerUnregisteredBarcode.value = '';
  quickBarcodeInput.value = '';
  showScannerModal.value = true;

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

  const scanConfig = {
    fps: 20,
    qrbox: (viewfinderWidth, viewfinderHeight) => {
      // Area pemindaian lega (96% lebar & 85% tinggi) agar barcode lebar tidak terpotong batas
      const w = Math.floor(viewfinderWidth * 0.96);
      const h = Math.floor(viewfinderHeight * 0.85);
      return { width: Math.max(260, w), height: Math.max(180, h) };
    },
    aspectRatio: 1.0,
    disableFlip: false
  };

  const createScannerInstance = () => {
    return new Html5Qrcode("scanner-viewfinder", {
      formatsToSupport: supportedFormats,
      verbose: false,
      useBarCodeDetectorIfSupported: false,
      experimentalFeatures: {
        useBarCodeDetectorIfSupported: false
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
    alert('Keranjang belanja masih kosong!');
    return;
  }
  inlineCashGiven.value = grandTotal.value.toLocaleString('id-ID');
  rightMode.value = 'cash';
}

function closeCashInline() {
  rightMode.value = 'normal';
}

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
  let current = inlineCashGiven.value.replace(/\./g, '');
  if (val === 'C') {
    inlineCashGiven.value = '0';
  } else if (val === '000') {
    current = (current === '0' || !current) ? '1000' : current + '000';
    inlineCashGiven.value = parseInt(current).toLocaleString('id-ID');
  } else {
    current = (current === '0' || !current) ? val : current + val;
    inlineCashGiven.value = parseInt(current).toLocaleString('id-ID');
  }
}

// Konfirmasi Pembayaran Tunai Inline
function confirmInlineCash(autoPrint = false) {
  const rawGiven = parseFloat(inlineCashGiven.value.replace(/\./g, '')) || 0;
  if (rawGiven < grandTotal.value) {
    playBeepSound('warning');
    alert('Uang yang diterima kurang dari total belanja!');
    return;
  }

  finalizeTransaction('TUNAI', rawGiven, inlineCashChange.value, autoPrint);
  rightMode.value = 'normal';
}

// ================= METODE PEMBAYARAN LAIN (QRIS & KASBON) =================
const showQrisModal = ref(false);
const showReceiptModal = ref(false);
const lastTransaction = ref(null);

function payQris() {
  if (cart.value.length === 0) {
    alert('Keranjang belanja masih kosong!');
    return;
  }
  showQrisModal.value = true;
}

function payKasbon() {
  if (cart.value.length === 0) {
    alert('Keranjang belanja masih kosong!');
    return;
  }
  if (!selectedCustomer.value || selectedCustomer.value.id === 1) {
    alert('Pilih nama pelanggan/member toko terlebih dahulu untuk transaksi Kasbon!');
    showCustomerModal.value = true;
    return;
  }

  if (confirm(`Catat belanjaan sebesar Rp ${grandTotal.value.toLocaleString('id-ID')} sebagai KASBON untuk "${selectedCustomer.value.name}"?`)) {
    finalizeTransaction('KASBON', grandTotal.value, 0, false);
  }
}

function finalizeTransaction(method, paidAmount, changeAmount, autoPrint = false) {
  playBeepSound('payment_success');
  const payload = {
    invoice_no: invoiceNo.value,
    cashier_name: props.authCashier.name,
    customer_id: selectedCustomer.value?.id || null,
    customer_name: selectedCustomer.value?.name || 'Pelanggan Umum',
    subtotal: subtotal.value,
    item_discount: itemDiscountTotal.value,
    global_discount: globalDiscount.value,
    global_discount_note: globalDiscountNote.value,
    grand_total: grandTotal.value,
    payment_method: method,
    paid_amount: paidAmount,
    change_amount: changeAmount,
    items: cart.value.map(item => {
      const activeUnitObj = item.units ? item.units.find(u => u.name === item.unit) : null;
      return {
        product_id: item.product_id,
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

  // Simpan ke IndexedDB Offline
  db.offline_transactions.add({
    ...payload,
    created_at: new Date().toISOString(),
    synced: isOnline.value ? 1 : 0
  });

  // Kirim ke Laravel jika online
  if (isOnline.value) {
    router.post(route('pos.checkout'), payload, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        if (method === 'KASBON' && selectedCustomer.value) {
          selectedCustomer.value.kasbon_balance += grandTotal.value;
        }
      }
    });
  }

  // Reset keranjang & kembalikan default pembeli ke Pelanggan Umum
  cart.value = [];
  selectedCustomer.value = getDefaultCustomer();
  globalDiscount.value = 0;
  globalDiscountNote.value = '';
  inlineCashGiven.value = '0';
  showQrisModal.value = false;
  invoiceNo.value = generateInvoiceNo();

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

function holdCurrentTransaction() {
  if (cart.value.length === 0) {
    playBeepSound('warning');
    alert('Keranjang belanja kosong!');
    return;
  }
  playBeepSound('hold');
  const defaultName = `Antrean (${totalQty.value} item) - ${currentTime.value}`;
  const name = prompt('Beri nama antrean ini:', defaultName);
  if (!name) return;

  const orderData = {
    id: Date.now(),
    name,
    customer: selectedCustomer.value,
    globalDiscount: globalDiscount.value,
    globalDiscountNote: globalDiscountNote.value,
    items: [...cart.value],
    created_at: currentTime.value
  };

  holdOrdersList.value.unshift(orderData);
  db.offline_hold_orders.add(orderData);

  cart.value = [];
  globalDiscount.value = 0;
  globalDiscountNote.value = '';
  alert(`Pesanan "${name}" berhasil di-hold.`);
}

function restoreHoldOrder(index) {
  const order = holdOrdersList.value[index];
  if (!order) return;

  if (cart.value.length > 0) {
    if (!confirm('Keranjang saat ini masih terisi. Ganti dengan antrean terpilih?')) return;
  }

  playBeepSound('hold');
  cart.value = [...(order.items || (order.data && order.data.items) || [])];
  if (order.customer) selectedCustomer.value = order.customer;
  if (order.globalDiscount) globalDiscount.value = order.globalDiscount;
  if (order.globalDiscountNote) globalDiscountNote.value = order.globalDiscountNote;

  holdOrdersList.value.splice(index, 1);
  showHoldModal.value = false;
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
    item.discount = parseFloat(editDraft.value.discount) || 0;
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
  let current = tempDiscountInput.value.replace(/\./g, '');
  if (val === 'C') {
    tempDiscountInput.value = '0';
  } else if (val === '000') {
    current = (current === '0' || !current) ? '1000' : current + '000';
    tempDiscountInput.value = parseInt(current).toLocaleString('id-ID');
  } else {
    current = (current === '0' || !current) ? val : current + val;
    tempDiscountInput.value = parseInt(current).toLocaleString('id-ID');
  }
}

function setDiscountShortcut(val) {
  const currentSub = subtotal.value;
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
  tempDiscountInput.value = current.toLocaleString('id-ID');
}

function saveGlobalDiscount() {
  const raw = tempDiscountInput.value.replace(/\./g, '');
  globalDiscount.value = parseFloat(raw) || 0;
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
const showQuickAddUnit = ref(false);
const quickUnitInput = ref('');

function quickAddUnit(targetType = 'base', unitIndex = null) {
  const u = quickUnitInput.value.trim();
  if (!u) return;
  if (!availableUnits.value.includes(u)) {
    availableUnits.value.push(u);
  }
  if (targetType === 'base') {
    newProd.value.unit = u;
  } else if (targetType === 'multi' && unitIndex !== null && newProd.value.extraUnits[unitIndex]) {
    newProd.value.extraUnits[unitIndex].name = u;
  }
  quickUnitInput.value = '';
  showQuickAddUnit.value = false;
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
    alert('Nama kategori tidak boleh kosong!');
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
  autoAdd: true,
  autoAddQty: 1,
  enableMultiUnit: false,
  extraUnits: []
});

const isQtyModeActive = computed(() => {
  return activeNumpadTarget.value === 'auto_qty' || activeNumpadTarget.value.startsWith('ratio_');
});

const activeNumpadLabel = computed(() => {
  if (activeNumpadTarget.value === 'base_price') {
    return `Harga Eceran (${newProd.value.unit})`;
  }
  if (activeNumpadTarget.value === 'auto_qty') {
    return `Jumlah Pcs Belanja (${newProd.value.unit})`;
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

function universalNumpad(val) {
  playBeepSound('click');
  const isQty = isQtyModeActive.value;
  let current = getTargetValue().replace(/\./g, '');

  if (val === 'C') {
    current = isQty ? '1' : '0';
  } else if (val === 'DEL') {
    current = current.slice(0, -1);
    if (!current) current = isQty ? '1' : '0';
  } else if (val === '000') {
    if (isQty) return;
    current = (!current || current === '0') ? '1000' : current + '000';
  } else if (typeof val === 'number') {
    if (isQty) {
      current = String(val);
    } else {
      const curNum = parseFloat(current) || 0;
      current = String(curNum + val);
    }
  } else {
    current = (!current || current === '0') ? val : current + val;
  }

  const numResult = parseInt(current) || 0;
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

function openAddProductModal(prefill = '') {
  activeNumpadTarget.value = 'base_price';
  newProd.value = {
    name: '',
    barcode: '',
    unit: 'Pcs',
    category: localCategories.value[0]?.name || 'Sembako',
    price: '',
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

function saveNewProduct() {
  if (!newProd.value.name.trim()) {
    alert('Nama produk tidak boleh kosong!');
    return;
  }
  const basePrice = parseFloat(newProd.value.price) || 0;
  if (basePrice <= 0) {
    alert('Harga jual produk harus lebih besar dari 0!');
    return;
  }

  const barcodeVal = newProd.value.barcode.trim();
  if (!barcodeVal) {
    alert('Barcode produk masih kosong! Silakan gunakan tombol [📷 Scan Barcode] untuk men-scan barcode kemasan barang.');
    return;
  }

  // Pengaman salah ketik (terlalu banyak nol)
  if (basePrice > 2000000) {
    if (!confirm(`Peringatan: Harga terdeteksi Rp ${basePrice.toLocaleString('id-ID')} (nominal cukup besar). Apakah angka ini sudah benar?`)) {
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

  const createdProd = {
    id: Date.now(),
    barcode: barcodeVal,
    name: newProd.value.name.trim(),
    category: newProd.value.category,
    icon: getCategoryIcon(newProd.value.category),
    units: allUnits
  };

  localProducts.value.unshift(createdProd);
  db.offline_products.add({ ...createdProd, synced: isOnline.value ? 1 : 0 });

  if (isOnline.value) {
    router.post(route('pos.products.store'), {
      barcode: createdProd.barcode,
      name: createdProd.name,
      category: createdProd.category,
      icon: createdProd.icon,
      unit_name: allUnits[0].name,
      price: allUnits[0].price,
      units: allUnits,
    }, { preserveScroll: true, preserveState: true });
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
    alert(
      'Petunjuk Pemasangan PWA Naning POS:\n\n' +
      '1. Di browser Chrome tablet/HP, ketuk ikon titik tiga (⋮) di pojok kanan atas.\n' +
      '2. Pilih menu "Tambahkan ke Layar Utama" (Add to Home screen) atau "Pasang Aplikasi".\n' +
      '3. Setelah terpasang, buka aplikasi langsung dari ikon Layar Utama tablet agar tampil Fullscreen tanpa address bar Chrome.'
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

onMounted(() => {
  updateClock();
  clockTimer = setInterval(updateClock, 1000);

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
  if (html5QrCode && isCameraActive.value) {
    html5QrCode.stop().catch(() => {});
  }
});
</script>

<template>
  <Head title="Naning POS - Layar Penuh Android Tablet" />

  <!-- ROOT CONTAINER: Mengisi Seluruh Layar (Fullscreen Edge-to-Edge) -->
  <div class="flex flex-row w-screen h-screen max-w-full max-h-screen overflow-hidden text-slate-800 bg-white select-none relative font-sans shadow-2xl">
    
    <!-- ================================================================
         PANEL KIRI (65%): KERANJANG BELANJA
         Header: Logo Toko + Search + Galeri + Scanner
         Body:   List Item Belanja Full Height
         Footer: Subtotal, Diskon, Grand Total
    ================================================================= -->
    <div class="w-[65%] bg-white flex flex-col border-r border-slate-200 overflow-hidden">
      
      <!-- Header Keranjang: Logo + Search + Tombol Galeri & Scanner -->
      <div class="bg-white border-b border-slate-200 px-4 py-2.5 shrink-0 flex items-center gap-3">
        <!-- Logo Toko -->
        <div class="flex items-center gap-2 shrink-0">
          <div class="w-8 h-8 rounded-xl bg-blue-700 flex items-center justify-center text-white font-black text-xs shadow-xs">
            TP
          </div>
          <div class="leading-tight">
            <div class="text-xs font-black text-slate-900 leading-none">TPKmart</div>
            <div class="text-[9px] text-slate-500 font-medium">Kasir: <strong class="text-slate-700">Siska A.</strong></div>
          </div>
        </div>

        <div class="w-px h-6 bg-slate-200 shrink-0"></div>

        <!-- Search Bar Terintegrasi Icon Kamera Scanner di Kiri & Auto-Suggest Dropdown -->
        <div id="pos-search-container" class="relative flex-1">
          <!-- Tombol Kamera Scanner Cepat di Sisi Kiri Input -->
          <button 
            @click="triggerBarcodeScanner" 
            type="button"
            title="Buka Scanner Barcode Kamera (Sentuh untuk Memindai)" 
            class="absolute left-1.5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-blue-600 hover:bg-blue-700 active:bg-blue-800 active:scale-95 text-white flex items-center justify-center transition shadow-xs cursor-pointer z-10"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
          </button>

          <input 
            type="text" 
            v-model="searchQuery" 
            @input="onSearchInput"
            @focus="onSearchFocus"
            @keydown="handleSearchKeydown"
            @keydown.enter.prevent="handleSearchSubmit"
            placeholder="Scan barcode atau ketik nama produk..."
            autocomplete="off"
            class="w-full bg-[#f1f3f4] text-slate-800 text-xs font-medium placeholder-slate-400 rounded-full pl-11 pr-8 py-2 focus:outline-none focus:bg-white focus:ring-2 focus:ring-blue-500 transition border border-transparent shadow-xs"
          />

          <!-- Action di Bagian Kanan Form Search (Clear & Ikon Kaca Pembesar) -->
          <div class="absolute right-2.5 top-1/2 -translate-y-1/2 flex items-center gap-1 text-slate-400">
            <button 
              v-if="searchQuery" 
              @click="clearSearchInput" 
              type="button"
              class="w-5 h-5 rounded-full bg-slate-200 hover:bg-slate-300 text-slate-600 flex items-center justify-center text-[10px] font-bold transition cursor-pointer"
            >
              ✕
            </button>
            <svg v-else class="w-4 h-4 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
          </div>

          <!-- Dropdown Hasil Pencarian Live & Auto-Suggest -->
          <div 
            v-if="isSearchDropdownOpen && (searchSuggestions.length > 0 || searchUnregisteredQuery)" 
            class="absolute left-0 right-0 top-full mt-1.5 bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden z-50 flex flex-col divide-y divide-slate-100"
          >
            <!-- Header Ringkas Info Hasil -->
            <div class="px-3 py-1.5 bg-slate-50 flex items-center justify-between text-[11px] text-slate-500 font-medium">
              <span>Hasil Pencarian ({{ searchSuggestions.length }})</span>
              <span class="text-[10px] text-slate-400">Pilih atau tekan Enter</span>
            </div>

            <!-- List Hasil Cocok -->
            <div v-if="searchSuggestions.length > 0" class="overflow-y-auto max-h-[300px] divide-y divide-slate-50">
              <div 
                v-for="(item, idx) in searchSuggestions" 
                :key="(item.id || item.barcode || '') + '-' + idx"
                @click="selectSuggestion(item)"
                @mouseenter="highlightedIndex = idx"
                :class="[
                  'px-3 py-2 flex items-center justify-between cursor-pointer transition select-none',
                  highlightedIndex === idx ? 'bg-blue-50 text-blue-900' : 'hover:bg-slate-50'
                ]"
              >
                <div class="flex items-center gap-2.5 min-w-0 pr-2">
                  <span class="text-xl p-1 bg-slate-100 rounded-xl shrink-0">{{ item.icon || '📦' }}</span>
                  <div class="truncate">
                    <div class="text-xs font-bold text-slate-900 truncate">{{ item.name }}</div>
                    <div class="flex items-center gap-2 text-[10px] text-slate-400 font-mono">
                      <span v-if="item.barcode">#{{ item.barcode }}</span>
                      <span v-if="item.barcode" class="text-slate-300">•</span>
                      <span>{{ getProductCategoryName(item) }}</span>
                    </div>
                  </div>
                </div>

                <div class="text-right shrink-0">
                  <div class="text-xs font-mono font-black text-slate-900">
                    Rp {{ (item.units && item.units[0] ? item.units[0].price : (item.price || 0)).toLocaleString('id-ID') }}
                  </div>
                  <span class="text-[9px] bg-blue-100 text-blue-700 px-1.5 py-0.2 rounded font-bold">
                    {{ item.units && item.units[0] ? item.units[0].name : (item.unit || 'Pcs') }}
                  </span>
                </div>
              </div>
            </div>

            <!-- State Jika Tidak Ditemukan / Tombol Daftarkan Barang Baru -->
            <div v-if="searchSuggestions.length === 0 && searchUnregisteredQuery" class="p-3 text-center bg-amber-50/50">
              <div class="text-xs font-bold text-slate-800 mb-0.5">
                Produk "{{ searchUnregisteredQuery }}" Belum Terdaftar
              </div>
              <p class="text-[10px] text-slate-500 mb-2">
                Barcode atau nama barang tidak ditemukan di katalog toko
              </p>
              <button 
                @click="quickRegisterFromSearch(searchUnregisteredQuery)"
                type="button"
                class="w-full bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold text-xs py-2 px-3 rounded-xl flex items-center justify-center gap-1.5 shadow-xs transition cursor-pointer"
              >
                <span>➕</span>
                <span>Daftarkan Produk Baru</span>
              </button>
            </div>
          </div>
        </div>

        <!-- Tombol Galeri Produk -->
        <button @click="showGalleryModal = true" class="shrink-0 flex items-center gap-1.5 bg-[#f1f3f4] hover:bg-slate-200 text-slate-700 font-bold text-xs px-3 py-2 rounded-full border border-slate-200/80 transition cursor-pointer">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
          Galeri
        </button>

        <!-- Badges & Counter Item -->
        <div class="shrink-0 flex items-center">
          <span class="bg-blue-50 text-blue-700 text-[10px] font-bold px-2.5 py-1 rounded-full border border-blue-200">
            {{ cart.length }} Item
          </span>
        </div>
      </div>

      <!-- Daftar Belanjaan (Material Android List - Full Height) -->
      <div v-if="cart.length > 0" class="flex-1 overflow-y-auto divide-y divide-slate-100 px-2">
        <div 
          v-for="(item, index) in cart" 
          :key="item.barcode + '-' + item.unit" 
          class="flex items-center justify-between py-2 px-2 hover:bg-slate-50 transition rounded-xl"
        >
          <!-- Info Nama & Satuan -->
          <div class="flex items-center gap-2.5 flex-1 min-w-0 pr-2 cursor-pointer" @click="openItemEdit(index)">
            <span class="text-xl p-1 bg-white rounded-xl shadow-2xs shrink-0">{{ item.icon || '📦' }}</span>
            <div class="truncate">
              <div class="text-xs font-bold text-slate-900 truncate leading-snug">{{ item.name }}</div>
              <div class="flex items-center gap-1.5 mt-0.5">
                <!-- Multi-Satuan Toggle Button: klik langsung berganti satuan -->
                <button 
                  v-if="item.units && item.units.length > 1"
                  type="button"
                  @click.stop="cycleItemUnit(index)"
                  class="bg-blue-600 hover:bg-blue-700 active:scale-95 text-white text-[10px] font-bold px-2 py-0.5 rounded-md flex items-center gap-1 shadow-2xs transition"
                  title="Klik untuk ganti satuan (Multi-Satuan)"
                >
                  <span>{{ item.unit }}</span>
                  <span class="text-[8px] opacity-90">🔄</span>
                </button>
                <span v-else class="bg-blue-50 text-blue-700 border border-blue-100 text-[10px] font-bold px-1.5 py-0.2 rounded-md">
                  {{ item.unit }}
                </span>
                <span class="text-[10px] text-slate-400 font-mono">Rp {{ item.price.toLocaleString('id-ID') }}</span>
                <span v-if="item.discount > 0" class="text-[10px] text-amber-600 font-mono font-bold">(-{{ item.discount.toLocaleString('id-ID') }})</span>
              </div>
            </div>
          </div>

          <!-- Qty Adjuster & Subtotal Item -->
          <div class="flex items-center gap-4 shrink-0">
            <!-- Counter Touch Pill -->
            <div class="flex items-center bg-[#f1f3f4] rounded-full p-0.5 border border-slate-200">
              <button @click.stop="updateQty(index, -1)" class="w-6 h-6 rounded-full bg-white text-slate-700 hover:bg-slate-200 flex items-center justify-center font-bold text-xs shadow-2xs">
                -
              </button>
              <span @click.stop="openItemEdit(index)" class="w-7 text-center font-mono font-bold text-xs text-slate-800 cursor-pointer">
                {{ item.qty }}
              </span>
              <button @click.stop="updateQty(index, 1)" class="w-6 h-6 rounded-full bg-white text-slate-700 hover:bg-slate-200 flex items-center justify-center font-bold text-xs shadow-2xs">
                +
              </button>
            </div>

            <!-- Total Per Baris -->
            <div class="text-right w-20">
              <div class="text-xs font-mono font-black text-slate-900">
                Rp {{ ((item.price * item.qty) - (item.discount || 0)).toLocaleString('id-ID') }}
              </div>
            </div>

            <!-- Tombol Hapus -->
            <button @click.stop="removeCartItem(index)" class="text-slate-300 hover:text-rose-500 text-xs p-1">
              ✕
            </button>
          </div>
        </div>
      </div>

      <!-- State Keranjang Kosong -->
      <div v-else class="flex-1 flex flex-col items-center justify-center p-6 text-center text-slate-400">
        <div class="w-16 h-16 rounded-full bg-blue-50 text-blue-600 border border-blue-100 flex items-center justify-center text-3xl mb-2">🛒</div>
        <p class="text-xs font-bold text-slate-700">Keranjang Belanja Masih Kosong</p>
        <p class="text-[11px] text-slate-400 mt-0.5 mb-3">Scan barcode produk, pilih dari galeri atau daftarkan produk baru</p>
        <div class="flex items-center gap-2">
          <!-- Tombol Utama: Scan Barcode Kamera -->
          <button 
            @click="triggerBarcodeScanner" 
            type="button"
            class="bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-bold text-xs px-4 py-2 rounded-full flex items-center gap-1.5 shadow-xs transition cursor-pointer active:scale-95"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            <span>Scan Barcode</span>
          </button>
          <button @click="showGalleryModal = true" type="button" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs px-3.5 py-2 rounded-full flex items-center gap-1.5 border border-slate-200 transition cursor-pointer">
            <span>📂</span> Buka Galeri
          </button>
          <button @click="openAddProductModal()" type="button" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs px-3.5 py-2 rounded-full flex items-center gap-1.5 shadow-xs transition cursor-pointer">
            <span>➕</span> Tambah Barang
          </button>
        </div>
      </div>

      <!-- Footer Keranjang: Subtotal & Grand Total + Diskon Nota -->
      <div class="border-t border-slate-200 bg-[#f8f9fa] shrink-0">
        <!-- Baris atas: info subtotal + tombol diskon nota -->
        <div class="px-4 pt-2 pb-1 flex items-center gap-3 text-xs text-slate-500 font-medium">
          <span>Subtotal: <strong class="text-slate-800 font-mono">Rp {{ subtotal.toLocaleString('id-ID') }}</strong></span>
          <span class="w-px h-4 bg-slate-300"></span>
          <span>Diskon Item: <strong class="text-amber-700 font-mono">Rp {{ itemDiscountTotal.toLocaleString('id-ID') }}</strong></span>
          <span class="w-px h-4 bg-slate-300"></span>
          <!-- Tombol Diskon Nota langsung di sini -->
          <button 
            @click="openGlobalDiscountModal"
            class="flex items-center gap-1 bg-amber-50 hover:bg-amber-100 border border-amber-200 text-amber-800 font-bold text-[11px] px-2.5 py-0.5 rounded-full transition"
          >
            🏷️ Diskon Nota: <strong class="font-mono text-amber-700">Rp {{ globalDiscount.toLocaleString('id-ID') }}</strong>
          </button>
          <!-- Badge catatan aktif + hapus -->
          <div v-if="globalDiscount > 0" class="flex items-center gap-1.5 bg-amber-100 border border-amber-200 px-2 py-0.5 rounded-full text-[10px] text-amber-800">
            <span class="italic">{{ globalDiscountNote || 'Diskon' }}</span>
            <button @click="removeGlobalDiscount" class="font-black text-amber-900 leading-none">✕</button>
          </div>
        </div>
        <!-- Baris bawah: Grand Total -->
        <div class="px-4 pb-2.5 flex items-center justify-between">
          <div class="text-[10px] text-slate-400 font-medium">Total tagihan yang harus dibayar</div>
          <div class="flex items-center gap-2 shrink-0">
            <div class="text-right leading-tight">
              <div class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">GRAND TOTAL</div>
            </div>
            <span class="text-emerald-600 font-black text-2xl font-mono tracking-tight">
              Rp {{ grandTotal.toLocaleString('id-ID') }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- ================================================================
         PANEL KANAN (35%): KASIR CHECKOUT
         Header: Identitas Kasir + Jam
         Body:   Pelanggan, Total Belanjaan
         Footer: Tombol Bayar (Tunai, QRIS, Kasbon)
    ================================================================= -->
    <div class="w-[35%] bg-white flex flex-col border-l border-slate-200 overflow-hidden">
      
      <!-- Header Panel Kanan: Kasir Info, Antrean & Jam -->
      <div class="bg-white border-b border-slate-200 px-4 py-2.5 shrink-0 flex items-center justify-between">
        <div class="flex items-center gap-2">
          <div class="w-7 h-7 rounded-full bg-indigo-600 text-white font-bold text-xs flex items-center justify-center">S</div>
          <div class="leading-tight">
            <div class="text-xs font-bold text-slate-900 leading-none">Siska Anggraeni</div>
            <div class="text-[9px] text-slate-500">Shift Pagi · Kasir Aktif</div>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <!-- Tombol Antrean di Header -->
          <button @click="showHoldModal = true" class="relative flex items-center gap-1 bg-blue-50 hover:bg-blue-100 border border-blue-200 text-blue-700 text-[10px] font-bold px-2.5 py-1 rounded-full transition">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
            Antrean
            <span class="bg-blue-600 text-white text-[9px] font-black rounded-full w-4 h-4 flex items-center justify-center">
              {{ holdOrdersList.length }}
            </span>
          </button>
          <span class="text-[11px] font-mono font-bold text-slate-600 bg-slate-100 px-2 py-0.5 rounded-full border border-slate-200">
            {{ currentTime }}
          </span>
        </div>
      </div>

      <!-- ═══ MODE NORMAL (DEFAULT) ═══ -->
      <template v-if="rightMode === 'normal'">
        <!-- Body Panel Kanan: Pelanggan + Info Total -->
        <div class="flex-1 overflow-y-auto flex flex-col gap-3 p-3">
          <!-- Kartu Pelanggan Aktif -->
          <div class="bg-[#f8f9fa] rounded-xl p-2.5 border border-slate-200 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
              <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-xs">👤</div>
              <div class="flex flex-col">
                <span class="text-xs font-bold text-slate-900 leading-tight">{{ selectedCustomer?.name || 'Pelanggan Umum' }}</span>
                <span class="text-[10px] text-slate-500">{{ selectedCustomer?.id === 1 ? 'Non-Member / Cash' : `Member (${selectedCustomer?.phone || '-'})` }}</span>
              </div>
            </div>
            <button @click="showCustomerModal = true" class="bg-white border border-slate-300 hover:bg-slate-100 text-blue-700 text-[11px] font-bold px-3 py-1 rounded-full shadow-2xs">
              Pilih
            </button>
          </div>

          <!-- Total Qty Info -->
          <div class="bg-[#f8f9fa] rounded-xl px-3 py-2 border border-slate-200 flex justify-between items-center">
            <span class="text-xs text-slate-500">Total Belanjaan</span>
            <span class="text-xs font-bold text-slate-800 font-mono">{{ totalQty }} pcs</span>
          </div>
        </div>

        <!-- Footer Panel Kanan: Tombol Pembayaran -->
        <div class="border-t border-slate-200 shrink-0">
          <!-- Aksi Sekunder: Hold & Batalkan (kecil, di atas tombol bayar) -->
          <div class="px-3 pt-2.5 pb-1.5 grid grid-cols-2 gap-2">
            <button @click="holdCurrentTransaction" class="bg-[#f1f3f4] hover:bg-slate-200 text-slate-600 text-[11px] font-bold py-1.5 px-3 rounded-full flex items-center justify-center gap-1.5 border border-slate-200 transition">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>
              Hold Order
            </button>
            <button @click="clearCart" class="bg-rose-50 hover:bg-rose-100 text-rose-600 text-[11px] font-bold py-1.5 px-3 rounded-full flex items-center justify-center gap-1.5 border border-rose-200 transition">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
              Batalkan
            </button>
          </div>
          <!-- Tombol Bayar Utama -->
          <div class="px-3 pb-3 flex flex-col gap-2">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Metode Pembayaran</span>

            <!-- Bayar Tunai -->
            <button @click="openCashInline" class="bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-black py-4 px-4 flex items-center justify-between text-xs tracking-wide shadow-sm transition">
              <span class="flex items-center gap-2 text-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                BAYAR TUNAI (CASH)
              </span>
              <span class="bg-emerald-700/80 px-2.5 py-0.5 rounded-full text-[10px] font-bold font-mono">UANG PAS</span>
            </button>

            <div class="grid grid-cols-2 gap-2">
              <!-- QRIS -->
              <button @click="payQris" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-3 flex items-center justify-center gap-1.5 text-xs shadow-xs transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                QRIS / Transfer
              </button>
              <!-- Kasbon -->
              <button @click="payKasbon" class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-3 px-3 flex items-center justify-center gap-1.5 text-xs shadow-xs transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Kasbon Toko
              </button>
            </div>
          </div>
        </div>
      </template>

      <!-- ═══ MODE TUNAI INLINE (MENGGANTIKAN BODY + FOOTER SAAT BAYAR TUNAI DITEKAN) ═══ -->
      <template v-else-if="rightMode === 'cash'">
        <div class="flex-1 flex flex-col overflow-hidden">
          <!-- Sub-header mode tunai -->
          <div class="bg-emerald-600 px-4 py-2.5 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-2 text-white">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
              <span class="font-black text-sm tracking-wide">BAYAR TUNAI</span>
            </div>
            <button @click="closeCashInline" class="bg-emerald-700/70 hover:bg-emerald-800/80 text-white text-[10px] font-bold px-2.5 py-1 rounded-full flex items-center gap-1 transition">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
              Kembali
            </button>
          </div>

          <!-- Total + Input uang -->
          <div class="px-3 pt-3 pb-2 flex flex-col gap-2 shrink-0">
            <!-- Total Tagihan -->
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-3 py-2 flex justify-between items-center">
              <span class="text-xs font-bold text-emerald-800">Total Tagihan</span>
              <span class="font-mono font-black text-emerald-700 text-lg">Rp {{ grandTotal.toLocaleString('id-ID') }}</span>
            </div>
            <!-- Input uang diterima -->
            <div>
              <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block mb-1">Uang Diterima</label>
              <div class="bg-[#f1f3f4] border-2 border-slate-200 focus-within:border-emerald-500 rounded-xl px-3 py-2 transition">
                <input 
                  type="text" 
                  :value="inlineCashGiven" 
                  readonly 
                  placeholder="0"
                  class="w-full bg-transparent text-right text-2xl font-mono font-black text-slate-900 focus:outline-none tracking-tight"
                />
              </div>
            </div>
            <!-- Kembalian -->
            <div class="bg-[#f8f9fa] border border-slate-200 rounded-xl px-3 py-2 flex justify-between items-center">
              <span class="text-xs font-bold text-slate-500 uppercase tracking-wide">Kembalian</span>
              <span class="font-mono font-black text-xl text-amber-600">Rp {{ inlineCashChange.toLocaleString('id-ID') }}</span>
            </div>
          </div>

          <!-- Chip pecahan uang -->
          <div class="px-3 pb-2 shrink-0">
            <div class="grid grid-cols-4 gap-1 font-mono text-[10px]">
              <button @click="inlineCashShortcut('exact')" class="bg-emerald-600 hover:bg-emerald-700 text-white font-black py-1.5 rounded-lg shadow-xs transition">Pas</button>
              <button @click="inlineCashShortcut(2000)"   class="bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 font-bold py-1.5 rounded-lg transition">+2rb</button>
              <button @click="inlineCashShortcut(5000)"   class="bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 font-bold py-1.5 rounded-lg transition">+5rb</button>
              <button @click="inlineCashShortcut(10000)"  class="bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 font-bold py-1.5 rounded-lg transition">+10rb</button>
              <button @click="inlineCashShortcut(20000)"  class="bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 font-bold py-1.5 rounded-lg transition">+20rb</button>
              <button @click="inlineCashShortcut(50000)"  class="bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 font-bold py-1.5 rounded-lg transition">+50rb</button>
              <button @click="inlineCashShortcut(100000)" class="bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 font-bold py-1.5 rounded-lg transition">+100rb</button>
              <button @click="inlineCashShortcut(200000)" class="bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 font-bold py-1.5 rounded-lg transition">+200rb</button>
            </div>
          </div>

          <!-- Numpad -->
          <div class="px-3 pb-2 grid grid-cols-3 gap-1.5 font-mono flex-1">
            <button v-for="n in ['1','2','3','4','5','6','7','8','9']" :key="n" @click="inlineCashNumpad(n)" class="bg-[#f1f3f4] hover:bg-slate-200 active:bg-slate-300 rounded-2xl text-lg font-black text-slate-800 transition">
              {{ n }}
            </button>
            <button @click="inlineCashNumpad('C')"   class="bg-rose-100 hover:bg-rose-200 text-rose-700 rounded-2xl text-sm font-black transition">C</button>
            <button @click="inlineCashNumpad('0')"   class="bg-[#f1f3f4] hover:bg-slate-200 active:bg-slate-300 rounded-2xl text-lg font-black text-slate-800 transition">0</button>
            <button @click="inlineCashNumpad('000')" class="bg-[#f1f3f4] hover:bg-slate-200 active:bg-slate-300 rounded-2xl text-sm font-black text-slate-700 transition">000</button>
          </div>

          <!-- Tombol Konfirmasi Pembayaran (Dual Options) -->
          <div class="px-3 pb-3 shrink-0 grid grid-cols-2 gap-2">
            <button @click="confirmInlineCash(false)" class="bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-black py-3 px-2 text-xs flex items-center justify-center gap-1.5 transition shadow-sm rounded-xl">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              SELESAI (CEPAT)
            </button>
            <button @click="confirmInlineCash(true)" class="bg-emerald-700 hover:bg-emerald-800 active:bg-emerald-900 text-white font-black py-3 px-2 text-xs flex items-center justify-center gap-1.5 transition shadow-sm rounded-xl">
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

    <!-- 1. ANDROID SIDE SHEET 1: GALERI PRODUK (DOCK DI PANEL KIRI 65%) -->
    <div v-if="showGalleryModal" class="fixed inset-0 z-50 flex items-stretch justify-start">
      <div class="absolute inset-0 bg-black/40 backdrop-blur-2xs" @click="showGalleryModal = false"></div>
      <div class="relative w-[65%] h-full bg-white border-r border-slate-200 shadow-2xl flex flex-col overflow-hidden z-10">
        <!-- Header Galeri Produk -->
        <div class="bg-white border-b border-slate-200 px-4 py-2.5 flex justify-between items-center shrink-0 gap-3">
          <div class="flex items-center gap-2.5 shrink-0">
            <div class="w-8 h-8 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-sm shadow-2xs">
              📂
            </div>
            <div>
              <h3 class="font-black text-xs text-slate-900 leading-tight">Katalog Produk Toko</h3>
              <p class="text-[10px] text-slate-500">Sentuh produk untuk menambah ke keranjang</p>
            </div>
          </div>
          <div class="flex items-center gap-2 flex-1 justify-end max-w-md">
            <!-- Search Input di Samping Tambah Barang -->
            <div class="relative w-56">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none">🔍</span>
              <input 
                type="text" 
                v-model="gallerySearchQuery" 
                placeholder="Cari produk / barcode..." 
                class="w-full bg-[#f8f9fa] hover:bg-white focus:bg-white border border-slate-200 focus:border-blue-500 rounded-full pl-8 pr-7 py-1.5 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 shadow-2xs font-medium transition"
              />
              <button 
                v-if="gallerySearchQuery" 
                @click="clearGallerySearch" 
                class="absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 rounded-full bg-slate-200 hover:bg-slate-300 text-slate-600 flex items-center justify-center text-[10px] font-bold transition"
              >
                ✕
              </button>
            </div>

            <!-- Tombol Tambah Barang -->
            <button @click="openAddProductModal()" class="shrink-0 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white text-xs font-bold px-3 py-1.5 rounded-full flex items-center gap-1 shadow-xs transition">
              <span>➕</span>
              <span>Tambah Barang</span>
            </button>

            <!-- Tombol Tutup Galeri -->
            <button @click="showGalleryModal = false" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center font-bold text-xs transition shrink-0">✕</button>
          </div>
        </div>

        <!-- Material 3 Filter Chips (Dynamic dari Database/Local) -->
        <div class="px-4 py-2 border-b border-slate-100 flex items-center gap-1.5 overflow-x-auto text-xs shrink-0 bg-[#f8f9fa]">
          <button 
            @click="selectedCategory = 'Semua'"
            :class="selectedCategory === 'Semua' ? 'cat-pill active bg-blue-600 text-white px-3.5 py-1.5 rounded-full font-bold shadow-2xs' : 'cat-pill bg-white text-slate-700 hover:bg-slate-100 border border-slate-200 px-3.5 py-1.5 rounded-full font-medium'"
          >
            Semua
          </button>
          <button 
            v-for="cat in localCategories" 
            :key="cat.id || cat.name"
            @click="selectedCategory = cat.name"
            :class="selectedCategory.toLowerCase() === cat.name.toLowerCase() ? 'cat-pill active bg-blue-600 text-white px-3.5 py-1.5 rounded-full font-bold shadow-2xs' : 'cat-pill bg-white text-slate-700 hover:bg-slate-100 border border-slate-200 px-3.5 py-1.5 rounded-full font-medium'"
          >
            {{ cat.icon || '📦' }} {{ cat.name }}
          </button>
          <button 
            @click="openQuickAddCategory"
            class="shrink-0 bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 px-2.5 py-1.5 rounded-full font-bold text-[11px] flex items-center gap-1 transition cursor-pointer"
          >
            <span>➕</span> Kategori
          </button>
        </div>

        <!-- Grid Produk -->
        <div class="p-3 grid grid-cols-3 gap-2 overflow-y-auto flex-1 content-start auto-rows-max">
          <!-- Kartu Tambah Barang Baru (Hanya Tampil Jika Produk Kosong / Tidak Ditemukan) -->
          <button 
            v-if="filteredCatalogProducts.length === 0" 
            @click="openAddProductModal(gallerySearchQuery)" 
            class="border-2 border-dashed border-emerald-400 bg-emerald-50/70 hover:bg-emerald-100 active:bg-emerald-200/80 rounded-2xl p-2.5 text-center flex flex-col items-center justify-center gap-1 transition shadow-2xs cursor-pointer group h-[104px]"
          >
            <div class="w-8 h-8 rounded-full bg-emerald-600 text-white flex items-center justify-center font-bold text-base shadow-xs group-hover:scale-110 transition">➕</div>
            <div class="text-xs text-emerald-950 font-bold">Tambah Produk Baru</div>
            <span class="text-[9px] text-emerald-700 font-medium">Daftarkan item ke katalog</span>
          </button>

          <!-- List Produk -->
          <button 
            v-for="p in filteredCatalogProducts" 
            :key="p.id" 
            @click="addToCart(p, 0)"
            class="bg-[#f8f9fa] hover:bg-blue-50 active:bg-blue-100 border border-slate-200/80 rounded-2xl p-2.5 text-left flex flex-col justify-between transition shadow-2xs cursor-pointer h-[104px]"
          >
            <div class="flex items-start gap-2.5">
              <div class="w-8 h-8 rounded-xl bg-white shadow-2xs flex items-center justify-center shrink-0 overflow-hidden">
                <img v-if="p.image" :src="p.image" :alt="p.name" class="w-full h-full object-cover" />
                <span v-else class="text-xl">{{ p.icon || '📦' }}</span>
              </div>
              <div class="truncate flex-1">
                <div class="flex items-center justify-between gap-1">
                  <span class="text-[9px] text-blue-600 font-bold uppercase tracking-wider block truncate">{{ getProductCategoryName(p) }}</span>
                  <span v-if="p.units && p.units.length > 1" class="text-[8px] bg-purple-100 text-purple-700 font-bold px-1.5 py-0.2 rounded-md shrink-0">
                    {{ p.units.length }} Satuan
                  </span>
                </div>
                <div class="text-xs text-slate-900 font-bold line-clamp-2 mt-0.5 leading-snug">{{ p.name }}</div>
              </div>
            </div>
            <div class="mt-2.5 pt-1.5 border-t border-slate-200/80 flex justify-between items-end">
              <span class="text-[10px] text-slate-500 font-medium">{{ p.units && p.units[0] ? p.units[0].name : 'Pcs' }}</span>
              <span class="text-xs font-mono text-emerald-700 font-black">
                Rp {{ (p.units && p.units[0] ? p.units[0].price : 0).toLocaleString('id-ID') }}
              </span>
            </div>
          </button>

          <!-- State jika belum ada produk atau hasil pencarian kosong -->
          <div v-if="filteredCatalogProducts.length === 0" class="col-span-2 border border-slate-200/90 rounded-2xl p-4 flex flex-col items-center justify-center text-center text-slate-400 bg-slate-50/60 min-h-[104px]">
            <span class="text-xl mb-1">{{ gallerySearchQuery ? '🔍' : '📦' }}</span>
            <span class="text-xs font-bold text-slate-600">
              {{ gallerySearchQuery ? `Tidak ada produk dengan kata kunci "${gallerySearchQuery}"` : 'Belum ada barang di kategori ini' }}
            </span>
            <span class="text-[10px] text-slate-400 mt-0.5">
              {{ gallerySearchQuery ? 'Sentuh kartu hijau di samping untuk mendaftarkan barang ini' : 'Sentuh kartu hijau di samping untuk mendaftarkan barang' }}
            </span>
          </div>
        </div>

        <!-- Footer Galeri Produk -->
        <div class="bg-[#f8f9fa] border-t border-slate-200 px-4 py-2 shrink-0 flex justify-between items-center text-[11px] text-slate-500">
          <span class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            <span>Sentuh kartu untuk menambah belanjaan</span>
          </span>
          <span class="text-slate-400 font-mono text-[10px]">Panel kasir kanan tetap aktif</span>
        </div>
      </div>
    </div>

    <!-- 2. ANDROID SIDE SHEET 2: PILIH PELANGGAN (DOCK DI PANEL KANAN 35%) -->
    <div v-if="showCustomerModal" class="fixed inset-0 z-50 flex items-stretch justify-end">
      <div class="absolute inset-0 bg-black/40 backdrop-blur-2xs" @click="showCustomerModal = false"></div>
      <div class="relative w-[35%] h-full bg-white border-l border-slate-200 shadow-2xl flex flex-col overflow-hidden z-10">
        <!-- Header -->
        <div class="bg-white border-b border-slate-200 px-4 py-2.5 flex justify-between items-center shrink-0">
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-xs shadow-2xs">👥</div>
            <div>
              <h3 class="font-black text-xs text-slate-900 leading-tight">Pilih Pelanggan / Member</h3>
              <p class="text-[10px] text-slate-500">Pilih pembeli untuk kasbon & riwayat</p>
            </div>
          </div>
          <button @click="showCustomerModal = false" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center font-bold text-xs transition">✕</button>
        </div>

        <!-- Search -->
        <div class="p-3 border-b border-slate-100 bg-[#f8f9fa] shrink-0">
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none">🔍</span>
            <input 
              type="text" 
              v-model="customerSearchQuery" 
              placeholder="Cari nama atau nomor HP..." 
              class="w-full bg-white border border-slate-200 rounded-full pl-8 pr-3 py-1.5 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-2xs font-medium"
            />
          </div>
        </div>

        <!-- List Pelanggan -->
        <div class="flex flex-col gap-2 p-3 overflow-y-auto flex-1 divide-y divide-slate-100">
          <!-- Pilihan Cepat: Pelanggan Umum (Default Cash) -->
          <div 
            @click="selectCustomer(getDefaultCustomer())"
            :class="(selectedCustomer?.id === 1 || selectedCustomer?.name?.toLowerCase()?.includes('umum')) ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-400' : 'border-slate-200 bg-white hover:bg-slate-50'"
            class="p-2.5 rounded-2xl border flex items-center justify-between transition cursor-pointer shadow-2xs mb-1"
          >
            <div class="flex items-center gap-2.5">
              <div class="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-xs shadow-2xs">
                🛒
              </div>
              <div>
                <div class="font-black text-xs text-slate-900">Pelanggan Umum</div>
                <div class="text-[10px] text-slate-500">Non-Member · Transaksi Tunai Langsung</div>
              </div>
            </div>
            <span v-if="selectedCustomer?.id === 1 || selectedCustomer?.name?.toLowerCase()?.includes('umum')" class="text-[10px] bg-blue-600 text-white font-bold px-2 py-0.5 rounded-full">
              ✓ Aktif
            </span>
            <span v-else class="text-[10px] text-blue-600 font-bold bg-blue-50 px-2 py-0.5 rounded-full border border-blue-200">
              Pilih
            </span>
          </div>

          <!-- List Member Terdaftar Lainnya -->
          <div 
            v-for="c in filteredCustomers.filter(c => c.id !== 1 && !c.name.toLowerCase().includes('umum'))" 
            :key="c.id" 
            @click="selectCustomer(c)"
            class="pt-2 flex items-center justify-between p-2 rounded-2xl hover:bg-blue-50 cursor-pointer transition border border-transparent hover:border-blue-200"
          >
            <div class="flex items-center gap-2.5">
              <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center font-bold text-slate-600 text-xs shrink-0">
                {{ c.name.charAt(0) }}
              </div>
              <div>
                <div class="font-bold text-xs text-slate-900">{{ c.name }}</div>
                <div class="text-[10px] text-slate-400 font-mono">{{ c.phone || '-' }}</div>
              </div>
            </div>
            <div class="text-right">
              <span class="text-[9px] text-slate-400 block font-medium">Kasbon:</span>
              <span class="text-xs font-mono font-bold" :class="c.kasbon_balance > 0 ? 'text-rose-600' : 'text-slate-400'">
                Rp {{ (c.kasbon_balance || 0).toLocaleString('id-ID') }}
              </span>
            </div>
          </div>
        </div>

        <!-- Footer Pelanggan -->
        <div class="p-3 border-t border-slate-200 bg-[#f8f9fa] shrink-0 flex justify-between items-center">
          <span class="text-[10px] text-slate-400 font-medium">Panel keranjang kiri tetap aktif</span>
          <button @click="showCustomerModal = false" class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs px-4 py-1.5 rounded-full font-bold transition">Tutup</button>
        </div>
      </div>
    </div>

    <!-- 3. ANDROID DIALOG: TAMBAH PRODUK BARU (DUAL-CARD SPLIT LAYOUT 1097x686) -->
    <div v-if="showAddProductModal" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-50 flex items-center justify-center p-3">
      <div class="bg-white rounded-3xl w-[860px] max-w-[860px] h-[550px] max-h-[550px] flex shadow-2xl overflow-hidden border border-slate-200">
        
        <!-- ================= KOLOM KIRI: FORMULIR PRODUK (58%) ================= -->
        <div class="w-[58%] border-r border-slate-200 p-4 flex flex-col justify-between overflow-y-auto">
          <div>
            <!-- Header Modal Kiri -->
            <div class="flex items-center gap-2 border-b border-slate-100 pb-2 mb-3">
              <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-sm shadow-2xs">
                ➕
              </div>
              <div>
                <h3 class="font-bold text-xs text-slate-900 leading-tight">Tambah Produk Baru</h3>
                <p class="text-[10px] text-slate-500">Daftarkan item ke katalog toko & keranjang</p>
              </div>
            </div>

            <!-- Formulir Fields -->
            <div class="flex flex-col gap-2.5">
              <!-- Nama Produk -->
              <div>
                <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-0.5">Nama Barang / Produk:</label>
                <input 
                  type="text" 
                  v-model="newProd.name" 
                  placeholder="Contoh: Kecap Bango 550ml..."
                  class="w-full bg-[#f1f3f4] border border-slate-200 rounded-xl px-3 py-1.5 text-xs text-slate-800 font-bold focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
              </div>

              <!-- Barcode & Satuan Dasar -->
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <div class="flex justify-between items-center mb-0.5">
                    <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Barcode:</label>
                    <button type="button" @click="scanBarcodeForNewProduct" class="text-[9px] text-emerald-700 bg-emerald-50 hover:bg-emerald-100 active:bg-emerald-200 border border-emerald-300 px-2 py-0.5 rounded-lg font-bold flex items-center gap-1 shadow-2xs transition">
                      <span>📷</span>
                      <span>Scan Barcode</span>
                    </button>
                  </div>
                  <div class="relative">
                    <input 
                      type="text" 
                      v-model="newProd.barcode" 
                      placeholder="Scan kemasan..."
                      class="w-full bg-[#f1f3f4] border border-slate-200 rounded-xl pl-2.5 pr-7 py-1.5 text-xs text-slate-800 font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500"
                    />
                    <button v-if="newProd.barcode" type="button" @click="newProd.barcode = ''" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-xs font-bold" title="Hapus barcode">
                      ✕
                    </button>
                  </div>
                </div>
                <div>
                  <div class="flex justify-between items-center mb-0.5">
                    <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Satuan Dasar:</label>
                    <button type="button" @click="showQuickAddUnit = !showQuickAddUnit" class="text-[9px] text-blue-600 font-bold hover:underline">➕ Satuan</button>
                  </div>

                  <!-- Inline Quick Add Satuan -->
                  <div v-if="showQuickAddUnit" class="mb-1 flex gap-1 p-1 bg-blue-50 border border-blue-200 rounded-xl">
                    <input 
                      type="text" 
                      v-model="quickUnitInput" 
                      placeholder="Satuan baru..." 
                      class="flex-1 bg-white border border-slate-200 rounded-lg px-2 py-0.5 text-xs text-slate-800 font-bold focus:outline-none"
                      @keydown.enter.prevent="quickAddUnit('base')"
                    />
                    <button type="button" @click="quickAddUnit('base')" class="bg-blue-600 text-white text-[10px] px-2 py-0.5 rounded-lg font-bold">OK</button>
                    <button type="button" @click="showQuickAddUnit = false" class="bg-slate-200 text-slate-600 text-[10px] px-1.5 py-0.5 rounded-lg">✕</button>
                  </div>

                  <select v-model="newProd.unit" class="w-full bg-[#f1f3f4] border border-slate-200 rounded-xl px-2 py-1.5 text-xs text-slate-800 font-bold focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <option v-for="u in availableUnits" :key="u" :value="u">{{ u }}</option>
                  </select>
                </div>
              </div>

              <!-- Kategori Chips + Quick Add -->
              <div>
                <div class="flex justify-between items-center mb-1">
                  <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Kategori Produk:</label>
                  <button type="button" @click="openQuickAddCategory" class="text-[9px] text-blue-600 font-bold hover:underline flex items-center gap-0.5">
                    <span>➕</span> Kategori Baru
                  </button>
                </div>
                <div class="flex flex-wrap gap-1 max-h-16 overflow-y-auto">
                  <button 
                    v-for="cat in localCategories" 
                    :key="cat.id || cat.name"
                    type="button"
                    @click="newProd.category = cat.name"
                    :class="newProd.category.toLowerCase() === cat.name.toLowerCase() ? 'bg-emerald-600 text-white font-bold border-emerald-600' : 'bg-slate-100 text-slate-700 border-slate-200 font-medium'"
                    class="text-[10px] px-2 py-0.5 rounded-full border transition flex items-center gap-1"
                  >
                    <span>{{ cat.icon || '📦' }}</span>
                    <span>{{ cat.name }}</span>
                  </button>
                </div>
              </div>

              <!-- Harga Jual Satuan Dasar (Touch Highlight) -->
              <div>
                <div class="flex justify-between items-center mb-0.5">
                  <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block">Harga Satuan Eceran (Rp):</label>
                  <span v-if="activeNumpadTarget === 'base_price'" class="text-[9px] text-emerald-600 font-bold animate-pulse">● Numpad Aktif</span>
                </div>
                <div 
                  @click="activeNumpadTarget = 'base_price'" 
                  :class="activeNumpadTarget === 'base_price' ? 'ring-2 ring-emerald-500 bg-emerald-50/50 border-emerald-500' : 'bg-[#f1f3f4] border-slate-200'"
                  class="border rounded-xl px-3 py-1.5 flex items-center justify-between cursor-pointer transition shadow-2xs"
                >
                  <span class="text-xs font-bold text-slate-400">Rp</span>
                  <input 
                    type="text" 
                    :value="newProd.price ? Number(newProd.price).toLocaleString('id-ID') : '0'" 
                    readonly
                    class="w-full bg-transparent text-right text-slate-900 font-mono font-black text-base focus:outline-none pointer-events-none"
                  />
                </div>
              </div>

              <!-- Multi-Satuan Grosir (Dus/Renceng) -->
              <div class="border border-slate-200 rounded-xl p-2 bg-slate-50">
                <div class="flex justify-between items-center mb-1">
                  <div>
                    <span class="text-[11px] font-bold text-slate-800 block leading-tight">Multi-Satuan / Grosir</span>
                    <span class="text-[8px] text-slate-500">Jual Dus/Renceng/Slop</span>
                  </div>
                  <button 
                    type="button" 
                    @click="addExtraUnit" 
                    class="bg-blue-600 hover:bg-blue-700 text-white text-[9px] font-bold px-2 py-0.5 rounded-full flex items-center gap-1 shadow-2xs transition"
                  >
                    <span>➕</span> Tambah Grosir
                  </button>
                </div>

                <div v-if="newProd.extraUnits.length === 0" class="text-[9px] text-slate-400 italic py-1 text-center bg-white rounded-lg border border-dashed border-slate-200">
                  Hanya satuan eceran ({{ newProd.unit }}). Klik "+ Tambah Grosir" jika ada penjualan grosir.
                </div>

                <!-- List Extra Units -->
                <div v-else class="flex flex-col gap-1 max-h-28 overflow-y-auto">
                  <div 
                    v-for="(eu, idx) in newProd.extraUnits" 
                    :key="idx" 
                    class="bg-white border border-slate-200 rounded-lg p-1.5 flex items-center gap-1.5 shadow-2xs"
                  >
                    <!-- Satuan Grosir -->
                    <div class="w-1/3">
                      <span class="text-[8px] font-bold text-slate-400 uppercase block mb-0.5">Grosir:</span>
                      <select v-model="eu.name" class="w-full bg-[#f1f3f4] border border-slate-200 rounded px-1 py-0.5 text-[10px] font-bold text-slate-800">
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
                        :class="activeNumpadTarget === 'ratio_' + idx ? 'ring-2 ring-emerald-500 bg-emerald-50/50 border-emerald-500' : 'bg-[#f1f3f4] border-slate-200'"
                        class="border rounded px-1 py-0.5 flex items-center justify-center cursor-pointer transition"
                      >
                        <input 
                          type="text" 
                          :value="eu.ratio || 1" 
                          readonly
                          class="w-full bg-transparent text-center text-[11px] font-bold text-slate-900 font-mono pointer-events-none"
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
                        :class="activeNumpadTarget === 'price_' + idx ? 'ring-2 ring-emerald-500 bg-emerald-50/50 border-emerald-500' : 'bg-[#f1f3f4] border-slate-200'"
                        class="border rounded px-1.5 py-0.5 flex items-center justify-between cursor-pointer transition"
                      >
                        <input 
                          type="text" 
                          :value="eu.price ? Number(eu.price).toLocaleString('id-ID') : '0'" 
                          readonly
                          placeholder="0" 
                          class="w-full bg-transparent text-right text-[11px] font-black text-emerald-700 font-mono pointer-events-none"
                        />
                      </div>
                    </div>

                    <!-- Hapus Extra Unit -->
                    <button 
                      type="button" 
                      @click="removeExtraUnit(idx)" 
                      class="w-5 h-5 rounded-full bg-rose-50 text-rose-600 hover:bg-rose-100 flex items-center justify-center font-bold text-[10px] shrink-0 self-end mb-0.5"
                    >
                      ✕
                    </button>
                  </div>
                </div>
              </div>

              <!-- Opsi Langsung Keranjang + Counter Pcs -->
              <div class="p-2 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center justify-between">
                <label class="flex items-center gap-1.5 cursor-pointer select-none">
                  <input type="checkbox" v-model="newProd.autoAdd" class="w-4 h-4 text-emerald-600 rounded focus:ring-emerald-500" />
                  <span class="text-xs font-bold text-emerald-900">Langsung masuk keranjang:</span>
                </label>
                <div v-if="newProd.autoAdd" class="flex items-center gap-1">
                  <button type="button" @click="newProd.autoAddQty = Math.max(1, newProd.autoAddQty - 1)" class="w-6 h-6 rounded-lg bg-white border border-emerald-300 text-emerald-800 font-bold text-xs flex items-center justify-center shadow-2xs hover:bg-emerald-100">
                    -
                  </button>
                  <div 
                    @click="activeNumpadTarget = 'auto_qty'" 
                    :class="activeNumpadTarget === 'auto_qty' ? 'ring-2 ring-emerald-500 bg-white' : 'bg-emerald-100/60'"
                    class="px-2 py-0.5 rounded-lg border border-emerald-300 cursor-pointer text-center font-mono font-black text-xs text-emerald-950 min-w-10 shadow-2xs"
                    title="Sentuh untuk ubah qty dengan numpad"
                  >
                    {{ newProd.autoAddQty }} {{ newProd.unit }}
                  </div>
                  <button type="button" @click="newProd.autoAddQty += 1" class="w-6 h-6 rounded-lg bg-white border border-emerald-300 text-emerald-800 font-bold text-xs flex items-center justify-center shadow-2xs hover:bg-emerald-100">
                    +
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer Tombol Kiri -->
          <div class="flex gap-2 pt-2 border-t border-slate-100 mt-2">
            <button @click="showAddProductModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2 rounded-full font-bold transition">
              Batal
            </button>
            <button @click="saveNewProduct" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs py-2 rounded-full font-bold shadow-xs transition flex items-center justify-center gap-1">
              <span>💾</span>
              <span>Simpan Produk</span>
            </button>
          </div>
        </div>

        <!-- ================= KOLOM KANAN: DEDICATED SMART NUMPAD (42%) ================= -->
        <div class="w-[42%] bg-[#f8f9fa] p-4 flex flex-col justify-between select-none">
          <div>
            <!-- Header Numpad -->
            <div class="flex justify-between items-center pb-2 border-b border-slate-200">
              <span class="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                <span>🔢</span>
                <span>Numpad Kasir Standby</span>
              </span>
              <button @click="showAddProductModal = false" class="w-7 h-7 rounded-full bg-slate-200 hover:bg-slate-300 text-slate-600 flex items-center justify-center font-bold text-xs transition">
                ✕
              </button>
            </div>

            <!-- Display Numpad Terbaca Jelas (Pemisah Ribuan / Pcs) -->
            <div class="mt-2.5 bg-white border-2 border-emerald-500 rounded-2xl p-2.5 shadow-2xs text-right">
              <div class="flex justify-between items-center text-[10px] font-bold text-emerald-700 uppercase tracking-wider mb-0.5">
                <span>Target:</span>
                <span class="truncate ml-1">{{ activeNumpadLabel }}</span>
              </div>
              <div class="text-2xl font-mono font-black text-slate-900 tracking-tight">
                <template v-if="!isQtyModeActive">
                  <span class="text-sm font-bold text-slate-400 mr-1">Rp</span>
                  {{ getTargetValue() ? Number(getTargetValue()).toLocaleString('id-ID') : '0' }}
                </template>
                <template v-else>
                  {{ getTargetValue() || 1 }} <span class="text-sm font-bold text-emerald-700">{{ newProd.unit }}</span>
                </template>
              </div>
            </div>

            <!-- Shortcut Baris Cepat -->
            <div class="mt-2">
              <div v-if="isQtyModeActive" class="grid grid-cols-6 gap-1 font-mono text-[9px]">
                <button type="button" v-for="q in [6, 10, 12, 20, 24, 40]" :key="q" @click="universalNumpad(q)" class="bg-white hover:bg-emerald-50 border border-slate-200 py-1.5 rounded-lg font-bold text-slate-700 active:scale-95 transition text-center shadow-2xs">
                  {{ q }}
                </button>
              </div>
              <div v-else class="grid grid-cols-6 gap-1 font-mono text-[9px]">
                <button type="button" v-for="p in [1000, 2000, 5000, 10000, 20000, 50000]" :key="p" @click="universalNumpad(p)" class="bg-white hover:bg-emerald-50 border border-slate-200 py-1.5 rounded-lg font-bold text-slate-700 active:scale-95 transition text-center shadow-2xs">
                  +{{ p >= 1000 ? (p/1000) + 'k' : p }}
                </button>
              </div>
            </div>
          </div>

          <!-- Numpad Touch Grid Berukuran Besar & Empuk -->
          <div class="grid grid-cols-4 gap-1.5 font-mono text-sm mt-2">
            <button type="button" v-for="n in ['1', '2', '3']" :key="n" @click="universalNumpad(n)" class="bg-white hover:bg-slate-200 active:bg-slate-300 py-2.5 rounded-xl font-black text-slate-800 shadow-2xs text-center border border-slate-200/80 transition">
              {{ n }}
            </button>
            <button type="button" @click="universalNumpad('DEL')" class="bg-amber-100 hover:bg-amber-200 active:bg-amber-300 text-amber-800 py-2.5 rounded-xl font-black flex items-center justify-center border border-amber-200 shadow-2xs transition">
              ⌫
            </button>

            <button type="button" v-for="n in ['4', '5', '6']" :key="n" @click="universalNumpad(n)" class="bg-white hover:bg-slate-200 active:bg-slate-300 py-2.5 rounded-xl font-black text-slate-800 shadow-2xs text-center border border-slate-200/80 transition">
              {{ n }}
            </button>
            <button type="button" @click="universalNumpad('C')" class="bg-rose-100 hover:bg-rose-200 active:bg-rose-300 text-rose-700 py-2.5 rounded-xl font-black border border-rose-200 shadow-2xs transition">
              C
            </button>

            <button type="button" v-for="n in ['7', '8', '9']" :key="n" @click="universalNumpad(n)" class="bg-white hover:bg-slate-200 active:bg-slate-300 py-2.5 rounded-xl font-black text-slate-800 shadow-2xs text-center border border-slate-200/80 transition">
              {{ n }}
            </button>
            <button type="button" @click="universalNumpad(isQtyModeActive ? 1 : '000')" :class="isQtyModeActive ? 'opacity-30 pointer-events-none' : ''" class="bg-white hover:bg-slate-200 active:bg-slate-300 py-2.5 rounded-xl font-black text-slate-800 shadow-2xs text-xs border border-slate-200/80 transition">
              {{ isQtyModeActive ? '-' : '000' }}
            </button>

            <button type="button" @click="universalNumpad('0')" class="col-span-2 bg-white hover:bg-slate-200 active:bg-slate-300 py-2.5 rounded-xl font-black text-slate-800 shadow-2xs text-center border border-slate-200/80 transition">
              0
            </button>
            <button type="button" @click="activeNumpadTarget = (activeNumpadTarget === 'base_price' && newProd.extraUnits.length > 0 ? 'ratio_0' : 'base_price')" class="col-span-2 bg-blue-100 hover:bg-blue-200 active:bg-blue-300 text-blue-800 py-2.5 rounded-xl font-bold text-xs border border-blue-200 shadow-2xs transition">
              Target ⇄
            </button>
          </div>

        </div>

      </div>
    </div>

    <!-- MODAL QUICK ADD KATEGORI BARU -->
    <div v-if="showQuickAddCategoryModal" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-[70] flex items-center justify-center p-4">
      <div class="bg-white rounded-3xl w-full max-w-sm flex flex-col shadow-2xl p-4 gap-3">
        <div class="flex justify-between items-center border-b border-slate-100 pb-2">
          <div class="flex items-center gap-2">
            <span class="text-xl p-1 bg-blue-50 rounded-xl">{{ quickCatIcon }}</span>
            <div>
              <h3 class="font-bold text-xs text-slate-900 leading-tight">Tambah Kategori Baru</h3>
              <p class="text-[10px] text-slate-500">Kategori langsung aktif di kasir & katalog</p>
            </div>
          </div>
          <button @click="showQuickAddCategoryModal = false" class="w-7 h-7 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center font-bold text-xs">✕</button>
        </div>

        <div class="flex flex-col gap-2.5">
          <div>
            <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-0.5">Nama Kategori:</label>
            <input 
              type="text" 
              v-model="quickCatName" 
              @keydown.enter.prevent="saveQuickCategory"
              placeholder="Contoh: Bumbu Dapur, Snack, Obat..." 
              class="w-full bg-[#f1f3f4] border border-slate-200 rounded-2xl px-3 py-2 text-xs text-slate-800 font-bold focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <div>
            <label class="text-[10px] text-slate-500 font-bold uppercase tracking-wider block mb-1">Pilih Icon Emoji:</label>
            <div class="grid grid-cols-8 gap-1 p-2 bg-[#f8f9fa] rounded-2xl border border-slate-200">
              <button 
                v-for="ico in emojiOptions" 
                :key="ico"
                type="button"
                @click="quickCatIcon = ico"
                :class="quickCatIcon === ico ? 'bg-blue-600 text-white scale-110 shadow-xs' : 'bg-white hover:bg-slate-100'"
                class="w-8 h-8 rounded-xl flex items-center justify-center text-sm transition"
              >
                {{ ico }}
              </button>
            </div>
          </div>
        </div>

        <div class="flex gap-2 pt-1 border-t border-slate-100">
          <button @click="showQuickAddCategoryModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2 rounded-full font-bold">Batal</button>
          <button @click="saveQuickCategory" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-xs py-2 rounded-full font-bold shadow-xs">Simpan Kategori</button>
        </div>
      </div>
    </div>

    <!-- MODAL KONFIRMASI PRODUK BELUM TERDAFTAR -->
    <div v-if="showUnregisteredModal" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-60 flex items-center justify-center p-4">
      <div class="bg-white rounded-3xl w-full max-w-sm flex flex-col shadow-2xl p-4 gap-3 border border-amber-200">
        <div class="flex items-center gap-2.5 border-b border-slate-100 pb-2">
          <div class="w-9 h-9 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center text-lg font-bold shadow-2xs">
            ⚠️
          </div>
          <div>
            <h3 class="font-bold text-xs text-slate-900 leading-tight">Produk Belum Terdaftar!</h3>
            <p class="text-[10px] text-slate-500">Barcode belum ada di katalog toko TPKmart</p>
          </div>
        </div>

        <div class="bg-amber-50/70 border border-amber-200 rounded-2xl p-3 text-center">
          <span class="text-[10px] text-amber-700 font-bold block mb-1">Kode Barcode Terdeteksi:</span>
          <div class="font-mono font-black text-base text-slate-800 tracking-wider bg-white py-1 px-3 rounded-xl border border-amber-200 inline-block shadow-2xs">
            {{ unregisteredBarcode }}
          </div>
          <p class="text-[11px] text-slate-600 mt-2 font-medium">
            Apakah Anda ingin mendaftarkan produk ini ke katalog toko sekarang?
          </p>
        </div>

        <div class="flex gap-2 pt-1 border-t border-slate-100">
          <button @click="showUnregisteredModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-full font-bold">
            Batal
          </button>
          <button @click="confirmRegisterUnregistered" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs py-2.5 rounded-full font-bold shadow-xs flex items-center justify-center gap-1">
            <span>➕</span>
            <span>Daftarkan Sekarang</span>
          </button>
        </div>
      </div>
    </div>

    <!-- 4. ANDROID DIALOG 2: EDIT ITEM & MULTI-SATUAN -->
    <div v-if="showItemEditModal && editDraft" class="fixed inset-0 bg-black/50 backdrop-blur-2xs z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-3xl w-full max-w-sm flex flex-col shadow-2xl p-4 gap-3">
        <div class="flex justify-between items-center border-b border-slate-100 pb-2">
          <div class="truncate pr-2">
            <span class="text-[9px] text-blue-600 font-bold uppercase tracking-wider block">Ubah Detail Produk</span>
            <h3 class="font-bold text-sm text-slate-900 truncate">{{ cart[currentEditIndex]?.name }}</h3>
          </div>
          <div class="flex items-center gap-1.5 shrink-0">
            <button @click="removeItemCurrentEdit" class="bg-rose-50 hover:bg-rose-100 text-rose-600 px-2.5 py-1 rounded-full text-[11px] font-bold flex items-center gap-1 border border-rose-200 transition">
              <span>🗑️</span> Hapus
            </button>
            <button @click="showItemEditModal = false" class="w-7 h-7 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center font-bold text-xs">✕</button>
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
                :class="editDraft.unit === u.name ? 'bg-blue-600 border-blue-600 text-white font-bold shadow-2xs' : 'bg-[#f1f3f4] border-slate-200 text-slate-700 hover:bg-slate-200'"
                class="p-2 rounded-2xl border text-center transition text-xs"
              >
                <div class="text-[10px] leading-tight font-bold truncate">{{ u.name }}</div>
                <div class="text-[9px] font-mono mt-0.5 opacity-90">Rp {{ u.price.toLocaleString('id-ID') }}</div>
              </button>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="text-[10px] text-slate-500 block mb-0.5 font-bold uppercase">Jumlah Qty:</label>
              <input type="text" :value="editDraft.qty" class="w-full bg-[#f1f3f4] border border-slate-200 rounded-2xl px-2 py-2 text-base text-slate-900 font-mono font-bold text-center focus:outline-none focus:ring-2 focus:ring-blue-500" readonly />
            </div>
            <div>
              <label class="text-[10px] text-slate-500 block mb-0.5 font-bold uppercase">Diskon Item (Rp):</label>
              <input type="number" v-model="editDraft.discount" class="w-full bg-[#f1f3f4] border border-slate-200 rounded-2xl px-2 py-2 text-xs text-amber-700 font-mono font-bold focus:outline-none focus:ring-2 focus:ring-amber-500" placeholder="0" />
            </div>
          </div>

          <!-- Numpad Touchpad Virtual -->
          <div class="grid grid-cols-3 gap-1.5 font-mono pt-0.5">
            <button v-for="n in ['1','2','3','4','5','6','7','8','9']" :key="n" @click="numpadPress(n)" class="bg-[#f1f3f4] hover:bg-slate-200 active:bg-slate-300 py-2.5 rounded-2xl text-base font-bold text-slate-800 shadow-2xs">
              {{ n }}
            </button>
            <button @click="numpadPress('C')" class="bg-rose-100 hover:bg-rose-200 text-rose-700 py-2.5 rounded-2xl text-sm font-bold">C</button>
            <button @click="numpadPress('0')" class="bg-[#f1f3f4] hover:bg-slate-200 active:bg-slate-300 py-2.5 rounded-2xl text-base font-bold text-slate-800 shadow-2xs">0</button>
            <button @click="numpadPress('DEL')" class="bg-slate-200 hover:bg-slate-300 active:bg-slate-400 text-slate-700 py-2.5 rounded-2xl text-sm font-bold flex items-center justify-center">⌫</button>
          </div>
        </div>

        <div class="flex gap-2 pt-1 border-t border-slate-100">
          <button @click="showItemEditModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-full font-bold">Batal</button>
          <button @click="saveItemEdit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-xs py-2.5 rounded-full font-bold shadow-xs">Simpan</button>
        </div>
      </div>
    </div>

    <!-- 5. ANDROID DIALOG 3: DISKON NOTA DENGAN NUMPAD -->
    <div v-if="showDiscountModal" class="fixed inset-0 bg-black/50 backdrop-blur-2xs z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-3xl w-full max-w-sm flex flex-col shadow-2xl p-4 gap-2.5">
        <div class="flex justify-between items-center border-b border-slate-100 pb-2">
          <div class="flex items-center gap-1.5">
            <span class="text-amber-500 text-sm">🏷️</span>
            <h3 class="font-bold text-xs text-slate-900">Potongan Diskon Nota</h3>
          </div>
          <button @click="showDiscountModal = false" class="w-6 h-6 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center font-bold text-xs">✕</button>
        </div>

        <!-- Display Potongan -->
        <div class="bg-amber-50/60 border-2 border-amber-400 rounded-2xl p-2.5 text-center">
          <span class="text-[9px] text-amber-700 uppercase font-bold block">Nominal Potongan:</span>
          <div class="text-xl font-mono font-black text-amber-900">Rp {{ tempDiscountInput }}</div>
        </div>

        <!-- Tombol Shortcut Diskon -->
        <div class="grid grid-cols-4 gap-1 font-mono text-[9px]">
          <button @click="setDiscountShortcut('round')" class="bg-[#f1f3f4] hover:bg-amber-100 py-1.5 rounded-xl font-bold text-amber-800 border border-slate-200">Bulat</button>
          <button @click="setDiscountShortcut('5%')" class="bg-[#f1f3f4] hover:bg-amber-100 py-1.5 rounded-xl font-bold text-amber-800 border border-slate-200">5%</button>
          <button @click="setDiscountShortcut('10%')" class="bg-[#f1f3f4] hover:bg-amber-100 py-1.5 rounded-xl font-bold text-amber-800 border border-slate-200">10%</button>
          <button @click="setDiscountShortcut(1000)" class="bg-[#f1f3f4] hover:bg-amber-100 py-1.5 rounded-xl font-bold text-slate-700 border border-slate-200">+1rb</button>
          <button @click="setDiscountShortcut(2000)" class="bg-[#f1f3f4] hover:bg-amber-100 py-1.5 rounded-xl font-bold text-slate-700 border border-slate-200">+2rb</button>
          <button @click="setDiscountShortcut(5000)" class="bg-[#f1f3f4] hover:bg-amber-100 py-1.5 rounded-xl font-bold text-slate-700 border border-slate-200">+5rb</button>
          <button @click="setDiscountShortcut(10000)" class="bg-[#f1f3f4] hover:bg-amber-100 py-1.5 rounded-xl font-bold text-slate-700 border border-slate-200">+10rb</button>
          <button @click="setDiscountShortcut(20000)" class="bg-[#f1f3f4] hover:bg-amber-100 py-1.5 rounded-xl font-bold text-slate-700 border border-slate-200">+20rb</button>
        </div>

        <!-- Numpad Virtual Diskon -->
        <div class="grid grid-cols-3 gap-1 font-mono pt-0.5">
          <button v-for="n in ['1','2','3','4','5','6','7','8','9']" :key="n" @click="discountNumpad(n)" class="bg-[#f1f3f4] hover:bg-slate-200 active:bg-slate-300 py-1.5 rounded-xl text-base font-bold text-slate-800 shadow-2xs">
            {{ n }}
          </button>
          <button @click="discountNumpad('C')" class="bg-rose-100 hover:bg-rose-200 text-rose-700 py-1.5 rounded-xl text-xs font-bold">C</button>
          <button @click="discountNumpad('0')" class="bg-[#f1f3f4] hover:bg-slate-200 active:bg-slate-300 py-1.5 rounded-xl text-base font-bold text-slate-800 shadow-2xs">0</button>
          <button @click="discountNumpad('000')" class="bg-[#f1f3f4] hover:bg-slate-200 active:bg-slate-300 py-1.5 rounded-xl text-xs font-bold text-slate-700 shadow-2xs">000</button>
        </div>

        <!-- Alasan Diskon -->
        <div class="flex flex-col gap-1">
          <label class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block">Alasan Diskon:</label>
          <div class="flex gap-1 overflow-x-auto text-[10px]">
            <button v-for="r in ['Pembulatan', 'Pelanggan Tetap', 'Promo Toko']" :key="r" @click="tempDiscountNote = r" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2 py-0.5 rounded-full border border-slate-200 shrink-0">
              {{ r }}
            </button>
          </div>
          <input type="text" v-model="tempDiscountNote" class="w-full bg-[#f1f3f4] border border-slate-200 rounded-xl px-2.5 py-1 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500" placeholder="Alasan lain (opsional)..." />
        </div>

        <div class="flex gap-2 pt-1 border-t border-slate-100">
          <button @click="showDiscountModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2 rounded-full font-bold">Batal</button>
          <button @click="saveGlobalDiscount" class="flex-1 bg-amber-600 hover:bg-amber-700 text-white text-xs py-2 rounded-full font-bold shadow-xs">Terapkan Diskon</button>
        </div>
      </div>
    </div>

    <!-- 6. ANDROID DIALOG 5: ANTREAN TRANSAKSI (HOLD) -->
    <div v-if="showHoldModal" class="fixed inset-0 bg-black/50 backdrop-blur-2xs z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-3xl w-full max-w-lg flex flex-col shadow-2xl p-4 gap-3">
        <div class="flex justify-between items-center border-b border-slate-100 pb-2">
          <h3 class="font-bold text-xs text-slate-900 flex items-center gap-1.5">⏸️ Antrean Order Tersimpan (Hold)</h3>
          <button @click="showHoldModal = false" class="w-6 h-6 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center font-bold text-xs">✕</button>
        </div>

        <div class="flex flex-col gap-2 max-h-[280px] overflow-y-auto">
          <div v-if="holdOrdersList.length === 0" class="text-center py-6 text-slate-400 text-xs">
            Belum ada antrean order yang tersimpan.
          </div>
          <div 
            v-for="(order, idx) in holdOrdersList" 
            :key="order.id"
            class="flex items-center justify-between p-2.5 bg-[#f8f9fa] border border-slate-200 rounded-2xl"
          >
            <div>
              <div class="text-xs font-bold text-slate-900">{{ order.name }}</div>
              <div class="text-[10px] text-slate-500 mt-0.5">
                {{ order.items ? order.items.length : 0 }} item · Disimpan: {{ order.created_at }}
              </div>
            </div>
            <div class="flex items-center gap-2">
              <button @click="restoreHoldOrder(idx)" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-3 py-1.5 rounded-full shadow-2xs">
                Buka
              </button>
              <button @click="holdOrdersList.splice(idx, 1)" class="text-rose-500 hover:text-rose-700 text-xs p-1">
                🗑️
              </button>
            </div>
          </div>
        </div>

        <div class="flex justify-end pt-2 border-t border-slate-100">
          <button @click="showHoldModal = false" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs px-4 py-2 rounded-full font-bold">Tutup</button>
        </div>
      </div>
    </div>

    <!-- 7. ANDROID RIGHT DOCK (35% LEBAR LAYAR): SCANNER BARCODE KAMERA -->
    <div v-if="showScannerModal" class="fixed inset-0 z-50 flex justify-end select-none">
      <!-- Backdrop Halus di Sisi Kiri (65%) - Klik untuk Tutup -->
      <div class="flex-1 bg-black/25 backdrop-blur-2xs transition" @click="stopCameraScanner"></div>

      <!-- Panel Scanner di Sisi Kanan (35% Lebar Layar) -->
      <div class="w-[35%] h-full bg-slate-900 text-white border-l border-slate-800 shadow-2xl flex flex-col justify-between p-3 z-10">
        
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
            <!-- Tombol Tes Suara Beep -->
            <button 
              @click="playBeepSound('success')" 
              type="button"
              title="Tes Bunyi Beep Kasir"
              class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 active:bg-slate-600 text-slate-300 hover:text-emerald-400 flex items-center gap-1 text-[10px] font-semibold transition cursor-pointer"
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
              class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 active:bg-slate-600 text-slate-200 hover:text-white flex items-center gap-1 text-[10px] font-semibold transition cursor-pointer"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
              <span>Ganti</span>
            </button>

            <!-- Tombol Tutup di Kanan Atas -->
            <button 
              @click="stopCameraScanner" 
              class="w-7 h-7 rounded-full bg-slate-800 hover:bg-slate-700 active:bg-slate-600 text-slate-300 hover:text-white flex items-center justify-center font-bold text-xs transition cursor-pointer"
            >
              ✕
            </button>
          </div>
        </div>

        <!-- Area Kamera Scanner Melebar 100% Panel Kanan -->
        <div class="flex-1 flex flex-col items-center justify-center my-auto w-full relative">
          <div class="relative w-full aspect-square max-h-[290px] bg-slate-950 rounded-2xl overflow-hidden border-2 border-emerald-500/80 shadow-[0_0_25px_rgba(16,185,129,0.25)] flex items-center justify-center">
            
            <!-- Elemen Pembaca Kamera HTML5-QRCode -->
            <div id="scanner-viewfinder" class="w-full h-full object-cover rounded-2xl overflow-hidden"></div>

            <!-- Efek Flash Hijau & Tanda "✓ TERDETEKSI" Saat Barcode Berhasil Discan -->
            <div 
              v-if="isScanFlashActive" 
              class="absolute inset-0 bg-emerald-500/40 border-4 border-emerald-400 rounded-2xl z-30 pointer-events-none transition-all duration-150 flex items-center justify-center shadow-[inset_0_0_30px_rgba(16,185,129,0.6)]"
            >
              <div class="px-3.5 py-1.5 bg-emerald-600 text-white rounded-full text-xs font-black tracking-wider shadow-2xl flex items-center gap-1.5 border border-emerald-300/40 animate-bounce">
                <span class="text-sm">✓</span>
                <span>TERDETEKSI</span>
              </div>
            </div>

            <!-- Laser Line Merah Beranimasi (Sinar Pemindai) -->
            <div class="absolute inset-x-4 top-1/2 -translate-y-1/2 h-0.5 bg-rose-500 shadow-[0_0_10px_#f43f5e] animate-pulse pointer-events-none z-10"></div>

            <!-- Sudut Target Viewfinder -->
            <div class="absolute top-2.5 left-2.5 w-5 h-5 border-t-3 border-l-3 border-emerald-400 pointer-events-none z-10 rounded-tl-md"></div>
            <div class="absolute top-2.5 right-2.5 w-5 h-5 border-t-3 border-r-3 border-emerald-400 pointer-events-none z-10 rounded-tr-md"></div>
            <div class="absolute bottom-2.5 left-2.5 w-5 h-5 border-b-3 border-l-3 border-emerald-400 pointer-events-none z-10 rounded-bl-md"></div>
            <div class="absolute bottom-2.5 right-2.5 w-5 h-5 border-b-3 border-r-3 border-emerald-400 pointer-events-none z-10 rounded-br-md"></div>

            <!-- Pesan Error Kamera jika ada -->
            <div v-if="cameraError" class="absolute inset-0 bg-slate-900/95 p-4 flex flex-col items-center justify-center text-center text-white z-20">
              <span class="text-2xl mb-1">📷</span>
              <p class="text-xs font-bold text-rose-400 mb-0.5">Akses Kamera Gagal</p>
              <p class="text-[10px] text-slate-300 max-w-[220px] mb-2.5 leading-relaxed">{{ cameraError }}</p>
              <button 
                @click="startCameraScanner(scannerTarget)" 
                type="button"
                class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white font-bold text-[11px] rounded-lg shadow transition cursor-pointer"
              >
                🔄 Coba Lagi
              </button>
            </div>

            <!-- Dialog Melayang Barcode Belum Terdaftar -->
            <div v-if="scannerUnregisteredBarcode" class="absolute inset-0 bg-slate-900/95 backdrop-blur-md p-4 flex flex-col items-center justify-center text-center z-30">
              <div class="w-9 h-9 rounded-full bg-amber-500/20 text-amber-400 flex items-center justify-center text-lg mb-1">
                ⚠️
              </div>
              <h4 class="text-xs font-bold text-white mb-0.5">Produk Belum Terdaftar</h4>
              <p class="text-[10px] text-slate-300 font-mono bg-slate-800 px-2.5 py-0.5 rounded-full mb-2 border border-slate-700">
                {{ scannerUnregisteredBarcode }}
              </p>
              <div class="flex flex-col gap-1.5 w-full max-w-[200px]">
                <button 
                  @click="registerFromScanner(scannerUnregisteredBarcode)" 
                  class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[11px] py-2 rounded-full shadow-md transition flex items-center justify-center gap-1 cursor-pointer"
                >
                  <span>➕</span>
                  <span>Daftarkan Produk</span>
                </button>
                <button 
                  @click="scannerUnregisteredBarcode = ''" 
                  class="w-full bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-[11px] py-1.5 rounded-full transition cursor-pointer"
                >
                  Abaikan & Lanjut Scan
                </button>
              </div>
            </div>
          </div>

          <!-- Input Alternatif Cepat Manual Barcode / Testing Laptop -->
          <div class="w-full mt-2 shrink-0">
            <div class="relative">
              <input 
                v-model="quickBarcodeInput"
                @keydown.enter="submitQuickBarcode"
                type="text" 
                placeholder="Ketik barcode lalu tekan Enter..."
                class="w-full bg-slate-950/90 border border-slate-700 focus:border-emerald-500 rounded-xl px-3 py-1.5 pr-14 text-xs text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 font-mono transition"
              />
              <button 
                @click="submitQuickBarcode"
                type="button"
                class="absolute right-1 top-1 bottom-1 px-2.5 bg-emerald-600 hover:bg-emerald-500 text-white text-[10px] font-bold rounded-lg transition cursor-pointer flex items-center gap-0.5"
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

        <!-- Footer Panel Scanner: Hanya Notifikasi Terakhir Ter-Scan -->
        <div class="shrink-0 pt-2 min-h-[44px] flex items-center justify-center">
          <transition enter-active-class="transition duration-200 ease-out" enter-from-class="transform translate-y-2 opacity-0" enter-to-class="transform translate-y-0 opacity-100" leave-active-class="transition duration-150 ease-in" leave-from-class="transform translate-y-0 opacity-100" leave-to-class="transform translate-y-2 opacity-0">
            <div v-if="lastScannedProduct" class="bg-emerald-600 text-white px-3 py-1.5 rounded-xl shadow-md flex items-center gap-2 border border-emerald-400/30 w-full">
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

    <!-- 8. ANDROID DIALOG 7: QRIS -->
    <div v-if="showQrisModal" class="fixed inset-0 bg-black/50 backdrop-blur-2xs z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-3xl w-full max-w-sm flex flex-col items-center shadow-2xl p-4 gap-3">
        <div class="flex justify-between items-center w-full border-b border-slate-100 pb-2">
          <h3 class="font-bold text-xs text-slate-900 flex items-center gap-1.5">📱 QRIS Toko Kelontong</h3>
          <button @click="showQrisModal = false" class="w-6 h-6 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center font-bold text-xs">✕</button>
        </div>
        
        <div class="text-center flex flex-col items-center gap-2">
          <span class="text-xs text-slate-500">Arahkan aplikasi m-banking atau e-wallet:</span>
          <div class="bg-white p-3 rounded-3xl w-44 h-44 flex flex-col items-center justify-center border-2 border-blue-500 shadow-md relative">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=TPKMART-POS-TRANSACTION-MOCKUP" alt="QRIS Mockup" class="w-36 h-36" />
            <span class="absolute bottom-1 text-[8px] font-bold text-slate-500 font-mono tracking-tighter">QRIS RESMI</span>
          </div>
          <div class="flex flex-col items-center">
            <span class="text-[10px] text-slate-400 uppercase tracking-wider">Total Pembayaran:</span>
            <span class="font-mono font-black text-emerald-600 text-lg">Rp {{ grandTotal.toLocaleString('id-ID') }}</span>
          </div>
        </div>
        
        <div class="grid grid-cols-3 gap-2 w-full pt-2 border-t border-slate-100">
          <button @click="showQrisModal = false" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2 rounded-full font-bold">Batal</button>
          <button @click="finalizeTransaction('QRIS', grandTotal, 0, false)" class="bg-blue-600 hover:bg-blue-700 text-white text-xs py-2 rounded-full font-bold shadow-xs">Selesai</button>
          <button @click="finalizeTransaction('QRIS', grandTotal, 0, true)" class="bg-blue-700 hover:bg-blue-800 text-white text-xs py-2 rounded-full font-bold shadow-xs flex items-center justify-center gap-1">
            <span>🖨️</span> Selesai & Cetak
          </button>
        </div>
      </div>
    </div>

    <!-- 9. ANDROID DIALOG 9: PREVIEW CETAK STRUK THERMAL 80MM -->
    <div v-if="showReceiptModal && lastTransaction" class="fixed inset-0 bg-black/60 backdrop-blur-2xs z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-3xl w-full max-w-sm flex flex-col shadow-2xl p-4 gap-3 max-h-[90vh]">
        <div class="flex justify-between items-center border-b border-slate-100 pb-2">
          <h3 class="font-bold text-xs text-slate-900 flex items-center gap-1.5">🖨️ Struk Belanja Pelanggan</h3>
          <button @click="showReceiptModal = false" class="w-7 h-7 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center font-bold text-xs">✕</button>
        </div>

        <div class="thermal-receipt p-4 rounded-2xl text-xs overflow-y-auto max-h-[440px] border border-slate-200 shadow-inner select-text font-mono" id="printable-receipt">
          <div class="text-center font-bold text-sm tracking-widest">TPKMART</div>
          <div class="text-center text-[10px] leading-tight mt-0.5 text-gray-600">
            Jl. Melati No. 12, Kel. Sukamaju<br>
            Telp: 0812-3456-7890
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

          <div class="border-b border-dashed border-gray-400 my-2"></div>

          <!-- Items -->
          <div class="flex flex-col gap-1 text-[10px]">
            <div v-for="it in lastTransaction.items" :key="it.product_name" class="flex justify-between">
              <div class="truncate pr-2">
                <div>{{ it.product_name }}</div>
                <div class="text-[9px] text-gray-500">{{ it.qty }} x Rp {{ it.unit_price.toLocaleString('id-ID') }}</div>
              </div>
              <span class="font-bold">Rp {{ it.total.toLocaleString('id-ID') }}</span>
            </div>
          </div>

          <div class="border-b border-dashed border-gray-400 my-2"></div>

          <div class="flex flex-col gap-0.5 text-[10px]">
            <div class="flex justify-between">
              <span>Subtotal</span>
              <span>Rp {{ lastTransaction.subtotal.toLocaleString('id-ID') }}</span>
            </div>
            <div v-if="lastTransaction.global_discount > 0" class="flex justify-between text-amber-700">
              <span>Diskon Nota ({{ lastTransaction.global_discount_note || 'Promo' }})</span>
              <span>- Rp {{ lastTransaction.global_discount.toLocaleString('id-ID') }}</span>
            </div>
            <div class="flex justify-between font-bold text-xs pt-1 border-t border-gray-300 mt-1">
              <span>TOTAL</span>
              <span>Rp {{ lastTransaction.grand_total.toLocaleString('id-ID') }}</span>
            </div>
            <div class="flex justify-between pt-1">
              <span>{{ lastTransaction.payment_method }}</span>
              <span>Rp {{ lastTransaction.paid_amount.toLocaleString('id-ID') }}</span>
            </div>
            <div class="flex justify-between">
              <span>Kembali</span>
              <span>Rp {{ lastTransaction.change_amount.toLocaleString('id-ID') }}</span>
            </div>
          </div>

          <div class="border-b border-dashed border-gray-400 my-2"></div>

          <div class="text-center text-[9px] leading-tight text-gray-600">
            Terima Kasih Atas Kunjungan Anda!<br>
            Barang yang sudah dibeli tidak dapat ditukar.
          </div>
        </div>

        <div class="flex gap-2 pt-1 border-t border-slate-100">
          <button @click="showReceiptModal = false" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs py-2.5 rounded-full font-bold">Tutup</button>
          <button @click="window.print()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-xs py-2.5 rounded-full font-bold shadow-xs flex items-center justify-center gap-1.5">
            <span>🖨️</span> Cetak Struk
          </button>
        </div>
      </div>
    </div>

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
  border-radius: 1.5rem !important;
}
#scanner-viewfinder img[alt="Info icon"] {
  display: none !important;
}
</style>
