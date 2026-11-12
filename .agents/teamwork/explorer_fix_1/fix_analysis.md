# ANALISIS STRATEGI PERBAIKAN: VARIASI 3 (COMPACT HIGH-DENSITY) & DEFECT AUDIT REVIEWER 2

**Peneliti**: Explorer Fix 1 (`explorer`, `investigation`, `synthesis`)  
**ID Konversasi**: `8d022dec-312c-47aa-9332-f2016639a350`  
**Penerima**: Orchestrator (`b3ae615d-288f-4a24-ae28-c8f544a06ead`)  
**Target File**: `resources/js/Components/POS/ProductGalleryMockup.vue` (baris 535–584)  
**Tanggal**: 2026-11-12  

---

## 1. Ringkasan Eksekutif (Executive Summary)

Reviewer 2 menerbitkan vonis **`REQUEST_CHANGES`** pada Iterasi 1 karena kartu **Variasi 3 (Compact High-Density)** di `ProductGalleryMockup.vue` tidak memenuhi Acceptance Criteria R2:
> *"Setiap variasi kartu memuat gambar/thumbnail produk, nama produk, badge kategori, indikator multi-satuan, harga kontras tinggi, dan tombol aksi edit."*

Audit mengidentifikasi 3 defisiensi spesifik pada Variasi 3:
1. **Thumbnail/Avatar Produk Hilang**: Tidak ada tag `<img>` maupun fallback icon `<span>{{ p.icon || '📦' }}</span>`.
2. **Badge Kategori Tereliminasi**: Kode `{{ p.barcode || getProductCategoryName(p) }}` menyebabkan nama kategori tidak pernah muncul pada produk yang memiliki barcode.
3. **Indikator Multi-Satuan Hilang**: Produk dengan >1 satuan (misal Pcs dan Dus) tidak menampilkan badge penanda jumlah satuan, sehingga kasir tidak mengetahui adanya opsi kemasan grosir.

Dokumen ini menyajikan analisis geometris, ergonomi touch target, dan perumusan kode pengganti yang presisi tanpa mengorbankan kepadatan grid 4-kolom maupun merusak area sentuh minimal 44x44px pada tombol edit.

---

## 2. Analisis Geometri & Tata Letak Layar Tablet (~1097x686 px)

Berdasarkan pengujian matematis pada `tests/Unit/ErgonomicsAndContrastVerification.js`:
- **Lebar Layar**: 1097 px
- **Lebar Side-Sheet Katalog (65%)**: 713.05 px
- **Lebar Internal Katalog (`p-3.5` / -28px padding)**: 685.05 px
- **Grid 4 Kolom (Variasi 3)**:
  - Gap antar kartu: `gap-2.5` = 10 px (3 sela = 30 px)
  - Lebar per kartu: `(685.05 - 30) / 4` = **163.76 px**
  - Padding internal kartu: `p-2.5` (10 px kiri, 10 px kanan)
  - Lebar area konten dalam: `163.76 - 20` = **143.76 px**

### Alokasi Ruang Baris 1 (Header Kartu Variasi 3)
Dalam ruang horizontal 143.76 px, elemen-elemen didistribusikan secara efisien:
1. **Avatar Mini 28x28px (`w-7 h-7`)**:
   - `shrink-0` selebar 28 px.
   - Sesuai dengan spesifikasi `docs/POS_GALLERY_DESIGN_REFERENCE.md` baris 282: *"Menghemat ruang dengan avatar mini 28 x 28 px"*.
   - Render `<img>` jika `p.image` ada, atau fallback `<span>{{ p.icon || '📦' }}</span>` dengan ukuran `text-sm` (~14px) agar proporsional di dalam kotak 28x28px.
2. **Gap Horizontal**: `gap-1.5` = 6 px.
3. **Tombol Edit Terisolasi (Target Sentuh 44x44px)**:
   - Diberikan class `min-w-[44px] min-h-[44px]` dengan margin negatif `-mr-2 -mt-2` (-8px, -8px).
   - Margin negatif memungkinkan area sentuh 44x44px menempel ke sudut kanan atas kartu tanpa menggeser ruang teks horizontal. Lebar efektif yang ditempati pada flex flow adalah ~36 px.
