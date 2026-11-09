<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
  settings: {
    type: Object,
    default: () => ({})
  },
  initialLiveState: {
    type: Object,
    default: () => ({})
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

// Live State Reactive Container
const liveState = ref({
  state: 'IDLE', // 'IDLE' | 'ACTIVE' | 'PAYING_CASH' | 'PAYING_QRIS' | 'SUCCESS'
  store: {
    name: props.store.name || props.settings.store_name || 'Rozak Mart',
    address: props.store.address || props.settings.store_address || '',
    phone: props.store.phone || props.settings.store_phone || '',
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
let wirelessPollInterval = null;
const isConnected = ref(true);
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

// Handler penerimaan pesan sinkronisasi
function handleSyncMessage(payload) {
  if (!payload || typeof payload !== 'object') return;
  liveState.value = {
    ...liveState.value,
    ...payload,
    timestamp: Date.now()
  };
  isConnected.value = true;
}

// Polling nirkabel untuk device terpisah (WiFi tablet kedua / smartphone)
async function pollWirelessState() {
  try {
    const res = await fetch('/pos/display/live', { headers: { 'Accept': 'application/json' } });
    if (res.ok) {
      const data = await res.json();
      if (data && data.timestamp && data.timestamp > (liveState.value.timestamp || 0)) {
        handleSyncMessage(data);
      }
    }
  } catch (e) {
    // Silent network retry
  }
}

onMounted(() => {
  updateClock();
  clockInterval = setInterval(updateClock, 1000);

  // 1. BroadcastChannel API (Nol Latensi - untuk Dual Monitor / Satu Perangkat)
  try {
    if ('BroadcastChannel' in window) {
      broadcastChannel = new BroadcastChannel('rozak_pos_display');
      broadcastChannel.onmessage = (event) => {
        handleSyncMessage(event.data);
      };
    }
  } catch (e) {
    console.warn('BroadcastChannel tidak didukung:', e);
  }

  // 2. localStorage Storage Event (Fallback multi-tab / iframe)
  window.addEventListener('storage', (event) => {
    if (event.key === 'rozak_dual_display_state' && event.newValue) {
      try {
        const parsed = JSON.parse(event.newValue);
        handleSyncMessage(parsed);
      } catch (err) {}
    }
  });

  // Cek state tersimpan di localStorage saat pertama load
  try {
    const saved = localStorage.getItem('rozak_dual_display_state');
    if (saved) {
      handleSyncMessage(JSON.parse(saved));
    }
  } catch (err) {}

  // 3. Polling Nirkabel cadangan (Setiap 2 detik untuk tablet kedua nirkabel)
  wirelessPollInterval = setInterval(pollWirelessState, 2000);
});

onUnmounted(() => {
  if (clockInterval) clearInterval(clockInterval);
  if (wirelessPollInterval) clearInterval(wirelessPollInterval);
  if (broadcastChannel) {
    broadcastChannel.close();
  }
});

// Computed Helper State
const displayState = computed(() => {
  if (liveState.value.state === 'SUCCESS') return 'SUCCESS';
  if (liveState.value.state === 'PAYING_QRIS') return 'PAYING_QRIS';
  if (liveState.value.state === 'PAYING_CASH') return 'PAYING_CASH';
  if (liveState.value.cart && liveState.value.cart.length > 0) return 'ACTIVE';
  return 'IDLE';
});
</script>

<template>
  <Head :title="`Layar Pembeli · ${liveState.store.name || 'Rozak Mart'}`" />

  <div class="w-screen h-screen max-w-full max-h-screen overflow-hidden bg-slate-900 text-slate-100 flex flex-col select-none font-sans">
    
    <!-- TOP BAR: Store Branding, Cashier Info, Live Clock & Fullscreen Button -->
    <header class="bg-slate-950/90 border-b border-slate-800 px-6 py-3.5 flex items-center justify-between shrink-0 shadow-md">
      <!-- Store Brand -->
      <div class="flex items-center gap-3.5">
        <div class="w-10 h-10 rounded-2xl bg-blue-600 text-white font-black text-base flex items-center justify-center shadow-md shadow-blue-600/30">
          RM
        </div>
        <div>
          <h1 class="text-base font-black text-white tracking-wide uppercase leading-tight">
            {{ liveState.store.name || 'ROZAK MART' }}
          </h1>
          <p class="text-xs text-slate-400 font-medium flex items-center gap-2">
            <span>{{ liveState.store.address || 'Pusat Belanja Hemat & Terjangkau' }}</span>
            <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
            <span class="text-emerald-400 font-bold">Layar Pembeli Aktif</span>
          </p>
        </div>
      </div>

      <!-- Clock & Fullscreen Control -->
      <div class="flex items-center gap-4">
        <div class="text-right leading-tight">
          <div class="text-xs text-slate-400 font-medium">{{ currentDate }}</div>
          <div class="text-sm font-mono font-black text-blue-400 tracking-wider mt-0.5">{{ currentTime }}</div>
        </div>
        <button 
          @click="toggleFullscreen" 
          title="Mode Layar Penuh" 
          class="w-9 h-9 rounded-xl bg-slate-800 hover:bg-slate-700 active:bg-slate-600 text-slate-300 border border-slate-700 flex items-center justify-center text-sm transition cursor-pointer"
        >
          {{ isFullscreen ? '⛶' : '🖵' }}
        </button>
      </div>
    </header>

    <!-- MAIN BODY AREA (DYNAMIC STATES) -->
    <main class="flex-1 overflow-hidden relative flex">

      <!-- ============================================================== -->
      <!-- STATE 1: IDLE / WELCOME SCREEN (Saat kasir belum mulai transaksi)-->
      <!-- ============================================================== -->
      <div v-if="displayState === 'IDLE'" class="w-full h-full flex flex-col items-center justify-center p-8 text-center bg-gradient-to-b from-slate-900 to-slate-950">
        <!-- Store Big Icon & Greeting -->
        <div class="w-24 h-24 rounded-3xl bg-blue-600/20 border border-blue-500/30 text-blue-400 flex items-center justify-center text-5xl mb-5 shadow-2xl animate-pulse">
          🛍️
        </div>
        <h2 class="text-3xl font-black text-white tracking-tight uppercase mb-2">
          Selamat Datang di {{ liveState.store.name || 'Rozak Mart' }}
        </h2>
        <p class="text-base text-slate-400 max-w-lg mb-8 leading-relaxed font-medium">
          Silakan letakkan barang belanjaan Anda di meja kasir. Kami siap melayani Anda dengan ramah, cepat, dan akurat.
        </p>

        <!-- Feature Promo Cards -->
        <div class="grid grid-cols-3 gap-4 max-w-3xl w-full">
          <div class="bg-slate-800/60 border border-slate-700/80 rounded-2xl p-4 text-left flex items-start gap-3.5 shadow-sm">
            <span class="text-2xl p-2 bg-blue-600/20 rounded-xl text-blue-400">⚡</span>
            <div>
              <div class="text-xs font-black text-white uppercase tracking-wide">Transaksi Cepat</div>
              <div class="text-[11px] text-slate-400 mt-0.5">Pemindaian barcode barcode presisi tanpa antre lama.</div>
            </div>
          </div>
          <div class="bg-slate-800/60 border border-slate-700/80 rounded-2xl p-4 text-left flex items-start gap-3.5 shadow-sm">
            <span class="text-2xl p-2 bg-emerald-600/20 rounded-xl text-emerald-400">💳</span>
            <div>
              <div class="text-xs font-black text-white uppercase tracking-wide">Bayar QRIS Mudah</div>
              <div class="text-[11px] text-slate-400 mt-0.5">Mendukung BCA, Mandiri, GoPay, OVO, ShopeePay & Dana.</div>
            </div>
          </div>
          <div class="bg-slate-800/60 border border-slate-700/80 rounded-2xl p-4 text-left flex items-start gap-3.5 shadow-sm">
            <span class="text-2xl p-2 bg-amber-600/20 rounded-xl text-amber-400">🏷️</span>
            <div>
              <div class="text-xs font-black text-white uppercase tracking-wide">Harga Terjangkau</div>
              <div class="text-[11px] text-slate-400 mt-0.5">Potongan grosir & promo hemat kebutuhan harian.</div>
            </div>
          </div>
        </div>

        <div class="mt-8 text-xs text-slate-500 font-mono flex items-center gap-2">
          <span>Kasir Siap Melayani:</span>
          <strong class="text-slate-300 font-bold uppercase tracking-wider">{{ liveState.cashier.name || 'Siska Anggraeni' }}</strong>
        </div>
      </div>

      <!-- ============================================================== -->
      <!-- STATE 2 & 3: ACTIVE CART / SCANNING & CASH SETTLEMENT          -->
      <!-- ============================================================== -->
      <div v-else-if="displayState === 'ACTIVE' || displayState === 'PAYING_CASH'" class="w-full h-full flex flex-row">
        
        <!-- SISI KIRI (60%): DAFTAR BARANG YANG SEDANG DISCAN -->
        <div class="w-[60%] h-full bg-slate-900 border-r border-slate-800 flex flex-col overflow-hidden">
          
          <!-- Header Daftar Belanjaan -->
          <div class="bg-slate-950/70 px-6 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-2">
              <span class="text-sm font-black text-white uppercase tracking-wider">Daftar Belanjaan Anda</span>
              <span class="bg-blue-600 text-white text-[11px] font-bold px-2.5 py-0.5 rounded-full font-mono">
                {{ liveState.totalQty }} pcs
              </span>
            </div>
            <div class="text-xs text-slate-400 font-medium">
              {{ liveState.cart.length }} jenis barang
            </div>
          </div>

          <!-- List Item Belanjaan -->
          <div class="flex-1 overflow-y-auto divide-y divide-slate-800/80 p-3 flex flex-col gap-1">
            <div 
              v-for="(item, idx) in liveState.cart" 
              :key="idx"
              :class="idx === 0 ? 'bg-blue-950/40 border border-blue-500/40 shadow-sm' : 'bg-slate-800/40 border border-transparent'"
              class="p-3.5 rounded-2xl flex items-center justify-between transition-all"
            >
              <div class="flex items-center gap-3.5 min-w-0 pr-3">
                <span class="w-10 h-10 rounded-xl bg-slate-800 border border-slate-700 text-slate-200 flex items-center justify-center text-xl shrink-0">
                  {{ item.icon || '📦' }}
                </span>
                <div class="truncate">
                  <div class="text-sm font-bold text-white truncate leading-snug">{{ item.name }}</div>
                  <div class="flex items-center gap-2 text-xs text-slate-400 mt-0.5 font-mono">
                    <span>{{ item.qty }} {{ item.unit || 'Pcs' }}</span>
                    <span>×</span>
                    <span>Rp {{ item.price.toLocaleString('id-ID') }}</span>
                    <span v-if="item.discount > 0" class="text-amber-400 font-bold">(-{{ item.discount.toLocaleString('id-ID') }})</span>
                  </div>
                </div>
              </div>

              <div class="text-right shrink-0">
                <div class="text-base font-black font-mono text-emerald-400">
                  Rp {{ item.subtotal.toLocaleString('id-ID') }}
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- SISI KANAN (40%): RINGKASAN TAGIHAN & CASH SETTLEMENT -->
        <div class="w-[40%] h-full bg-slate-950 flex flex-col justify-between p-6 overflow-hidden">
          
          <!-- Pelanggan Info -->
          <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-xl bg-indigo-600/20 text-indigo-400 flex items-center justify-center text-lg">👤</div>
              <div>
                <div class="text-xs font-bold text-white uppercase tracking-wide">{{ liveState.customer?.name || 'Pelanggan Umum' }}</div>
                <div class="text-[10px] text-slate-400">{{ liveState.customer?.phone ? `Member: ${liveState.customer.phone}` : 'Non-Member' }}</div>
              </div>
            </div>
            <span class="text-[10px] bg-slate-800 text-slate-300 px-2 py-1 rounded-md font-mono font-bold">
              KASIR AKTIF
            </span>
          </div>

          <!-- Rincian Nominal / Cash Settlement Mode -->
          <div class="flex flex-col gap-3 my-auto">
            
            <!-- Jika Kasir sedang input Tunai: Tampilkan Rincian Uang & Kembalian -->
            <template v-if="displayState === 'PAYING_CASH'">
              <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-col gap-3">
                <div class="flex justify-between items-center text-xs text-slate-400">
                  <span class="uppercase tracking-wider font-bold">Total Belanja</span>
                  <span class="font-mono font-bold text-slate-200">Rp {{ liveState.grandTotal.toLocaleString('id-ID') }}</span>
                </div>
                <div class="flex justify-between items-center text-xs text-slate-400">
                  <span class="uppercase tracking-wider font-bold">Uang Diterima</span>
                  <span class="font-mono font-black text-blue-400 text-base">Rp {{ liveState.cash.given.toLocaleString('id-ID') }}</span>
                </div>
              </div>

              <!-- Kartu Kembalian Raksasa -->
              <div class="bg-emerald-950/70 border-2 border-emerald-500/80 rounded-2xl p-5 text-center shadow-lg">
                <span class="text-xs font-black text-emerald-400 uppercase tracking-widest block mb-1">UANG KEMBALIAN</span>
                <div class="text-4xl font-mono font-black text-emerald-300 tracking-tight">
                  Rp {{ liveState.cash.change.toLocaleString('id-ID') }}
                </div>
                <p class="text-[11px] text-emerald-400/80 mt-1 font-medium">Mohon periksa kembali uang kembalian Anda</p>
              </div>
            </template>

            <!-- Mode Standar Scanning: Tampilkan Rincian Subtotal & Grand Total -->
            <template v-else>
              <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-col gap-2.5">
                <div class="flex justify-between items-center text-xs text-slate-400">
                  <span>Subtotal Belanjaan</span>
                  <span class="font-mono font-bold text-slate-200">Rp {{ liveState.subtotal.toLocaleString('id-ID') }}</span>
                </div>
                <div v-if="liveState.discount > 0" class="flex justify-between items-center text-xs text-amber-400 font-bold">
                  <span>Total Potongan / Diskon</span>
                  <span class="font-mono">-Rp {{ liveState.discount.toLocaleString('id-ID') }}</span>
                </div>
              </div>

              <!-- Grand Total Display Besar -->
              <div class="bg-gradient-to-br from-emerald-950/80 to-slate-900 border-2 border-emerald-500/70 rounded-2xl p-6 text-center shadow-xl">
                <span class="text-xs font-black text-emerald-400 uppercase tracking-widest block mb-1.5">TOTAL TAGIHAN</span>
                <div class="text-4xl font-mono font-black text-white tracking-tight flex items-baseline justify-center gap-2">
                  <span class="text-xl text-emerald-400 font-bold">Rp</span>
                  <span>{{ liveState.grandTotal.toLocaleString('id-ID') }}</span>
                </div>
              </div>
            </template>
          </div>

          <!-- Bottom Footer Note -->
          <div class="text-center text-xs text-slate-500 italic">
            {{ liveState.store.footer || 'Terima Kasih Telah Berbelanja di Rozak Mart' }}
          </div>
        </div>

      </div>

      <!-- ============================================================== -->
      <!-- STATE 4: DYNAMIC QRIS DISPLAY (Menghadap langsung ke pembeli)   -->
      <!-- ============================================================== -->
      <div v-else-if="displayState === 'PAYING_QRIS'" class="w-full h-full flex flex-col items-center justify-center p-8 bg-slate-950 text-center">
        <div class="max-w-md w-full bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-2xl flex flex-col items-center gap-4">
          
          <div class="flex items-center justify-between w-full border-b border-slate-800 pb-3">
            <div class="flex items-center gap-2 text-left">
              <div class="w-8 h-8 rounded-xl bg-blue-600 text-white font-black text-xs flex items-center justify-center">QR</div>
              <div>
                <div class="text-xs font-black text-white uppercase tracking-wider">PEMBAYARAN QRIS</div>
                <div class="text-[10px] text-slate-400">Scan via Mobile Banking / E-Wallet</div>
              </div>
            </div>
            <div class="flex items-center gap-1.5">
              <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
              <span class="text-[11px] font-bold text-emerald-400">Menunggu Scan</span>
            </div>
          </div>

          <!-- QR Code Container -->
          <div class="bg-white p-4 rounded-2xl border-4 border-slate-800 shadow-xl flex items-center justify-center">
            <img 
              v-if="liveState.qris.qrUrl" 
              :src="liveState.qris.qrUrl" 
              alt="QRIS Dinamis Rozak Mart" 
              class="w-56 h-56 object-contain"
            />
            <div v-else class="w-56 h-56 flex flex-col items-center justify-center text-slate-400 gap-2">
              <span class="text-3xl animate-spin">🔄</span>
              <span class="text-xs font-medium">Membuat Kode QR...</span>
            </div>
          </div>

          <!-- Tagihan Pas -->
          <div class="bg-blue-950/60 border border-blue-500/40 rounded-2xl px-5 py-3 w-full flex items-center justify-between">
            <div class="text-left">
              <span class="text-[10px] text-blue-300 font-bold uppercase tracking-wider block">Nominal Pas Terkunci:</span>
              <span class="text-xs text-slate-400">Tidak perlu ketik angka manual</span>
            </div>
            <span class="font-mono font-black text-emerald-400 text-2xl">Rp {{ liveState.grandTotal.toLocaleString('id-ID') }}</span>
          </div>

          <!-- Logo E-Wallet & Bank yang didukung -->
          <div class="flex items-center justify-center gap-3 text-slate-400 text-xs font-medium pt-1">
            <span>BCA Mobile</span> • <span>Livin' Mandiri</span> • <span>GoPay</span> • <span>OVO</span> • <span>Dana</span> • <span>ShopeePay</span>
          </div>
        </div>
      </div>

      <!-- ============================================================== -->
      <!-- STATE 5: PAYMENT SUCCESS / CELEBRATION                         -->
      <!-- ============================================================== -->
      <div v-else-if="displayState === 'SUCCESS'" class="w-full h-full flex flex-col items-center justify-center p-8 bg-gradient-to-b from-slate-900 to-slate-950 text-center">
        <!-- Big Checkmark -->
        <div class="w-24 h-24 rounded-full bg-emerald-500/20 border-2 border-emerald-400 text-emerald-400 flex items-center justify-center text-5xl mb-6 shadow-2xl animate-bounce">
          ✓
        </div>
        <h2 class="text-3xl font-black text-white tracking-tight uppercase mb-2">
          Pembayaran Berhasil!
        </h2>
        <p class="text-sm text-slate-400 mb-6">
          Transaksi telah selesai dan struk belanjaan Anda sedang dicetak.
        </p>

        <!-- Summary Receipt Card -->
        <div class="max-w-md w-full bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-lg flex flex-col gap-3 text-xs mb-8">
          <div class="flex justify-between items-center text-slate-400 border-b border-slate-800 pb-2">
            <span>Total Transaksi</span>
            <span class="font-mono font-black text-emerald-400 text-lg">Rp {{ liveState.grandTotal.toLocaleString('id-ID') }}</span>
          </div>
          <div class="flex justify-between items-center text-slate-400">
            <span>Metode Pembayaran</span>
            <span class="font-bold text-white uppercase">{{ liveState.lastTransaction?.payment_method || 'Lunas' }}</span>
          </div>
        </div>

        <div class="text-base font-bold text-blue-400 italic">
          "{{ liveState.store.footer || 'Terima Kasih Atas Kunjungan Anda! Sampai Jumpa Kembali.' }}"
        </div>
      </div>

    </main>

  </div>
</template>

<style scoped>
/* Transisi halus */
* {
  box-sizing: border-box;
}
</style>
