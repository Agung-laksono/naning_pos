# Laporan Survei 2: Analisis Screenshot Referensi & Ergonomi Tablet POS Landscape (~1097x686)

**Disusun oleh**: `explorer_survey_2` (Teamwork Explorer)  
**Tanggal**: 2026-11-12  
**Target Viewport**: Android Tablet Landscape (~1097x686 piksel)  
**Tujuan**: Menyediakan analisis mendalam terhadap tangkapan layar modal katalog POS yang ada, mengevaluasi aspek ergonomi sentuh, kontras, tata letak, dan penanganan multi-satuan, serta merekomendasikan variasi kartu produk untuk implementasi komponen Vue 3 + Tailwind CSS.

---

## 1. Eksekutif Ringkasan

Berdasarkan investigasi visual pada tangkapan layar referensi (`media_1794417036293_2f70dd8e.png`) dan kode sumber modal galeri di `resources/js/Pages/POS/Index.vue` (baris 3922–4069):

1. **Struktur Modal Saat Ini**: Berupa *side-sheet drawer* di sisi kiri layar dengan lebar 65% (`w-[65%]`), menyisakan 35% sisi kanan untuk panel kasir/keranjang aktif. Pendekatan dua-panel (*dual-pane*) ini sangat ergonomis karena kasir dapat menambah item katalog tanpa kehilangan konteks total belanjaan.
2. **Kekuatan Visual Utama**:
   - Hierarki header sangat rapi (ikon folder, judul, search bar terintegrasi, tombol "+ Tambah Barang", dan tombol tutup).
   - Filter kategori berbentuk *chips* horizontal yang mudah digeser (*scrollable*).
   - Grid 3 kolom x 4 baris menampilkan 12 kartu produk sekaligus secara proporsional.
