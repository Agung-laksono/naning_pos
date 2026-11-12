# Laporan Survei Kode Sumber & Arsitektur POS (Survey 1)
**Objektif**: Investigasi mendalam basis kode POS, arsitektur modal galeri produk (`showGalleryModal`), struktur data multi-satuan, ergonomi tablet Android landscape (~1097x686), dan integrasi komponen mockup Vue 3 + Tailwind CSS.
**Tanggal**: 2026-11-12
**Lokasi Kode**: `resources/js/Pages/POS/Index.vue`
**Status Build**: Terverifikasi (`npm run build` sukses, Vite v8.3.6, waktu kompilasi 4.36s)

---

## 1. Ringkasan Eksekutif (Executive Summary)

Aplikasi kasir TPKMart POS dibangun menggunakan Laravel 12, Inertia.js v2, Vue 3 (Composition API `<script setup>`), dan Tailwind CSS v3. Aplikasi ini dirancang khusus untuk operasional kasir cepat minimarket/kelontong pada tablet Android layar sentuh (orientasi landscape ~1097x686 px) dan dilengkapi integrasi Capacitor (Android hybrid, barcode scanner MLKit/kamera, dan database lokal Dexie untuk offline capability).

Modal Galeri Produk (`showGalleryModal`) saat ini diimplementasikan sebagai **Android Side-Sheet di sisi kiri layar dengan lebar 65%** (~713px), membiarkan sisi kanan 35% (~384px) tetap menampilkan panel kasir, keranjang ringkas, dan tombol pembayaran cepat. Walaupun tata letak dasar sudah fungsional, hasil audit ergonomi dan perbandingan dengan sistem POS ritel cepat terkemuka (Moka, Pawoon, Olsera) menemukan sejumlah batasan kritis pada kartu produk saat ini:
1. **Target Sentuh Kurang Standar**: Tombol edit/pengaturan harga hanya berukuran `w-5 h-5` (20x20px), jauh di bawah standar ergonomi sentuh W3C/Android Material Design (minimal 44–48px), menyebabkan risiko salah sentuh yang tinggi.
2. **Seleksi Multi-Satuan Kaku**: Kartu hanya memicu `addToCart(p, 0)` (selalu satuan dasar/index 0). Kasir tidak dapat memilih satuan Dus/Renceng/Pack langsung dari kartu galeri.
3. **Visibilitas Visual & Stok Terbatas**: Thumbnail gambar/ikon sangat kecil (`w-8 h-8` / 32x32px), dan informasi sisa stok produk (`stock`) sama sekali belum ditampilkan pada kartu produk.

Laporan ini menyajikan pemetaan arsitektur lengkap, kontrak data, benchmark POS, dan cetak biru teknis untuk perancangan dokumen referensi dan komponen mockup Vue 3 interaktif.

---

## 2. Arsitektur Viewport & Ergonomi Layar Tablet (~1097x686)

### 2.1 Pembagian Layout Utama POS (`resources/js/Pages/POS/Index.vue`)
Layar kasir menggunakan container layar penuh edge-to-edge:
```html
<div class="flex flex-row w-screen h-screen max-w-full max-h-screen overflow-hidden text-slate-800 bg-white select-none relative font-sans shadow-none">
```
- **Panel Kiri (65% width / ~713px)**:
  - Header (Toko, Barcode/Text Search, Shortcut Scanner Kamera, Tombol Galeri `F2`).
  - Body: Tabel keranjang belanjaan dengan stepper kuantitas touch `+` / `-`, selector multi-satuan `cycleItemUnit(index)`.
  - Footer: LED-style Total Tagihan berukuran besar dengan latar gelap kontras tinggi (`bg-slate-900` / `bg-black/60`).
- **Panel Kanan (35% width / ~384px)**:
  - Header: Identitas kasir aktif, jam digital realtime (dengan detik), status antrean & shift.
  - Body: Selector Pelanggan/Member (`Pelanggan Umum` default, limit kasbon, status hutang).
  - Footer: Panel Pembayaran Cepat (Tombol Tunai, QRIS Dinamis, Kasbon Member).

