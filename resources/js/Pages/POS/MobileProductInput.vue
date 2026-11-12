<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { db } from '@/db';
import { processCurrencyInput } from '@/utils/currency';
import { showAlert, showConfirm } from '@/utils/popup';
import AppPopup from '@/Components/AppPopup.vue';

const page = usePage();
const storeName = computed(() => page.props.store?.name || 'Rozak Mart');

const props = defineProps({
  products: {
    type: Array,
    default: () => []
  },
  categories: {
    type: Array,
    default: () => []
  },
  canManageProducts: {
    type: Boolean,
    default: true
  },
  isAccessDenied: {
    type: Boolean,
    default: false
  },
  deniedMessage: {
    type: String,
    default: ''
  },
  mobileToken: {
    type: String,
    default: ''
  },
  expiresHuman: {
    type: String,
    default: ''
  },
  authCashier: {
    type: Object,
    default: () => ({ name: 'Petugas Toko', is_admin: false })
  }
});

// ================= STATE DASAR =================
const localProducts = ref([...props.products]);
const localCategories = ref([...props.categories]);

// Form State
const form = ref({
  id: null,
  barcode: '',
  name: '',
  category: '',
  unit_name: 'Pcs',
  price: '',
  cost_price: '',
  stock: 10,
  enableMultiUnit: false,
  units: []
});

const isEditing = computed(() => Boolean(form.value.id));
const isSaving = ref(false);
const saveSuccessMessage = ref('');
const errorMessage = ref('');

// ================= DETEKSI DUPLIKAT BARCODE & NAMA INSTAN =================
const duplicateBarcodeProduct = computed(() => {
  const bc = String(form.value.barcode || '').trim().toLowerCase();
  if (!bc) return null;
  return localProducts.value.find(p => 
    String(p.barcode || '').trim().toLowerCase() === bc &&
    p.id !== form.value.id
  ) || null;
});

const duplicateNameProduct = computed(() => {
  const name = String(form.value.name || '').trim().toLowerCase();
  if (!name || name.length < 3) return null;
  return localProducts.value.find(p => 
    String(p.name || '').trim().toLowerCase() === name &&
    p.id !== form.value.id
  ) || null;
});

// Opsi Mode Scan Beruntun (Continuous Stock Taking)
const continuousMode = ref(true);

// Riwayat sesi input hari ini di HP
const sessionHistory = ref([]);

// Preset Satuan Cepat & Satuan Kustom (Tidak Disingkat)
const customUnits = ref(JSON.parse(localStorage.getItem('tpk_custom_units') || '[]'));
const commonUnitsList = computed(() => {
  const base = [
    'Pcs',
    'Botol',
    'Renceng',
    'Dus',
    'Pack',
    'Kilogram',
    'Bungkus',
    'Kaleng',
    'Lusin',
    'Slop',
    'Bal',
    'Sak',
    'Ikat',
    'Liter',
    'Butir',
    'Gram',
    'Karton',
    'Lembar'
  ];
  const combined = [...base, ...customUnits.value];
  return Array.from(new Set(combined));
});

// Modal Tambah Satuan
const showAddUnitModal = ref(false);
const newUnitName = ref('');

function saveNewUnit() {
  const unit = newUnitName.value.trim();
  if (!unit) return;
  if (!customUnits.value.includes(unit)) {
    customUnits.value.push(unit);
    localStorage.setItem('tpk_custom_units', JSON.stringify(customUnits.value));
  }
  form.value.unit_name = unit;
  newUnitName.value = '';
  showAddUnitModal.value = false;
  playSound('success');
}

// Preset Kategori Cepat & Modal Tambah Kategori
const showAddCategoryModal = ref(false);
const newCategoryName = ref('');
const isSavingCategory = ref(false);

const defaultCategoryList = computed(() => {
  if (localCategories.value.length > 0) {
    return localCategories.value.map(c => c.name);
  }
  return ['Sembako', 'Makanan & Snack', 'Minuman', 'Rokok', 'Bumbu Dapur', 'Kebersihan', 'Obat & Vitamin', 'Lainnya'];
});

async function saveNewCategory() {
  const cat = newCategoryName.value.trim();
  if (!cat) return;
  isSavingCategory.value = true;
  errorMessage.value = '';
  try {
    if (window.axios) {
      const res = await window.axios.post('/pos/categories', { 
        name: cat, 
        _mobile_token: props.mobileToken || '' 
      }, {
        headers: { 
          'Accept': 'application/json',
          'X-Mobile-Token': props.mobileToken || ''
        }
      });
      if (!localCategories.value.some(c => (c.name || c).toLowerCase() === cat.toLowerCase())) {
        localCategories.value.push(res.data?.category || { id: Date.now(), name: cat, icon: '📦' });
      }
      form.value.category = cat;
      newCategoryName.value = '';
      showAddCategoryModal.value = false;
      playSound('success');
    } else {
      throw new Error('Koneksi HTTP tidak tersedia.');
    }
  } catch (err) {
    errorMessage.value = err?.response?.data?.message || 'Gagal menyimpan kategori ke server. Pastikan token HP masih berlaku.';
    playSound('warning');
  } finally {
    isSavingCategory.value = false;
  }
}

// Fitur Bersihkan Cache PWA HP & Reload Versi Terbaru
async function forceRefreshApp() {
  if (await showConfirm('Bersihkan cache ponsel dan muat ulang versi terbaru sekarang?')) {
    try {
      if ('serviceWorker' in navigator) {
        const regs = await navigator.serviceWorker.getRegistrations();
        for (let r of regs) await r.unregister();
      }
      if ('caches' in window) {
        const names = await caches.keys();
        for (let n of names) await caches.delete(n);
      }
    } catch (e) {}
    window.location.reload(true);
  }
}

// Format Rupiah Tampilan
function formatRupiah(value) {
  if (!value && value !== 0) return 'Rp 0';
  const num = typeof value === 'number' ? value : parseInt(String(value).replace(/\D/g, ''), 10) || 0;
  return 'Rp ' + num.toLocaleString('id-ID');
}

// Format angka dengan separator ribuan titik (contoh: 50000 -> 50.000)
function formatThousand(val) {
  if (val === null || val === undefined || val === '') return '';
  const num = typeof val === 'number' ? val : parseInt(String(val).replace(/\D/g, ''), 10);
  if (isNaN(num)) return '';
  return num.toLocaleString('id-ID');
}

// Handler input harga otomatis format rupiah titik ribuan (50.000) dengan menjaga posisi kursor
async function applyFormattedFieldInput(e, setter) {
  const target = e.target;
  const { formatted, nextCaret } = processCurrencyInput(e, { maxDigits: 12 });
  setter(formatted);
  await nextTick();
  if (target) {
    if (target.value !== formatted) {
      target.value = formatted;
    }
    try {
      target.setSelectionRange(nextCaret, nextCaret);
    } catch (err) {}
  }
}

function onPriceInput(e) {
  applyFormattedFieldInput(e, (v) => { form.value.price = v; });
}

