# ANALISIS TEKNIS & STRATEGI PERBAIKAN: VARIASI 2 (HYBRID MULTI-UNIT SEGMENTED) & TEARDOWN LIFECYCLE

**Penyusun**: Explorer Fix 2 (`explorer`, `investigator`)  
**ID Konversasi**: `1eda7e8e-74c3-4df5-9aa1-4a213a60782d`  
**Penerima**: Orchestrator (`b3ae615d-288f-4a24-ae28-c8f544a06ead`) / Worker  
**Komponen Target**: `resources/js/Components/POS/ProductGalleryMockup.vue`  
**Status**: Rekomendasi Siap Diterapkan (Read-Only Analysis)  
**Tanggal**: 2026-11-12  

---

## 1. Ringkasan Eksekutif (Executive Summary)

Pada audit adversarial Iterasi 1, Reviewer 2 menerbitkan hasil **`REQUEST_CHANGES`** dengan 2 temuan utama, di mana Temuan 2 dan Temuan 3 secara spesifik menargetkan:
1. **Limitasi Multi-Satuan pada Variasi 2 (Temuan 2 - Major)**: Pemotongan daftar satuan secara sepihak menggunakan `.slice(0, 2)` pada baris 476 tanpa ada penanda visual bahwa produk memiliki tingkatan satuan ke-3 atau ke-4 (contoh komoditas sembako: Sachet → Renceng → Dus; Rokok: Bungkus → Slop → Bal).
2. **Potensi Memory Leak Minor (Temuan 3 - Minor)**: Timer visual feedback kartu `feedbackTimeout` (baris 30–33, 136–140) tidak dibersihkan saat komponen di-unmount dari DOM.

Dokumen ini menyajikan analisis mendalam mengenai anatomi grid tablet Android landscape (~1097x686), perbandingan opsi penanganan multi-satuan tanpa merusak konsistensi tinggi kartu (*card height preservation*), serta spesifikasi kode pengganti (*drop-in replacement code*) yang presisi untuk diterapkan oleh tim implementasi (Worker).

---

## 2. Analisis Kondisi Saat Ini (Current Implementation Breakdown)

### 2.1 Inspeksi Kode Variasi 2 (`ProductGalleryMockup.vue` Baris 467–501)

Kode saat ini pada bagian body aksi satuan Variasi 2 adalah:

```html
<!-- Body Aksi: SEGMENTED INLINE UNIT PILLS (Tombol Langsung Satuan) -->
<div class="mt-3 pt-2 border-t border-slate-100">
  <!-- Jika Produk Memiliki Lebih dari 1 Satuan -->
  <div v-if="getUnits(p).length > 1" class="flex flex-col gap-1.5">
    <div class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">
      Pilih Satuan Langsung:
    </div>
    <div class="grid grid-cols-2 gap-1.5">
      <button 
        v-for="(u, uIdx) in getUnits(p).slice(0, 2)" 
        :key="uIdx"
        type="button"
        @click="handleAddToCart(p, uIdx)"
        :class="[
          'min-h-[44px] px-2 py-1.5 rounded-xl border flex flex-col justify-center items-start text-left transition cursor-pointer',
          justAddedProductId === p.id && justAddedUnitIndex === uIdx
            ? 'bg-emerald-600 text-white border-emerald-700 ring-2 ring-emerald-500/30'
            : 'bg-slate-50 hover:bg-blue-600 hover:text-white border-slate-200 text-slate-800'
        ]"
      >
        <div class="flex items-center justify-between w-full">
          <span class="text-[10px] font-extrabold uppercase truncate">
            {{ u.name }}
          </span>
          <span v-if="uIdx > 0" class="text-[8px] bg-purple-100 text-purple-900 font-black px-1 rounded uppercase">
            Grosir
          </span>
        </div>
        <span class="text-xs font-mono font-black mt-0.5">
          {{ formatRupiah(u.price) }}
        </span>
      </button>
    </div>
  </div>
```

### 2.2 Dampak dan Akar Masalah (Root Cause & Adverse Impact)

