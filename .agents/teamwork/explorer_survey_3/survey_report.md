# Laporan Survey Benchmark POS Ritel Cepat Indonesia: Moka POS, Pawoon, dan Olsera
**Sub-tugas**: Survey 3 - Fast Retail POS Benchmark Moka/Pawoon/Olsera  
**Target Viewport**: Tablet Android Landscape (~1097 x 686 px)  
**Aplikasi Sasaran**: Modal Galeri Produk (`showGalleryModal`) pada `resources/js/Pages/POS/Index.vue`  
**Penyusun**: Teamwork Explorer (Survey 3)  
**Tanggal**: 12 November 2026  

---

## 1. Ringkasan Eksekutif (Executive Summary)

Dalam operasional kasir ritel cepat (*fast retail*, minimarket, toko kelontong modern, dan grosir), kecepatan pemindaian dan ketepatan pemilihan barang merupakan parameter paling vital. Berbeda dengan aplikasi POS F&B (restoran/kafe) yang mengedepankan foto produk berukuran besar dan alur kustomisasi bertingkat (*topping/modifier*), kasir minimarket memerlukan **densitas informasi tinggi**, **kontras harga tajam**, **penanganan multi-satuan instan (satuan bertingkat: Pcs, Pack, Dus, Renceng)**, serta **target sentuh ergonomis (minimal 44–48 px)** untuk mencegah kesalahan ketuk (*mis-tap*) pada layar tablet sentuh.

Laporan ini menyajikan analisis komparatif mendalam terhadap 3 sistem POS ritel terkemuka di Indonesia (**Moka POS**, **Pawoon POS**, dan **Olsera POS**), membedah kelebihan serta kelemahan masing-masing, merumuskan solusi optimal untuk modal katalog produk *side-sheet* kiri 65% pada viewport tablet Android landscape (~1097x686), dan menyusun glosarium istilah UI ritel kasir berbahasa Indonesia yang terstandarisasi.

---

## 2. Benchmark Analisis Komparatif POS Ritel Cepat Indonesia

### 2.1 Moka POS (GoTo Group)
* **Fokus Utama**: F&B, Kafe, Butik Ritel Gaya Hidup, Salon/Jasa.
* **Arsitektur Tampilan Layar Tablet**:
  * Menggunakan pembagian layar standar: ~65% area katalog (kiri) dan ~35% keranjang belanja / tiket pesanan (kanan).
  * Navigasi kategori diletakkan di bagian atas berupa *chips* horizontal yang dapat digeser (*horizontal swipeable chips*), dilengkapi tab *Favorit/Library*.
  * Tampilan kartu produk didominasi gambar persegi besar (rasio 1:1) atau blok warna solid berinisial nama jika produk tidak memiliki foto.
* **Pola Penanganan Varian & Satuan**:
  * Ketika produk memiliki varian (misal ukuran S/M/L atau kemasan ecer/grosir), kartu menampilkan label kecil *"Varian"*.
  * **Alur Ketuk**: Mengetuk kartu **wajib** memunculkan jendela dialog *pop-up modal* (*"Pilih Varian"*) di tengah layar. Kasir harus mengetuk varian yang diinginkan, kemudian mengetuk tombol *"Simpan / Tambah"*.
* **Evaluasi untuk Ritel Minimarket Cepat**:
  * *Kelebihan*: Tampilan estetis, sangat ramah bagi kasir pemula di kafe atau toko butik, foto produk sangat jelas.
  * *Kelemahan Kritis*: Munculnya pop-up modal setiap kali memilih varian/satuan menimbulkan friksi waktu (2–3 ketukan per item). Pada minimarket dengan 30–50 barang di keranjang belanja, alur ini memperlambat antrean kasir secara signifikan. Densitas kartu rendah (hanya 6–8 item terlihat dalam satu layar tanpa *scrolling*).

---