function onCostPriceInput(e) {
  applyFormattedFieldInput(e, (v) => { form.value.cost_price = v; });
}

// Handler input harga satuan bertingkat / grosir
function onUnitPriceInput(idx, e) {
  applyFormattedFieldInput(e, (v) => {
    if (form.value.units[idx]) {
      form.value.units[idx].price = v;
    }
  });
}

// ================= SISTEM AUDIO BEEP (WEB AUDIO API) =================
let audioCtx = null;

function unlockAudio() {
  if (!audioCtx) {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (AudioContextClass) audioCtx = new AudioContextClass();
  }
  if (audioCtx && audioCtx.state === 'suspended') {
    audioCtx.resume();
  }
}

function playSound(type = 'scan') {
  try {
    unlockAudio();
    if (!audioCtx) return;
    const now = audioCtx.currentTime;

    if (type === 'scan' || type === 'success') {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(1850, now);
      gain.gain.setValueAtTime(0.35, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.09);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start(now);
      osc.stop(now + 0.09);
    } else if (type === 'new') {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(950, now);
      osc.frequency.linearRampToValueAtTime(1600, now + 0.12);
      gain.gain.setValueAtTime(0.4, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.14);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start(now);
      osc.stop(now + 0.14);
    } else if (type === 'error') {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(220, now);
      gain.gain.setValueAtTime(0.4, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.25);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start(now);
      osc.stop(now + 0.25);
    }
  } catch (e) {
    console.warn('Audio playback error', e);
  }
}

// ================= SCANNER KAMERA HP (HTML5-QRCODE DENGAN CACHE KAMERA & SENTER) =================
const isCameraActive = ref(false);
const cameraError = ref('');
const isTorchOn = ref(false);
const hasTorch = ref(false);
const availableCameras = ref([]);
const selectedCameraId = ref(localStorage.getItem('tpk_mobile_camera_id') || '');
let html5QrCode = null;
let lastScannedCode = '';
let lastScanTime = 0;

// Deteksi dan simpan cache kamera
async function loadAvailableCameras() {
  try {
    const devices = await Html5Qrcode.getCameras();
    if (devices && devices.length > 0) {
      availableCameras.value = devices.map((d, i) => ({
        id: d.id,
        label: d.label || `Kamera ${i + 1}`
      }));

      // Jika belum ada kamera yang tersimpan atau kamera lama tidak ditemukan
      const currentValid = availableCameras.value.some(c => c.id === selectedCameraId.value);
      if (!selectedCameraId.value || !currentValid) {
        // Utamakan kamera belakang
        const backCam = availableCameras.value.find(c => 
          /back|belakang|rear|environment/i.test(c.label)
        ) || availableCameras.value[availableCameras.value.length - 1]; // Android biasanya menaruh kamera belakang di akhir
        
        selectedCameraId.value = backCam.id;
        localStorage.setItem('tpk_mobile_camera_id', backCam.id);
      }
    }
  } catch (err) {
    console.warn('Gagal membaca daftar kamera:', err);
  }
}

// Ganti kamera dan simpan permanen ke cache (localStorage)
async function onCameraChange(newCameraId) {
  if (!newCameraId) return;
  selectedCameraId.value = newCameraId;
  localStorage.setItem('tpk_mobile_camera_id', newCameraId);
  
  if (isCameraActive.value) {
    await stopCameraScanner();
    await startCameraScanner();
  }
}

async function startCameraScanner() {
  unlockAudio();
  cameraError.value = '';
  isCameraActive.value = true;
  hasTorch.value = false;
  isTorchOn.value = false;

  await nextTick();

  // Muat daftar kamera
  await loadAvailableCameras();

  try {
    if (!html5QrCode) {
      html5QrCode = new Html5Qrcode('mobile-barcode-reader', {
        formatsToSupport: [
          Html5QrcodeSupportedFormats.EAN_13,
          Html5QrcodeSupportedFormats.EAN_8,
          Html5QrcodeSupportedFormats.UPC_A,
          Html5QrcodeSupportedFormats.UPC_E,
          Html5QrcodeSupportedFormats.CODE_128,
          Html5QrcodeSupportedFormats.CODE_39,
          Html5QrcodeSupportedFormats.QR_CODE
        ],
        verbose: false,
        useBarCodeDetectorIfSupported: true,
        experimentalFeatures: {
          useBarCodeDetectorIfSupported: true
        }
      });
    }

    const config = {
      fps: 25,
      qrbox: (viewfinderWidth, viewfinderHeight) => {
        const w = Math.floor(viewfinderWidth * 0.92);
        const h = Math.floor(Math.min(viewfinderHeight * 0.65, 200));
        return { width: Math.max(240, w), height: Math.max(120, h) };
      },
      aspectRatio: 1.333333,
      disableFlip: false
    };

    // Gunakan kamera dari cache jika ada, jika tidak fallback ke environment
    let cameraConfig = { facingMode: 'environment' };
    if (selectedCameraId.value) {
      cameraConfig = selectedCameraId.value;
    }

    try {
      await html5QrCode.start(
        cameraConfig,
        config,
        onBarcodeDetected,
        () => {}
      );
    } catch (startErr) {
      console.warn('Start dengan camera ID cache gagal, coba fallback environment:', startErr);
      await html5QrCode.start(
        { facingMode: 'environment' },
        config,
        onBarcodeDetected,
        () => {}
      );
    }

    // Refresh daftar kamera setelah izin aktif (label kamera kini terbaca)
    await loadAvailableCameras();

    // Deteksi ketersediaan senter (torch) & autofocus hardware
    setTimeout(() => {
      detectTorchAndAutofocus();
    }, 300);

  } catch (err) {
    console.error('Camera start failed', err);
    cameraError.value = 'Izin kamera belum diberikan atau kamera tidak terdeteksi.';
    isCameraActive.value = false;
  }
}

// Deteksi senter dan optimasi fokus kamera
function detectTorchAndAutofocus() {
  try {
    const videoEl = document.querySelector('#mobile-barcode-reader video');
    if (!videoEl || !videoEl.srcObject) return;
    const stream = videoEl.srcObject;
    const track = stream.getVideoTracks ? stream.getVideoTracks()[0] : null;
    if (!track) return;

    const caps = track.getCapabilities ? track.getCapabilities() : {};
    
    // Kunci Continuous Autofocus Hardware jika didukung HP
    if (caps.focusMode && Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) {
      track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(() => {});
    }

    // Periksa fitur torch / senter
    hasTorch.value = !!caps.torch;
  } catch (e) {
    hasTorch.value = false;
  }
}

async function stopCameraScanner() {
  if (isTorchOn.value) {
    try {
      await toggleTorch();
    } catch (e) {}
  }
  isTorchOn.value = false;
  hasTorch.value = false;

  if (html5QrCode && html5QrCode.isScanning) {
    try {
      await html5QrCode.stop();
    } catch (err) {
      console.warn('Error stopping camera', err);
    }
  }
  isCameraActive.value = false;
}