### 2.2 Mekanisme Modal Galeri Produk (`showGalleryModal`)
- **Deklarasi State**: Baris 2207: `const showGalleryModal = ref(false);`
- **Pemicu Buka (Open Triggers)**:
  1. Tombol Header Kiri (Baris 3488): `<button v-show="!isSearchActive" @click="showGalleryModal = true" ...><span>Galeri</span> <span ...>F2</span></button>`
  2. Shortcut Keyboard: Tombol `F2` (Baris 351–355):
     ```javascript
     if (e.key === 'F2') {
       e.preventDefault();
       showGalleryModal.value = !showGalleryModal.value;
       return;
     }
     ```
  3. Tombol State Keranjang Kosong (Baris 3573): `<button @click="showGalleryModal = true" ...><span>📂</span> Buka Galeri</button>`
- **Pemicu Tutup (Close Triggers)**:
  1. Tombol `Escape` (Baris 416): `if (showGalleryModal.value) { showGalleryModal.value = false; return; }`
  2. Klik Backdrop Overlay (Baris 3928): `<div class="absolute inset-0 bg-black/40 backdrop-blur-2xs" @click="showGalleryModal = false"></div>`
  3. Tombol Silang Header (Baris 3967): `<button @click="showGalleryModal = false" class="w-8 h-8 rounded-lg ...">✕</button>`
- **Posisi & Geometri Side-Sheet**:
  ```html
  <div v-if="showGalleryModal" class="fixed inset-0 z-50 flex items-stretch justify-start">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-2xs" @click="showGalleryModal = false"></div>
    <div class="relative w-[65%] h-full bg-white border-r border-slate-200 shadow-2xl flex flex-col overflow-hidden z-10">
      <!-- Header Galeri (px-4 py-2.5, tinggi ~56px) -->
      <!-- Kategori Filter Chips (px-4 py-2, tinggi ~48px) -->
      <!-- Grid Katalog Produk (p-3 flex-1 overflow-y-auto) -->
      <!-- Footer Info Galeri (px-4 py-2.5, tinggi ~42px) -->
    </div>
  </div>
  ```
  *Keunggulan Arsitektur*: Sisi kanan 35% tidak tertutup oleh modal, sehingga kasir dapat melihat keranjang dan total belanja bertambah secara langsung saat menyentuh produk di galeri.

---

## 3. Kontrak Data & Struktur Model Produk

Data produk diteruskan dari backend Laravel (`PosController@index`) melalui props Inertia:
```php
$products = Product::with(['units', 'category'])->latest()->get();
$categories = Category::all();
```

### 3.1 Skema Data Produk (`Product`)
File model: `app/Models/Product.php`
| Field | Tipe Data | Keterangan | Contoh Nilai |
|---|---|---|---|
| `id` | Integer | ID Unik Produk | `12` |
| `category_id` | Integer / Null | Foreign key ke tabel `categories` | `2` |
| `barcode` | String | Barcode EAN-13 / SKU toko | `"899123456705"` |
| `name` | String | Nama lengkap produk | `"Indomie Goreng Spesial"` |
| `icon` | String / Null | Emoji ikon representasi | `"🍜"`, `"🌾"`, `"🥤"` |
| `image` | String / Null | URL / path gambar produk | `"/storage/products/indomie.jpg"` / `null` |
| `base_unit` | String | Nama satuan terkecil | `"Bungkus"`, `"Pcs"`, `"Sachet"` |
| `stock` | Integer | Sisa kuantitas stok fisik toko | `400` |
| `min_stock` | Integer | Batas minimal peringatan stok | `20` |
| `cost_price` | Integer | Harga modal (disembunyikan jika non-admin) | `2700` |
| `is_active` | Boolean | Status produk aktif dijual | `true` |
| `category` | Object / Null | Relasi kategori `{ id, name, icon, image }` | `{ id: 2, name: "Mie & Instan" }` |
| `units` | Array | Daftar relasi satuan multi-satuan | *Lihat tabel 3.2* |