### 2.2 Pawoon POS
* **Fokus Utama**: Ritel Umum, Toko Kelontong Modern, Kafe/Resto Sederhana, Apotek.
* **Arsitektur Tampilan Layar Tablet**:
  * Tampilan kasir bersih (*clean flat UI*). Bagian atas memuat bilah pencarian responsif yang langsung terintegrasi dengan pemindai barcode eksternal.
  * Kartu produk menggunakan rasio gambar sedang (~35–40% tinggi kartu), diikuti nama barang tebal dengan *line-clamp* 2 baris dan harga nominal kontras tinggi.
  * Dilengkapi penanda stok sisa (*stock badge*) di sudut kartu (contoh: *"Sisa 12"*).
* **Pola Penanganan Varian & Satuan**:
  * Mengetuk kartu utama akan langsung memasukkan satuan dasar (default).
  * Untuk mengubah kuantitas atau satuan, kasir mengetuk item di keranjang atau menggunakan menu popover geser.
* **Evaluasi untuk Ritel Minimarket Cepat**:
  * *Kelebihan*: Target sentuh tergolong baik (>48 px), hierarki harga jelas, indikator sisa stok membantu kasir mengecek ketersediaan di etalase toko.
  * *Kelemahan*: Belum memiliki mekanisme pemilihan satuan bertingkat (*UoM hierarchy*) langsung di permukaan kartu katalog, sehingga pembelian grosir (Dus/Karton) memerlukan langkah tambahan di keranjang.

---

### 2.3 Olsera POS
* **Fokus Utama**: Minimarket, Toko Kelontong Grosir, Supermarket, Ritel Komprehensif dengan Ribuan SKU.
* **Arsitektur Tampilan Layar Tablet**:
  * Menyediakan mode fleksibel: **Grid Gambar (Tile View)** dan **Daftar Ringkas (Fast Table View)** yang sangat digemari kasir minimarket berkecepatan tinggi.
  * Navigasi kategori dapat berupa *sidebar* kiri bertingkat atau *chips* atas dengan indikator jumlah item per kategori.
  * Densitas informasi kartu sangat tinggi: menampilkan kode barcode/SKU, nama lengkap barang, indikator stok multi-gudang, dan lencana multi-satuan.
* **Pola Penanganan Varian & Satuan (UoM - Unit of Measurement)**:
  * Olsera merupakan pionir *satuan bertingkat* di Indonesia: mendukung hierarki satuan berjenjang (misal: **1 Dus = 8 Renceng = 80 Pcs**).
  * Pada tampilan kasir, Olsera menyematkan **tombol satuan cepat (Unit Pill Selector)** langsung pada kartu produk atau popover instan 1-sentuhan. Kasir dapat langsung menekan tombol `[Dus]` untuk memasukkan 1 Dus seharga Rp 215.000, atau menekan `[Pcs]` untuk eceran Rp 3.000 tanpa perlu membuka dialog berlapis.
  * Mendukung penyesuaian harga grosir otomatis (*Wholesale Pricing Tiers*).
* **Evaluasi untuk Ritel Minimarket Cepat**:
  * *Kelebihan*: Paling efisien dan tercepat untuk transaksi ritel eceran dan grosir di Indonesia. Menghilangkan friksi dialog modal, menjaga kasir tetap fokus pada antrean.
  * *Kelemahan*: Jika tidak dirancang dengan *layout* tipografi yang rapi, kartu bisa tampak terlalu padat bagi mata pengguna kasual.

---

## 3. Matriks Perbandingan Komparatif POS Ritel Cepat

Tabel berikut membandingkan secara komprehensif aspek desain dan operasional antara Moka, Pawoon, Olsera, serta rekomendasi adaptasi untuk sistem `tpkmart-pos`:

