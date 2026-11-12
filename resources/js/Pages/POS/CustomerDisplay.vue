<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { db } from '@/db';
import { generateQrDataUrl } from '@/utils/qr';
import { generateDynamicQris } from '@/utils/qris';
import { getStoreInitials } from '@/utils/store';
import { showAlert, showConfirm } from '@/utils/popup';
import AppPopup from '@/Components/AppPopup.vue';

const props = defineProps({
  settings: {
    type: Object,
    default: () => ({})
  },
  initialLiveState: {
    type: Object,
    default: () => ({})
  },
  pairingCode: {
    type: String,
    default: ''
  },
  store: {
    type: Object,
    default: () => ({
      name: 'Rozak Mart',
      address: 'Jl. KH Wahid Hasyim No. 42',
      phone: '0812-3456-7890',
      footer: 'Terima Kasih Atas Kunjungan Anda!'
    })
  }
});

// Theme Mode: Dikendalikan secara terpusat dari Kasir (Default: Dark)
const isDarkMode = ref(localStorage.getItem('customer_display_theme') !== 'light');

// Banner Gambar Promosi Murni dari Database Browser (IndexedDB Dexie)
const imageBanners = ref([]);
const currentStandbyIndex = ref(0);
const currentSidebarIndex = ref(0);
let bannerTimer = null;
const promoIntervalMs = ref(4500);
const fullscreenStandbyEnabled = ref(localStorage.getItem('customer_display_fullscreen_standby') !== 'false');
const videoFitMode = ref(localStorage.getItem('customer_display_video_fit') || 'contain'); // 'contain' (Tampil Utuh) | 'cover' (Penuh Layar)
const bannersVersion = ref(0);

// Pemisahan Banner Standby (16:9) vs Banner Kolom Kanan (1:1 Kotak)
const standbyBanners = computed(() => {
  return imageBanners.value.filter(b => b.placement === 'standby' || !b.placement);
});

const sidebarBanners = computed(() => {
  const filtered = imageBanners.value.filter(b => b.placement === 'sidebar');
  return filtered.length > 0 ? filtered : standbyBanners.value;
});

async function fetchBannersFromBackend() {
  try {
    const res = await fetch('/api/pos/display/banners');
    if (res.ok) {
      const list = await res.json();
      if (Array.isArray(list) && list.length > 0) {
        imageBanners.value = list;
        try {
          await db.customer_banners.clear();
          await db.customer_banners.bulkAdd(list);
        } catch (e) {}
      }
    }
  } catch (err) {
    console.warn('Gagal fetch banner dari backend:', err);
  }
}

async function loadBannersFromDb() {
  try {
    const list = await db.customer_banners.toArray();
    if (list && list.length > 0) {
      imageBanners.value = list;
    }
  } catch (err) {
    console.warn('Gagal membaca customer_banners di Layar Pembeli:', err);
  }
}

function isVideoBanner(banner) {
  if (!banner) return false;
  if (banner.type === 'video') return true;
  const src = banner.url || banner.image || '';
  return /\.(mp4|webm|ogg|mov)(\?|$)/i.test(src) || src.startsWith('data:video/');
}

function getBannerMediaSrc(banner) {
  if (!banner) return '';
  return banner.url || banner.image || '';
}

function nextStandbyBanner() {
  if (standbyBanners.value.length === 0) return;
  currentStandbyIndex.value = (currentStandbyIndex.value + 1) % standbyBanners.value.length;
}

function prevStandbyBanner() {
  if (standbyBanners.value.length === 0) return;
  currentStandbyIndex.value = (currentStandbyIndex.value - 1 + standbyBanners.value.length) % standbyBanners.value.length;
}

function nextSidebarBanner() {
  if (sidebarBanners.value.length === 0) return;
  currentSidebarIndex.value = (currentSidebarIndex.value + 1) % sidebarBanners.value.length;
}

function prevSidebarBanner() {
  if (sidebarBanners.value.length === 0) return;
  currentSidebarIndex.value = (currentSidebarIndex.value - 1 + sidebarBanners.value.length) % sidebarBanners.value.length;
}

function onStandbyVideoEnded() {
  if (standbyBanners.value.length > 1) {
    nextStandbyBanner();
  }
}

function onSidebarVideoEnded() {
  if (sidebarBanners.value.length > 1) {
    nextSidebarBanner();
  }
}

function restartBannerTimer() {
  if (bannerTimer) clearInterval(bannerTimer);
  bannerTimer = setInterval(() => {
    // Jika slide saat ini bukan video, ganti slide standby sesuai timer interval
    const currentStandby = standbyBanners.value[currentStandbyIndex.value];
    if (!isVideoBanner(currentStandby)) {
      nextStandbyBanner();
    }
    // Jika slide saat ini bukan video, ganti slide sidebar sesuai timer interval
    const currentSidebar = sidebarBanners.value[currentSidebarIndex.value];
    if (!isVideoBanner(currentSidebar)) {
      nextSidebarBanner();
    }
  }, promoIntervalMs.value || 4500);
}

// Live State Reactive Container
const liveState = ref({
  state: 'IDLE', // 'IDLE' | 'ACTIVE' | 'PAYING_CASH' | 'PAYING_QRIS' | 'SUCCESS'
  store: {
    name: props.store.name || props.settings.store_name || 'Rozak Mart',
    address: props.store.address || props.settings.store_address || '',
    phone: props.store.phone || props.settings.store_phone || '',
    description: props.store.description || props.settings.store_description || '',
    footer: props.store.footer || props.settings.receipt_footer || ''
  },
  cashier: {
    name: 'Siska Anggraeni',
    shift: 'Kasir Aktif'
  },
  customer: null,
  cart: [],
  totalQty: 0,
  subtotal: 0,
  discount: 0,
  grandTotal: 0,
  cash: {
    given: 0,
    change: 0
  },
  qris: {
    mode: 'emvco',
    qrUrl: '',
    status: '',
    message: ''
  },
  lastTransaction: null,
  timestamp: Date.now(),
  ...props.initialLiveState
});

// Realtime Clock
const currentTime = ref('');
const currentDate = ref('');

