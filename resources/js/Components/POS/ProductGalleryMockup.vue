<script setup>
import { ref, computed, onUnmounted, watch } from 'vue';

const props = defineProps({
  products: {
    type: Array,
    default: () => []
  },
  categories: {
    type: Array,
    default: () => []
  },
  activeCartItems: {
    type: Array,
    default: () => []
  },
  initialStyle: {
    type: String,
    default: 'standard',
    validator: (v) => ['standard', 'table'].includes(v)
  },
  canEdit: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['addToCart', 'editProduct', 'addProduct', 'addCategory', 'close']);

// ================= STATE =================
const savedLayout = typeof window !== 'undefined' ? localStorage.getItem('gallery_layout_mode') : null;
const currentStyle = ref(savedLayout === 'table' || savedLayout === 'standard' ? savedLayout : props.initialStyle);
const searchQuery = ref('');
const selectedCategory = ref('Semua');
const justAddedProductId = ref(null);
const justAddedUnitIndex = ref(null);
let feedbackTimeout = null;

watch(currentStyle, (val) => {
  if (typeof window !== 'undefined') {
    localStorage.setItem('gallery_layout_mode', val);
  }
});

// ================= HELPERS =================
function formatRupiah(val) {
  const num = Number(val) || 0;
  return 'Rp ' + num.toLocaleString('id-ID');
}

function getProductCategoryName(p) {
  if (p.category && p.category.name) return p.category.name;
  if (p.category_id && props.categories.length) {
    const c = props.categories.find(cat => cat.id === p.category_id);
    if (c) return c.name;
  }
  return 'Umum';
}

function getUnits(p) {
  if (p.units && Array.isArray(p.units) && p.units.length > 0) {
    return p.units;
  }
  return [{
    name: p.base_unit || 'Pcs',
    price: p.price || 0,
    ratio: 1,
    is_base: true
  }];
}

function getStockInfo(p) {
  const stock = typeof p.stock === 'number' ? p.stock : (parseInt(p.stock, 10) || 0);
  const minStock = typeof p.min_stock === 'number' ? p.min_stock : (parseInt(p.min_stock, 10) || 5);
  const baseUnit = p.base_unit || (p.units && p.units[0] ? p.units[0].name : 'Pcs');

  if (stock <= 0) {
    return {
      type: 'danger',
      text: 'Habis',
      dotClass: 'bg-rose-500',
      badgeClass: 'bg-rose-100 text-rose-800 border-rose-200'
    };
  }
  if (stock <= minStock) {
    return {
      type: 'warning',
      text: `Sisa ${stock} ${baseUnit}`,
      dotClass: 'bg-amber-500',
      badgeClass: 'bg-amber-100 text-amber-900 border-amber-300'
    };
  }
  return {
    type: 'success',
    text: `${stock} ${baseUnit}`,
    dotClass: 'bg-emerald-500',
    badgeClass: 'bg-emerald-50 text-emerald-800 border-emerald-200'
  };
}

function getCartQty(productId, unitName = null) {
  if (!props.activeCartItems || props.activeCartItems.length === 0) return 0;
  if (unitName) {
    const found = props.activeCartItems.find(item => item.product_id === productId && item.unit === unitName);
    return found ? found.qty : 0;
  }
  return props.activeCartItems
    .filter(item => item.product_id === productId)
    .reduce((sum, item) => sum + (item.qty || 0), 0);
}

// ================= FILTERING =================
const filteredProducts = computed(() => {
  let list = props.products || [];

  if (selectedCategory.value !== 'Semua') {
    const catLower = selectedCategory.value.toLowerCase();
    list = list.filter(p => {
      const pCat = getProductCategoryName(p).toLowerCase();
      return pCat === catLower;
    });
  }

  const q = searchQuery.value.trim().toLowerCase();
  if (q) {
    list = list.filter(p => {
      const nameMatch = p.name && p.name.toLowerCase().includes(q);
      const barcodeMatch = p.barcode && p.barcode.toLowerCase().includes(q);
      const catMatch = getProductCategoryName(p).toLowerCase().includes(q);
      return nameMatch || barcodeMatch || catMatch;
    });
  }

  return list;
});

// ================= ACTIONS =================
function handleAddToCart(product, unitIndex = 0) {
  emit('addToCart', product, unitIndex);

  justAddedProductId.value = product.id;
  justAddedUnitIndex.value = unitIndex;
  if (feedbackTimeout) clearTimeout(feedbackTimeout);
  feedbackTimeout = setTimeout(() => {
    justAddedProductId.value = null;
    justAddedUnitIndex.value = null;
  }, 550);
}

function handleEdit(product) {
  emit('editProduct', product);
}

function clearSearch() {
  searchQuery.value = '';
}

onUnmounted(() => {
  if (feedbackTimeout) {
    clearTimeout(feedbackTimeout);
    feedbackTimeout = null;
  }
});
</script>

<template>
  <div class="flex flex-col h-full bg-slate-100 text-slate-800 select-none overflow-hidden font-sans">

    <!-- HEADER GALERI TERPADU (Pencarian Lebar Penuh) -->
    <header class="bg-white border-b border-slate-200 px-3.5 py-2.5 shrink-0 shadow-xs flex items-center gap-2 z-20">
      <!-- Input Pencarian Lebar -->
      <div class="relative flex-1 min-w-0">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none">🔍</span>
        <input
          type="text"
          v-model="searchQuery"
          placeholder="Cari nama produk / kode barcode..."
          class="w-full h-11 bg-slate-50 hover:bg-white focus:bg-white border border-slate-300 focus:border-blue-600 rounded-xl pl-8 pr-9 text-xs text-slate-800 placeholder-slate-400 focus:outline-none transition font-medium"
        />
        <button
          v-if="searchQuery"
          type="button"
          @click="clearSearch"
          title="Bersihkan Pencarian"
          class="absolute right-0 top-0 w-10 h-11 flex items-center justify-center text-slate-400 hover:text-slate-700 cursor-pointer"
        >
          <span class="w-5 h-5 rounded-full bg-slate-200 hover:bg-slate-300 flex items-center justify-center text-[10px] font-bold text-slate-600">✕</span>
        </button>
      </div>

      <!-- Saklar Toggle 1-Klik: Kartu (Varian 1) vs List Tabel (Ala Indomaret) -->
        <div class="inline-flex bg-slate-100 p-1 rounded-xl border border-slate-200 shrink-0" role="group" aria-label="Mode tampilan katalog">
          <button
            type="button"
            @click="currentStyle = 'standard'"
            :class="[
              'min-h-[36px] px-2.5 rounded-lg text-[11px] font-bold flex items-center gap-1 transition cursor-pointer',
              currentStyle === 'standard'
                ? 'bg-blue-600 text-white shadow-xs'
                : 'text-slate-600 hover:text-slate-900'
            ]"
            title="Tampilan Kartu Visual (Varian 1)"
          >
            <span>⊞</span>
            <span>Kartu</span>
          </button>
          <button
            type="button"
            @click="currentStyle = 'table'"
            :class="[
              'min-h-[36px] px-2.5 rounded-lg text-[11px] font-bold flex items-center gap-1 transition cursor-pointer',
              currentStyle === 'table'
                ? 'bg-blue-600 text-white shadow-xs'
                : 'text-slate-600 hover:text-slate-900'
            ]"
            title="Tampilan List Tabel Minimarket (Ala Indomaret)"
          >
            <span>☰</span>
            <span>Tabel</span>
          </button>
        </div>

        <!-- Tombol Tambah Barang -->
        <button
          type="button"
          @click="emit('addProduct', searchQuery)"
          class="min-h-[44px] shrink-0 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white text-[11px] font-black px-3 rounded-xl flex items-center gap-1 transition cursor-pointer uppercase shadow-xs"
        >
          <span>➕</span>
          <span class="hidden lg:inline">Tambah Barang</span>
          <span class="lg:hidden">Baru</span>
        </button>

        <!-- Tombol Tutup (44x44px) -->
        <button
          type="button"
          @click="emit('close')"
          title="Tutup Galeri (Esc)"
          class="min-w-[44px] min-h-[44px] rounded-xl bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 border border-slate-300 flex items-center justify-center font-bold text-sm transition cursor-pointer shrink-0"
        >
          ✕
        </button>
    </header>

    <!-- NAVIGASI KATEGORI HORIZONTAL -->
    <nav class="bg-white/90 border-b border-slate-200 px-4 py-2 shrink-0 flex items-center gap-1.5 overflow-x-auto text-xs z-10">
      <button
        type="button"
        @click="selectedCategory = 'Semua'"
        :class="[
          'min-h-[40px] px-3.5 py-1.5 rounded-xl font-bold uppercase transition cursor-pointer whitespace-nowrap text-[11px] flex items-center',
          selectedCategory === 'Semua'
            ? 'bg-slate-900 text-white shadow-2xs'
            : 'bg-white text-slate-700 hover:bg-slate-100 border border-slate-200'
        ]"
      >
        Semua ({{ products.length }})
      </button>

      <button
        v-for="cat in categories"
        :key="cat.id || cat.name"
        type="button"
        @click="selectedCategory = cat.name"
        :class="[
          'min-h-[40px] px-3.5 py-1.5 rounded-xl font-bold uppercase transition cursor-pointer whitespace-nowrap text-[11px] flex items-center gap-1.5',
          selectedCategory.toLowerCase() === cat.name.toLowerCase()
            ? 'bg-slate-900 text-white shadow-2xs'
            : 'bg-white text-slate-700 hover:bg-slate-100 border border-slate-200'
        ]"
      >
        <span>{{ cat.icon || '📦' }}</span>
        <span>{{ cat.name }}</span>
      </button>

      <button
        type="button"
        @click="emit('addCategory')"
        class="min-h-[40px] shrink-0 bg-blue-50 hover:bg-blue-100 text-blue-800 border border-blue-200 px-3 py-1.5 rounded-xl font-bold text-[11px] flex items-center gap-1 transition cursor-pointer uppercase whitespace-nowrap"
      >
        <span>➕</span>
        <span>Kategori</span>
      </button>
    </nav>

    <!-- BODY KATALOG PRODUK -->
    <main class="flex-1 overflow-y-auto p-3.5">

      <!-- =================================================================
           MODE 1: KARTU VISUAL (VARIAN 1 - STANDARD RETAIL PLUS)
           ================================================================= -->
      <div
        v-if="currentStyle === 'standard' && filteredProducts.length > 0"
        class="grid grid-cols-3 gap-3 auto-rows-max"
      >
        <div
          v-for="p in filteredProducts"
          :key="'std-' + p.id"
          @click="handleAddToCart(p, 0)"
          :class="[
            'bg-white border rounded-2xl p-3 flex flex-col justify-between transition relative group cursor-pointer shadow-xs min-h-[135px]',
            justAddedProductId === p.id && justAddedUnitIndex === 0
              ? 'border-emerald-500 ring-2 ring-emerald-500/30 bg-emerald-50/40'
              : 'border-slate-200 hover:border-blue-500 hover:shadow-md active:bg-blue-50/50'
          ]"
        >
          <!-- Bagian Atas: Thumbnail, Kategori, Satuan & Tombol Edit 44px -->
          <div class="flex items-start gap-2.5">
            <div class="w-10 h-10 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center shrink-0 overflow-hidden shadow-2xs">
              <img v-if="p.image" :src="p.image" :alt="p.name" class="w-full h-full object-cover" />
              <span v-else class="text-2xl">{{ p.icon || '📦' }}</span>
            </div>

            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between gap-1">
                <span class="text-[9px] text-blue-700 bg-blue-50 border border-blue-200 font-black uppercase tracking-wider px-2 py-0.5 rounded-md truncate max-w-[100px]">
                  {{ getProductCategoryName(p) }}
                </span>

                <div class="flex items-center gap-1 shrink-0">
                  <span
                    v-if="getUnits(p).length > 1"
                    class="text-[9px] bg-purple-100 text-purple-800 border border-purple-200 font-extrabold px-1.5 py-0.5 rounded-md uppercase"
                  >
                    {{ getUnits(p).length }} Satuan
                  </span>

                  <button
                    v-if="canEdit"
                    type="button"
                    @click.stop="handleEdit(p)"
                    title="Ubah Produk / Atur Harga"
                    class="min-w-[44px] min-h-[44px] rounded-xl bg-slate-100 hover:bg-amber-100 active:bg-amber-200 border border-slate-200 hover:border-amber-300 text-slate-600 hover:text-amber-800 flex items-center justify-center text-xs transition cursor-pointer shrink-0"
                  >
                    ✏️
                  </button>
                </div>
              </div>

              <h3 class="text-xs text-slate-900 font-bold line-clamp-2 mt-1 leading-snug">
                {{ p.name }}
              </h3>
            </div>
          </div>

          <!-- Bagian Bawah: Stok 3-Warna & Harga Kontras Tinggi -->
          <div class="mt-2.5 pt-2 border-t border-slate-100 flex items-end justify-between gap-2">
            <div class="flex items-center gap-1.5">
              <span :class="['w-2 h-2 rounded-full shrink-0', getStockInfo(p).dotClass]"></span>
              <span class="text-[10px] text-slate-700 font-bold">
                {{ getStockInfo(p).text }}
              </span>
            </div>

            <div class="text-right">
              <div class="text-[10px] text-slate-700 font-extrabold uppercase">
                {{ getUnits(p)[0].name }}
              </div>
              <div class="text-sm font-mono text-emerald-800 font-black tracking-tight leading-none mt-0.5">
                {{ formatRupiah(getUnits(p)[0].price) }}
              </div>
            </div>
          </div>

          <!-- Badge Qty di Keranjang Aktif -->
          <div
            v-if="getCartQty(p.id) > 0"
            class="absolute -top-2 -left-2 bg-emerald-600 text-white text-[10px] font-black w-6 h-6 rounded-full flex items-center justify-center border-2 border-white shadow-sm"
          >
            {{ getCartQty(p.id) }}
          </div>
        </div>
      </div>

      <!-- =================================================================
           MODE 2: LIST TABEL MINIMARKET (ALA INDOMARET — PADAT & MUDAH DIPINDAI)
           ================================================================= -->
      <div
        v-else-if="currentStyle === 'table' && filteredProducts.length > 0"
        class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs"
      >
        <table class="w-full text-left border-collapse">
          <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-10 text-[10px] font-black text-slate-600 uppercase tracking-wider">
            <tr>
              <th class="py-2.5 px-3">Nama Produk</th>
              <th class="py-2.5 px-2.5 w-32">Barcode / PLU</th>
              <th class="py-2.5 px-2.5 w-28">Stok &amp; Satuan</th>
              <th class="py-2.5 px-2.5 text-right w-32">Harga Jual</th>
              <th v-if="canEdit" class="py-2.5 px-2 text-center w-16">Edit</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="(p, rIdx) in filteredProducts"
              :key="'tbl-' + p.id"
              @click="handleAddToCart(p, 0)"
              :class="[
                'transition cursor-pointer',
                justAddedProductId === p.id && justAddedUnitIndex === 0
                  ? 'bg-emerald-50 ring-1 ring-inset ring-emerald-400'
                  : (rIdx % 2 === 1 ? 'bg-slate-50/40 hover:bg-blue-50/70 active:bg-blue-100/70' : 'bg-white hover:bg-blue-50/70 active:bg-blue-100/70')
              ]"
            >
              <!-- Produk & Kategori -->
              <td class="py-2 px-3 min-h-[52px]">
                <div class="flex items-center gap-2.5">
                  <div class="w-8 h-8 rounded-lg bg-slate-50 border border-slate-200 flex items-center justify-center shrink-0 overflow-hidden">
                    <img v-if="p.image" :src="p.image" :alt="p.name" class="w-full h-full object-cover" />
                    <span v-else class="text-base">{{ p.icon || '📦' }}</span>
                  </div>
                  <div class="min-w-0 flex-1">
                    <div class="text-xs font-bold text-slate-900 leading-snug">
                      {{ p.name }}
                    </div>
                    <div class="flex items-center gap-1.5 mt-0.5 flex-wrap">
                      <span class="text-[9px] text-blue-700 bg-blue-50 border border-blue-200 font-black uppercase px-1.5 py-0.2 rounded">
                        {{ getProductCategoryName(p) }}
                      </span>
                      <span
                        v-if="getCartQty(p.id) > 0"
                        class="text-[9px] bg-emerald-600 text-white font-black px-1.5 py-0.2 rounded"
                      >
                        ✓ {{ getCartQty(p.id) }} di keranjang
                      </span>
                    </div>
                  </div>
                </div>
              </td>

              <!-- Barcode / PLU -->
              <td class="py-2 px-2.5 font-mono text-[11px] font-bold text-slate-600">
                {{ p.barcode || '-' }}
              </td>

              <!-- Stok & Satuan -->
              <td class="py-2 px-2.5">
                <div class="flex items-center gap-1.5">
                  <span :class="['w-2 h-2 rounded-full shrink-0', getStockInfo(p).dotClass]"></span>
                  <span class="text-[11px] font-bold text-slate-700">{{ getStockInfo(p).text }}</span>
                </div>
                <div v-if="getUnits(p).length > 1" class="mt-0.5">
                  <span class="text-[9px] bg-purple-100 text-purple-800 border border-purple-200 font-extrabold px-1.5 py-0.2 rounded uppercase">
                    {{ getUnits(p).length }} Satuan
                  </span>
                </div>
              </td>

              <!-- Harga Rata Kanan Kontras Tinggi -->
              <td class="py-2 px-2.5 text-right">
                <div class="text-xs sm:text-sm font-mono font-black text-emerald-800 leading-tight">
                  {{ formatRupiah(getUnits(p)[0].price) }}
                </div>
                <div class="text-[9px] text-slate-500 font-bold uppercase">
                  / {{ getUnits(p)[0].name }}
                </div>
              </td>

              <!-- Tombol Edit Terisolasi (Khusus Owner - Minimal 44x44px) -->
              <td v-if="canEdit" class="py-1.5 px-2 text-center" @click.stop>
                <button
                  type="button"
                  @click.stop="handleEdit(p)"
                  title="Ubah Produk / Atur Harga"
                  class="min-w-[44px] min-h-[44px] rounded-xl bg-slate-100 hover:bg-amber-100 active:bg-amber-200 border border-slate-200 hover:border-amber-300 text-slate-600 hover:text-amber-800 inline-flex items-center justify-center text-xs transition cursor-pointer"
                >
                  ✏️
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- STATE KOSONG + PINTAS TAMBAH PRODUK BARU -->
      <div
        v-if="filteredProducts.length === 0"
        class="border-2 border-dashed border-slate-300 rounded-2xl p-8 flex flex-col items-center justify-center text-center text-slate-500 bg-white min-h-[240px]"
      >
        <span class="text-4xl mb-2">{{ searchQuery ? '🔍' : '📦' }}</span>
        <h4 class="text-sm font-bold text-slate-800 uppercase">
          {{ searchQuery ? `Produk "${searchQuery}" Belum Terdaftar` : 'Belum Ada Produk di Kategori Ini' }}
        </h4>
        <p class="text-xs text-slate-500 mt-1 max-w-sm">
          {{ searchQuery ? 'Anda dapat langsung mendaftarkan barang baru ini atau mengatur ulang filter pencarian.' : 'Klik tombol Tambah Barang di bawah untuk memasukkan produk ke kategori ini.' }}
        </p>
        <div class="flex flex-wrap items-center justify-center gap-2 mt-4">
          <button
            type="button"
            @click="emit('addProduct', searchQuery)"
            class="min-h-[44px] px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-black uppercase transition cursor-pointer flex items-center gap-1.5 shadow-xs"
          >
            <span>➕</span>
            <span>Daftarkan Produk Baru</span>
          </button>
          <button
            type="button"
            @click="clearSearch(); selectedCategory = 'Semua'"
            class="min-h-[44px] px-4 py-2 bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200 rounded-xl text-xs font-bold uppercase transition cursor-pointer flex items-center gap-1.5"
          >
            <span>🔄</span>
            <span>Reset Filter</span>
          </button>
        </div>
      </div>
    </main>

    <!-- FOOTER STATUS -->
    <footer class="bg-white border-t border-slate-200 px-4 py-2 shrink-0 flex items-center justify-between text-xs text-slate-600 shadow-xs z-10">
      <div class="flex items-center gap-2">
        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
        <span class="text-[11px] font-medium text-slate-700">
          Sentuh {{ currentStyle === 'table' ? 'baris produk' : 'kartu' }} untuk masuk keranjang<template v-if="canEdit"> · Sentuh ✏️ untuk edit</template>
        </span>
      </div>

      <div class="flex items-center gap-2 text-[10px] font-mono">
        <span class="bg-slate-100 border border-slate-200 text-slate-600 px-2 py-0.5 rounded font-bold">
          {{ filteredProducts.length }} / {{ products.length }} produk
        </span>
        <span class="text-slate-400 hidden sm:inline">
          Panel kasir kanan 35% tetap aktif
        </span>
      </div>
    </footer>

  </div>
</template>