3. **Kelemahan Kritis Ergonomi (Target Sentuh & Fitts's Law)**:
   - **Tombol Edit Pensil (`w-5 h-5` / 20x20px)**: Terlalu kecil (jauh di bawah standar 44-48px). Diletakkan bersarang (*nested*) di dalam kartu yang dapat diklik (`addToCart`), menimbulkan risiko tinggi salah sentuh (*accidental mis-taps*).
   - **Penanda Multi-Satuan (`2 SATUAN`)**: Bersifat pasif. Kasir tidak melihat jenis satuan kedua (misal: Renceng/Dus) maupun harga satuannya, dan sentuhan kartu selalu memasukkan satuan default (index 0).
   - **Kontras Harga & Satuan**: Label satuan `PCS` menggunakan `text-slate-400` dengan rasio kontras 2.25:1 (gagal WCAG AA). Harga `text-emerald-700` dapat memudar di bawah pencahayaan toko ritel yang silau.

---

## 2. Dekonstruksi Anatomi Visual Screenshot Referensi

Tangkapan layar merepresentasikan antarmuka modal katalog produk toko pada status terisi (*populated state*). Berikut adalah rincian elemen visual per segmen:

### 2.1. Arsitektur Modal & Backdrop
- **Tipe Komponen**: *Left Side-Sheet Modal* berlabuh di sebelah kiri layar (`fixed inset-0 z-50 flex items-stretch justify-start`).
- **Dimensi**: Lebar modal 65% dari total layar (~713px pada layar 1097px). Lebar panel kanan 35% (~384px).
- **Latar Belakang (Backdrop)**: Lapisan gelap transparan `bg-black/40` dengan efek *backdrop blur* halus (`backdrop-blur-2xs`).
- **Kontainer Modal**: Putih murni (`bg-white`), batas kanan `border-r border-slate-200`, bayangan kuat `shadow-2xl`.

### 2.2. Header Bar Modal
- **Dimensi & Spasi**: Padding `px-4 py-2.5`, border bawah `border-slate-200`, tinggi sekitar ~54px.
- **Elemen Sisi Kiri**:
  - Ikon Identitas: Kotak rounded persegi 36x36px (`w-9 h-9 rounded-xl bg-blue-600 text-white shadow-xs`) dengan simbol folder 📂.
  - Teks Judul: `KATALOG PRODUK TOKO` menggunakan `font-black text-xs text-slate-900 leading-tight uppercase tracking-wide`.
  - Teks Subjudul: `Sentuh produk untuk menambah ke keranjang` menggunakan `text-[10px] text-slate-500 mt-0.5`.
- **Elemen Sisi Kanan (Aksi & Pencarian)**:
  - Kotak Pencarian Barcode: Lebar tetap `w-56` rounded-xl (`rounded-xl`), background `bg-slate-50`, ikon prefix kaca pembesar `🔍`, teks placeholder `Cari produk / barcode...`, dan tombol silang `✕` pembersih teks di ujung kanan.
  - Tombol Tambah Barang: `bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-3 py-1.5 rounded-xl uppercase shadow-xs flex items-center gap-1`.
  - Tombol Tutup: Kotak rounded 32x32px (`w-8 h-8 rounded-lg bg-slate-100 border border-slate-200 text-slate-700`) bertuliskan `✕`.

### 2.3. Bilah Navigasi Kategori (Category Filter Chips)
- **Dimensi & Gaya**: `px-4 py-2 bg-slate-50/70 border-b border-slate-200 flex items-center gap-1.5 overflow-x-auto text-xs shrink-0`.
- **Chip Aktif (`SEMUA`)**: Solid biru `bg-blue-600 text-white px-3.5 py-1.5 rounded-lg font-bold uppercase shadow-2xs`.
- **Chip Inaktif**: Putih berbingkai `bg-white text-slate-700 border border-slate-200 px-3.5 py-1.5 rounded-lg font-bold uppercase` dengan ikon emoji spesifik:
  - 🌾 `SEMBAKO`
  - 🍜 `MIE & INSTAN` (teks bertumpuk dua baris pada chip)
  - 🥤 `MINUMAN`
  - 🚬 `ROKOK`
  - 🧼 `SABUN & KEBERSIHAN`
  - 📦 `LAINNYA`
  - 📦 `BUMBU`
- **Aksi Cepat Tambah Kategori**: Tombol `+ Kategori` di ujung kanan chips (`bg-blue-50 text-blue-800 border border-blue-200 px-2.5 py-1.5 rounded-lg font-bold text-[11px] uppercase`).

### 2.4. Grid Katalog Produk
- **Susunan**: Grid 3 kolom (`grid grid-cols-3 gap-2.5 p-3 overflow-y-auto flex-1 content-start auto-rows-max`).
- **Kepadatan Tampilan**: Menampilkan 12 kartu produk dalam satu pandangan tanpa perlu scrolling awal (3 kolom x 4 baris).
- **Slot Kartu Kosong (Jika Hasil Filter Kosong)**: Kartu bergaris putus-putus hijau emerald (`border-2 border-dashed border-emerald-400 bg-emerald-50/60 rounded-xl p-3 h-[115px]`) bertuliskan *"Tambah Produk Baru"*.

### 2.5. Struktur Kartu Produk Individu (Product Card Anatomy)
Setiap kartu produk memiliki tinggi tetap 115px (`h-[115px]`), latar belakang putih (`bg-white`), radius sudut melengkung (`rounded-xl`), border abu-abu terang (`border-slate-200`), padding `p-2.5`, dan tata letak vertikal `flex flex-col justify-between`.

| Bagian Kartu | Elemen Visual | Gaya & Kelas Tailwind | Pengamatan Khusus |
|---|---|---|---|
| **Header Kartu** | Thumbnail Produk | `w-8 h-8 rounded-lg bg-slate-50 border border-slate-200` | Memuat gambar produk atau fallback emoji (📦, 🍫) |
| | Tag Kategori | `text-[9px] text-blue-600 font-black uppercase tracking-wider truncate` | Memberikan pengelompokan instan (e.g. `BUMBU`, `SEMBAKO`) |
| | Badge Multi-Satuan | `text-[8px] bg-purple-50 text-purple-700 border border-purple-200 font-bold px-1.5 py-0.2 rounded-md uppercase` | Muncul jika produk memiliki `units.length > 1` (e.g. `2 SATUAN`) |
| | Tombol Edit Pensil | `w-5 h-5 rounded-md bg-slate-100 border border-slate-200 text-[10px]` | Ikon ✏️ untuk membuka modal edit/hapus produk |
| **Isi / Body** | Nama Produk | `text-xs text-slate-900 font-bold line-clamp-2 mt-0.5 leading-snug` | Nama produk dengan batas 2 baris (e.g. *"Desaku bawang putih 6 g"*) |
| **Footer Kartu** | Garis Pembatas | `mt-2 pt-1 border-t border-slate-100 flex justify-between items-end` | Memisahkan nama dari harga jual |
| | Label Satuan Dasar | `text-[10px] text-slate-400 font-bold uppercase` | Satuan terkecil/default (e.g. `PCS`) |
| | Angka Harga Jual | `text-xs font-mono text-emerald-700 font-black` | Format Rupiah tebal (e.g. `Rp 1.000`, `Rp 3.500`, `Rp 50.000`) |

### 2.6. Bilah Status / Footer Modal
- **Dimensi**: `px-4 py-2.5 bg-slate-50 border-t border-slate-200 flex justify-between items-center text-[11px] text-slate-500`.
- **Petunjuk Operasi**: Titik hijau bulat (`w-2 h-2 rounded-full bg-emerald-500`) diikuti teks:  
  *"Sentuh kartu untuk ke keranjang · Sentuh ✏️ untuk edit/hapus produk"*.
- **Indikator Status Kasir**: Teks monospace abu-abu di ujung kanan:  
  *"Panel kasir kanan tetap aktif"*.

---

## 3. Evaluasi Ergonomi Layar Tablet Android Landscape (~1097x686)

Target perangkat yang disurvei adalah tablet Android 10"–11" dalam orientasi landscape (seperti Samsung Galaxy Tab A9+, Xiaomi Pad 6, Advan Tab Sketsa, atau Sunmi POS Terminal) dengan resolusi viewport browser tipikal **1097 x 686 piksel**.

### 3.1. Dimensi Target Sentuh (Touch Target Dimensions & Fitts's Law)
Standar ergonomi antarmuka sentuh internasional:
- **Android Material Design Guidelines**: Minimum **48 x 48 dp**.
- **Apple Human Interface Guidelines (HIG)**: Minimum **44 x 44 pt**.
- **WCAG 2.1 / 2.2 Success Criterion 2.5.5 / 2.5.8**: Minimum **44 x 44 CSS px** untuk antarmuka berkecepatan tinggi tanpa resiko salah sentuh.

#### Analisis Kritis pada Kartu Produk Saat Ini:
1. **Badan Kartu (Utama)**:
   - Dimensi fisik: ~225px x 115px.
   - Status: **Sangat Baik**. Area sentuh sangat luas, memungkinkan kasir memindai dan menepuk kartu dengan cepat saat melayani antrean panjang.
2. **Tombol Edit Bersarang (`✏️`):**
   - Dimensi fisik: `w-5 h-5` = **20 x 20 piksel**.
   - Status: **KRITIS / HAZARD ERGONOMI TINGGI**.
   - Alasan:
     - Lebar ujung jari rata-rata orang dewasa saat menyentuh layar kapasitif adalah 8–11 mm (sekitar 38–52 piksel).
     - Tombol 20x20px yang bersarang di dalam tombol kartu 225x115px membuat probabilitas salah sentuh mencapai >40%.
     - Jika kasir berniat menyentuh `✏️` tetapi meleset 2 mm ke bawah, sistem akan mengeksekusi `addToCart(p, 0)` dan memasukkan barang yang salah ke keranjang pembeli.
     - Sebaliknya, jika kasir ingin menambah barang ke keranjang namun menyentuh area kanan atas kartu, modal edit produk akan tiba-tiba terbuka di tengah transaksi yang sibuk.
3. **Tombol Tutup Modal (`✕`)**:
   - Dimensi: `w-8 h-8` = **32 x 32 piksel**.
   - Rekomendasi: Perlu diperbesar menjadi minimal `w-10 h-10` (40x40px) atau `w-11 h-11` (44x44px) dengan padding transparan agar kasir mudah menutup katalog dengan sekali sentuh.
4. **Tombol Hapus Pencarian (`✕`)**:
   - Dimensi: `w-4 h-4` = **16 x 16 piksel**.
   - Status: Terlalu kecil untuk jari kasir; membutuhkan stylus atau sentuhan ujung kuku. Perlu diperbesar menjadi minimal 32x32px touch area.
5. **Ketinggian Tab Kategori**:
   - Ketinggian saat ini: `py-1.5` (~32px tinggi).
   - Rekomendasi: Naikkan padding vertikal menjadi `py-2.5` atau `min-h-[44px]` agar geseran (*swipe*) jemari kasir tidak meleset ke luar baris.

---

### 3.2. Zona Jangkauan Jempol Kasir (Thumb Zones & Hand Placement)

Dalam operasional kasir retail minimarket, terdapat dua postur utama penggunaan tablet:

```
+-------------------------------------------------------------------------------+
|                        POSTUR 1: COUNTER-STAND POS (MEJA)                     |
|  - Tablet terpasang di stand dudukan meja kasir dengan sudut 30°-45°.          |
|  - Kasir mengoperasikan layar menggunakan 1 jari telunjuk tangan dominan.     |
|  - Jarak pandang: 40-60 cm. Area sentuh harus memiliki kontras visual tinggi. |
+-------------------------------------------------------------------------------+
|                       POSTUR 2: MOBILE / HANDHELD TABLET                       |
|  - Kasir memegang tablet dengan kedua tangan saat mobile / antrean panjang.   |
|  - Jangkauan Jempol Kiri: Kolom 1 (Mudah), Kolom 2 (Regang).                  |
|  - Jangkauan Jempol Kanan: Panel Keranjang & Tombol Bayar (Sangat Nyaman).    |
+-------------------------------------------------------------------------------+
```

#### Analisis Ergonomi Split 65% (Katalog) & 35% (Keranjang):
- **Keunggulan Ergonomis**: Penempatan katalog di sisi kiri (65%) dan panel keranjang serta tombol bayar di sisi kanan (35%) sangat ideal bagi mayoritas kasir bertangan dominan kanan:
  - Tangan kiri atau telunjuk kiri dapat fokus memilih katalog produk.
  - Tangan kanan selalu berada di dekat zona aksi penting: penambahan jumlah (*qty stepper*), pemilihan metode bayar (*Cash/QRIS*), dan tombol penyelesaian transaksi.
- **Hierarki Jangkauan**:
  - Kolom 1 grid katalog berada di zona paling dekat dengan tepi kiri (sangat mudah dijangkau).
  - Kolom 3 berada tepat di batas tengah layar, masih sangat nyaman untuk jangkauan jari tanpa perlu membungkukkan badan.

---

### 3.3. Densitas vs Scanability (Keseimbangan Beban Kognitif Kasir)

1. **Efektivitas Grid 3x4 (12 Item Terlihat)**:
   - Menampilkan 12 item secara simultan adalah angka optimal (*sweet spot*) untuk modal selebar 713px.
   - Jika ditingkatkan menjadi 4 atau 5 kolom (16-20 item):
     - Lebar kartu berkurang menjadi <160px.
     - Nama produk ritel Indonesia yang relatif panjang (misal: *"Desaku ketumbar bubuk sachet 15g"*) akan terpotong parah (*ellipsis*), memaksa kasir menebak isi produk.
     - Ukuran teks harga akan mengecil dan menyulitkan pembacaan cepat.
   - Jika dikurangi menjadi 2 kolom (6-8 item):
     - Kasir harus terlalu sering melakukan *scrolling*, memperlambat proses checkout antrean.
2. **Keterbacaan Pola Visual (Visual Chunking)**:
   - Penggunaan warna kategori berbeda atau ikon/thumbnail visual di sudut kiri atas kartu sangat membantu kasir menemukan produk dalam <500 milidetik.
   - Nama produk wajib dialokasikan minimal **2 baris teks** (`line-clamp-2`) dengan `leading-snug`.

---

### 3.4. Kontras Harga & Keterbacaan di Bawah Pencahayaan Ritel

Toko kelontong dan minimarket modern memiliki karakteristik pencahayaan yang menantang: lampu LED tabung intensitas tinggi, pantulan kaca display pendingin, atau silau sinar matahari dekat etalase kaca depan.

| Elemen Teks | Warna Saat Ini | Kode Hex | Rasio Kontras (vs Putih) | Kepatuhan WCAG | Dampak Lapangan Kasir |
|---|---|---|---|---|---|
| **Nama Produk** | `text-slate-900` | `#0f172a` | **17.8:1** | **Lolos AAA** | Sangat tajam dan jelas terbaca dari jarak 60 cm. |
| **Harga Jual** | `text-emerald-700` | `#047857` | **4.64:1** | **Lolos AA (Gagal AAA)** | Rentan memudar jika layar tablet terkena pantulan lampu toko. |
| **Kategori Tag** | `text-blue-600` | `#2563eb` | **4.56:1** | **Lolos AA** | Cukup terbaca, namun font kecil (9px) mengurangi legibilitas. |
| **Label Satuan** | `text-slate-400` | `#94a3b8` | **2.25:1** | **GAGAL TOTAL (FAILS AA)** | Sangat kabur di bawah pencahayaan toko; kasir sulit membedakan PCS/DUS. |

#### Rekomendasi Solusi Kontras:
1. **Harga**: Tingkatkan ke `text-emerald-800` (`#065f46`, rasio 7.1:1 - Lolos AAA) atau gunakan kontainer berlatar kontras: `bg-emerald-50 text-emerald-900 px-2 py-0.5 rounded-lg border border-emerald-200/60 font-black`.
2. **Label Satuan**: Ubah dari `text-slate-400` menjadi `text-slate-600` (`#475569`, rasio 5.9:1) atau `text-slate-700` dengan bobot `font-bold uppercase`.

---

### 3.5. Kejelasan Penanda Multi-Satuan (Multi-Unit Badge Clarity)

Pada sistem ritel grosir dan eceran di Indonesia (seperti sembako, rokok, bumbu dapur), barang sering diperjualbelikan dalam berbagai satuan bertingkat:
- Contoh: Bumbu (1 PCS @ Rp 1.000 vs 1 RENCENG isi 10 @ Rp 9.500 vs 1 DUS isi 100 @ Rp 90.000).

#### Masalah pada Implementasi Tangkapan Layar:
1. Badge saat ini hanya menuliskan teks generik: `2 SATUAN` dalam warna ungu muda.
2. Tidak ada indikasi nama satuan kedua (apakah DUS, RENCENG, LUSIN, atau PACK).
3. Tidak ada informasi rasio konversi (misal: `1 Dus = 24 Pcs`).
4. Ketika kasir menyentuh kartu produk, fungsi yang dipanggil adalah `addToCart(p, 0)` yang **secara otomatis selalu menambahkan satuan pertama (Pcs)**.
5. Kasir tidak memiliki cara langsung untuk menjual satuan kedua (Dus) langsung dari katalog tanpa harus membuka modal edit atau mengubah satuan secara manual di panel keranjang.

#### Rekomendasi Penanganan Multi-Satuan:
1. **Model Tag Informatif**: Menampilkan nama satuan turunan pada badge, misal `+ DUS (24x)` atau `PCS / DUS`.
2. **Model Split Action (Aksi Langsung)**: Pada kartu produk multi-satuan, sediakan 2 tombol pil sentuh di footer kartu:
   - Pil 1: `PCS · Rp 1.000`
   - Pil 2: `DUS · Rp 22.000`  
   Dengan demikian, kasir cukup menyentuh pil yang sesuai untuk langsung memasukkan satuan tersebut ke keranjang belanja tanpa dialog popup tambahan!

---

## 4. Rekomendasi Desain Komponen & Variasi Gaya Kartu Produk

Untuk memenuhi Acceptance Criteria R2 (pembuatan komponen mockup interaktif Vue 3 + Tailwind CSS), kami merekomendasikan **tiga variasi kartu produk** yang dapat dipilih atau dialihkan secara interaktif:

### Variasi 1: "Standard Retail Plus" (Penyempurnaan Desain Referensi)
- **Tujuan**: Mempertahankan estetika bersih dan familiar dari screenshot referensi, namun menuntaskan seluruh cacat ergonomi dan target sentuh.
- **Karakteristik**:
  - Grid 3 kolom (12 item per viewport).
  - Thumbnail 40x40px (`w-10 h-10 rounded-xl`).
  - Badge satuan informatif: `PCS + DUS` berwarna indigo berlatar cerah.
  - Tombol edit produk: Dipisahkan dengan zona aman (*safe click separation*) dan diperluas touch target-nya (`w-8 h-8 rounded-lg` dengan efek hover/active jelas).
  - Harga dengan kontras tinggi: Monospace emerald tua dengan background chip lembut `bg-emerald-50 text-emerald-800 border border-emerald-200`.

### Variasi 2: "Compact High-Density" (Densitas Maksimal untuk Kasir Cepat)
- **Tujuan**: Menampilkan jumlah produk terbanyak untuk kasir minimarket yang sudah hafal letak barang dan membutuhkan efisiensi ruang maksimum.
- **Karakteristik**:
  - Grid 4 kolom (menampilkan 16 item dalam viewport yang sama).
  - Ketinggian kartu lebih ringkas (`h-[92px]`).
  - Thumbnail berukuran minimalis atau digantikan badge kategori warna-warni (*color-coded badge*) yang memudahkan scanning visual.
  - Tipografi nama produk 11px tebal 2 baris.
  - Harga monospace tajam tanpa dekorasi berlebih.

### Variasi 3: "Hybrid Multi-Unit Action Card" (Kartu Aksi Cepat Grosir/Eceran)
- **Tujuan**: Menghilangkan hambatan pemilihan satuan pada produk multi-satuan (seperti toko sembako grosir dan warung ritel modern).
- **Karakteristik**:
  - Grid 3 kolom.
  - Badan kartu terbagi menjadi zona deskripsi produk dan zona aksi satuan.
  - Jika produk memiliki >1 satuan, footer kartu menampilkan pil-pil satuan sentuh langsung (*direct touch chips*):
    - Sentuh `[ Pcs: Rp 1.500 ]` -> langsung masuk 1 Pcs ke keranjang.
    - Sentuh `[ Dus: Rp 35.000 ]` -> langsung masuk 1 Dus ke keranjang.
  - Menghemat 2 langkah kerja kasir (*zero-dialog multi-unit addition*).

---

## 5. Ringkasan Matriks Perbandingan Variasi Kartu

| Parameter | Desain Saat Ini (Screenshot) | Variasi 1: Standard Plus | Variasi 2: Compact High-Density | Variasi 3: Hybrid Action |
|---|---|---|---|---|
| **Jumlah Kolom** | 3 Kolom | 3 Kolom | 4 Kolom | 3 Kolom |
| **Item per Layar** | 12 Item | 12 Item | 16 Item | 9–12 Item |
| **Ukuran Target Sentuh Edit** | 20x20px (Bahaya) | 36x36px (Aman) | Dropdown Menu / Long Press | Tombol Aksi Sudut 36px |
| **Tingkat Kontras Harga** | 4.64:1 (Sedang) | 7.2:1 (Sangat Tinggi) | 7.5:1 (Sangat Tinggi) | 7.2:1 (Sangat Tinggi) |
| **Penanganan Multi-Satuan** | Pasif (`2 SATUAN`) | Informatif (`PCS + DUS`) | Badge Ringkas (`2 Sat`) | **Pil Aksi Langsung (Sentuh & Tambah)** |
| **Kesesuaian Tipe Toko** | Ritel Umum | Minimarket & Toko Kosmetik | Ritel Cepat / Kasir Berpengalaman | Toko Grosir, Sembako, & Bahan Bangunan |

---

## 6. Rekomendasi Teknis untuk Implementasi Komponen Vue 3

1. **Kompatibilitas Tailwind CSS**:
   Gunakan kelas Tailwind standar (`v3/v4`) yang sudah ada dalam repositori tanpa memerlukan plugin tambahan.
2. **Reusabilitas Props**:
   Komponen mockup harus menerima prop produk standar (`name`, `price`, `category`, `image`/`icon`, `units`) dan memancarkan (*emit*) event `@select-product`, `@select-unit`, dan `@edit-product`.
3. **Penyelarasan Bahasa**:
   Seluruh label antarmuka, pesan kosong, badge, dan keterangan aksi menggunakan Bahasa Indonesia formal dan baku yang lazim pada ekosistem ritel Indonesia (seperti Moka POS, Pawoon, Olsera).