function updateClock() {
  const now = new Date();
  currentTime.value = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + ' WIB';
  currentDate.value = now.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

let clockInterval = null;
let broadcastChannel = null;
let pollTimeoutId = null;
let webrtcOfferInterval = null;
let p2pConnection = null;
let p2pDataChannel = null;
const isP2pConnected = ref(false);
const isConnected = ref(true);
const failedPollCount = ref(0);
const isFullscreen = ref(false);

function toggleFullscreen() {
  if (!document.fullscreenElement) {
    document.documentElement.requestFullscreen().catch(() => {});
    isFullscreen.value = true;
  } else {
    document.exitFullscreen().catch(() => {});
    isFullscreen.value = false;
  }
}

const lastSyncedTimestamp = ref(props.initialLiveState?.timestamp || 0);

// Handler penerimaan pesan sinkronisasi
function handleSyncMessage(payload) {
  if (!payload || typeof payload !== 'object') return;
  liveState.value = {
    ...liveState.value,
    ...payload
  };

  // Sinkronisasi Tema Warna Dark/Light dari Kasir
  if (payload.theme) {
    isDarkMode.value = (payload.theme !== 'light');
    try {
      localStorage.setItem('customer_display_theme', isDarkMode.value ? 'dark' : 'light');
    } catch (e) {}
  }

  // Sinkronisasi Banner Gambar dari Database Kasir jika versinya baru
  if (payload.bannersVersion && payload.bannersVersion !== bannersVersion.value) {
    bannersVersion.value = payload.bannersVersion;
    fetchBannersFromBackend();
  }

  if (payload.promoInterval && payload.promoInterval !== promoIntervalMs.value) {
    promoIntervalMs.value = payload.promoInterval;
    restartBannerTimer();
  }

  if (payload.fullscreenStandby !== undefined) {
    fullscreenStandbyEnabled.value = payload.fullscreenStandby;
    try {
      localStorage.setItem('customer_display_fullscreen_standby', payload.fullscreenStandby ? 'true' : 'false');
    } catch (e) {}
  }

  if (payload.videoFit !== undefined) {
    videoFitMode.value = payload.videoFit;
    try {
      localStorage.setItem('customer_display_video_fit', payload.videoFit);
    } catch (e) {}
  }

  isConnected.value = true;
  failedPollCount.value = 0;
  scheduleNextPoll();
}

function waitForIceGatheringComplete(pc, timeoutMs = 1200) {
  if (pc.iceGatheringState === 'complete') {
    return Promise.resolve();
  }
  return new Promise((resolve) => {
    let resolved = false;
    const timer = setTimeout(() => {
      if (!resolved) {
        resolved = true;
        pc.removeEventListener('icegatheringstatechange', checkState);
        resolve();
      }
    }, timeoutMs);

    function checkState() {
      if (pc.iceGatheringState === 'complete') {
        if (!resolved) {
          resolved = true;
          clearTimeout(timer);
          pc.removeEventListener('icegatheringstatechange', checkState);
          resolve();
        }
      }
    }
    pc.addEventListener('icegatheringstatechange', checkState);
  });
}

// ================= MULTI-KASIR PAIRING SYSTEM =================
const pairedCode = ref('');
const manualPinInput = ref('');
const pairTab = ref('scan'); // 'scan' | 'pin'
const isScannerActive = ref(false);
const cameraError = ref('');
let cfdQrScanner = null;

// Gestur Rahasia: Tekan 3x pada nama kasir untuk memunculkan tombol ganti meja
const showSecretDisconnect = ref(false);
let tapCount = 0;
let lastTapTime = 0;
let autoHideTimer = null;

function handleCashierBadgeTap() {
  const now = Date.now();
  if (now - lastTapTime < 700) {
    tapCount++;
  } else {
    tapCount = 1;
  }
  lastTapTime = now;

  if (tapCount >= 3) {
    tapCount = 0;
    showSecretDisconnect.value = true;
    if (autoHideTimer) clearTimeout(autoHideTimer);
    autoHideTimer = setTimeout(() => {
      showSecretDisconnect.value = false;
    }, 6000); // Otomatis disembunyikan kembali setelah 6 detik
  }
}

function extractPairCode(raw) {
  if (!raw) return '';
  const str = String(raw).trim();
  if (str.includes('code=')) {
    try {
      const url = new URL(str, window.location.origin);
      const c = url.searchParams.get('code');
      if (c) return c.replace(/[^a-zA-Z0-9_-]/g, '');
    } catch (e) {
      const match = str.match(/code=([a-zA-Z0-9_-]+)/);
      if (match) return match[1];
    }
  }
  return str.replace(/[^a-zA-Z0-9_-]/g, '');
}

function connectToCashier(rawCode) {
  const code = extractPairCode(rawCode);
  if (!code) {
    showAlert('Kode meja kasir tidak valid.');
    return;
  }

  stopPairingScanner();
  pairedCode.value = code;
  try {
    localStorage.setItem('cfd_paired_code', code);
  } catch (e) {}

  initCashierConnection(code);
}

async function disconnectCashier() {
  if (await showConfirm(`Putuskan koneksi dari Meja Kasir #${pairedCode.value}?`)) {
    try {
      localStorage.removeItem('cfd_paired_code');
    } catch (e) {}
    pairedCode.value = '';
    if (broadcastChannel) {
      try { broadcastChannel.close(); } catch (e) {}
      broadcastChannel = null;
    }
    if (pollTimeoutId) clearTimeout(pollTimeoutId);
    pollTimeoutId = null;
    liveState.value = {
      state: 'IDLE',
      cart: [],
      totalQty: 0,
      subtotal: 0,
      discount: 0,
      grandTotal: 0,
      cash: { given: 0, change: 0 },
      qris: { mode: 'emvco', qrUrl: '', status: '', message: '' }
    };
  }
}

function appendPinDigit(digit) {
  if (manualPinInput.value.length < 10) {
    manualPinInput.value += digit;
  }
}

function backspacePinDigit() {
  manualPinInput.value = manualPinInput.value.slice(0, -1);
}

function clearPinInput() {
  manualPinInput.value = '';
}

function submitManualPin() {
  if (!manualPinInput.value || manualPinInput.value.trim().length < 4) {
    showAlert('Masukkan minimal 4 hingga 6 digit PIN Meja Kasir.');
    return;
  }
  connectToCashier(manualPinInput.value);
}

async function startPairingScanner() {
  cameraError.value = '';
  isScannerActive.value = true;
  await nextTick();

  try {
    if (cfdQrScanner) {
      try { await cfdQrScanner.stop(); } catch (e) {}
      cfdQrScanner = null;
    }

    cfdQrScanner = new Html5Qrcode('cfd-pairing-scanner', {
      formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE],
      verbose: false
    });

    await cfdQrScanner.start(
      { facingMode: 'environment' },
      { fps: 20, qrbox: { width: 220, height: 220 } },
      (decodedText) => {
        if (decodedText) {
          connectToCashier(decodedText);
        }
      },
      () => {}
    );
  } catch (err) {
    console.warn('Environment camera scan error, trying user webcam:', err);
    try {
      if (cfdQrScanner) {
        await cfdQrScanner.start(
          { facingMode: 'user' },
          { fps: 20, qrbox: { width: 220, height: 220 } },
          (decodedText) => {
            if (decodedText) {
              connectToCashier(decodedText);
            }
          },
          () => {}
        );
      }
    } catch (e2) {
      console.error('Kamera gagal diakses:', e2);
      cameraError.value = 'Kamera tidak dapat diakses atau diblokir. Pastikan izin kamera aktif atau gunakan tab Input PIN.';
      isScannerActive.value = false;
    }
  }
}

async function stopPairingScanner() {
  if (cfdQrScanner) {
    try {
      await cfdQrScanner.stop();
      cfdQrScanner.clear();
    } catch (e) {}
    cfdQrScanner = null;
  }
  isScannerActive.value = false;
}