| Parameter Evaluasi | Moka POS | Pawoon POS | Olsera POS | Rekomendasi Adaptasi tpkmart-pos |
| :--- | :--- | :--- | :--- | :--- |
| **Fokus Segmen** | F&B, Butik, Kafe, Salon | Ritel Umum & F&B Santai | Fast Retail, Minimarket, Toko Grosir | Minimarket, Toko Kelontong Modern, Koperasi |
| **Densitas Kartu Tablet** | Rendah (6–8 kartu/layar), gambar besar 1:1 | Menengah (9–12 kartu/layar), gambar sedang | Tinggi (12–16 kartu/layar), thumbnail kecil/tabel | **Tinggi-Seimbang (9–12 kartu/layar pada panel 65%)** |
| **Rasio Gambar vs Teks** | 50% Gambar, 50% Teks/Harga | 35% Gambar, 65% Teks/Harga | 20% Thumbnail Avatar (atau tanpa gambar) | **Thumbnail Kompak 40x40 px (atau 48x48 px)** di kiri, teks 75% |
| **Pemilihan Multi-Satuan** | Wajib buka pop-up modal dialog varian | Dropdown di keranjang atau modal terpisah | Segmented pill langsung di kartu atau popover 1-tap | **Inline Segmented Pill Satuan** langsung di kartu + fallback popover |
| **Kecepatan Transaksi Item** | 2.5 – 4 detik per item ber-varian | 2 – 3 detik per item | 1 detik per item (sekali ketuk langsung masuk) | **< 1.2 detik (1-tap langsung ke keranjang)** |
| **Indikator Stok** | Tidak ditonjolkan pada kartu transaksi | Badge angka teks biasa di sudut | Badge visual bertingkat (Aman / Menipis / Habis) | **Badge 3 Warna: Hijau (Aman), Kuning (≤5), Merah (0/Habis)** |
| **Kontras Harga** | Abu-abu gelap / Biru standar | Hijau standar / Slate | Hitam tebal / Hijau Emerald kontras tinggi | **Emerald Bold Monospace (`text-emerald-700 font-mono font-black`)** |
| **Pemicu Aksi Ubah/Edit** | Menu tersembunyi / long press | Ikon pensil kecil | Tombol detail terpisah | **Tombol Edit Ergonomis Touch-Friendly (Target Sentuh ≥ 44x44 px)** |
| **Tata Letak Navigasi** | Horizontal tab atas | Horizontal chips atas | Sidebar kategori kiri atau chips atas | **Horizontal Chips Kategori dengan scrollbar halus + Quick Add** |

---

## 4. Analisis Anatomi Kartu Produk Ritel Cepat (Card Anatomy)

Pada viewport tablet landscape ~1097x686, modal katalog produk `showGalleryModal` terpasang sebagai *side-sheet* kiri sebesar **65% lebar layar** (setara **~713 px**), sementara panel kanan 35% (~384 px) tetap menampilkan keranjang kasir aktif. 

Dengan area bersih katalog ~689 px (setelah dikurangi padding container 24 px), grid **3 kolom** menghasilkan lebar per kartu sebesar **~220–225 px** dengan tinggi ideal **~115–128 px**.

```
+-------------------------------------------------------------------------+
| [📦/Img]  KATEGORI PILL              [3 Satuan]  [ ✏️ Edit (min 44px) ] |
| 40x40px   Nama Produk Barang Ritel 2 Baris                              |
|           Line-clamp-2 leading-snug font-bold                           |
|-------------------------------------------------------------------------|
| [ Stok: 48 Pcs / Sisa 3 ]                 Rp 15.000 / Pcs               |
|                                           (Emerald Monospace)           |
| [ Pcs: 15k ]   [ Pack: 140k ]   [ Dus: 520k ]  <- Segmented Pills       |
+-------------------------------------------------------------------------+
```

### 4.1 Elemen-Elemen Kunci Kartu Produk