// Menghidupkan / mematikan lampu senter kamera HP
async function toggleTorch() {
  try {
    const nextTorchState = !isTorchOn.value;

    // Cara 1: Direct Video Track constraints (Paling handal di Android Chrome)
    const videoEl = document.querySelector('#mobile-barcode-reader video');
    const track = videoEl?.srcObject?.getVideoTracks ? videoEl.srcObject.getVideoTracks()[0] : null;
    if (track && track.applyConstraints) {
      await track.applyConstraints({
        advanced: [{ torch: nextTorchState }]
      });
      isTorchOn.value = nextTorchState;
      return;
    }

    // Cara 2: html5QrCode applyVideoConstraints
    if (html5QrCode && html5QrCode.isScanning) {
      await html5QrCode.applyVideoConstraints({
        advanced: [{ torch: nextTorchState }]
      });
      isTorchOn.value = nextTorchState;
    }
  } catch (e) {
    console.warn('Senter tidak didukung pada kamera ini:', e);
  }
}

function onBarcodeDetected(decodedText) {
  const code = String(decodedText || '').trim();
  if (!code) return;

  const now = Date.now();
  // Cooldown 1.5 detik jika membaca barcode sama berulang kali
  if (code === lastScannedCode && (now - lastScanTime) < 1500) {
    return;
  }

  lastScannedCode = code;
  lastScanTime = now;

  stopCameraScanner();
  handleBarcodeScanned(code);
}

// ================= LOGIKA DETEKSI & PENGISIAN BARCODE =================
function handleBarcodeScanned(barcode) {
  errorMessage.value = '';
  saveSuccessMessage.value = '';

  const cleanBarcode = String(barcode).trim();
  form.value.barcode = cleanBarcode;

  // 1. Cek di daftar produk lokal
  const existing = localProducts.value.find(p => String(p.barcode || '').trim() === cleanBarcode);

  if (existing) {
    // Mode EDIT / UPDATE
    playSound('scan');
    loadProductToForm(existing);
  } else {
    // Mode TAMBAH BARANG BARU
    playSound('new');
    initNewProductForm(cleanBarcode);
  }
}

function loadProductToForm(p) {
  form.value.id = p.id;
  form.value.barcode = p.barcode || '';
  form.value.name = p.name || '';
  form.value.category = (p.category && p.category.name) || p.category_name || (typeof p.category === 'string' ? p.category : '') || localCategories.value[0]?.name || 'Sembako';
  
  const baseUnit = (p.units && p.units.find(u => u.is_base)) || (p.units && p.units[0]) || { name: p.base_unit || 'Pcs', price: p.price || 0 };
  form.value.unit_name = baseUnit.name;
  form.value.price = baseUnit.price ? formatThousand(baseUnit.price) : '';
  form.value.cost_price = p.cost_price ? formatThousand(p.cost_price) : '';
  form.value.stock = p.stock !== undefined && p.stock !== null ? p.stock : 10;

  const extraUnits = (p.units && p.units.filter(u => u !== baseUnit && !u.is_base)) || [];
  if (extraUnits.length > 0) {
    form.value.enableMultiUnit = true;
    form.value.units = extraUnits.map(u => ({
      name: u.name,
      ratio: u.ratio || 1,
      price: u.price ? formatThousand(u.price) : '',
      is_base: false
    }));
  } else {
    form.value.enableMultiUnit = false;
    form.value.units = [];
  }
}

function initNewProductForm(prefillBarcode = '') {
  form.value.id = null;
  form.value.barcode = prefillBarcode;
  form.value.name = '';
  form.value.category = localCategories.value[0]?.name || 'Sembako';
  form.value.unit_name = 'Pcs';
  form.value.price = '';
  form.value.cost_price = '';
  form.value.stock = 10;
  form.value.enableMultiUnit = false;
  form.value.units = [];

  // Fokuskan ke nama produk
  nextTick(() => {
    const el = document.getElementById('mobile-product-name');
    if (el) el.focus();
  });
}

function resetForm() {
  form.value = {
    id: null,
    barcode: '',
    name: '',
    category: localCategories.value[0]?.name || 'Sembako',
    unit_name: 'Pcs',
    price: '',
    cost_price: '',
    stock: 10,
    enableMultiUnit: false,
    units: []
  };
  saveSuccessMessage.value = '';
  errorMessage.value = '';
}

// Stepper stok cepat
function adjustStock(delta) {
  const current = parseInt(form.value.stock, 10) || 0;
  form.value.stock = Math.max(0, current + delta);
}

// Tambah satuan bertingkat
function addExtraUnit() {
  form.value.units.push({
    name: 'Dus',
    ratio: 12,
    price: 0,
    is_base: false
  });
}

function removeExtraUnit(index) {
  form.value.units.splice(index, 1);
  if (form.value.units.length === 0) {
    form.value.enableMultiUnit = false;
  }
}