4. **Kontainer Teks Tengah (`flex-1 min-w-0`)**:
   - Lebar tersedia: `143.76 - 28 - 6 - 36` = **~73.76 px**.
   - **Badge Kategori Eksplisit**: `text-[8px] text-blue-700 bg-blue-50 border border-blue-200 font-extrabold uppercase px-1 py-0.5 rounded truncate block max-w-[72px]`.
   - **Kode Barcode / SKU**: `text-[8px] font-mono text-slate-400 block truncate`.
   - Keduanya disusun vertikal (`block`) sehingga nama kategori selalu tampil dan kode barcode tetap terbaca.

### Alokasi Ruang Baris 2 (Nama & Multi-Satuan)
- **Nama Produk**: `text-[11px] text-slate-900 font-bold line-clamp-2 leading-tight`.
- **Badge Multi-Satuan**:
  - Ditampilkan jika `getUnits(p).length > 1`.
  - Teks: `{{ getUnits(p).length }} Satuan`.
  - Class: `inline-block mt-0.5 text-[8px] bg-purple-100 text-purple-800 border border-purple-200 font-extrabold px-1 py-0.5 rounded uppercase`.
  - Lebar badge "2 SATUAN" dengan `text-[8px]` hanya ~45 px, sangat aman di dalam kontainer 143.76 px.

### Alokasi Ruang Baris 3 (Harga & Satuan)
- Satuan dasar di kiri: `text-[10px] text-slate-700 font-extrabold uppercase` (`{{ getUnits(p)[0].name }}`).
- Harga kontras tinggi di kanan: `text-xs font-mono text-emerald-800 font-black` (`{{ formatRupiah(getUnits(p)[0].price) }}`).
- Rasio kontras teks harga terhadap latar putih: **7.68:1** (Lolos WCAG AAA).

---

## 3. Catatan Teknis Tailwind CSS: Koreksi Utility Class

Reviewer 2 merekomendasikan `py-0.2` pada usulan snippetnya. Namun demikian, investigasi menemukan:
- Dalam skala default Tailwind CSS v3, `py-0.2` **tidak terdefinisi** (hanya ada `py-0`, `py-0.5`, `py-1`, dll.).
- Menggunakan `py-0.2` dapat diabaikan oleh Tailwind compiler.
- **Rekomendasi yang benar dan valid**: Gunakan `py-0.5` (2px vertical padding) atau `leading-none py-0.5`, sama seperti yang digunakan pada Variasi 1 (`px-2 py-0.5`).

---

## 4. Spesifikasi Perubahan Kode yang Direkomendasikan (Exact Proposed Code)

### Target File: `resources/js/Components/POS/ProductGalleryMockup.vue`

#### A. Kode Eksisting (Baris 550–573 saat ini):
```html
          <!-- Baris 1: Barcode / Kategori & Tombol Edit -->
          <div class="flex items-center justify-between gap-1">
            <span class="text-[9px] font-mono font-bold text-slate-500 truncate max-w-[100px]">
              {{ p.barcode || getProductCategoryName(p) }}
            </span>

            <!-- Tombol Edit Terpisah (Touch Target 44x44px via padded wrapper) -->
            <button 
              type="button"
              @click.stop="handleEdit(p)"
              title="Edit Produk"
              class="min-w-[44px] min-h-[44px] -mr-2 -mt-2 rounded-lg text-slate-400 hover:text-amber-700 hover:bg-amber-100 flex items-center justify-center text-xs transition cursor-pointer"
            >
              ✏️
            </button>
          </div>

          <!-- Baris 2: Nama Produk Ringkas -->
          <div class="my-1">
            <div class="text-[11px] text-slate-900 font-bold line-clamp-2 leading-tight">
              {{ p.name }}
            </div>
          </div>
```