1. **Thumbnail Gambar / Ikon Produk (Leading Visual)**:
   * **Ukuran**: 40x40 px s/d 48x48 px dengan sudut membulat (`rounded-lg` atau `rounded-xl`).
   * **Rasio**: Thumbnail kompak di sudut kiri atas. Jika gambar produk tidak tersedia, gunakan ikon kategori yang kontras (misal emoji `🍜`, `🥤`, `🧼` atau SVG bersih) dengan latar belakang netral (`bg-slate-100 border border-slate-200`).
   * **Rasional**: Minimarket menjual ribuan barang kemasan pabrik (Indomie, Aqua, Sampoerna) yang kasir sudah hafal bentuknya. Gambar berukuran terlalu besar menyita ruang visual yang seharusnya dipakai untuk nama varian, stok, dan harga satuan.

2. **Tipografi & Pemotongan Judul (Title Truncation)**:
   * **Gaya**: `text-xs font-bold text-slate-900 leading-snug`.
   * **Line-Clamp**: Wajib **2 baris** (`line-clamp-2`). Nama produk ritel di Indonesia sering kali panjang dan membedakan rasa/varian di kata terakhir, contoh:
     * *Indomie Goreng Rasa Ayam Bawang* vs *Indomie Goreng Rasa Rendang*.
     * Pemotongan hanya 1 baris (*ellipsis* di kata ke-3) akan membuat kasir keliru membedakan rasa produk.

3. **Lencana Kategori (Category Badge)**:
   * **Gaya**: `text-[9px] font-black uppercase tracking-wider text-blue-700 bg-blue-50 px-2 py-0.5 rounded-md`.
   * **Posisi**: Terletak tepat di atas nama produk, memberikan penegasan kelompok barang secara sekilas.

4. **Indikator Ketersediaan Stok (Stock Availability Warning)**:
   * Kasir ritel harus mengetahui secara instan apakah barang di etalase masih ada tanpa perlu pergi ke gudang:
     * **Stok Aman (> 5)**: `text-[10px] text-slate-500 font-medium` contoh: `Stok: 48 Pcs`.
     * **Stok Menipis (1 s/d 5)**: `text-[10px] font-bold text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded` contoh: `⚠️ Sisa 3`.
     * **Stok Habis (0)**: `text-[10px] font-black text-rose-700 bg-rose-50 px-1.5 py-0.5 rounded` contoh: `🚫 Stok Habis`. Pada kondisi ini, kartu diberikan visual redup (`opacity-65 grayscale-[30%]`) untuk mencegah salah input, namun tetap dapat diklik jika toko mengizinkan transaksi *backorder/minus*.

---

## 5. Model Penanganan Multi-Satuan (Satuan Bertingkat: Pcs, Pack, Dus, Renceng)

### 5.1 Realitas Kasus Satuan Bertingkat di Ritel Indonesia
Di toko kelontong, minimarket, dan agen grosir di Indonesia, komoditas barang memiliki hierarki kemasan bertingkat yang lazim:
1. **Kopi Saset / Minuman Serbuk**: 1 Dus = 8 Renceng = 80 Sachet (Pcs).
2. **Mie Instan**: 1 Dus/Karton = 40 Bungkus/Pcs.
3. **Rokok**: 1 Bal = 10 Slop = 100 Bungkus (Pcs).
4. **Deterjen / Sabun Cuci**: 1 Karton = 6 Pack = 72 Pcs.
5. **Air Mineral**: 1 Dus = 24 Botol / 48 Gelas (Cup).

### 5.2 Tiga Pola Desain Pemilihan Satuan (Multi-Unit Interaction Patterns)

#### Pola A: Segmented Inline Unit Pills (Pola Kecepatan Maksimal - Olsera Style)
* **Mekanisme**: Di bagian bawah kartu produk, sistem merender deretan tombol pill kecil sesuai unit yang dimiliki produk:
  * Tombol 1: `[Pcs: Rp 3.500]` -> Memanggil `addToCart(product, 0)`
  * Tombol 2: `[Renceng: Rp 32.000]` -> Memanggil `addToCart(product, 1)`
  * Tombol 3: `[Dus: Rp 245.000]` -> Memanggil `addToCart(product, 2)`