// ================= SIMPAN KE DATABASE & OFFLINE (FAST AXIOS API) =================
async function submitProduct() {
  if (!props.canManageProducts) {
    await showAlert('Akses Dibatasi: Anda tidak memiliki izin untuk mengelola katalog produk.');
    return;
  }

  if (!form.value.name.trim()) {
    errorMessage.value = 'Nama produk wajib diisi!';
    playSound('error');
    return;
  }

  if (!form.value.barcode.trim()) {
    errorMessage.value = 'Barcode wajib diisi!';
    playSound('error');
    return;
  }

  // Cegah simpan jika barcode duplikat dengan produk lain
  if (duplicateBarcodeProduct.value) {
    errorMessage.value = `Barcode "${form.value.barcode}" sudah dipakai oleh produk "${duplicateBarcodeProduct.value.name}". Barcode tidak boleh duplikat!`;
    playSound('error');
    return;
  }

  isSaving.value = true;
  errorMessage.value = '';
  saveSuccessMessage.value = '';

  const parsedBasePrice = parseInt(String(form.value.price).replace(/\D/g, ''), 10) || 0;
  const parsedCostPrice = parseInt(String(form.value.cost_price).replace(/\D/g, ''), 10) || 0;
  const parsedStock = parseInt(form.value.stock, 10) || 0;

  // Siapkan array satuan
  const unitList = [
    {
      name: form.value.unit_name || 'Pcs',
      price: parsedBasePrice,
      ratio: 1,
      is_base: true
    }
  ];

  if (form.value.enableMultiUnit && form.value.units.length > 0) {
    form.value.units.forEach(u => {
      unitList.push({
        name: u.name || 'Dus',
        price: parseInt(String(u.price).replace(/\D/g, ''), 10) || 0,
        ratio: parseInt(u.ratio, 10) || 1,
        is_base: false
      });
    });
  }

  const payload = {
    barcode: form.value.barcode.trim(),
    name: form.value.name.trim(),
    category: form.value.category,
    unit_name: form.value.unit_name || 'Pcs',
    price: parsedBasePrice,
    cost_price: parsedCostPrice,
    stock: parsedStock,
    units: unitList,
    _mobile_token: props.mobileToken || ''
  };

  const isEditMode = Boolean(form.value.id);
  const targetUrl = isEditMode ? `/pos/products/${form.value.id}` : '/pos/products';

  try {
    const http = window.axios || (await import('axios')).default;
    const axiosHeaders = { 
      'Accept': 'application/json', 
      'Content-Type': 'application/json' 
    };
    if (props.mobileToken) {
      axiosHeaders['X-Mobile-Token'] = props.mobileToken;
    }

    const response = isEditMode 
      ? await http.put(targetUrl, payload, {
          headers: axiosHeaders,
          timeout: 10000
        })
      : await http.post(targetUrl, payload, {
          headers: axiosHeaders,
          timeout: 10000
        });

    const savedProd = response.data?.product;
    const finalId = savedProd?.id || form.value.id || Date.now();

    // Update state produk lokal langsung di memori HP
    const updatedItem = {
      id: finalId,
      barcode: payload.barcode,
      name: payload.name,
      category: { name: payload.category },
      cost_price: payload.cost_price,
      stock: payload.stock,
      base_unit: payload.unit_name,
      price: payload.price,
      units: payload.units
    };

    if (isEditMode) {
      const idx = localProducts.value.findIndex(p => p.id === form.value.id);
      if (idx !== -1) localProducts.value[idx] = updatedItem;
    } else {
      localProducts.value.unshift(updatedItem);
    }

    // Sync ke Dexie IndexedDB offline_products
    try {
      if (db && db.offline_products) {
        await db.offline_products.put({
          id: updatedItem.id,
          name: updatedItem.name,
          barcode: updatedItem.barcode,
          synced: 1,
          updated_at: new Date().toISOString()
        });
      }
    } catch (dbErr) {
      console.warn('Dexie save error:', dbErr);
    }

    // Catat ke riwayat sesi input HP
    sessionHistory.value.unshift({
      name: payload.name,
      barcode: payload.barcode,
      price: payload.price,
      stock: payload.stock,
      action: isEditMode ? 'Update' : 'Baru',
      time: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
    });

    playSound('success');
    saveSuccessMessage.value = `✓ Berhasil ${isEditMode ? 'memperbarui' : 'menyimpan'} "${payload.name}"!`;

    // Jika mode beruntun aktif: buka kamera kembali untuk scan produk selanjutnya
    if (continuousMode.value) {
      resetForm();
      setTimeout(() => {
        startCameraScanner();
      }, 350);
    } else {
      setTimeout(() => {
        saveSuccessMessage.value = '';
      }, 4000);
    }
  } catch (err) {
    playSound('error');
    if (err.response) {
      if (err.response.status === 422 && err.response.data?.errors) {
        const firstErr = Object.values(err.response.data.errors)[0];
        errorMessage.value = Array.isArray(firstErr) ? firstErr[0] : firstErr;
      } else if (err.response.data?.message) {
        errorMessage.value = err.response.data.message;
      } else if (err.response.status === 419) {
        errorMessage.value = 'Sesi habis (CSRF token expired). Silakan refresh halaman HP.';
      } else {
        errorMessage.value = `Gagal menyimpan: Error ${err.response.status}`;
      }
    } else if (err.code === 'ECONNABORTED' || err.message?.includes('timeout')) {
      errorMessage.value = 'Waktu penyimpanan habis (Timeout). Silakan periksa koneksi atau coba klik Simpan lagi.';
    } else {
      errorMessage.value = err.message || 'Gagal menyimpan barang. Periksa koneksi internet.';
    }
  } finally {
    isSaving.value = false;
  }
}

// Navigasi Kembali ke Kasir
function goBackToPos() {
  stopCameraScanner();
  router.visit(route('pos.index'));
}

onMounted(() => {
  unlockAudio();
  loadAvailableCameras();
  if (props.mobileToken && window.axios) {
    window.axios.defaults.headers.common['X-Mobile-Token'] = props.mobileToken;
  }
  try {
    if (window.screen && window.screen.orientation && window.screen.orientation.unlock) {
      window.screen.orientation.unlock();
    }
  } catch (e) {}
});

onUnmounted(() => {
  stopCameraScanner();
});
</script>