### 3.2 Skema Data Multi-Satuan (`ProductUnit`)
File model: `app/Models/ProductUnit.php`
| Field | Tipe Data | Keterangan | Contoh Nilai |
|---|---|---|---|
| `id` | Integer | ID Unik Satuan | `45` |
| `product_id` | Integer | ID Produk pemilik | `12` |
| `name` | String | Nama satuan penjualan | `"Bungkus"`, `"Dus (40 bks)"`, `"Renceng (10 sch)"` |
| `price` | Integer | Harga jual untuk satuan ini | `3100` (eceran), `118000` (grosir/dus) |
| `ratio` | Integer | Rasio konversi ke satuan dasar | `1` (dasar), `40` (dus), `10` (renceng) |
| `is_base` | Boolean | Penanda apakah ini satuan dasar | `true` / `false` |

### 3.3 Contoh Nyata Data Multi-Satuan dari Database (`DummySampleSeeder.php`)
1. **Indomie Goreng Spesial**:
   - Satuan 1 (Base): `Bungkus` @ Rp 3.100 (ratio 1)
   - Satuan 2: `Dus (40 bks)` @ Rp 118.000 (ratio 40)
2. **Kopi Kapal Api Special Mix**:
   - Satuan 1 (Base): `Sachet` @ Rp 2.000 (ratio 1)
   - Satuan 2: `Renceng (10 sch)` @ Rp 18.500 (ratio 10)
3. **Minyak Goreng Bimoli 2L**:
   - Satuan 1 (Base): `Pcs` @ Rp 19.500 (ratio 1)
   - Satuan 2: `Dus (6 pcs)` @ Rp 114.000 (ratio 6)
4. **Rokok Sampoerna Mild 16**:
   - Satuan 1 (Base): `Bungkus` @ Rp 34.500 (ratio 1)
   - Satuan 2: `Slop (10 bks)` @ Rp 340.000 (ratio 10)

### 3.4 Interaksi Penambahan ke Keranjang (`addToCart`)
Fungsi `addToCart` pada baris 117–141 menerima 3 argumen:
```javascript
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
```
*Catatan Penting*: Fungsi `addToCart` sudah mendukung pemilihan `unitIndex` secara dinamis. Namun UI galeri produk yang ada saat ini hanya memanggil `addToCart(p, 0)` secara hardcoded.

---

## 4. Audit Mendalam Implementasi Kartu Produk Saat Ini

Kode eksisting kartu produk di dalam galeri (baris 4009–4046):
```html
<button 
  v-for="p in filteredCatalogProducts" 
  :key="p.id" 
  @click="addToCart(p, 0)"
  class="bg-white hover:bg-blue-50/40 active:bg-blue-100/60 border border-slate-200 hover:border-blue-500 rounded-xl p-2.5 text-left flex flex-col justify-between transition cursor-pointer h-[115px] relative group shadow-2xs"
>
  <div class="flex items-start gap-2.5">
    <div class="w-8 h-8 rounded-lg bg-slate-50 border border-slate-200 flex items-center justify-center shrink-0 overflow-hidden">
      <img v-if="p.image" :src="p.image" :alt="p.name" class="w-full h-full object-cover" />
      <span v-else class="text-xl">{{ p.icon || '📦' }}</span>
    </div>
    <div class="truncate flex-1">
      <div class="flex items-center justify-between gap-1">
        <span class="text-[9px] text-blue-600 font-black uppercase tracking-wider block truncate">{{ getProductCategoryName(p) }}</span>
        <div class="flex items-center gap-1">
          <span v-if="p.units && p.units.length > 1" class="text-[8px] bg-purple-50 text-purple-700 border border-purple-200 font-bold px-1.5 py-0.2 rounded-md shrink-0 uppercase">
            {{ p.units.length }} Satuan
          </span>
          <button 
            type="button"
            @click.stop="openEditProductModal(p)"
            title="Edit Produk / Atur Harga / Hapus"
            class="w-5 h-5 rounded-md bg-slate-100 border border-slate-200 hover:bg-amber-100 hover:border-amber-300 text-slate-600 hover:text-amber-800 flex items-center justify-center text-[10px] transition cursor-pointer"
          >
            ✏️
          </button>
        </div>
      </div>
      <div class="text-xs text-slate-900 font-bold line-clamp-2 mt-0.5 leading-snug">{{ p.name }}</div>
    </div>
  </div>
  <div class="mt-2 pt-1 border-t border-slate-100 flex justify-between items-end">
    <span class="text-[10px] text-slate-400 font-bold uppercase">{{ p.units && p.units[0] ? p.units[0].name : 'Pcs' }}</span>
    <span class="text-xs font-mono text-emerald-700 font-black">
      Rp {{ (p.units && p.units[0] ? p.units[0].price : 0).toLocaleString('id-ID') }}
    </span>
  </div>
</button>
```