1. **Pemotongan Sepihak (*Hard Truncation*)**:
   Ekspresi `getUnits(p).slice(0, 2)` hanya mengambil 2 indeks pertama (`uIdx = 0` dan `uIdx = 1`). Jika suatu komoditas memiliki 3 atau lebih satuan (contoh: *Kopi Kapal Api*: Sachet @ Rp 2.000, Renceng @ Rp 18.500, Dus @ Rp 140.000), satuan Dus terpotong total. Kasir di kasir cepat tidak mengetahui bahwa toko menjual kemasan Dus, dan tidak ada cara untuk menambahkannya langsung dari kartu.
2. **Ketiadaan Indikator Informasi**:
   Teks label di atas grid hanya bertuliskan statis `"Pilih Satuan Langsung:"`. Kasir tidak mendapat sinyal visual apa pun mengenai keberadaan satuan lain.

---

## 3. Analisis Tata Letak Layar Tablet & Opsi Solusi

### 3.1 Karakteristik Grid Variasi 2 pada Tablet Landscape (~1097x686 px)
- Variasi 2 menggunakan grid 3-kolom: `grid grid-cols-3 gap-3 auto-rows-max` di dalam container panel galeri lebar 65% (~713 px).
- Lebar masing-masing kartu adalah sekitar **225–230 px**.
- Tinggi dasar kartu saat ini adalah **`min-h-[148px]`**.
- Mengapa perancang awal menggunakan 2 tombol satuan bersisian (`grid-cols-2`)?
  - Pada lebar 225 px, membagi 2 kolom menghasilkan tombol satuan selebar ~106 px dengan tinggi sentuh `min-h-[44px]`. Ini adalah ukuran optimal untuk kenyamanan ibu jari kasir tanpa teks terpotong drastis.

### 3.2 Evaluasi Komparatif Opsi Solusi

| Kriteria Evaluasi | Opsi A: Visual Badge Penanda di Header Satuan (Rekomendasi) | Opsi B: Grid Fleksibel Multi-Row (Tampilkan Semua Satuan) | Opsi C: Tombol Siklus Satuan / Dropdown Popover |
|---|---|---|---|
| **Dampak Tinggi Kartu** | **0 piksel (Tinggi kartu tetap seragam)** | **Membengkak +48px hingga +96px** (Membuat grid compang-camping / *ragged rows*) | 0 piksel (Namun popover dapat terpotong boundary overflow) |
| **Ergonomi Sentuh (≥44x44px)** | **Lolos 100%** (2 tombol utama tetap `min-h-[44px]`, tombol edit tetap 44x44px) | Rawan gagal jika tombol dikecilkan agar muat | Lolos pada tombol, rentan mis-tap pada menu popup |
| **Kepatuhan AC & Reviewer 2** | **100% Sesuai Rekomendasi Reviewer 2 & AC R2** | Melanggar kestabilan tinggi kartu | Memperkenalkan kompleksitas interaksi ganda |
| **Kecepatan Transaksi Kasir** | **Sangat Tinggi (1-tap langsung masuk keranjang)** | Menurun (Terlalu banyak pilihan tombol kecil) | Menurun (Perlu 2-tap: buka popover lalu pilih) |
| **Risiko Regresi Kode** | **Hampir Nol (Non-breaking)** | Tinggi terhadap flex/grid alignment | Sedang (Perlu state popover per item) |

### 3.3 Keputusan Arsitektur: Opsi A (Header-Aligned Visual Badge)

Dengan memanfaatkan baris judul `"Pilih Satuan Langsung:"`, kita dapat mengubahnya menjadi kontainer flex `flex items-center justify-between`. Di sisi kanan baris tersebut, kita menempatkan lencana status:
```html
<span 
  v-if="getUnits(p).length > 2" 
  class="text-[8px] bg-purple-100 text-purple-800 border border-purple-200 font-extrabold px-1.5 py-0.5 rounded uppercase tracking-tight cursor-pointer hover:bg-purple-200 transition"
  :title="`Tersedia ${getUnits(p).length} satuan: ${getUnits(p).map(u => u.name).join(', ')}. Klik ubah untuk kelola satuan.`"
  @click.stop="handleEdit(p)"
>
  +{{ getUnits(p).length - 2 }} Satuan Lain
</span>
```