// WebRTC Client P2P (Handshake Lengkap dengan Kandidat IP WiFi Lokal)
async function initClientP2P(code = '') {
  if (typeof window === 'undefined' || !('RTCPeerConnection' in window)) return;
  if (p2pConnection) {
    try { p2pConnection.close(); } catch (e) {}
  }

  const effectiveCode = code || pairedCode.value || 'default';

  try {
    p2pConnection = new RTCPeerConnection({
      iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' }
      ]
    });

    p2pConnection.ondatachannel = (event) => {
      p2pDataChannel = event.channel;
      p2pDataChannel.onopen = () => {
        console.log('⚡ WebRTC P2P Berhasil Terhubung di Sisi Layar Pembeli!');
        isP2pConnected.value = true;
        scheduleNextPoll();
      };
      p2pDataChannel.onclose = () => {
        isP2pConnected.value = false;
        scheduleNextPoll();
      };
      p2pDataChannel.onmessage = (e) => {
        try {
          const payload = JSON.parse(e.data);
          handleSyncMessage(payload);
        } catch (err) {}
      };
    };

    let lastOfferTimestamp = 0;
    let offerAttempts = 0;
    if (webrtcOfferInterval) clearInterval(webrtcOfferInterval);
    webrtcOfferInterval = setInterval(async () => {
      offerAttempts++;
      if (isP2pConnected.value || offerAttempts > 5) {
        clearInterval(webrtcOfferInterval);
        return;
      }
      try {
        const res = await fetch(`/api/pos/display/signal?role=host_offer&code=${encodeURIComponent(effectiveCode)}`);
        if (res.ok) {
          const data = await res.json();
          if (data && data.signal && data.timestamp !== lastOfferTimestamp && p2pConnection && p2pConnection.signalingState === 'stable') {
            lastOfferTimestamp = data.timestamp;
            await p2pConnection.setRemoteDescription(new RTCSessionDescription(data.signal));
            const answer = await p2pConnection.createAnswer();
            await p2pConnection.setLocalDescription(answer);

            // Tunggu pengumpulan kandidat IP WiFi lokal ke dalam SDP answer (Vanilla ICE)
            await waitForIceGatheringComplete(p2pConnection);

            await fetch('/api/pos/display/signal', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ 
                code: effectiveCode,
                role: 'client_answer', 
                signal: p2pConnection.localDescription,
                timestamp: Date.now()
              })
            }).catch(() => {});
          }
        }
      } catch (err) {}
    }, 1000);
  } catch (err) {
    console.warn('WebRTC P2P Client Init Error:', err);
  }
}

// Logika Interval Adaptif: Cepat saat belanja, Tenang & Santai saat standby
function getAdaptiveInterval() {
  if (!pairedCode.value) return 3000;
  // 1. Jika WebRTC P2P Wi-Fi tersambung langsung, polling HTTP diperlambat drastis (heartbeat cadangan 5000ms)
  if (isP2pConnected.value) {
    return 5000;
  }
  
  // 2. Jika keranjang belanja sedang aktif terisi atau tahap pembayaran, polling super cepat (350ms)
  if ((liveState.value.cart && liveState.value.cart.length > 0) || 
      displayState.value === 'ACTIVE' || 
      displayState.value === 'PAYING_CASH' || 
      displayState.value === 'PAYING_QRIS') {
    return 350;
  }

  // 3. Jika sedang Standby (keranjang kasir kosong / iklan reklame), polling rileks (1500ms)
  return 1500;
}

function scheduleNextPoll(delayMs = null) {
  if (pollTimeoutId) clearTimeout(pollTimeoutId);
  if (!pairedCode.value) return;
  const nextInterval = delayMs !== null ? delayMs : getAdaptiveInterval();
  pollTimeoutId = setTimeout(pollWirelessState, nextInterval);
}

// Polling nirkabel file stateless cerdas (Adaptive Polling)
async function pollWirelessState() {
  if (!pairedCode.value) return;
  try {
    const url = `/api/pos/display/live?code=${encodeURIComponent(pairedCode.value)}`;
    const res = await fetch(url, { 
      headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' } 
    });
    if (res.ok) {
      const data = await res.json();
      failedPollCount.value = 0;
      isConnected.value = true;
      if (data && data.timestamp && data.timestamp !== lastSyncedTimestamp.value) {
        lastSyncedTimestamp.value = data.timestamp;
        handleSyncMessage(data);
      }
    } else {
      failedPollCount.value++;
      if (failedPollCount.value > 6 && !isP2pConnected.value) isConnected.value = false;
    }
  } catch (e) {
    failedPollCount.value++;
    if (failedPollCount.value > 6 && !isP2pConnected.value) isConnected.value = false;
  } finally {
    if (pairedCode.value) {
      scheduleNextPoll();
    }
  }
}

function initCashierConnection(code) {
  // 1. BroadcastChannel API lokal terisolasi per kode
  try {
    if (broadcastChannel) broadcastChannel.close();
    if ('BroadcastChannel' in window) {
      broadcastChannel = new BroadcastChannel('pos_display_' + code);
      broadcastChannel.onmessage = (event) => {
        handleSyncMessage(event.data);
      };
    }
  } catch (e) {}

  // 2. Baca state lokal tersimpan
  try {
    const saved = localStorage.getItem('rozak_dual_display_state_' + code) || localStorage.getItem('rozak_dual_display_state');
    if (saved) {
      handleSyncMessage(JSON.parse(saved));
    }
  } catch (err) {}

  // 3. WebRTC P2P Client
  initClientP2P(code);

  // 4. Polling nirkabel
  pollWirelessState();
}

onMounted(() => {
  updateClock();
  clockInterval = setInterval(updateClock, 1000);

  // Muat banner gambar dari IndexedDB lokal & fetch dari backend
  loadBannersFromDb();
  fetchBannersFromBackend();
  restartBannerTimer();

  // Storage Event listener
  window.addEventListener('storage', (event) => {
    if (pairedCode.value) {
      if ((event.key === 'rozak_dual_display_state_' + pairedCode.value || event.key === 'rozak_dual_display_state') && event.newValue) {
        try {
          const parsed = JSON.parse(event.newValue);
          handleSyncMessage(parsed);
        } catch (err) {}
      }
    }
  });

  // Deteksi Kode Pairing: Otomatis tersambung instan ke kasir
  const urlParams = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : null;
  const urlCode = urlParams?.get('code') || props.pairingCode || '';
  const savedCfdCode = typeof window !== 'undefined' ? localStorage.getItem('cfd_paired_code') : null;
  const localCashierCode = typeof window !== 'undefined' ? localStorage.getItem('pos_cashier_display_code') : null;

  // Sambungkan otomatis: URL -> Tablet Saved -> Local PC Kasir -> Default Meja Kasir 1 (100001)
  const targetCode = urlCode || savedCfdCode || localCashierCode || '100001';
  connectToCashier(targetCode);
});

onUnmounted(() => {
  if (clockInterval) clearInterval(clockInterval);
  if (pollTimeoutId) clearTimeout(pollTimeoutId);
  if (webrtcOfferInterval) clearInterval(webrtcOfferInterval);
  if (bannerTimer) clearInterval(bannerTimer);
  if (broadcastChannel) {
    try { broadcastChannel.close(); } catch (e) {}
  }
  if (p2pConnection) {
    try { p2pConnection.close(); } catch (e) {}
  }
  stopPairingScanner();
});

// Computed Helper State
const displayState = computed(() => {
  if (liveState.value.state === 'SUCCESS') return 'SUCCESS';
  if (liveState.value.state === 'PAYING_QRIS') return 'PAYING_QRIS';
  if (liveState.value.state === 'PAYING_CASH') return 'PAYING_CASH';
  if (liveState.value.cart && liveState.value.cart.length > 0) return 'ACTIVE';
  return 'IDLE';
});

// Mode Standby Iklan Layar Penuh (Giant Signage Billboard)
const isStandbyFullscreen = computed(() => {
  return displayState.value === 'IDLE' && fullscreenStandbyEnabled.value;
});

// Fallback QRIS 100% Offline Generator
const qrisDisplayUrl = computed(() => {
  if (liveState.value.qris?.qrUrl) return liveState.value.qris.qrUrl;
  if (liveState.value.qris?.payload) return generateQrDataUrl(liveState.value.qris.payload, 260, 2);
  
  if (liveState.value.grandTotal > 0) {
    const raw = props.settings?.qris_static_payload || '';
    const dynamicPayload = generateDynamicQris(raw, liveState.value.grandTotal);
    return generateQrDataUrl(dynamicPayload, 260, 2);
  }
  return '';
});
</script>