### 4.1 Analisis Kekurangan & Titik Masalah (Pain Points)
1. **Target Sentuh Tombol Edit Berbahaya (20x20px)**:
   Tombol edit (`w-5 h-5`) berada di pojok kanan atas kartu produk. Pada layar tablet yang dioperasikan dengan jari kasir (lebar jari rata-rata 10–12mm), tombol 20px sangat sulit disentuh secara presisi. Kasir kerap salah menyentuh area kartu utama sehingga produk tidak sengaja masuk ke keranjang belanja bukannya membuka modal edit. Target sentuh wajib diperbesar menjadi minimal 44x44px atau diberikan padding hit-area 44px.
2. **Badge Multi-Satuan Pasif (Hanya Teks Informasi)**:
   Badge `{{ p.units.length }} Satuan` hanya berupa badge ungu pasif (`text-[8px]`). Ketika kasir ingin menjual 1 Dus Indomie, sentuhan pada kartu tetap memasukkan 1 Bungkus. Kasir harus berpindah ke panel keranjang di sisi kanan untuk mengklik tombol `cycleItemUnit` atau membuka form edit. Ini memperlambat kecepatan transaksi minimarket hingga 3x lipat.
3. **Thumbnail Gambar Terlalu Kecil (32x32px)**:
   Ukuran thumbnail `w-8 h-8` membuat foto produk ritel (kemasan snack, botol, sachet) sulit dikenali dari jarak pandang kasir (~40–60 cm dari tablet). Kasir harus mengandalkan membaca teks nama produk baris demi baris.
4. **Tidak Ada Indikator Sisa Stok (`stock`)**:
   Meskipun model database memiliki atribut `stock` dan `min_stock`, kartu galeri saat ini sama sekali tidak menampilkan status persediaan barang. Kasir berisiko menjual barang yang fisiknya sudah habis di rak.
5. **Ukuran Grid & Densitas Tetap**:
   Grid saat ini dipatok `grid-cols-3 gap-2.5` dengan tinggi kartu tetap `h-[115px]`. Di layar ~1097px (lebar sheet ~713px), setiap kolom memiliki lebar ~223px. Untuk minimarket yang memiliki ratusan SKU sering laku, tata letak ini belum memiliki opsi densitas fleksibel (Compact Grid vs Card Visual Grid).

---

## 5. Benchmark Desain Sistem POS Ritel Cepat (Moka, Pawoon, Olsera)