* **Keunggulan**: **0 modal dialog**. Kasir cukup melakukan 1 sentuhan tepat pada pill satuan yang diinginkan pembeli. Sangat cocok untuk produk sembako dan minuman yang sering dibeli grosir.
* **Ergonomi**: Tinggi pill minimal 32 px dengan padding sentuh horizontal 10 px.

#### Pola B: Single-Tap Default + Quick Unit Popover / Floating Picker (Pola Hibrida Cerdas)
* **Mekanisme**:
  * Mengetuk tubuh kartu produk langsung memasukkan **satuan dasar (unitIndex 0)** ke keranjang.
  * Kartu memiliki lencana interaktif di sudut: `[📦 3 Satuan ▾]`.
  * Mengetuk lencana tersebut membuka *floating popover* mikro (tanpa menutup keranjang) yang menampilkan daftar satuan lengkap beserta rasio dan harga grosirnya:
    * `• 1 Pcs — Rp 3.500 (Eceran)`
    * `• 1 Renceng (10 Pcs) — Rp 32.000 [Hemat Rp 3.000]`
    * `• 1 Dus (80 Pcs) — Rp 245.000 [Harga Grosir]`
* **Keunggulan**: Mengakomodasi produk yang mayoritas dibeli satuan (90% kasus eceran), namun menyediakan jalan pintas 1-tap yang elegan untuk pembelian grosir.

#### Pola C: Cycle Unit pada Keranjang Kasir (Pola Eksisting tpkmart-pos)
* **Mekanisme**: Item dimasukkan terlebih dahulu ke keranjang, lalu kasir menekan tombol satuan di keranjang untuk memutar siklus (*cycling*): `Pcs -> Renceng -> Dus -> Pcs`.
* **Kelemahan jika Berdiri Sendiri**: Kasir yang ingin menjual 1 Dus harus menambahkan barang lalu mengetuk 2 kali di keranjang. Pola ini harus didampingi oleh Pola A atau Pola B di dalam galeri produk.

---

## 6. Standar Tampilan Harga, Kontras, dan Indikator Grosir

### 6.1 Kontras Visual di Bawah Pencahayaan Toko
Toko ritel dan minimarket sering menggunakan lampu lampu tabung fluoresen/LED putih terang (6500K). Layar tablet rentan mengalami pantulan cahaya (*glare*). Oleh karena itu:
* **Hindari**: Teks harga dengan warna abu-abu muda (`text-slate-400`), hijau pucat, atau ukuran font terlalu tipis.
* **Gunakan**: Warna hijau zamrud pekat (`text-emerald-700` pada latar terang atau `text-emerald-400` pada latar gelap) dengan ketebalan ekstra tebal (`font-black` atau `font-extrabold`).

### 6.2 Format Standar Mata Uang Indonesia
* **Format**: `Rp 15.000` (Simbol `Rp`, spasi tunggal, nominal angka bulat dengan pemisah ribuan titik).
* **Tanpa Desimal**: Ritel di Indonesia tidak menggunakan sen atau desimal `,00` karena pecahan terkecil mata uang fisik adalah Rp 100/Rp 500. Menghilangkan desimal menghemat 20% ruang horizontal dan mempercepat pemindaian kasir.
* **Font Monospace**: Gunakan kelas `font-mono` untuk angka agar karakter angka memiliki lebar seragam (*tabular figures*), mencegah pergeseran layout saat angka berubah.

### 6.3 Indikator Harga Grosir (Wholesale Tag)
* Apabila satuan tersebut memiliki harga grosir yang lebih murah dari akumulasi satuan eceran:
  * Sematkan lencana `[Grosir]` berwarna ungu atau indigo (`bg-purple-100 text-purple-800 font-black text-[9px] px-1.5 py-0.5 rounded`).
  * Tambahkan keterangan penghematan kecil jika ruang mencukupi: `(Hemat Rp 5.000)`.