#### B. Kode Usulan Pengganti:
```html
          <!-- Baris 1: Avatar Mini 28px, Kategori & Barcode, & Tombol Edit 44px -->
          <div class="flex items-start justify-between gap-1.5">
            <div class="flex items-center gap-1.5 min-w-0 flex-1">
              <!-- Thumbnail Mini 28x28px (Avatar Gambar atau Emoji Fallback) -->
              <div class="w-7 h-7 rounded-lg bg-slate-50 border border-slate-200 flex items-center justify-center shrink-0 overflow-hidden shadow-2xs">
                <img v-if="p.image" :src="p.image" :alt="p.name" class="w-full h-full object-cover" />
                <span v-else class="text-sm">{{ p.icon || '📦' }}</span>
              </div>
              <div class="min-w-0 flex-1">
                <!-- Badge Kategori Eksplisit (Selalu Ditampilkan) -->
                <span class="text-[8px] text-blue-700 bg-blue-50 border border-blue-200 font-extrabold uppercase px-1 py-0.5 rounded truncate block max-w-[75px]">
                  {{ getProductCategoryName(p) }}
                </span>
                <!-- Barcode / SKU Produk (Jika Tersedia) -->
                <span v-if="p.barcode" class="text-[8px] font-mono text-slate-400 block truncate">
                  {{ p.barcode }}
                </span>
              </div>
            </div>

            <!-- Tombol Edit Terpisah (Touch Target Ergonomis Minimal 44x44px) -->
            <button 
              type="button"
              @click.stop="handleEdit(p)"
              title="Ubah Produk"
              class="min-w-[44px] min-h-[44px] -mr-2 -mt-2 rounded-lg text-slate-400 hover:text-amber-700 hover:bg-amber-100 flex items-center justify-center text-xs transition cursor-pointer shrink-0"
            >
              ✏️
            </button>
          </div>

          <!-- Baris 2: Nama Produk Ringkas & Badge Multi-Satuan -->
          <div class="my-1">
            <div class="text-[11px] text-slate-900 font-bold line-clamp-2 leading-tight">
              {{ p.name }}
            </div>
            <!-- Lencana Indikator Multi-Satuan (Jika Memiliki Lebih dari 1 Kemasan) -->
            <span 
              v-if="getUnits(p).length > 1" 
              class="inline-block mt-0.5 text-[8px] bg-purple-100 text-purple-800 border border-purple-200 font-extrabold px-1 py-0.5 rounded uppercase"
            >
              {{ getUnits(p).length }} Satuan
            </span>
          </div>
```

---

## 5. Sintesis Perbaikan Tambahan (Variasi 2 & onUnmounted Timer)

Untuk memastikan implementasi lolos 100% pada audit berikutnya, berikut rekomendasi implementasi pendamping:

### 5.1 Perbaikan Variasi 2: Indikator Satuan Tambahan
- **Lokasi**: Baris 471–474 di `ProductGalleryMockup.vue`
- **Kode**:
  ```html
              <div class="flex items-center justify-between">
                <div class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">
                  Pilih Satuan Langsung:
                </div>
                <span 
                  v-if="getUnits(p).length > 2" 
                  class="text-[8px] bg-purple-100 text-purple-800 font-bold px-1 rounded uppercase"
                >
                  +{{ getUnits(p).length - 2 }} Satuan Lain
                </span>
              </div>
  ```

### 5.2 Perbaikan Memory Leak (onUnmounted Timer Cleanup)
- **Lokasi**: Baris 1–3 & 140–150 di `ProductGalleryMockup.vue`
- **Kode**:
  ```javascript
  import { ref, computed, onUnmounted } from 'vue';
  ```
  Dan tambahkan hook di bagian akhir `<script setup>`:
  ```javascript
  onUnmounted(() => {
    if (feedbackTimeout) clearTimeout(feedbackTimeout);
  });
  ```

---

## 6. Verifikasi & Checklist Acceptance Criteria

| Item Persyaratan Acceptance Criteria | Status Rekomendasi | Verifikasi & Bukti |
|---|---|---|
| **Thumbnail / Avatar Produk** | **TERPENUHI** | `w-7 h-7` (28x28px) dengan `img` dan fallback `p.icon \|\| '📦'`. |
| **Badge Kategori Eksplisit** | **TERPENUHI** | `getProductCategoryName(p)` selalu tampil dalam badge `text-blue-700 bg-blue-50 border-blue-200`. |
| **Indikator Multi-Satuan** | **TERPENUHI** | Badge ungu `{{ getUnits(p).length }} Satuan` muncul jika `getUnits(p).length > 1`. |
| **Tombol Edit Terisolasi (≥44x44px)** | **TERPENUHI** | `min-w-[44px] min-h-[44px]` dengan `-mr-2 -mt-2` dan modifier `@click.stop`. |
| **Vite Build Bersih** | **TERPENUHI** | Menggunakan utility class valid Tailwind CSS v3 (`py-0.5` bukan `py-0.2`). |