| Karakteristik | Moka POS | Pawoon POS | Olsera POS | Rekomendasi Redesain TPKMart |
|---|---|---|---|---|
| **Pola Grid Tablet** | 3–4 Kolom dengan kartu proporsional, thumbnail persegi di atas atau kiri | Grid adaptif dengan header kategori berwarna | High-density grid minimarket (fokus kecepatan kasir) | **3 Kolom Responsif (~223px)** di dalam side-sheet 65%, dengan densitas padat dan tinggi kartu seimbang (120–145px) |
| **Hierarki Visual** | Gambar produk dominan, nama produk 2 baris, harga kontras tebal | Kategori berkode warna, nama produk tebal, label varian jelas | Barcode & kode barang terlihat, harga bold kontras tinggi | Nama produk 2 baris bold (`text-xs font-bold text-slate-900`), badge kategori warna, harga font-mono hijau kontras tinggi |
| **Penanganan Multi-Satuan** | Dialog pilihan varian muncul seketika jika ada varian | Popover chip varian pada sentuhan kartu | Tombol pintas multi-satuan langsung pada kartu (Pcs / Dus) | **Pills Multi-Satuan Interaktif langsung pada kartu**: Kasir dapat mengetuk chip `Bks Rp 3.100` atau `Dus Rp 118.000` secara terpisah dengan area sentuh 44px! |
| **Ergonomi Sentuh** | Target sentuh tombol minimal 48x48dp | Tombol aksi terpisah dengan margin pengaman | Tombol numpad dan aksi cepat berukuran besar | Kartu utama tap-to-cart luas, tombol edit terisolasi dengan tap-target minimal 44x44px |
| **Status Persediaan (Stok)** | Badge sisa stok numerik di sudut kartu | Dot warna stok (hijau: cukup, oranye: menipis, merah: habis) | Peringatan stok kritis langsung di label harga | **Badge Stok Adaptif**: Dot & angka stok (Hijau jika >10, Amber jika <=10, Merah/Disable jika 0) |

---

## 6. Spesifikasi Sistem Desain & Konfigurasi Frontend

### 6.1 Dependensi & Toolchain
- **Vite**: `vite v8.3.6` dengan `@vitejs/plugin-vue: ^6.0.0` dan `laravel-vite-plugin: ^3.1`
- **Vue**: `vue: ^3.4.0` (Composition API, `<script setup>`)
- **Tailwind CSS**: `tailwindcss: ^3.2.1` dengan `@tailwindcss/forms: ^0.5.3`
- **Font**: Figtree (`font-sans`) untuk teks antarmuka, `font-mono` untuk angka rupiah, harga, barcode, dan kuantitas.
- **Pustaka Ikon**: Proyek tidak menginstal pustaka ikon pihak ketiga (tidak ada `lucide-vue-next` atau `heroicons`). Sistem UI menggunakan **Inline SVG seragam** (`stroke-width="2"` / `2.5"`) dipadukan dengan **Emoji representatif** (`📦`, `🌾`, `🍜`, `🥤`, `🚬`, `🧼`, `🍪`, `➕`, `✏️`, `✕`, `🔍`).

### 6.2 Palet Warna Standar POS
- **Slate (Struktur & Latar)**: `bg-slate-50`, `bg-slate-100`, `border-slate-200`, `text-slate-500`, `text-slate-700`, `text-slate-900`
- **Blue (Brand & Fokus POS)**: `bg-blue-600` (hover: `bg-blue-700`, active: `bg-blue-800`), `bg-blue-50`, `text-blue-600`, `border-blue-200`
- **Emerald (Aksi Belanja & Harga)**: `bg-emerald-600`, `text-emerald-700`, `bg-emerald-50`, `border-emerald-200`, LED text: `text-emerald-400`
- **Amber (Peringatan & Edit)**: `bg-amber-100`, `text-amber-800`, `border-amber-300`, `bg-amber-500`
- **Purple / Violet (Multi-Satuan)**: `bg-purple-50`, `text-purple-700`, `border-purple-200`, `bg-purple-600`
- **Rose / Red (Stok Habis / Hapus)**: `bg-rose-50`, `text-rose-600`, `border-rose-200`

---

## 7. Cetak Biru Komponen Mockup Interaktif (`ProductGalleryMockup.vue`)

Komponen mockup dirancang sebagai komponen Vue 3 mandiri (reusable) yang dapat dipasang di dalam side-sheet galeri atau dijalankan dalam mode demo/pratinjau interaktif tanpa mengganggu logika checkout yang sedang berjalan.