**Keuntungan Opsi A**:
1. **Zero Layout Shift / Card Height Intact**: Karena baris judul sudah ada, penambahan badge di sisi kanan (dalam flex row yang sama) tidak menambah 1 piksel pun tinggi kartu. Kartu produk 1 satuan, 2 satuan, maupun 4 satuan memiliki ketinggian yang sangat seimbang dan rapi dalam baris grid.
2. **Keterbacaan & Kontras Visual Tinggi**: Menggunakan skema warna indigo/purple (`bg-purple-100 text-purple-800 border-purple-200`) yang selaras dengan badge grosir dan memenuhi rasio kontras WCAG AAA (>7:1).
3. **Interaktivitas Tambahan Tanpa Risiko**: 
   - Dilengkapi atribut `:title` yang menampilkan daftar lengkap nama satuan saat kursor melintas (*hover*).
   - Menambahkan event `@click.stop="handleEdit(p)"` sehingga kasir yang ingin menginspeksi atau mengedit satuan ke-3/ke-4 dapat langsung mengetuk lencana tersebut untuk membuka modal edit produk bawaan.

---

## 4. Analisis Teardown Timer & Pencegahan Memory Leak

### 4.1 Lokasi Masalah
Pada baris 136–140:
```javascript
function handleAddToCart(product, unitIndex = 0) {
  emit('addToCart', product, unitIndex);

  // Berikan feedback visual kartu berkedip sejenak
  justAddedProductId.value = product.id;
  justAddedUnitIndex.value = unitIndex;
  if (feedbackTimeout) clearTimeout(feedbackTimeout);
  feedbackTimeout = setTimeout(() => {
    justAddedProductId.value = null;
    justAddedUnitIndex.value = null;
  }, 600);
}
```

### 4.2 Analisis Siklus Hidup (Lifecycle Assessment)
- Saat ini `feedbackTimeout` dideklarasikan pada lingkup modul komponen (baris 32: `let feedbackTimeout = null;`).
- Jika kasir menekan tombol tutup (`showGalleryModal = false`) atau berpindah rute segera setelah menyentuh produk (< 600ms):
  - Komponen `ProductGalleryMockup` di-unmount dari pohon DOM.
  - Namun handler timer pada Node.js/Browser Event Loop masih hidup dan memegang referensi reaktif terhadap `justAddedProductId` dan `justAddedUnitIndex`.
  - Ketika 600ms berakhir, callback mencoba mengubah `.value` pada objek ref yang komponennya sudah unmounted.
- Meskipun dalam aplikasi desktop/web sederhana dampaknya kecil, pada terminal POS kasir yang berjalan terus-menerus berhari-hari (kios kasir 24 jam), penumpukan timer tak terbersihkan dapat memicu peringatan konsol Vue dan degradasi memori bertahap.

### 4.3 Strategi Teardown yang Benar
1. Impor `onUnmounted` dari `'vue'`:
   ```javascript
   import { ref, computed, onUnmounted } from 'vue';
   ```
2. Pasang hook `onUnmounted` di dalam `<script setup>`:
   ```javascript
   onUnmounted(() => {
     if (feedbackTimeout) {
       clearTimeout(feedbackTimeout);
       feedbackTimeout = null;
     }
   });
   ```

---

## 5. Rencana Perubahan Kode Konkret (Exact Replacement Specifications)

### 5.1 Perubahan 1: Impor & Lifecycle Hook `onUnmounted`
**File Target**: `resources/js/Components/POS/ProductGalleryMockup.vue`

#### Bagian Impor (Baris 2)
```diff
--- a/resources/js/Components/POS/ProductGalleryMockup.vue
+++ b/resources/js/Components/POS/ProductGalleryMockup.vue
@@ -1,4 +1,4 @@
 <script setup>
-import { ref, computed } from 'vue';
+import { ref, computed, onUnmounted } from 'vue';
```