<template>
  <Head :title="`Input & Cek Barang HP · ${storeName}`" />

  <!-- TAMPILAN JIKA AKSES HP DIKUNCI / KADALUARSA -->
  <div v-if="props.isAccessDenied" class="min-h-screen min-h-[100dvh] w-full bg-slate-100 flex items-center justify-center p-4 font-sans select-none">
    <div class="bg-white rounded-3xl p-6 w-full max-w-sm shadow-xl border border-slate-200 text-center flex flex-col items-center gap-3">
      <div class="w-16 h-16 rounded-2xl bg-amber-50 text-amber-600 border border-amber-200 flex items-center justify-center text-3xl shadow-xs">
        🔒
      </div>
      <h2 class="text-base font-black text-slate-900">Akses Input HP Terkunci</h2>
      <p class="text-xs text-slate-600 leading-relaxed">
        {{ props.deniedMessage || 'Akses input barang dari HP belum diaktifkan atau masa berlaku barcode telah habis.' }}
      </p>
      <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl text-[11px] text-slate-500 leading-normal text-left w-full">
        <strong class="text-slate-700 block mb-1">Cara Menghubungkan HP:</strong>
        1. Buka tablet kasir utama.<br/>
        2. Tekan menu <strong>Input Barang HP</strong>.<br/>
        3. Scan QR Barcode terbaru yang muncul di layar kasir.
      </div>
      <button 
        type="button" 
        @click="window.location.reload()" 
        class="mt-1 w-full py-2.5 bg-teal-600 hover:bg-teal-700 active:scale-95 text-white font-bold text-xs uppercase rounded-xl transition cursor-pointer shadow-xs"
      >
        🔄 Cek Ulang Akses
      </button>
    </div>
  </div>

  <!-- Container utama dengan scroll alami pas di layar smartphone -->
  <div v-else class="min-h-screen min-h-[100dvh] w-full bg-slate-100 text-slate-800 flex flex-col font-sans pb-28">
    
    <!-- ================= 1. HEADER MOBILE RESPONSIVE ================= -->
    <header class="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-slate-200 py-2.5 shadow-xs">
      <div class="w-[92%] max-w-sm sm:max-w-md mx-auto flex items-center justify-between">
        <div class="flex items-center gap-2">
          <button 
            type="button" 
            @click="goBackToPos"
            class="w-9 h-9 rounded-xl bg-slate-100 hover:bg-slate-200 active:scale-95 text-slate-800 font-black text-base flex items-center justify-center transition cursor-pointer border border-slate-200"
            title="Kembali ke Meja Kasir"
          >
            ←
          </button>
          <div>
            <h1 class="text-sm font-black text-slate-900 leading-tight flex items-center gap-1.5">
              <span>📦</span> Input Barang HP
            </h1>
            <p class="text-[10px] text-slate-500 font-medium leading-tight mt-0.5 flex items-center gap-1.5 flex-wrap">
              <span>{{ props.authCashier?.name || 'Petugas' }}</span>
              <span v-if="props.expiresHuman" class="text-[9px] bg-emerald-100 text-emerald-800 px-1.5 py-0.5 rounded-md font-bold">
                ● Aktif s/d {{ props.expiresHuman }}
              </span>
            </p>
          </div>
        </div>

        <div class="flex items-center gap-1.5">
          <!-- Tombol Segarkan PWA (Bersihkan Cache Ponsel) -->
          <button
            type="button"
            @click="forceRefreshApp"
            class="px-2 py-1.5 rounded-xl text-[10px] font-bold border border-slate-200 bg-slate-50 hover:bg-slate-100 text-slate-700 transition flex items-center gap-1 cursor-pointer active:scale-95 shadow-2xs"
            title="Bersihkan Cache & Muat Ulang Versi Terbaru"
          >
            <span>🔄</span>
            <span class="hidden xs:inline">Segarkan</span>
          </button>

          <!-- Saklar Mode Beruntun (Continuous Scan) -->
          <button
            type="button"
            @click="continuousMode = !continuousMode"
            class="px-2.5 py-1.5 rounded-xl text-[11px] font-black border transition flex items-center gap-1 cursor-pointer shadow-2xs"
            :class="continuousMode ? 'bg-amber-400 text-slate-950 border-amber-500' : 'bg-slate-100 text-slate-600 border-slate-300'"
            title="Mode Scan Beruntun (Otomatis buka kamera setelah simpan)"
          >
            <span class="text-xs">⚡</span>
            <span>{{ continuousMode ? 'Auto' : 'Manual' }}</span>
          </button>
        </div>
      </div>
    </header>

    <!-- WRAPPER KONTEN TENGAH BERSIH & RESPONSIVE (FLUID 92% WIDTH) -->
    <div class="w-[92%] max-w-sm sm:max-w-md mx-auto flex flex-col flex-1 pt-3">

      <!-- ================= 2. VIEWPORT SCANNER KAMERA HP ================= -->
      <div v-show="isCameraActive" class="bg-black text-white p-3 rounded-2xl mb-3 flex flex-col items-center relative z-30 transition-all border border-slate-700 shadow-xl">
        <!-- Header Viewfinder: Pemilih Kamera (Cache Terpilih) + Tombol Senter + Tutup -->
        <div class="w-full flex items-center justify-between pb-2 gap-2">
          <!-- Pemilih Kamera HP (Dropdown Tersimpan di Cache) -->
          <div class="flex items-center gap-1.5 flex-1 min-w-0">
            <span class="text-xs">📷</span>
            <select
              v-if="availableCameras.length > 1"
              :value="selectedCameraId"
              @change="onCameraChange($event.target.value)"
              class="bg-slate-800 text-slate-100 text-[11px] font-bold rounded-lg px-2 py-1 border border-slate-700 truncate max-w-[170px] cursor-pointer"
              title="Pilih Kamera HP & simpan sebagai default"
            >
              <option v-for="cam in availableCameras" :key="cam.id" :value="cam.id">
                {{ cam.label || 'Kamera' }}
              </option>
            </select>
            <span v-else class="text-xs font-black text-amber-400 truncate">
              Arahkan Barcode
            </span>
          </div>

          <div class="flex items-center gap-1.5 shrink-0">
            <!-- Tombol Senter (Flash/Torch) untuk Rak Gelap -->
            <button
              type="button"
              @click="toggleTorch"
              class="px-2.5 py-1 rounded-lg text-xs font-black border transition cursor-pointer flex items-center gap-1 shadow-xs"
              :class="isTorchOn ? 'bg-amber-400 text-slate-950 border-amber-400 animate-pulse' : 'bg-slate-800 text-slate-200 hover:text-white border-slate-700'"
              title="Nyalakan Lampu Senter HP untuk Rak Gelap"
            >
              <span>🔦</span>
              <span>{{ isTorchOn ? 'Senter ON' : 'Senter' }}</span>
            </button>

            <!-- Tombol Tutup Kamera -->
            <button
              type="button"
              @click="stopCameraScanner"
              class="w-7 h-7 rounded-lg bg-slate-800 text-slate-200 hover:text-white flex items-center justify-center font-black text-xs cursor-pointer border border-slate-700"
              title="Tutup Kamera"
            >
              ✕
            </button>
          </div>
        </div>

        <!-- Kotak Viewfinder Kamera HTML5-QRCode -->
        <div class="w-full h-52 rounded-xl overflow-hidden border-2 border-dashed border-amber-400 relative bg-slate-950 flex items-center justify-center shadow-inner">
          <div id="mobile-barcode-reader" class="w-full h-full object-cover"></div>
          <!-- Garis Laser Merah Pemindai Animasi -->
          <div class="absolute inset-x-4 top-1/2 -translate-y-1/2 h-1 bg-rose-500 shadow-[0_0_16px_#f43f5e] pointer-events-none animate-pulse"></div>
        </div>
        <span class="text-[11px] font-medium text-slate-400 pt-1.5">Mendukung Barcode EAN-13, EAN-8, UPC, Code 128, & QR</span>
      </div>

      <!-- Error Kamera jika ada -->
      <div v-if="cameraError" class="p-2.5 mb-2.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-xs font-bold flex items-center justify-between">
        <span>⚠️ {{ cameraError }}</span>
        <button @click="cameraError = ''" class="font-black text-sm text-rose-900 px-1">✕</button>
      </div>

      <!-- Notifikasi Berhasil / Gagal -->
      <div v-if="saveSuccessMessage" class="mb-2.5 p-2.5 bg-emerald-50 border border-emerald-300 text-emerald-900 rounded-xl text-xs font-black text-center shadow-2xs">
        {{ saveSuccessMessage }}
      </div>
      <div v-if="errorMessage" class="mb-2.5 p-2.5 bg-rose-50 border border-rose-300 text-rose-900 rounded-xl text-xs font-black text-center shadow-2xs">
        ⚠️ {{ errorMessage }}
      </div>

      <!-- Status Barcode Terdaftar (Edit vs Baru) -->
      <div v-if="form.barcode" class="mb-2.5">
        <div 
          class="p-2.5 rounded-xl border flex items-center justify-between shadow-2xs transition"
          :class="isEditing ? 'bg-blue-50/90 border-blue-300 text-blue-950' : 'bg-emerald-50/90 border-emerald-300 text-emerald-950'"
        >
          <div class="flex items-center gap-2 truncate">
            <span class="text-base">{{ isEditing ? '✏️' : '✨' }}</span>
            <div class="truncate">
              <span class="text-[10px] font-black uppercase tracking-wider block">
                {{ isEditing ? 'Barang Sudah Terdaftar (Mode Edit)' : 'Barang Baru (Belum Terdaftar)' }}
              </span>
              <span class="text-xs font-mono font-black">{{ form.barcode }}</span>
            </div>
          </div>
          <button 
            type="button" 
            @click="resetForm" 
            class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition cursor-pointer shrink-0"
          >
            Reset
          </button>
        </div>
      </div>

      <!-- ================= 3. SINGLE UNIFIED CARD FORM (RESPONSIVE 2-KOLOM GRID) ================= -->
      <main class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3.5 flex flex-col gap-3">

        <!-- Baris 1: Barcode & Tombol Kamera Terintegrasi (Bersisian!) -->
        <div>
          <label class="text-[11px] font-black text-slate-700 uppercase tracking-wider block mb-1">
            Nomor Barcode:
          </label>
          <div class="flex items-center gap-2">
            <div class="relative flex-1">
              <input
                type="text"
                v-model="form.barcode"
                inputmode="numeric"
                @change="handleBarcodeScanned(form.barcode)"
                placeholder="Scan / ketik barcode..."
                class="w-full bg-slate-50 border border-slate-200 focus:bg-white focus:border-teal-500 rounded-xl pl-3 pr-16 py-2 text-sm font-mono font-bold text-slate-900 transition"
                :class="duplicateBarcodeProduct ? 'border-rose-400 bg-rose-50/50 text-rose-950' : ''"
              />
              <button
                type="button"
                @click="handleBarcodeScanned(form.barcode)"
                class="absolute right-1 top-1 bottom-1 px-2.5 bg-slate-200 hover:bg-slate-300 active:scale-95 rounded-lg text-slate-800 font-black text-xs flex items-center cursor-pointer transition"
                title="Cari nomor barcode ini"
              >
                🔍
              </button>
            </div>

            <!-- Tombol Scan Kamera Bersisian (Bukan Strip Lebar!) -->
            <button
              type="button"
              @click="startCameraScanner"
              class="shrink-0 px-3 py-2 bg-teal-600 hover:bg-teal-700 active:scale-95 text-white font-black text-xs rounded-xl shadow-xs flex items-center gap-1.5 transition cursor-pointer"
              title="Buka Kamera Scan HP"
            >
              <span>📷</span>
              <span>Scan</span>
            </button>
          </div>

          <!-- Alert Deteksi Duplikat Barcode Instan -->
          <div 
            v-if="duplicateBarcodeProduct" 
            class="mt-1.5 p-2 bg-rose-50 border border-rose-300 rounded-xl flex items-center justify-between gap-2 text-rose-950 animate-in fade-in duration-150"
          >
            <div class="truncate text-[11px] leading-tight">
              <span class="font-black text-rose-700 block">⚠️ Barcode Sudah Dipakai!</span>
              <span class="truncate block">Milik: <strong>{{ duplicateBarcodeProduct.name }}</strong> (Rp {{ formatRupiah(duplicateBarcodeProduct.price || duplicateBarcodeProduct.units?.[0]?.price) }})</span>
            </div>
            <button
              type="button"
              @click="loadProductToForm(duplicateBarcodeProduct)"
              class="shrink-0 px-2.5 py-1 bg-amber-500 hover:bg-amber-600 active:scale-95 text-white font-black text-[10px] rounded-lg shadow-2xs transition cursor-pointer"
              title="Buka dan edit barang yang memakai barcode ini"
            >
              ✏️ Edit
            </button>
          </div>
        </div>

        <div class="h-px bg-slate-100"></div>

        <!-- Baris 2: Nama Produk -->
        <div>
          <label class="text-[11px] font-black text-slate-700 uppercase tracking-wider flex items-center justify-between mb-1">
            <span>Nama Barang:</span>
            <span class="text-rose-500 font-bold text-[10px]">*Wajib</span>
          </label>
          <input
            id="mobile-product-name"
            type="text"
            v-model="form.name"
            placeholder="Contoh: Minyak Goreng Bimoli 2L"
            class="w-full bg-slate-50 border border-slate-200 focus:bg-white focus:border-teal-500 rounded-xl px-3 py-2 text-sm font-bold text-slate-900 transition placeholder:text-slate-400"
            :class="duplicateNameProduct ? 'border-amber-400 bg-amber-50/40 text-amber-950' : ''"
          />

          <!-- Alert Deteksi Duplikat Nama Instan -->
          <div 
            v-if="duplicateNameProduct" 
            class="mt-1.5 p-2 bg-amber-50 border border-amber-300 rounded-xl flex items-center justify-between gap-2 text-amber-950 animate-in fade-in duration-150"
          >
            <div class="truncate text-[11px] leading-tight">
              <span class="font-black text-amber-800 block">ℹ️ Nama Produk Serupa Terdaftar</span>
              <span class="truncate block"><strong>{{ duplicateNameProduct.name }}</strong> · Barcode: {{ duplicateNameProduct.barcode || 'Tanpa Barcode' }}</span>
            </div>
            <button
              type="button"
              @click="loadProductToForm(duplicateNameProduct)"
              class="shrink-0 px-2.5 py-1 bg-amber-600 hover:bg-amber-700 active:scale-95 text-white font-black text-[10px] rounded-lg shadow-2xs transition cursor-pointer"
              title="Buka dan lihat produk dengan nama ini"
            >
              Lihat
            </button>
          </div>
        </div>

        <!-- Baris 3: Kategori Barang (Scrollable Chips + Tambah Kategori Cepat) -->
        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="text-[11px] font-black text-slate-700 uppercase tracking-wider">Kategori:</label>
            <div class="flex items-center gap-1.5">
              <span class="text-[10px] font-black text-teal-800 bg-teal-50 px-2 py-0.5 rounded border border-teal-200 truncate max-w-[120px]">
                {{ form.category || 'Pilih' }}
              </span>
              <button
                type="button"
                @click="showAddCategoryModal = true"
                class="px-2 py-0.5 bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-300 rounded text-[10px] font-black flex items-center gap-1 active:scale-95 transition cursor-pointer"
                title="Tambah Kategori Baru"
              >
                <span>➕</span>
                <span>Baru</span>
              </button>
            </div>
          </div>
          <div class="flex items-center gap-1.5 overflow-x-auto pb-1 scrollbar-none">
            <button
              v-for="catName in defaultCategoryList"
              :key="catName"
              type="button"
              @click="form.category = catName"
              class="shrink-0 px-2.5 py-1 rounded-lg text-xs font-bold transition border cursor-pointer active:scale-95"
              :class="form.category === catName ? 'bg-teal-600 text-white border-teal-600 shadow-2xs' : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100'"
            >
              {{ catName }}
            </button>
          </div>
        </div>

        <div class="h-px bg-slate-100"></div>

        <!-- Baris 4 (2 KOLOM RESPONSIVE): Harga Jual (Kiri) & Satuan (Kanan) -->
        <div class="grid grid-cols-2 gap-2.5">
          <!-- Kolom Kiri: Harga Jual -->
          <div>
            <label class="text-[11px] font-black text-slate-700 uppercase tracking-wider block mb-1">
              Harga Jual (Kasir):
            </label>
            <div class="relative">
              <span class="absolute left-2.5 top-2 text-xs font-black text-slate-400">Rp</span>
              <input
                type="text"
                :value="form.price"
                @input="onPriceInput"
                inputmode="numeric"
                placeholder="0"
                class="w-full bg-slate-50 border border-slate-200 focus:bg-white focus:border-teal-500 rounded-xl pl-8 pr-2.5 py-1.5 text-sm font-black font-mono text-slate-900 transition"
              />
            </div>
          </div>

          <!-- Kolom Kanan: Pilihan Satuan Dasar (Tombol Tambah Tunggal di Kanan) -->
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="text-[11px] font-black text-slate-700 uppercase tracking-wider">
                Satuan:
              </label>
              <button
                type="button"
                @click="showAddUnitModal = true"
                class="px-2 py-0.5 bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-300 rounded text-[10px] font-black flex items-center gap-1 active:scale-95 transition cursor-pointer"
                title="Tambah Satuan Baru"
              >
                <span>➕</span>
                <span>Baru</span>
              </button>
            </div>
            <select
              v-model="form.unit_name"
              class="w-full bg-slate-50 border border-slate-200 focus:bg-white focus:border-teal-500 rounded-xl px-2.5 py-1.5 text-sm font-bold text-slate-900 transition cursor-pointer"
            >
              <option v-for="u in commonUnitsList" :key="u" :value="u">{{ u }}</option>
            </select>
          </div>
        </div>

        <!-- Baris 4B (2 KOLOM): Harga Modal / HPP (Kiri) & Stok Fisik (Kanan) -->
        <div class="grid grid-cols-2 gap-2.5">
          <div>
            <label class="text-[11px] font-black text-slate-700 uppercase tracking-wider block mb-1">
              Harga Modal / HPP:
            </label>
            <div class="relative">
              <span class="absolute left-2.5 top-2 text-xs font-black text-slate-400">Rp</span>
              <input
                type="text"
                :value="form.cost_price"
                @input="onCostPriceInput"
                inputmode="numeric"
                placeholder="Opsional (0)"
                class="w-full bg-slate-50 border border-slate-200 focus:bg-white focus:border-teal-500 rounded-xl pl-8 pr-2.5 py-1.5 text-sm font-black font-mono text-slate-900 transition"
              />
            </div>
          </div>
          <div>
            <label class="text-[11px] font-black text-slate-700 uppercase tracking-wider block mb-1">
              Stok Fisik ({{ form.unit_name }}):
            </label>
            <input
              type="text"
              inputmode="numeric"
              :value="form.stock"
              @input="(e) => { form.stock = parseInt(String(e.target.value).replace(/\D/g, ''), 10) || 0; }"
              placeholder="10"
              class="w-full bg-slate-50 border border-slate-200 focus:bg-white focus:border-teal-500 rounded-xl px-3 py-1.5 text-sm font-black font-mono text-slate-900 transition"
            />
          </div>
        </div>

        <!-- Baris 5: Satuan Bertingkat (Grosir / Multi-Unit) Expandable -->
        <div class="pt-2 border-t border-slate-100">
          <div class="flex items-center justify-between">
            <label class="text-[11px] font-black text-slate-800 uppercase tracking-wider flex items-center gap-1.5 cursor-pointer">
              <input
                type="checkbox"
                v-model="form.enableMultiUnit"
                class="w-4 h-4 text-teal-600 rounded cursor-pointer"
              />
              <span>Satuan Bertingkat / Grosir</span>
            </label>
            <button
              v-if="form.enableMultiUnit"
              type="button"
              @click="addExtraUnit"
              class="text-[10px] font-black text-teal-700 bg-teal-50 hover:bg-teal-100 px-2 py-0.5 rounded-lg border border-teal-200 transition cursor-pointer"
            >
              + Tambah
            </button>
          </div>

          <div v-if="form.enableMultiUnit" class="flex flex-col gap-2 pt-2 mt-2 border-t border-slate-100">
            <div
              v-for="(u, idx) in form.units"
              :key="idx"
              class="p-2.5 bg-slate-50 border border-slate-200 rounded-xl flex flex-col gap-1.5 relative"
            >
              <div class="flex items-center justify-between">
                <span class="text-[10px] font-black text-slate-600 uppercase">Satuan Tambahan #{{ idx + 1 }}</span>
                <button
                  type="button"
                  @click="removeExtraUnit(idx)"
                  class="text-rose-600 hover:text-rose-700 text-xs font-bold cursor-pointer"
                >
                  Hapus ✕
                </button>
              </div>

              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="text-[9px] text-slate-500 font-bold block mb-0.5">Nama Satuan:</label>
                  <input
                    type="text"
                    v-model="u.name"
                    placeholder="Dus / Pack"
                    class="w-full bg-white border border-slate-200 rounded-lg px-2 py-1 text-xs font-bold text-slate-900"
                  />
                </div>
                <div>
                  <label class="text-[9px] text-slate-500 font-bold block mb-0.5">Isi (Rasio):</label>
                  <div class="flex items-center gap-1">
                    <input
                      type="number"
                      v-model="u.ratio"
                      min="1"
                      class="w-full bg-white border border-slate-200 rounded-lg px-2 py-1 text-xs font-bold text-slate-900"
                    />
                    <span class="text-[10px] text-slate-500 font-bold">{{ form.unit_name }}</span>
                  </div>
                </div>
              </div>

              <div>
                <label class="text-[9px] text-slate-500 font-bold block mb-0.5">Harga Jual Satuan Ini:</label>
                <div class="relative">
                  <span class="absolute left-2 top-1 text-[10px] font-black text-slate-400">Rp</span>
                  <input
                    type="text"
                    :value="u.price"
                    @input="onUnitPriceInput(idx, $event)"
                    inputmode="numeric"
                    placeholder="0"
                    class="w-full bg-white border border-slate-200 rounded-lg pl-6 pr-2 py-1 text-xs font-black font-mono text-slate-900"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>

      </main>

      <!-- Riwayat Input Hari Ini -->
      <div v-if="sessionHistory.length > 0" class="mt-3 bg-white p-3 rounded-2xl border border-slate-200 shadow-2xs flex flex-col gap-1.5">
        <div class="flex items-center justify-between border-b border-slate-100 pb-1">
          <span class="text-[11px] font-black text-slate-700 uppercase tracking-wider">
            Riwayat Input HP ({{ sessionHistory.length }})
          </span>
          <button
            type="button"
            @click="sessionHistory = []"
            class="text-[10px] text-slate-400 hover:text-slate-600 font-bold cursor-pointer"
          >
            Bersihkan
          </button>
        </div>

        <div class="max-h-36 overflow-y-auto flex flex-col gap-1">
          <div
            v-for="(hist, hIdx) in sessionHistory"
            :key="hIdx"
            class="p-1.5 bg-slate-50 border border-slate-200/80 rounded-lg flex items-center justify-between text-xs"
          >
            <div class="truncate pr-2">
              <div class="font-bold text-slate-900 text-xs truncate">{{ hist.name }}</div>
              <div class="text-[9px] text-slate-400 font-mono">
                {{ hist.barcode }} · {{ hist.action }} @ {{ hist.time }}
              </div>
            </div>
            <div class="text-right shrink-0">
              <div class="font-black text-emerald-600 text-xs font-mono">{{ formatRupiah(hist.price) }}</div>
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- ================= 4. STICKY BOTTOM ACTION BAR (SEJAJAR DENGAN KONTEN W-[92%]) ================= -->
    <div class="fixed bottom-0 inset-x-0 z-40 bg-white/95 backdrop-blur-md border-t border-slate-200 py-2.5 shadow-xl">
      <div class="w-[92%] max-w-sm sm:max-w-md mx-auto flex items-center gap-2">
        <button
          type="button"
          @click="resetForm"
          class="py-2.5 px-4 bg-slate-100 hover:bg-slate-200 active:scale-95 text-slate-800 font-bold text-xs uppercase tracking-wider rounded-xl transition cursor-pointer border border-slate-200"
        >
          Batal
        </button>

        <button
          type="button"
          @click="submitProduct"
          :disabled="isSaving || Boolean(duplicateBarcodeProduct)"
          class="flex-1 py-2.5 px-4 text-white font-black text-xs uppercase tracking-wider rounded-xl shadow-md transition cursor-pointer flex items-center justify-center gap-1.5 disabled:opacity-50"
          :class="duplicateBarcodeProduct ? 'bg-slate-300 text-slate-500 cursor-not-allowed border border-slate-200 shadow-none' : (isEditing ? 'bg-blue-600 hover:bg-blue-700 shadow-blue-600/30' : 'bg-emerald-600 hover:bg-emerald-700 shadow-emerald-600/30')"
        >
          <span v-if="isSaving" class="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
          <span>
            {{ isSaving ? 'Menyimpan...' : (duplicateBarcodeProduct ? '⚠️ Barcode Duplikat' : (isEditing ? '💾 Update Barang' : '✨ Simpan Barang Baru')) }}
          </span>
        </button>
      </div>
    </div>

    <!-- ================= MODAL TAMBAH KATEGORI BARU ================= -->
    <div 
      v-if="showAddCategoryModal" 
      class="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4"
      @click.self="showAddCategoryModal = false"
    >
      <div class="bg-white rounded-2xl p-4 w-full max-w-xs shadow-2xl border border-slate-200 flex flex-col gap-3">
        <div class="flex items-center justify-between border-b border-slate-100 pb-2">
          <div class="flex items-center gap-1.5">
            <span class="text-base">🏷️</span>
            <h3 class="text-sm font-black text-slate-900">Tambah Kategori Baru</h3>
          </div>
          <button 
            type="button" 
            @click="showAddCategoryModal = false" 
            class="text-slate-400 hover:text-slate-700 text-sm font-black cursor-pointer"
          >
            ✕
          </button>
        </div>

        <div>
          <label class="text-[11px] font-bold text-slate-600 block mb-1">Nama Kategori:</label>
          <input
            type="text"
            v-model="newCategoryName"
            @keydown.enter.prevent="saveNewCategory"
            placeholder="Misal: Frozen Food, Kosmetik, Alat Tulis..."
            class="w-full bg-slate-50 border border-slate-200 focus:bg-white focus:border-teal-500 rounded-xl px-3 py-2 text-sm font-bold text-slate-900 transition"
            autofocus
          />
        </div>

        <div class="flex items-center justify-end gap-2 pt-1">
          <button
            type="button"
            @click="showAddCategoryModal = false"
            class="px-3 py-2 text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition cursor-pointer"
          >
            Batal
          </button>
          <button
            type="button"
            @click="saveNewCategory"
            :disabled="!newCategoryName.trim() || isSavingCategory"
            class="px-4 py-2 text-xs font-black text-white bg-teal-600 hover:bg-teal-700 disabled:opacity-50 rounded-xl shadow-xs transition cursor-pointer flex items-center gap-1.5"
          >
            <span v-if="isSavingCategory" class="w-3 h-3 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
            <span>Simpan Kategori</span>
          </button>
        </div>
      </div>
    </div>

    <!-- ================= MODAL TAMBAH SATUAN BARU ================= -->
    <div 
      v-if="showAddUnitModal" 
      class="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4"
      @click.self="showAddUnitModal = false"
    >
      <div class="bg-white rounded-2xl p-4 w-full max-w-xs shadow-2xl border border-slate-200 flex flex-col gap-3">
        <div class="flex items-center justify-between border-b border-slate-100 pb-2">
          <div class="flex items-center gap-1.5">
            <span class="text-base">📏</span>
            <h3 class="text-sm font-black text-slate-900">Tambah Satuan Baru</h3>
          </div>
          <button 
            type="button" 
            @click="showAddUnitModal = false" 
            class="text-slate-400 hover:text-slate-700 text-sm font-black cursor-pointer"
          >
            ✕
          </button>
        </div>

        <div>
          <label class="text-[11px] font-bold text-slate-600 block mb-1">Nama Satuan:</label>
          <input
            type="text"
            v-model="newUnitName"
            @keydown.enter.prevent="saveNewUnit"
            placeholder="Misal: Lusin, Bal, Sak, Ikat, Butir, Slop..."
            class="w-full bg-slate-50 border border-slate-200 focus:bg-white focus:border-teal-500 rounded-xl px-3 py-2 text-sm font-bold text-slate-900 transition"
            autofocus
          />
          <p class="text-[10px] text-slate-400 mt-1">Satuan akan tersimpan dan dapat dipakai untuk produk lain.</p>
        </div>

        <div class="flex items-center justify-end gap-2 pt-1">
          <button
            type="button"
            @click="showAddUnitModal = false"
            class="px-3 py-2 text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition cursor-pointer"
          >
            Batal
          </button>
          <button
            type="button"
            @click="saveNewUnit"
            :disabled="!newUnitName.trim()"
            class="px-4 py-2 text-xs font-black text-white bg-teal-600 hover:bg-teal-700 disabled:opacity-50 rounded-xl shadow-xs transition cursor-pointer"
          >
            Gunakan Satuan
          </button>
        </div>
      </div>
    </div>
    <AppPopup />
  </div>
</template>

<style>
#mobile-barcode-reader {
  border: none !important;
}
#mobile-barcode-reader video {
  object-fit: cover !important;
  width: 100% !important;
  height: 100% !important;
  border-radius: 1rem !important;
}
#mobile-barcode-reader img[alt="Info icon"] {
  display: none !important;
}

/* Hilangkan scrollbar horizontal namun tetap bisa discroll */
.scrollbar-none::-webkit-scrollbar {
  display: none;
}
.scrollbar-none {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>