### 7.1 Variasi Alternatif Gaya Kartu yang Direkomendasikan
1. **Variasi 1: Minimarket Fast-Retail Multi-Unit Pill (Sangat Direkomendasikan)**
   - *Karakteristik*: Kartu dengan tombol pill satuan langsung di bagian bawah.
   - *Perilaku*:
     - Jika produk memiliki 1 satuan: Seluruh kartu berfungsi sebagai satu touch-target besar `addToCart(p, 0)`.
     - Jika produk memiliki >1 satuan (misal Bungkus & Dus): Bagian atas kartu memuat info produk & tombol edit (44x44px), sedangkan bagian bawah menampilkan 2 tombol pill terpisah (`[ Bungkus · Rp 3.100 ]` dan `[ Dus · Rp 118.000 ]`). Kasir dapat langsung menyentuh pill yang diinginkan sekali sentuh!
2. **Variasi 2: Modern Visual Retail Card (Gaya Moka / Pawoon)**
   - *Karakteristik*: Gambar/thumbnail lebih besar (`w-12 h-12` / 48x48px) dengan badge stok dinamis di atas gambar (`Stok 40`).
   - *Perilaku*: Menampilkan badge multi-satuan interaktif yang memunculkan sheet mikro pemilihan satuan saat disentuh.
3. **Variasi 3: Ultra-Dense Barcode Card (Gaya Olsera Grosir)**
   - *Karakteristik*: Sangat padat untuk layar tablet dengan menampilkan potongan barcode EAN-13, kode kategori, harga modal vs jual (jika role admin), dan aksi cepat +1/+5.

### 7.2 Kontrak Props & Events Komponen Mockup
```typescript
interface ProductGalleryMockupProps {
  products: Product[];
  categories: Category[];
  selectedCategory?: string;
  searchQuery?: string;
  activeCardStyle?: 'fast-retail-pills' | 'modern-visual' | 'ultra-dense';
}

interface ProductGalleryMockupEmits {
  (e: 'add-to-cart', payload: { product: Product; unitIndex: number; qty: number }): void;
  (e: 'edit-product', product: Product): void;
  (e: 'close-gallery'): void;
}
```

---

## 8. Titik Integrasi pada Basis Kode

| Titik Integrasi | Lokasi Berkas | Baris Saat Ini | Dampak / Rencana |
|---|---|---|---|
| Pemicu Galeri | `resources/js/Pages/POS/Index.vue` | 3488, 3573, 353 | Tetap menggunakan `showGalleryModal = true` dan shortcut keyboard `F2` |
| Side-Sheet Galeri | `resources/js/Pages/POS/Index.vue` | 3926–4069 | Komponen mockup baru dapat diintegrasikan langsung menggantikan atau melengkapi blok grid baris 3995–4058 |
| Pemanggilan `addToCart` | `resources/js/Pages/POS/Index.vue` | 117–141, 4012 | Memanfaatkan parameter `unitIndex` yang sudah ada di `addToCart(product, unitIndex)` |
| Pemanggilan `openEditProductModal` | `resources/js/Pages/POS/Index.vue` | 2011–2036, 4029 | Diteruskan sebagai event emit `@edit-product="openEditProductModal"` dengan target sentuh aman |

---

## 9. Kesimpulan & Rekomendasi Langkah Berikutnya

Arsitektur saat ini sangat kokoh dan kompatibel dengan standar tablet Android hybrid. Modifikasi kartu galeri produk tidak memerlukan perubahan skema database maupun modifikasi rute backend, karena kontrak data `Product`, `ProductUnit`, dan `addToCart` sudah menyediakan seluruh fungsionalitas multi-satuan yang dibutuhkan.

Langkah berikutnya adalah:
1. Menyusun Dokumen Referensi Desain POS Ritel Cepat komprehensif dalam Bahasa Indonesia (Moka, Pawoon, Olsera).
2. Membangun komponen mockup interaktif Vue 3 + Tailwind CSS (`ProductGalleryMockup.vue`) dengan 3 alternatif variasi kartu yang mematuhi target sentuh tablet >= 44x44px.
3. Memastikan `npm run build` tetap lulus 100% tanpa kesalahan kompilasi.