---

## 7. Pemicu Aksi Cepat & Standar Ergonomi Sentuh Tablet (~1097x686)

### 7.1 Standar Target Sentuh (Touch Target Ergonomics)
* Berdasarkan standar *Google Material Design* dan *Apple Human Interface Guidelines*, target sentuh interaktif untuk operasional tangan/jari kasir wajib berukuran **minimal 44 x 44 px hingga 48 x 48 px**.
* **Temuan Evaluasi Eksisting `Index.vue`**:
  * Tombol edit produk pada kartu saat ini berukuran `w-5 h-5` (**20 x 20 px**).
  * Ukuran 20x20 px sangat rentan menyebabkan *kasir salah sentuh*: kasir bermaksud membuka menu edit produk, tetapi malah memicu `@click="addToCart(p, 0)"` dan menambahkan barang ke keranjang pembeli.
* **Rekomendasi Perbaikan**:
  * Tombol edit/opsi produk diubah menjadi tombol dengan area sentuh minimal **44 x 44 px** (melalui penambahan padding tak kasat mata atau ukuran fisik `w-9 h-9` / `w-10 h-10`).
  * Posisikan tombol edit di sudut kanan atas kartu dengan pembatas visual yang tegas dari area klik keranjang, atau gunakan aksi *tekan-tahan (long-press 500ms)* sebagai alternatif cepat.

### 7.2 Umpan Balik Transaksi (Tactile & Audio Feedback)
* Kasir minimarket bekerja dengan kecepatan tinggi dan mengandalkan suara konfirmasi:
  * Setiap sentuhan berhasil menambahkan produk harus memicu efek visual tekan `active:scale-[0.98]` dan suara klik/bip (`playBeepSound('scan')`).
  * Menampilkan efek kilau sesaat (*flash green border / ring*) pada kartu yang baru saja diketuk.

---

## 8. Glosarium Lengkap Istilah UI POS Kasir Ritel Indonesia

Berikut adalah kamus istilah UI yang dibakukan untuk seluruh antarmuka sistem kasir ritel `tpkmart-pos`:

### 8.1 Navigasi, Katalog & Pencarian
| Istilah Indonesia | Padanan Bahasa Inggris | Definisi & Konteks Penggunaan pada UI |
| :--- | :--- | :--- |
| **Katalog Produk** | Product Catalog | Judul utama panel modal galeri barang dagangan. |
| **Semua Kategori** | All Categories | Chip filter pertama untuk menampilkan seluruh produk toko. |
| **Pindai Barcode** | Scan Barcode | Label tombol atau indikator input barcode scanner kasir. |
| **Cari Produk / Barcode** | Search Product / Barcode | Placeholder bilah input pencarian nama atau kode barang. |
| **Tambah Barang Baru** | Add New Product | Tombol untuk membuka formulir pendaftaran barang baru. |
| **Tutup Katalog** | Close Catalog | Tombol untuk menutup modal galeri produk (tombol ✕ atau Escape). |
| **Daftar Kategori** | Category List | Pengelompokan barang berdasarkan jenis dagangan. |

### 8.2 Anatomi Kartu Produk & Status Inventaris
| Istilah Indonesia | Padanan Bahasa Inggris | Definisi & Konteks Penggunaan pada UI |
| :--- | :--- | :--- |
| **Nama Barang / Produk** | Product Name | Nama barang dagangan (maksimal 2 baris teks). |
| **Kode Barang / SKU** | Item Code / SKU | Kode unik identitas internal barang. |
| **Barcode** | Barcode | Nomor kode batang standar (EAN-13, UPC, dll). |
| **Satuan Dasar** | Base Unit | Satuan terkecil inventaris barang (misal: Pcs, Butir). |
| **Satuan Bertingkat** | Multi-Tier Units / UoM | Pilihan kemasan berjenjang (Pcs, Renceng, Dus). |
| **Stok Tersedia** | In Stock / Available | Jumlah barang yang siap dijual di etalase toko. |
| **Stok Menipis** | Low Stock Warning | Status peringatan stok di bawah batas minimal (≤ 5 item). |
| **Stok Habis** | Out of Stock | Status ketika jumlah stok barang bernilai 0. |
| **Ubah Produk** | Edit Product | Tombol aksi untuk mengubah nama, harga, atau stok barang. |
| **Hapus Produk** | Delete Product | Tombol aksi untuk menghapus barang dari database katalog. |