#### Bagian Lifecycle Teardown (Setelah baris 148 / fungsi `clearSearch`)
```diff
--- a/resources/js/Components/POS/ProductGalleryMockup.vue
+++ b/resources/js/Components/POS/ProductGalleryMockup.vue
@@ -147,4 +147,11 @@ function clearSearch() {
   searchQuery.value = '';
 }
+
+onUnmounted(() => {
+  if (feedbackTimeout) {
+    clearTimeout(feedbackTimeout);
+    feedbackTimeout = null;
+  }
+});
 </script>
```

---

### 5.2 Perubahan 2: Header Satuan dengan Badge `+X Satuan Lain` pada Variasi 2
**File Target**: `resources/js/Components/POS/ProductGalleryMockup.vue`

#### Bagian Template Variasi 2 (Baris 468–476)
```diff
--- a/resources/js/Components/POS/ProductGalleryMockup.vue
+++ b/resources/js/Components/POS/ProductGalleryMockup.vue
@@ -468,9 +468,17 @@
           <!-- Body Aksi: SEGMENTED INLINE UNIT PILLS (Tombol Langsung Satuan) -->
           <div class="mt-3 pt-2 border-t border-slate-100">
             <!-- Jika Produk Memiliki Lebih dari 1 Satuan -->
             <div v-if="getUnits(p).length > 1" class="flex flex-col gap-1.5">
-              <div class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">
-                Pilih Satuan Langsung:
-              </div>
+              <div class="flex items-center justify-between">
+                <span class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">
+                  Pilih Satuan Langsung:
+                </span>
+                <span 
+                  v-if="getUnits(p).length > 2" 
+                  class="text-[8px] bg-purple-100 text-purple-800 border border-purple-200 font-extrabold px-1.5 py-0.5 rounded uppercase tracking-tight cursor-pointer hover:bg-purple-200 transition"
+                  :title="`Tersedia ${getUnits(p).length} satuan: ${getUnits(p).map(u => u.name).join(', ')}. Klik ubah untuk kelola satuan.`"
+                  @click.stop="handleEdit(p)"
+                >
+                  +{{ getUnits(p).length - 2 }} Satuan Lain
+                </span>
+              </div>
               <div class="grid grid-cols-2 gap-1.5">
```

---

## 6. Matriks Verifikasi & Kepatuhan Standar (Verification Matrix)

| Aspek Pengujian | Metode Verifikasi | Hasil yang Diharapkan |
|---|---|---|
| **Stabilitas Tinggi Kartu Variasi 2** | Render produk dengan 1, 2, dan 3 satuan | Tinggi kartu tetap seragam (`min-h-[148px]`), grid 3-kolom tidak melompat (*zero layout shift*). |
| **Keterbacaan Indikator Satuan** | Produk dengan 3 satuan (contoh: Sachet, Renceng, Dus) | Tampil badge `+1 SATUAN LAIN` berwarna ungu kontras di kanan atas area satuan. |
| **Aksi Sentuh Langsung 1-Tap** | Ketuk tombol satuan 1 dan satuan 2 | Tetap memicu `handleAddToCart(p, 0)` dan `handleAddToCart(p, 1)` secara instan dengan touch target `min-h-[44px]`. |
| **Akses Satuan Tambahan** | Ketuk badge `+1 Satuan Lain` atau tombol ✏️ | Memicu `handleEdit(p)` tanpa menambah item ke keranjang (`@click.stop`), membuka modal manajemen produk di mana seluruh satuan dapat diakses. |
| **Teardown Lifecycle** | Ketuk tombol beli lalu segera tutup modal | Tidak ada peringatan memory leak atau lingering timer pada konsol browser. |
| **Vite Production Build** | Jalankan `npm run build` | Lolos kompilasi 100% tanpa error TypeScript / Vue SFC parsing. |

---

## 7. Rekomendasi Langkah Selanjutnya

1. Worker menerapkan perubahan pada `resources/js/Components/POS/ProductGalleryMockup.vue` sesuai spesifikasi di atas bersamaan dengan perbaikan Variasi 3 (dari Explorer 1).
2. Lakukan build test mandiri dengan `npm run build`.
3. Ajukan kembali ke Reviewer 2 untuk memperoleh verdict final **`APPROVE`**.