<template>
  <Head :title="`Layar Pembeli · ${liveState.store.name || 'Rozak Mart'}`" />

  <div 
    :class="isDarkMode ? 'bg-slate-950 text-slate-100' : 'bg-slate-100 text-slate-800'"
    class="w-screen h-screen max-w-full max-h-screen overflow-hidden flex flex-col select-none font-sans transition-colors duration-200"
  >
    
    <!-- ============================================================== -->
    <!-- TOP BAR: Store Branding, Mode Dark/Light & Fullscreen Button  -->
    <!-- ============================================================== -->
    <header 
      :class="isDarkMode ? 'bg-slate-900 border-slate-800' : 'bg-white border-slate-200 shadow-2xs'"
      class="border-b px-5 py-2.5 flex items-center justify-between shrink-0 transition-colors"
    >
      <!-- Store Brand & Network Status -->
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-xl bg-blue-600 text-white font-black text-xs flex items-center justify-center shadow-md shadow-blue-600/30">
          {{ getStoreInitials(liveState.store.name) }}
        </div>
        <div>
          <h1 
            :class="isDarkMode ? 'text-white' : 'text-slate-900'"
            class="text-xs font-black tracking-wide uppercase leading-tight"
          >
            {{ liveState.store.name || 'ROZAK MART' }}
          </h1>
          <p class="text-[10px] text-slate-400 font-medium flex items-center gap-2">
            <span>{{ liveState.store.description || liveState.store.address || 'Pusat Belanja Hemat & Terjangkau' }}</span>
            <template v-if="pairedCode">
              <span 
                :class="isP2pConnected ? 'bg-cyan-400' : (isConnected ? 'bg-emerald-400' : 'bg-amber-400')"
                class="inline-block w-1.5 h-1.5 rounded-full animate-pulse"
              ></span>
              <span 
                :class="isP2pConnected ? 'text-cyan-400 font-bold' : (isConnected ? 'text-emerald-500 font-bold' : 'text-amber-500 font-semibold')"
              >
                {{ isP2pConnected ? '⚡ P2P Langsung (Instan)' : (isConnected ? 'Terhubung Nirkabel (Live)' : 'Menghubungkan ke Kasir...') }}
              </span>
            </template>
            <template v-else>
              <span class="inline-block w-1.5 h-1.5 rounded-full bg-amber-400"></span>
              <span class="text-amber-400 font-bold">Menunggu Sambungan Kasir</span>
            </template>
          </p>
        </div>
      </div>

      <!-- Controls: Status Kasir, Jam & Fullscreen -->
      <div class="flex items-center gap-3">
        <!-- Badge Meja Kasir Terhubung (Ketuk 3x untuk memunculkan tombol ganti meja kasir) -->
        <div 
          v-if="pairedCode" 
          @click="handleCashierBadgeTap"
          class="flex items-center gap-1.5 bg-teal-500/15 border border-teal-500/30 px-2.5 py-1 rounded-xl select-none cursor-pointer active:scale-95 transition"
          title="Ketuk 3x untuk opsi pindah kasir"
        >
          <span class="w-2 h-2 rounded-full bg-teal-400 animate-pulse"></span>
          <span class="text-[11px] font-black text-teal-400 font-mono tracking-wide">
            {{ liveState.station?.name || ('Meja Kasir ' + (pairedCode.startsWith('10000') ? pairedCode.slice(5) : '#' + pairedCode)) }}
          </span>
          <span v-if="liveState.cashier?.name" class="text-[10px] text-slate-300 font-medium hidden md:inline">
            · {{ liveState.cashier.name }}
          </span>
          <!-- Tombol Rahasia Ganti Meja (Hanya Muncul jika Diketuk 3x) -->
          <button 
            v-if="showSecretDisconnect"
            type="button"
            @click.stop="disconnectCashier" 
            title="Putuskan sambungan dari kasir ini"
            class="text-[10px] font-bold bg-rose-600 hover:bg-rose-700 text-white px-2 py-0.5 rounded-md transition cursor-pointer ml-1 animate-in zoom-in duration-150 shadow-xs flex items-center gap-1"
          >
            <span>Pindah Meja ✕</span>
          </button>
        </div>

        <!-- Jam Realtime -->
        <div class="text-right leading-tight pr-1">
          <div class="text-[10px] text-slate-400 font-medium">{{ currentDate }}</div>
          <div class="text-xs font-mono font-black text-blue-500 tracking-wider">{{ currentTime }}</div>
        </div>

        <!-- Tombol Fullscreen Layar Tablet -->
        <button 
          @click="toggleFullscreen" 
          title="Mode Layar Penuh" 
          :class="isDarkMode ? 'bg-slate-800 hover:bg-slate-700 active:bg-slate-600 text-slate-300 border-slate-700' : 'bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 border-slate-300'"
          class="w-7 h-7 rounded-xl border flex items-center justify-center text-xs transition cursor-pointer"
        >
          {{ isFullscreen ? '⛶' : '🖵' }}
        </button>
      </div>
    </header>

    <!-- ============================================================== -->
    <!-- KONDISI KHUSUS: BELUM TERHUBUNG KE KASIR (PAIRING SCREEN)      -->
    <!-- ============================================================== -->
    <main 
      v-if="!pairedCode" 
      class="flex-1 flex flex-col items-center justify-center p-4 md:p-6 overflow-y-auto animate-in fade-in duration-200"
      :class="isDarkMode ? 'bg-slate-950 text-slate-100' : 'bg-slate-100 text-slate-800'"
    >
      <div 
        class="w-full max-w-md rounded-3xl p-6 sm:p-7 shadow-2xl flex flex-col items-center border transition-all"
        :class="isDarkMode ? 'bg-slate-900 border-slate-800 shadow-slate-950/60' : 'bg-white border-slate-200 shadow-slate-300/40'"
      >
        <!-- Store Icon / Logo -->
        <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-teal-600 to-emerald-500 text-white flex items-center justify-center text-2xl shadow-lg shadow-teal-600/30 mb-3">
          📱
        </div>

        <h2 class="text-base sm:text-lg font-black uppercase tracking-wider text-center" :class="isDarkMode ? 'text-white' : 'text-slate-900'">
          Sambungkan Layar Pembeli
        </h2>
        <p class="text-xs text-slate-400 text-center font-medium mt-1 mb-5">
          Scan barcode di meja kasir atau masukkan 6 digit PIN kasir
        </p>

        <!-- Segmented Tab: Scan Barcode vs Input PIN -->
        <div class="grid grid-cols-2 gap-1 w-full bg-slate-100 p-1 rounded-2xl mb-5" :class="isDarkMode ? 'bg-slate-800/80 border border-slate-700/60' : 'bg-slate-100 border border-slate-200'">
          <button 
            type="button" 
            @click="pairTab = 'scan'; startPairingScanner()"
            class="py-2 text-xs font-bold rounded-xl transition flex items-center justify-center gap-1.5 cursor-pointer"
            :class="pairTab === 'scan' ? (isDarkMode ? 'bg-teal-600 text-white shadow-sm' : 'bg-white text-teal-700 shadow-sm') : 'text-slate-400 hover:text-slate-200'"
          >
            <span>📷 Scan Barcode</span>
          </button>
          <button 
            type="button" 
            @click="pairTab = 'pin'; stopPairingScanner()"
            class="py-2 text-xs font-bold rounded-xl transition flex items-center justify-center gap-1.5 cursor-pointer"
            :class="pairTab === 'pin' ? (isDarkMode ? 'bg-teal-600 text-white shadow-sm' : 'bg-white text-teal-700 shadow-sm') : 'text-slate-400 hover:text-slate-200'"
          >
            <span>🔢 Input 6 PIN</span>
          </button>
        </div>

        <!-- TAB 1: SCAN BARCODE KAMERA -->
        <div v-if="pairTab === 'scan'" class="w-full flex flex-col items-center">
          <div 
            class="w-full aspect-square max-w-[270px] bg-black rounded-2xl overflow-hidden relative border-2 border-dashed border-teal-500 flex flex-col items-center justify-center shadow-inner"
          >
            <div id="cfd-pairing-scanner" class="w-full h-full object-cover"></div>

            <div v-if="!isScannerActive" class="absolute inset-0 flex flex-col items-center justify-center bg-slate-900/95 p-4 text-center">
              <span class="text-3xl mb-2">📸</span>
              <span class="text-xs font-bold text-white mb-3">Kamera Belum Aktif</span>
              <button 
                type="button" 
                @click="startPairingScanner" 
                class="px-4 py-2 rounded-xl bg-teal-600 hover:bg-teal-700 text-white font-bold text-xs shadow-md transition cursor-pointer"
              >
                Mulai Pindai Barcode
              </button>
            </div>
          </div>

          <p v-if="cameraError" class="text-[11px] text-rose-500 font-medium text-center mt-3 bg-rose-500/10 px-3 py-1.5 rounded-lg border border-rose-500/20">
            {{ cameraError }}
          </p>

          <span class="text-[11px] text-slate-400 font-medium text-center mt-3 leading-relaxed">
            Arahkan kamera tablet ke barcode di monitor kasir.<br>
            <strong class="text-teal-400 font-semibold">Koneksi meja kasir tersambung instan!</strong>
          </span>
        </div>

        <!-- TAB 2: INPUT 6 DIGIT PIN -->
        <div v-else class="w-full flex flex-col items-center">
          <div class="w-full flex flex-col items-center gap-1.5 mb-4">
            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">PIN Meja Kasir (6 Digit):</span>
            <input 
              v-model="manualPinInput"
              type="text" 
              inputmode="numeric"
              maxlength="10"
              placeholder="Contoh: 100001"
              @keydown.enter="submitManualPin"
              class="w-full text-center text-3xl font-black font-mono tracking-widest py-2.5 px-4 rounded-2xl border transition focus:outline-none focus:ring-2 focus:ring-teal-500"
              :class="isDarkMode ? 'bg-slate-950 border-slate-700 text-teal-400' : 'bg-slate-50 border-slate-300 text-teal-700'"
            />
          </div>

          <!-- Virtual Numeric Keypad Tablet -->
          <div class="grid grid-cols-3 gap-2 w-full max-w-[270px] mb-4">
            <button 
              v-for="digit in ['1', '2', '3', '4', '5', '6', '7', '8', '9']" 
              :key="digit"
              type="button" 
              @click="appendPinDigit(digit)"
              class="py-2.5 rounded-xl font-bold font-mono text-base transition active:scale-95 cursor-pointer shadow-xs border"
              :class="isDarkMode ? 'bg-slate-800 hover:bg-slate-700 border-slate-700 text-white' : 'bg-slate-100 hover:bg-slate-200 border-slate-200 text-slate-800'"
            >
              {{ digit }}
            </button>
            <button 
              type="button" 
              @click="clearPinInput"
              class="py-2.5 rounded-xl font-bold text-xs uppercase tracking-wider transition active:scale-95 cursor-pointer border text-rose-500 hover:bg-rose-500/10"
              :class="isDarkMode ? 'bg-slate-800 border-slate-700' : 'bg-slate-100 border-slate-200'"
            >
              C
            </button>
            <button 
              type="button" 
              @click="appendPinDigit('0')"
              class="py-2.5 rounded-xl font-bold font-mono text-base transition active:scale-95 cursor-pointer shadow-xs border"
              :class="isDarkMode ? 'bg-slate-800 hover:bg-slate-700 border-slate-700 text-white' : 'bg-slate-100 hover:bg-slate-200 border-slate-200 text-slate-800'"
            >
              0
            </button>
            <button 
              type="button" 
              @click="backspacePinDigit"
              class="py-2.5 rounded-xl font-bold text-sm transition active:scale-95 cursor-pointer border text-amber-500 hover:bg-amber-500/10"
              :class="isDarkMode ? 'bg-slate-800 border-slate-700' : 'bg-slate-100 border-slate-200'"
            >
              ⌫
            </button>
          </div>

          <button 
            type="button" 
            @click="submitManualPin" 
            class="w-full py-3 px-4 rounded-xl bg-teal-600 hover:bg-teal-700 active:scale-[0.98] text-white font-black text-xs uppercase tracking-wider shadow-lg shadow-teal-600/25 transition cursor-pointer flex items-center justify-center gap-2"
          >
            <span>🔗 Sambungkan Meja Kasir</span>
          </button>
        </div>

      </div>
    </main>

    <!-- ============================================================== -->
    <!-- KONDISI A: MODE STANDBY IKLAN LAYAR PENUH (DIGITAL SIGNAGE)    -->
    <!-- ============================================================== -->
    <main 
      v-else-if="isStandbyFullscreen" 
      class="flex-1 overflow-hidden relative flex flex-col p-4 md:p-5 justify-between animate-in fade-in duration-300"
    >
      <!-- Sub-Header: Indikator Promo & Slider Control -->
      <div class="flex items-center justify-between mb-2.5 shrink-0">
        <div class="flex items-center gap-2">
          <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider bg-rose-600 text-white shadow-md animate-pulse">
            <span>🔥</span> PROMO & PENAWARAN SPESIAL
          </span>
          <span :class="isDarkMode ? 'text-slate-400' : 'text-slate-500'" class="text-xs font-medium hidden sm:inline">
            Belanja makin hemat di {{ liveState.store.name || 'Rozak Mart' }}
          </span>
        </div>

        <!-- Controls: Dots & Panah Slide Banner Gambar Standby (16:9) -->
        <div v-if="standbyBanners.length > 1" class="flex items-center gap-3">
          <div class="flex items-center gap-1.5">
            <span 
              v-for="(s, i) in standbyBanners" 
              :key="i"
              @click="currentStandbyIndex = i"
              :class="i === currentStandbyIndex ? 'w-6 bg-blue-500' : (isDarkMode ? 'w-2 bg-slate-700' : 'w-2 bg-slate-300')"
              class="h-2 rounded-full transition-all cursor-pointer"
            ></span>
          </div>
          <div class="flex items-center gap-1.5">
            <button 
              @click="prevStandbyBanner" 
              :class="isDarkMode ? 'bg-slate-800 hover:bg-slate-700 text-slate-200 border-slate-700' : 'bg-white hover:bg-slate-100 text-slate-800 border-slate-200 shadow-2xs'"
              class="w-7 h-7 rounded-xl border flex items-center justify-center text-sm font-bold transition cursor-pointer"
            >
              ‹
            </button>
            <button 
              @click="nextStandbyBanner" 
              :class="isDarkMode ? 'bg-slate-800 hover:bg-slate-700 text-slate-200 border-slate-700' : 'bg-white hover:bg-slate-100 text-slate-800 border-slate-200 shadow-2xs'"
              class="w-7 h-7 rounded-xl border flex items-center justify-center text-sm font-bold transition cursor-pointer"
            >
              ›
            </button>
          </div>
        </div>
      </div>

      <!-- KARTU HERO BILLBOARD RAKSASA (BANNER GAMBAR & VIDEO MURNI STANDBY 16:9) -->
      <div 
        :class="isDarkMode ? 'bg-slate-900 border-slate-800 shadow-2xl' : 'bg-white border-slate-200 shadow-xl'"
        class="flex-1 rounded-3xl border flex flex-col justify-center items-center relative overflow-hidden transition-all duration-300"
      >
        <!-- JIKA ADA MEDIA BANNER STANDBY DIUPLOAD KASIR -->
        <div v-if="standbyBanners.length > 0" class="w-full h-full relative overflow-hidden rounded-3xl flex items-center justify-center bg-black">
          <!-- Ambient Backdrop Blur jika mode 'contain' agar ruang kosong terisi estetik -->
          <video 
            v-if="isVideoBanner(standbyBanners[currentStandbyIndex]) && videoFitMode === 'contain'"
            :key="'sb-vid-bg-' + (standbyBanners[currentStandbyIndex].id || currentStandbyIndex)"
            :src="getBannerMediaSrc(standbyBanners[currentStandbyIndex])"
            autoplay
            muted
            loop
            playsinline
            aria-hidden="true"
            class="absolute inset-0 w-full h-full object-cover blur-2xl opacity-40 scale-110 pointer-events-none"
          ></video>

          <!-- Video Promosi Standby Utama (Autoplay, Muted, Loop jika tunggal, @ended untuk auto-slide) -->
          <video 
            v-if="isVideoBanner(standbyBanners[currentStandbyIndex])"
            :key="'sb-vid-' + (standbyBanners[currentStandbyIndex].id || currentStandbyIndex)"
            :src="getBannerMediaSrc(standbyBanners[currentStandbyIndex])"
            autoplay
            muted
            :loop="standbyBanners.length === 1"
            playsinline
            @ended="onStandbyVideoEnded"
            :class="videoFitMode === 'cover' ? 'object-cover' : 'object-contain'"
            class="w-full h-full relative z-10 transition-all duration-300"
          ></video>

          <!-- Gambar Standby -->
          <img 
            v-else
            :key="'sb-img-' + (standbyBanners[currentStandbyIndex].id || currentStandbyIndex)"
            :src="getBannerMediaSrc(standbyBanners[currentStandbyIndex])" 
            :alt="standbyBanners[currentStandbyIndex]?.title || 'Banner Standby'"
            :class="videoFitMode === 'contain' ? 'object-contain' : 'object-cover'"
            class="w-full h-full relative z-10 transition-all duration-300"
          >
        </div>

        <!-- JIKA BELUM ADA GAMBAR BANNER: TAMPILAN STANDAR TOKO -->
        <div v-else class="flex flex-col items-center justify-center text-center p-8 my-auto">
          <div class="w-20 h-20 rounded-3xl bg-blue-600 text-white font-black text-3xl flex items-center justify-center shadow-xl mb-4">
            {{ getStoreInitials(liveState.store.name) }}
          </div>
          <h2 :class="isDarkMode ? 'text-white' : 'text-slate-900'" class="text-3xl font-black tracking-tight mb-2">
            {{ liveState.store.name || 'ROZAK MART' }}
          </h2>
          <p class="text-sm text-slate-400 max-w-md font-medium">
            {{ liveState.store.description || liveState.store.address || 'Pusat Belanja Hemat, Lengkap & Terpercaya. Silakan upload banner gambar promosi dari menu kasir.' }}
          </p>
        </div>
      </div>

      <!-- BOTTOM BANNER: AJAKAN SELAMAT DATANG & KESIAPAN KASIR -->
      <footer 
        :class="isDarkMode ? 'bg-blue-950/40 border-blue-500/30 text-blue-200' : 'bg-blue-50 border-blue-200 text-blue-900 shadow-2xs'"
        class="mt-2.5 py-2 px-5 rounded-2xl border flex items-center justify-between shrink-0"
      >
        <div class="flex items-center gap-3">
          <span class="text-xl">👋</span>
          <div>
            <span class="font-black text-xs uppercase tracking-wider block">Selamat Datang di {{ liveState.store.name }}</span>
            <span class="text-[11px] opacity-80">Silakan letakkan barang belanjaan Anda di meja kasir. Kami siap melayani Anda!</span>
          </div>
        </div>
        <div class="flex items-center gap-2 text-xs font-bold text-blue-600 bg-white/90 px-3 py-1 rounded-xl shadow-2xs">
          <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
          <span>Kasir Siap Melayani</span>
        </div>
      </footer>

    </main>

    <!-- ============================================================== -->
    <!-- KONDISI B: MAIN SPLIT WORKSPACE (SAAT ADA TRANSAKSI / AKTIF)    -->
    <!-- KIRI (54% INFO & CART COMPACT) + KANAN (46% IKLAN / QRIS JUMBO) -->
    <!-- ============================================================== -->
    <main v-else class="flex-1 overflow-hidden relative flex flex-row animate-in fade-in duration-200">

      <!-- ============================================================== -->
      <!-- KOLOM KIRI (54%): SEMUA INFORMASI & LIST BELANJAAN COMPACT      -->
      <!-- ============================================================== -->
      <section 
        :class="isDarkMode ? 'bg-slate-900 border-slate-800' : 'bg-white border-slate-200'"
        class="w-[54%] h-full border-r flex flex-col justify-between overflow-hidden transition-colors"
      >
        
        <!-- A. HEADER KASIR & PELANGGAN INFO -->
        <div 
          :class="isDarkMode ? 'bg-slate-950/70 border-slate-800' : 'bg-slate-50 border-slate-200'"
          class="px-4 py-2 border-b flex items-center justify-between shrink-0"
        >
          <div class="flex items-center gap-2">
            <span 
              :class="isDarkMode ? 'text-white' : 'text-slate-900'"
              class="text-xs font-black uppercase tracking-wider"
            >
              Belanjaan Anda
            </span>
            <span class="bg-blue-600 text-white text-[10px] font-bold px-2 py-0.5 rounded-full font-mono">
              {{ liveState.totalQty }} pcs ({{ liveState.cart.length }} item)
            </span>
          </div>

          <!-- Customer Name & Status -->
          <div class="flex items-center gap-1.5 text-xs">
            <span class="text-xs">👤</span>
            <span 
              :class="isDarkMode ? 'text-slate-200' : 'text-slate-800'"
              class="font-bold text-[11px]"
            >
              {{ liveState.customer?.name || 'Pelanggan Umum' }}
            </span>
            <span 
              v-if="liveState.customer?.phone" 
              class="text-[9px] bg-indigo-500/10 text-indigo-500 px-1.5 py-0.5 rounded font-mono font-bold"
            >
              Member
            </span>
          </div>
        </div>

        <!-- B. DAFTAR BARANG BELANJAAN COMPACT (TANPA GAMBAR/ICON) -->
        <div class="flex-1 overflow-y-auto divide-y divide-slate-800/30 px-3 py-1 flex flex-col">
          
          <!-- Jika Keranjang Kosong (Idle / Menunggu Barang) -->
          <div 
            v-if="liveState.cart.length === 0" 
            class="flex-1 flex flex-col items-center justify-center text-center p-6 text-slate-400"
          >
            <div class="text-3xl mb-2 opacity-50">🛒</div>
            <div class="text-xs font-bold uppercase tracking-wider mb-1">Menunggu Pemindaian Kasir</div>
            <p class="text-[11px] max-w-xs leading-relaxed text-slate-400">
              Silakan letakkan barang belanjaan Anda di meja. Kasir sedang siap memindai barcode produk.
            </p>
          </div>

          <!-- List Item Compact -->
          <div 
            v-for="(item, idx) in liveState.cart" 
            :key="idx"
            :class="isDarkMode ? (idx === 0 ? 'bg-blue-950/40 border-l-4 border-blue-500' : 'hover:bg-slate-800/50') : (idx === 0 ? 'bg-blue-50/80 border-l-4 border-blue-600' : 'hover:bg-slate-50')"
            class="px-2.5 py-2 flex items-center justify-between transition-colors"
          >
            <!-- Sisi Kiri: Nama Produk & Rincian Qty -->
            <div class="min-w-0 pr-3">
              <div 
                :class="isDarkMode ? 'text-white' : 'text-slate-900'"
                class="text-xs font-bold truncate leading-tight"
              >
                {{ item.name }}
              </div>
              <div class="flex items-center gap-1.5 text-[10px] text-slate-400 mt-0.5 font-mono">
                <span class="font-bold text-blue-500">{{ item.qty }} {{ item.unit || 'Pcs' }}</span>
                <span>×</span>
                <span>Rp {{ item.price.toLocaleString('id-ID') }}</span>
                <span v-if="item.discount > 0" class="text-amber-500 font-bold">
                  (Diskon -{{ item.discount.toLocaleString('id-ID') }})
                </span>
              </div>
            </div>

            <!-- Sisi Kanan: Subtotal Rp -->
            <div class="text-right shrink-0">
              <div 
                :class="isDarkMode ? 'text-emerald-400' : 'text-emerald-600'"
                class="text-xs font-black font-mono tracking-tight"
              >
                Rp {{ item.subtotal.toLocaleString('id-ID') }}
              </div>
            </div>
          </div>

        </div>

        <!-- C. PANEL RINGKASAN TAGIHAN / KEMBALIAN UANG JUMBO -->
        <div 
          :class="isDarkMode ? 'bg-slate-950 border-slate-800' : 'bg-slate-50 border-slate-200'"
          class="border-t p-3.5 flex flex-col gap-2 shrink-0 shadow-xs"
        >
          <!-- Subtotal & Diskon Strip -->
          <div class="flex items-center justify-between text-[11px] text-slate-400 px-1 font-medium">
            <div class="flex items-center gap-3">
              <span>Subtotal: <strong :class="isDarkMode ? 'text-slate-200' : 'text-slate-700'" class="font-mono">Rp {{ liveState.subtotal.toLocaleString('id-ID') }}</strong></span>
              <span v-if="liveState.discount > 0" class="text-amber-500 font-bold">
                Hemat: <strong class="font-mono">-Rp {{ liveState.discount.toLocaleString('id-ID') }}</strong>
              </span>
            </div>
            <div class="text-[10px] text-slate-400 font-mono">
              Kasir: {{ liveState.cashier.name || 'Siska A.' }}
            </div>
          </div>

          <!-- KONDISI 1: JIKA SEDANG PEMBAYARAN TUNAI (CASH SETTLEMENT) -->
          <template v-if="displayState === 'PAYING_CASH'">
            <div class="grid grid-cols-2 gap-2">
              <div 
                :class="isDarkMode ? 'bg-slate-900 border-slate-800 text-slate-200' : 'bg-white border-slate-200 text-slate-800 shadow-2xs'"
                class="border rounded-2xl p-2.5 text-center flex flex-col justify-center"
              >
                <span class="text-[9px] font-bold uppercase tracking-wider text-slate-400 block mb-0.5">UANG DITERIMA</span>
                <span class="text-base font-mono font-black text-blue-500">
                  Rp {{ liveState.cash.given.toLocaleString('id-ID') }}
                </span>
              </div>

              <!-- Kartu Kembalian Jumbo -->
              <div 
                :class="isDarkMode ? 'bg-emerald-950/80 border-emerald-500/80' : 'bg-emerald-50 border-emerald-500 shadow-xs'"
                class="border-2 rounded-2xl p-2.5 text-center flex flex-col justify-center"
              >
                <span class="text-[9px] font-black text-emerald-600 uppercase tracking-widest block mb-0.5">UANG KEMBALIAN</span>
                <span class="text-xl font-mono font-black text-emerald-600 tracking-tight">
                  Rp {{ liveState.cash.change.toLocaleString('id-ID') }}
                </span>
              </div>
            </div>
          </template>

          <!-- KONDISI 2: TOTAL TAGIHAN STANDAR JUMBO -->
          <template v-else>
            <div 
              :class="isDarkMode ? 'bg-gradient-to-r from-emerald-950 to-slate-900 border-2 border-emerald-500/70 text-white' : 'bg-gradient-to-r from-emerald-600 to-teal-700 text-white shadow-md'"
              class="rounded-2xl px-5 py-3 flex items-center justify-between"
            >
              <div>
                <span class="text-[10px] font-black text-emerald-200 uppercase tracking-widest block">TOTAL TAGIHAN</span>
                <span class="text-[10px] text-emerald-100/80">Periksa kembali barang belanjaan Anda</span>
              </div>
              <div class="text-right">
                <div class="text-2xl font-mono font-black tracking-tight flex items-baseline justify-end gap-1.5">
                  <span class="text-sm font-bold text-emerald-200">Rp</span>
                  <span>{{ liveState.grandTotal.toLocaleString('id-ID') }}</span>
                </div>
              </div>
            </div>
          </template>

        </div>

      </section>

      <!-- ============================================================== -->
      <!-- KOLOM KANAN (46%): SPACE IKLAN & BANNER BESAR / QRIS JUMBO     -->
      <!-- ============================================================== -->
      <section 
        :class="isDarkMode ? 'bg-slate-950' : 'bg-slate-50/70'"
        class="w-[46%] h-full flex flex-col justify-between p-4 overflow-hidden relative transition-colors"
      >
        
        <!-- ============================================================ -->
        <!-- MODE A: TAMPILAN QRIS JUMBO (KETIKA KASIR MEMILIH BAYAR QRIS) -->
        <!-- ============================================================ -->
        <div 
          v-if="displayState === 'PAYING_QRIS'" 
          class="w-full h-full flex flex-col items-center justify-between py-2 text-center animate-in fade-in duration-200"
        >
          <!-- Header QRIS -->
          <div class="flex items-center justify-between w-full border-b border-slate-700/30 pb-2">
            <div class="flex items-center gap-2 text-left">
              <div class="w-8 h-8 rounded-xl bg-blue-600 text-white font-black text-xs flex items-center justify-center">QR</div>
              <div>
                <div 
                  :class="isDarkMode ? 'text-white' : 'text-slate-900'"
                  class="text-xs font-black uppercase tracking-wider"
                >
                  PEMBAYARAN QRIS
                </div>
                <div class="text-[10px] text-slate-400">Scan via Mobile Banking / E-Wallet</div>
              </div>
            </div>
            <div class="flex items-center gap-1.5">
              <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
              <span class="text-[11px] font-bold text-emerald-500">Menunggu Scan</span>
            </div>
          </div>

          <!-- QR Code Container Super Besar (240px) -->
          <div class="bg-white p-3 rounded-3xl border-4 border-slate-200 shadow-xl flex items-center justify-center my-auto">
            <img 
              v-if="qrisDisplayUrl" 
              :src="qrisDisplayUrl" 
              :alt="`QRIS Dinamis ${liveState.store.name || 'Toko'}`" 
              class="w-56 h-56 object-contain"
            />
            <div v-else class="w-56 h-56 flex flex-col items-center justify-center text-slate-400 gap-2">
              <span class="text-3xl animate-spin">🔄</span>
              <span class="text-xs font-medium">Membuat Kode QR...</span>
            </div>
          </div>

          <!-- Tagihan Pas Terkunci -->
          <div 
            :class="isDarkMode ? 'bg-blue-950/60 border-blue-500/40' : 'bg-blue-50 border-blue-300'"
            class="border rounded-2xl px-4 py-2.5 w-full flex items-center justify-between"
          >
            <div class="text-left">
              <span class="text-[10px] text-blue-500 font-bold uppercase tracking-wider block">Nominal Pas Terkunci:</span>
              <span class="text-[11px] text-slate-400">Tidak perlu ketik angka manual</span>
            </div>
            <span class="font-mono font-black text-emerald-500 text-xl">Rp {{ liveState.grandTotal.toLocaleString('id-ID') }}</span>
          </div>

          <!-- Logo E-Wallet & Bank yang didukung -->
          <div class="text-[10px] text-slate-400 flex items-center justify-center gap-2 pt-1 font-medium">
            <span>BCA Mobile</span> • <span>Livin'</span> • <span>GoPay</span> • <span>OVO</span> • <span>Dana</span> • <span>ShopeePay</span>
          </div>
        </div>

        <!-- ============================================================ -->
        <!-- MODE B: BANNER GAMBAR PROMOSI TOKO (DARI INDEXEDDB KASIR)    -->
        <!-- ============================================================ -->
        <div 
          v-else 
          class="w-full h-full flex flex-col justify-between"
        >
          
          <!-- Header Banner Iklan & Dots Navigasi -->
          <div class="flex items-center justify-between mb-2 shrink-0">
            <div class="flex items-center gap-1.5">
              <span class="text-sm">📢</span>
              <span 
                :class="isDarkMode ? 'text-amber-400' : 'text-amber-600'"
                class="text-xs font-black uppercase tracking-wider"
              >
                Promo & Penawaran Spesial
              </span>
            </div>

            <!-- Dots Indicator & Navigasi Panah Banner Kolom Kanan (1:1) -->
            <div v-if="sidebarBanners.length > 1" class="flex items-center gap-2">
              <div class="flex items-center gap-1">
                <span 
                  v-for="(s, i) in sidebarBanners" 
                  :key="i"
                  @click="currentSidebarIndex = i"
                  :class="i === currentSidebarIndex ? 'w-4 bg-teal-500' : (isDarkMode ? 'w-1.5 bg-slate-700' : 'w-1.5 bg-slate-300')"
                  class="h-1.5 rounded-full transition-all cursor-pointer"
                ></span>
              </div>
              <div class="flex items-center gap-1">
                <button 
                  @click="prevSidebarBanner" 
                  :class="isDarkMode ? 'bg-slate-800 hover:bg-slate-700 text-slate-300 border-slate-700' : 'bg-white hover:bg-slate-100 text-slate-700 border-slate-200 shadow-2xs'"
                  class="w-6 h-6 rounded-lg border flex items-center justify-center text-xs font-bold transition cursor-pointer"
                >
                  ‹
                </button>
                <button 
                  @click="nextSidebarBanner" 
                  :class="isDarkMode ? 'bg-slate-800 hover:bg-slate-700 text-slate-300 border-slate-700' : 'bg-white hover:bg-slate-100 text-slate-700 border-slate-200 shadow-2xs'"
                  class="w-6 h-6 rounded-lg border flex items-center justify-center text-xs font-bold transition cursor-pointer"
                >
                  ›
                </button>
              </div>
            </div>
          </div>

          <!-- KARTU HERO BANNER GAMBAR & VIDEO KOLOM KANAN (VERTIKAL PORTRAIT ~4:5) -->
          <div 
            :class="isDarkMode ? 'bg-slate-900 border-slate-800 shadow-xl' : 'bg-white border-slate-200 shadow-md'"
            class="flex-1 rounded-3xl border flex flex-col justify-center items-center relative overflow-hidden transition-all duration-300"
          >
            <!-- JIKA ADA MEDIA BANNER KOLOM KANAN -->
            <div v-if="sidebarBanners.length > 0" class="w-full h-full relative overflow-hidden rounded-3xl flex items-center justify-center bg-black">
              <!-- Ambient Backdrop Blur jika mode 'contain' agar ruang samping terisi estetik -->
              <video 
                v-if="isVideoBanner(sidebarBanners[currentSidebarIndex]) && videoFitMode === 'contain'"
                :key="'col-vid-bg-' + (sidebarBanners[currentSidebarIndex].id || currentSidebarIndex)"
                :src="getBannerMediaSrc(sidebarBanners[currentSidebarIndex])"
                autoplay
                muted
                loop
                playsinline
                aria-hidden="true"
                class="absolute inset-0 w-full h-full object-cover blur-2xl opacity-40 scale-110 pointer-events-none"
              ></video>

              <!-- Video Promosi Sidebar Utama (Autoplay, Muted, Loop jika tunggal, @ended untuk auto-slide) -->
              <video 
                v-if="isVideoBanner(sidebarBanners[currentSidebarIndex])"
                :key="'col-vid-' + (sidebarBanners[currentSidebarIndex].id || currentSidebarIndex)"
                :src="getBannerMediaSrc(sidebarBanners[currentSidebarIndex])"
                autoplay
                muted
                :loop="sidebarBanners.length === 1"
                playsinline
                @ended="onSidebarVideoEnded"
                :class="videoFitMode === 'cover' ? 'object-cover' : 'object-contain'"
                class="w-full h-full relative z-10 transition-all duration-300"
              ></video>

              <!-- Gambar Kolom Kanan -->
              <img 
                v-else
                :key="'col-img-' + (sidebarBanners[currentSidebarIndex].id || currentSidebarIndex)"
                :src="getBannerMediaSrc(sidebarBanners[currentSidebarIndex])" 
                :alt="sidebarBanners[currentSidebarIndex]?.title || 'Banner Promo'"
                :class="videoFitMode === 'contain' ? 'object-contain' : 'object-cover'"
                class="w-full h-full relative z-10 transition-all duration-300"
              >
            </div>

            <!-- JIKA BELUM ADA GAMBAR BANNER: KARTU DEFAULT TOKO -->
            <div v-else class="flex flex-col items-center justify-center text-center p-6 my-auto">
              <div class="w-16 h-16 rounded-2xl bg-blue-600 text-white font-black text-2xl flex items-center justify-center shadow-lg mb-3">
                {{ getStoreInitials(liveState.store.name) }}
              </div>
              <h2 :class="isDarkMode ? 'text-white' : 'text-slate-900'" class="text-xl font-black tracking-tight mb-1">
                {{ liveState.store.name || 'ROZAK MART' }}
              </h2>
              <p class="text-xs text-slate-400 max-w-xs font-medium">
                {{ liveState.store.description || liveState.store.address || 'Pusat Belanja Hemat & Terpercaya. Silakan upload gambar banner promo dari menu kasir.' }}
              </p>
            </div>

          </div>

          <!-- Bottom Footer Note -->
          <div class="text-center text-[10px] text-slate-400 italic mt-2 shrink-0">
            {{ liveState.store.footer || `Terima Kasih Telah Berbelanja di ${liveState.store.name || 'Toko Kami'}` }}
          </div>

        </div>

      </section>

    </main>

    <AppPopup />
  </div>
</template>

<style>
* {
  box-sizing: border-box;
}

#cfd-pairing-scanner {
  border: none !important;
}
#cfd-pairing-scanner video {
  object-fit: cover !important;
  width: 100% !important;
  height: 100% !important;
  border-radius: 1rem !important;
}
#cfd-pairing-scanner img[alt="Info icon"] {
  display: none !important;
}
</style>