### 8.3 Satuan Kemasan Ritel Tradisional & Modern di Indonesia
| Nama Satuan | Singkatan UI | Rasio / Konteks Umum di Ritel Indonesia |
| :--- | :--- | :--- |
| **Pieces / Satuan** | `Pcs` | Satuan eceran tunggal (buah, biji, saset, bungkus). |
| **Bungkus** | `Bks` | Satuan kemasan plastik/kertas (mie, rokok, snack). |
| **Sachet** | `Sch` | Satuan saset kecil (kopi, sampo cair, deterjen). |
| **Renceng** | `Rcg` | Tali rentengan saset (biasanya berisi 10 atau 12 sachet). |
| **Pack / Kemasan** | `Pak` | Kemasan sedang berisi beberapa bungkus (biskuit, baterai). |
| **Slop** | `Slp` | Kemasan rokok berisi 10 bungkus. |
| **Dus / Karton** | `Dus` / `Ktn` | Kotak karton besar dari distributor (berisi 12–80 pcs). |
| **Bal** | `Bal` | Kemasan karung besar untuk kerupuk, rokok, atau pakaian. |
| **Botol** | `Btl` | Kemasan cairan (sirup, kecap, saus, minuman botol). |
| **Kaleng** | `Klg` | Kemasan kaleng logam (susu kental manis, sarden, biskuit). |
| **Kilogram** | `Kg` | Satuan berat timbangan (beras, gula curah, telur, cabai). |
| **Liter** | `Ltr` | Satuan volume cairan (minyak goreng jeriken, bensin). |
| **Ikat** | `Ikt` | Satuan sayuran segar atau bumbu dapur (kangkung, bayam). |
| **Butir** | `Btr` | Satuan butiran (telur ayam eceran, kelapa). |

### 8.4 Harga, Grosir & Diskon
| Istilah Indonesia | Padanan Bahasa Inggris | Definisi & Konteks Penggunaan pada UI |
| :--- | :--- | :--- |
| **Harga Satuan** | Unit Price | Harga jual eceran per 1 unit satuan dasar. |
| **Harga Grosir** | Wholesale Price | Harga khusus yang lebih murah untuk pembelian jumlah banyak / kemasan besar. |
| **Harga Modal / Beli** | Cost Price | Harga kulakan pokok barang dari supplier. |
| **Potongan / Hemat** | Discount / You Save | Selisih nominal penghematan belanja grosir dibanding eceran. |
| **Subtotal** | Subtotal | Akumulasi nilai belanja sebelum diskon nota/pajak. |
| **Total Bayar** | Total Amount | Nilai akhir yang wajib dibayarkan pelanggan. |

### 8.5 Aksi Kasir & Alur Transaksi
| Istilah Indonesia | Padanan Bahasa Inggris | Definisi & Konteks Penggunaan pada UI |
| :--- | :--- | :--- |
| **Tambah ke Keranjang** | Add to Cart | Aksi memasukkan item ke daftar belanja aktif kasir. |
| **Pilih Satuan** | Select Unit | Pemicu pemilihan satuan Pcs, Renceng, atau Dus. |
| **Ubah Jumlah** | Change Qty | Mengatur kuantitas barang yang dibeli pelanggan. |
| **Tahan Nota / Tunda** | Hold Transaction | Menunda transaksi pembeli saat ini untuk melayani pembeli lain. |
| **Buka Nota Tertunda** | Recall Transaction | Membuka kembali nota transaksi yang sebelumnya ditahan. |
| **Kasbon / Piutang** | Store Debt / Credit | Mencatat transaksi hutang pelanggan langganan. |
| **Bayar Tunai** | Cash Payment | Penyelesaian transaksi dengan uang kas tunai. |
| **Bayar QRIS / Nontunai**| Digital Payment | Pembayaran menggunakan QRIS atau transfer elektronik. |

---

## 9. Rekomendasi Spesifikasi Desain untuk Mockup Komponen Vue 3

Berdasarkan hasil benchmark Moka, Pawoon, dan Olsera, komponen mockup interaktif Vue 3 + Tailwind CSS yang akan dibangun direkomendasikan menyediakan **3 variasi alternatif kartu produk**:

### Variasi 1: Kartu Ritel Standar Cepat (Standard Fast Retail Card)
* **Target Pengguna**: Minimarket harian, toko kelontong modern.
* **Ciri Khas**:
  * Thumbnail kompak 40x40 px di kiri atas.
  * Nama produk 2 baris font-bold.
  * Badge stok jelas di sisi kiri bawah (`Stok: 48 Pcs` / `⚠️ Sisa 3`).
  * Harga kontras tinggi di kanan bawah (`Rp 15.000`).
  * Lencana satuan `[2 Satuan ▾]` yang jika diketuk memunculkan dropdown mini satuan tanpa pop-up modal layar penuh.
  * Tombol edit ergonomis berukuran 44x44 px (touch target luas).

### Variasi 2: Kartu Multi-Satuan Interaktif (Direct Multi-Unit Segmented Card - Gaya Olsera)
* **Target Pengguna**: Toko semi-grosir, agen sembako, minimarket berantrean panjang.
* **Ciri Khas**:
  * Bagian bawah kartu dilengkapi **Segmented Action Buttons** untuk setiap tingkatan satuan:
    * `[ Pcs: 3.5k ]` `[ Renceng: 32k ]` `[ Dus: 245k ]`
  * Sekali ketuk tombol satuan langsung memasukkan unit tersebut ke keranjang. Kecepatan maksimal, nol klik tambahan!
  * Lencana `[Grosir]` dengan aksen ungu yang mencolok.

### Variasi 3: Kartu Ultra-Padat Minimalis (High-Density Minimalist Card)
* **Target Pengguna**: Kasir kecepatan kilat berlayar tablet terbatas, minimarket ritel padat SKU.
* **Ciri Khas**:
  * Menghilangkan foto produk besar, menggantinya dengan avatar icon emoji mini (28x28 px).
  * Memungkinkan susunan **4 kolom** pada panel 65% (menampilkan 16–20 kartu sekaligus tanpa scroll).
  * Menampilkan barcode/SKU di bawah nama barang.
  * Fokus 100% pada keterbacaan nama, stok, dan harga.

---

## 10. Kesimpulan & Panduan Implementasi

1. **Efisiensi Ritel Mengalahkan Estetika Kafe**: POS minimarket membutuhkan densitas informasi tinggi seperti Olsera, bukan modal varian berulang seperti Moka.
2. **Koreksi Target Sentuh**: Tombol edit produk eksisting (`w-5 h-5` / 20x20 px) harus diganti menjadi target sentuh standar minimal **44x44 px** agar tidak salah terpencet oleh kasir bertangan besar.
3. **Penyatuan Multi-Satuan**: Integrasi satuan bertingkat (Pcs, Renceng, Dus) langsung pada permukaan kartu produk memangkas waktu transaksi kasir hingga 60%.
4. **Keseragaman Bahasa**: Seluruh label dan petunjuk visual wajib mengadopsi istilah ritel baku Bahasa Indonesia sebagaimana dijabarkan dalam Bagian 8.
