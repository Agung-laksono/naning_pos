# PANDUAN DAN REFERENSI DESAIN MODAL GALERI PRODUK POS RETAIL CEPAT
**Aplikasi Kasir TPKMart POS — Layar Tablet Android Landscape (~1097 x 686 px)**  
**Versi Dokumen**: 1.0.0 (Definitif)  
**Bahasa**: Bahasa Indonesia  
**Klasifikasi**: Standar Desain Sistem Antarmuka Kasir Minimarket & Ritel Indonesia  

---

## 1. Pendahuluan & Latar Belakang

Sistem kasir ritel cepat (*fast retail*, minimarket, toko kelontong modern, dan grosir sembako) memiliki karakteristik operasional yang sangat berbeda dengan aplikasi kasir F&B (restoran/kafe) maupun ritel butik/fesyen. Di minimarket Indonesia, antrean kasir sering kali menumpuk pada jam sibuk, dengan rata-rata belanjaan mencapai 10 hingga 40 produk per pelanggan dalam berbagai macam kemasan (eceran saset, bungkus, renceng, hingga dus karton).

Keberhasilan operasional kasir sangat bergantung pada:
1. **Kecepatan pemindaian dan pemilihan barang**: Kasir harus dapat menemukan dan memasukkan barang ke keranjang dalam waktu < 1,5 detik per item.
2. **Kepadatan informasi visual yang seimbang (*Scanability & Information Density*)**: Memaksimalkan jumlah produk yang terlihat dalam satu layar tanpa mengorbankan keterbacaan teks nama produk dan harga jual.
3. **Pemberian fleksibilitas multi-satuan instan**: Menghilangkan hambatan dialog pop-up bertingkat ketika pelanggan membeli dalam kemasan grosir (contoh: membeli 1 Dus Indomie vs 1 Bungkus).
4. **Ergonomi sentuh layar tablet (*Touch Target Ergonomics*)**: Mencegah salah ketuk (*mis-taps*) antara tombol tambah barang dengan tombol ubah/edit produk, terutama dioperasikan menggunakan jari tangan kasir pada layar sentuh kapasitif tablet 10–11 inci.
5. **Kontras visual tinggi**: Menjamin seluruh harga dan status stok terbaca dengan tajam di bawah paparan lampu toko minimarket (fluoresen/LED 6500K putih terang) maupun pantulan cahaya kaca etalase.

Dokumen ini menjadi cetak biru (*design blueprint*) dan panduan teknis implementasi untuk pembaruan modal galeri produk (`showGalleryModal`) pada aplikasi kasir **TPKMart POS**.

---

## 2. Benchmark Sistem POS Ritel Cepat Indonesia: Moka, Pawoon, dan Olsera

Untuk merumuskan pola UI terbaik, dilakukan studi komparatif mendalam terhadap tiga penyedia POS terdepan di Indonesia: **Moka POS** (GoTo Group), **Pawoon POS**, dan **Olsera POS**.

### 2.1 Moka POS (Fokus: F&B, Kafe, Butik Ritel)
* **Karakteristik Tampilan**:
  - Menggunakan rasio visual gambar besar (1:1 atau persegi dominan).
  - Mengutamakan estetika modern yang lapang dengan ruang kosong (*whitespace*) lebar.
  - Menampilkan 6 hingga 8 produk dalam satu layar tablet.
* **Pola Penanganan Satuan & Varian**:
  - Setiap kali produk memiliki varian ukuran, rasa, atau kemasan, kartu hanya diberi label kecil *"Varian"*.
  - Mengetuk kartu **selalu memunculkan dialog modal pop-up layar penuh** (*"Pilih Varian Produk"*). Kasir harus memilih varian, lalu menekan tombol *"Simpan / Tambah"*.
* **Evaluasi untuk Minimarket Cepat**:
  - *Kelebihan*: Sangat ramah untuk kasir kafe/restoran pemula, visual foto produk sangat menarik.
  - *Kelemahan Fatal*: Menimbulkan friksi ketukan yang parah (2–3 kali sentuh per barang). Untuk transaksi 25 item, kasir harus berinteraksi dengan puluhan modal pop-up bertingkat yang menghambat laju antrean pembeli.

### 2.2 Pawoon POS (Fokus: Ritel Umum & Kelontong Modern)
* **Karakteristik Tampilan**:
  - Desain antarmuka datar (*flat UI*) yang bersih.
  - Gambar produk berukuran sedang (~35% dari total tinggi kartu), dipadukan dengan tipografi judul tebal 2 baris dan harga nominal besar.
  - Dilengkapi indikator sisa stok angka sederhana di sudut kartu.
* **Pola Penanganan Satuan & Varian**:
  - Sentuhan pada kartu langsung memasukkan satuan dasar ke keranjang belanja.
  - Perubahan kuantitas atau satuan dilakukan di dalam panel keranjang kasir sebelah kanan.
* **Evaluasi untuk Minimarket Cepat**:
  - *Kelebihan*: Keterbacaan kartu cukup baik, alur transaksi langsung tanpa pop-up dialog yang mengganggu.
  - *Kelemahan*: Belum mendukung pemilihan kemasan grosir (Dus/Karton) langsung dari kartu katalog. Kasir wajib mengedit item di keranjang jika pembeli ingin membeli dalam partai besar.

### 2.3 Olsera POS (Fokus: Minimarket, Toko Grosir, Supermarket)
* **Karakteristik Tampilan**:
  - Menyediakan mode *High-Density Grid* dan *Fast Table View* yang dirancang khusus untuk memproses ratusan transaksi per jam.
  - Menampilkan informasi lengkap dalam ruang ringkas: thumbnail kompak/avatar, kode SKU/barcode, nama lengkap barang, level stok gudang, dan harga jual.
* **Pola Penanganan Multi-Satuan (UoM - Unit of Measurement)**:
  - Pelopor sistem **Satuan Bertingkat** di Indonesia (hierarki konversi: *1 Dus = 10 Renceng = 100 Sachet*).
  - Menyediakan **Segmented Inline Unit Pills** langsung pada kartu produk. Kasir dapat langsung menekan tombol satuan `[Dus · Rp 120.000]` atau `[Pcs · Rp 1.500]` secara mandiri pada permukaan kartu yang sama tanpa membuka dialog tambahan!
* **Evaluasi untuk Minimarket Cepat**:
  - *Kelebihan*: Paling efisien dan tercepat di kelasnya. Menghilangkan friksi popup hingga 100%, sangat disukai kasir toko grosir dan kelontong sembako.
  - *Kelemahan*: Memerlukan penataan tipografi dan jarak antar-elemen (*spacing*) yang presisi agar kartu tidak tampak sesak atau berantakan.

---

### 2.4 Matriks Perbandingan Komparatif POS Ritel Cepat

| Parameter Desain | Moka POS | Pawoon POS | Olsera POS | Standar Adaptasi TPKMart POS |
|---|---|---|---|---|
| **Segmen Utama** | Kafe, Resto, Salon, Butik | Ritel Umum & Apotek | Minimarket & Grosir Sembako | **Minimarket, Toko Kelontong Modern, Koperasi** |
| **Densitas Kartu Tablet** | Rendah (6–8 kartu/layar) | Sedang (9–12 kartu/layar) | Tinggi (12–16 kartu/layar) | **Optimal (12 kartu pada grid 3-kolom / 16 kartu pada grid 4-kolom)** |
| **Rasio Gambar vs Teks** | 50% Gambar : 50% Teks | 35% Gambar : 65% Teks | 20% Thumbnail : 80% Teks | **Thumbnail Kompak 40x40px (atau 48x48px) di kiri atas** |
| **Pemilihan Multi-Satuan** | Wajib buka pop-up modal varian | Menu di keranjang belanja | Segmented pill satuan di kartu | **Segmented Inline Unit Pills terintegrasi langsung di kartu** |
| **Kecepatan Transaksi Item** | 2.5 – 4.0 detik per item | 1.8 – 2.5 detik per item | 0.8 – 1.2 detik per item | **< 1.0 detik (1 kali sentuh langsung masuk ke keranjang)** |
| **Indikator Stok Fisik** | Tidak ditampilkan di kasir | Angka teks biasa di sudut | Badge 3 warna (Aman/Menipis/Habis) | **Badge Adaptif 3 Warna: Hijau (>5), Kuning (1-5), Merah (0/Habis)** |
| **Kontras Harga Jual** | Abu-abu gelap biasa | Hijau standar / Slate | Hitam tebal / Hijau Emerald | **Emerald Monospace Kontras Tinggi (`text-emerald-700 / 800`)** |
| **Target Sentuh Tombol Edit** | Tersembunyi / long press | Ikon kecil di kartu | Tombol detail terpisah | **Area Sentuh Aman Minimal 44x44px (W3C / Android Material)** |
| **Navigasi Kategori** | Tab geser atas | Tab geser atas | Sidebar kiri / Chips atas | **Horizontal Swipeable Chips dengan tombol Cepat Tambah Kategori** |

---

## 3. Ergonomi Layar Tablet Android Mode Landscape (~1097 x 686 px)

Perangkat keras standar yang menjadi acuan adalah tablet Android kelas 10–11 inci (seperti Samsung Galaxy Tab A9+, Xiaomi Pad 6, Advan Tab Sketsa, atau Terminal POS Android Sunmi) dengan resolusi *viewport* browser web tipikal **1097 x 686 piksel**.

```
+---------------------------------------------------------------------------------------------------+
| TABLET VIEWPORT TOTAL: ~1097 x 686 px                                                             |
+-------------------------------------------------------------+-------------------------------------+
| PANEL KIRI: KATALOG PRODUK / SIDE-SHEET (65% / ~713 px)     | PANEL KANAN: KERANJANG KASIR (35%)  |
|                                                             | (~384 px)                           |
| [📂 KATALOG PRODUK]  [🔍 Cari / Barcode...] [+ Tambah] [✕]  | Kasir: Siska · 14:20:15 WIB         |
| [SEMUA] [🌾 Sembako] [🍜 Mie] [🥤 Minuman] [🚬 Rokok]       | Pelanggan: Pelanggan Umum           |
|                                                             |                                     |
| GRID KATALOG PRODUK (3 Kolom x 4 Baris = 12 Kartu Terlihat) | DAFTAR BELANJA KASIR                |
| +-------------------+ +-------------------+ +---------------+ • Indomie Goreng (2 Bks)  Rp  6.200 |
| | Kartu Produk 1    | | Kartu Produk 2    | | Kartu Prod 3  | • Kopi Kapal Api (1 Rcg) Rp 18.500 |
| | ~223 x 120 px     | | ~223 x 120 px     | | ~223 x 120 px | • Bimoli 2L (1 Pcs)      Rp 19.500 |
| +-------------------+ +-------------------+ +---------------+ ----------------------------------- |
| +-------------------+ +-------------------+ +---------------+ TOTAL TAGIHAN                       |
| | Kartu Produk 4    | | Kartu Produk 5    | | Kartu Prod 6  | Rp 44.200                           |
| +-------------------+ +-------------------+ +---------------+ [TUNAI F10] [QRIS F11] [KASBON F12]  |
+-------------------------------------------------------------+-------------------------------------+
```

### 3.1 Rasio Emas Dua Panel: Side-Sheet 65% vs Keranjang 35%
Arsitektur antarmuka TPKMart POS menggunakan pembagian dua panel (*dual-pane edge-to-edge layout*):
1. **Panel Kiri (65% / ~713 px)**:
   - Difungsikan untuk katalog produk, filter kategori, pencarian barcode, dan modal galeri produk (*side-sheet* kiri).
   - Ketika modal galeri produk aktif (`showGalleryModal = true`), jendela katalog berlabuh di sisi kiri selebar 65% layar, menggantikan area tabel keranjang sementara, **namun sisi kanan tetap terbuka**.
2. **Panel Kanan (35% / ~384 px)**:
   - Difungsikan untuk operasional inti kasir: identitas kasir aktif, jam transaksi presisi, selector pelanggan/member, ringkasan keranjang mini, dan tombol pintas pembayaran cepat (Tunai, QRIS, Kasbon).
3. **Keunggulan Ergonomis Pembagian 65% / 35%**:
   - Kasir **tidak pernah kehilangan konteks transaksi**. Saat kasir menyentuh kartu produk di sisi kiri, kasir dapat melihat item bertambah dan angka total tagihan di sisi kanan bergerak secara *real-time* tanpa tertutup oleh modal layar penuh (*no full-screen modal occlusion*).
   - Menghilangkan kecemasan kasir *"Apakah produk tadi sudah berhasil masuk ke keranjang?"*.

### 3.2 Geometri Kolom Grid & Ketinggian Kartu
* **Grid 3 Kolom (Paling Direkomendasikan untuk Ritel Reguler)**:
  - Lebar total area katalog: ~713 px dikurangi padding horizontal 24 px = ~689 px area bersih.
  - Jarak antar kartu (*gap*): `gap-2.5` (10 px) -> 2 celah = 20 px.
  - Lebar bersih setiap kartu produk: `(689 - 20) / 3` = **~223 piksel**.
  - Ketinggian optimal kartu: **115 px hingga 130 px**.
  - Dalam ketinggian layar 686 px (setelah dikurangi header modal ~54 px, chips kategori ~46 px, dan footer info ~42 px), area kartu memiliki ruang vertikal setinggi ~544 px. Grid 3x4 memungkinkan **12 kartu tampil penuh serentak** tanpa terpotong!
* **Grid 4 Kolom (Mode Ultra-Padat untuk Minimarket SKU Banyak)**:
  - Lebar per kartu: `(689 - 30) / 4` = **~164 piksel**.
  - Ketinggian kartu: **92 px hingga 105 px**.
  - Menampilkan **16 hingga 20 kartu produk sekaligus** di layar pertama.

### 3.3 Zona Jangkauan Jari & Postur Kasir (Thumb Zones & Posture)
Operasional kasir tablet memiliki dua skenario postur utama:
1. **Postur Dudukan Kasir Meja (*Counter-Stand POS*)**:
   - Tablet diletakkan di stand meja kasir bersudut 30°–45°.
   - Kasir mengoperasikan layar dengan satu jari telunjuk (tangan dominan).
   - Jarak pandang kasir: 40–60 cm dari layar. Teks nama produk harus jelas dan kontras harga harus tinggi.
2. **Postur Genggam (*Handheld Mobile Tablet*)**:
   - Kasir memegang tablet dengan dua tangan saat melayani antrean berjalan (*queue busting*).
   - Ibu jari kiri menjangkau kolom 1 dan chip kategori dengan mudah.
   - Ibu jari kanan bersiaga di panel 35% (tombol bayar dan kuantitas keranjang).

---

## 4. Standar Target Sentuh (Touch Target) & Pencegahan Salah Sentuh (Mis-Taps)

### 4.1 Standar Ergonomi Global
- **W3C Web Content Accessibility Guidelines (WCAG 2.1 / 2.2 SC 2.5.5 & 2.5.8)**: Target sentuh interaktif wajib berukuran minimal **44 x 44 piksel CSS**.
- **Google Android Material Design**: Area sentuh minimal **48 x 48 dp**.
- **Apple Human Interface Guidelines (HIG)**: Area sentuh minimal **44 x 44 pt**.

Alasan biomekanika: Lebar bantalan jari manusia dewasa saat menyentuh permukaan kaca kapasitif adalah **8 hingga 11 milimeter** (setara dengan 38 hingga 52 piksel pada densitas layar standar). Menempatkan elemen sentuh di bawah 44 px akan meningkatkan probabilitas salah sentuh (*error rate*) hingga lebih dari 35%.

### 4.2 Audit Kritis Tombol Edit Eksisting (20 x 20 px)
Pada kode awal `Index.vue` baris 4027–4035:
```html
<!-- IMPLEMENTASI LAMA: BAHAYA SALAH KETUK -->
<button 
  type="button"
  @click.stop="openEditProductModal(p)"
  title="Edit Produk / Atur Harga / Hapus"
  class="w-5 h-5 rounded-md bg-slate-100 border border-slate-200 ... text-[10px]"
>
  ✏️
</button>
```
* **Titik Bahaya Fatal**:
  1. Ukuran fisik tombol hanya `w-5 h-5` = **20 x 20 piksel**!
  2. Tombol ini bersarang (*nested*) di dalam kartu berukuran 223 x 115 px yang memiliki event `@click="addToCart(p, 0)"`.
  3. Ketika kasir berniat mengedit harga barang, meleset 2 mm ke samping akan memicu penambahan barang ke keranjang pelanggan yang sedang mengantre.
  4. Sebaliknya, saat kasir ingin menyentuh kartu untuk belanja, menyentuh sudut kanan atas akan mendadak membuka modal edit produk dan memutus alur pemindaian belanja kasir.

### 4.3 Spesifikasi Solusi Target Sentuh Baru
1. **Tombol Edit Produk**:
   - Diberikan pembatas visual mandiri di sudut kanan atas kartu.
   - Area sentuh diperluas menjadi **minimal 44 x 44 piksel** (dapat menggunakan ukuran fisik `w-9 h-9` (36px) ditambah padding sentuh tak kasat mata, atau tombol ukuran `w-10 h-10` / `w-11 h-11`).
   - Dilengkapi atribut `@click.stop` dan feedback visual aktif (`active:scale-95 bg-amber-100 border-amber-300`).
2. **Tombol Tutup Galeri (`✕`)**:
   - Diperbesar dari `w-8 h-8` (32px) menjadi minimal **44 x 44 piksel** (`w-11 h-11` atau `w-10 h-10` dengan touch margin) sehingga kasir dapat menutup katalog dalam satu kali tepukan cepat.
3. **Tombol Bersihkan Pencarian (`✕`)**:
   - Diperbesar dari 16x16px menjadi area sentuh minimal **36x36px** (`w-9 h-9`).
4. **Chip Tab Kategori**:
   - Ketinggian baris dinaikkan dengan padding `py-2.5 px-4` (tinggi total minimal 44 px) agar saat kasir menggeser jari (*swipe horizontal*), jemari tidak meleset keluar dari bilah kategori.

---

## 5. Analisis Kontras Warna & Aksesibilitas WCAG AA/AAA di Lingkungan Ritel

### 5.1 Karakteristik Pencahayaan Minimarket & Ritel Indonesia
Minimarket seperti Indomaret, Alfamart, dan toko kelontong modern rata-rata diterangi oleh lampu LED tabung 6500 Kelvin (Cool Daylight) dengan intensitas cahaya 500–800 lux. Selain itu, pantulan dari pintu kaca pendingin minuman sering menyebabkan *glare* pada permukaan tablet kasir.

### 5.2 Evaluasi Kontras Warna UI

| Elemen Antarmuka | Warna Lama | Warna Perbaikan Baru | Rasio Kontras (vs Putih) | Status Kepatuhan WCAG |
|---|---|---|---|---|
| **Nama Produk** | `text-slate-900` (`#0f172a`) | `text-slate-900` (`#0f172a`) | **17.8 : 1** | **Lolos AAA (Sangat Tajam)** |
| **Harga Jual Satuan** | `text-emerald-700` (`#047857`) | `text-emerald-800` (`#065f46`) atau chip `bg-emerald-50 text-emerald-900` | **7.1 : 1 s/d 9.2 : 1** | **Lolos AAA (Sangat Kontras)** |
| **Label Satuan Dasar** | `text-slate-400` (`#94a3b8`) | `text-slate-700` (`#334155`) font-bold | **7.2 : 1** | **Lolos AAA (Diperbaiki dari 2.25:1 Fails AA)** |
| **Badge Multi-Satuan** | `bg-purple-50 text-purple-700` | `bg-purple-100 text-purple-800 border-purple-300` | **6.4 : 1** | **Lolos AA (Aksen Tajam)** |
| **Badge Stok Aman** | - (Belum Ada) | `bg-emerald-50 text-emerald-800 border-emerald-200` | **7.1 : 1** | **Lolos AAA** |
| **Badge Stok Menipis** | - (Belum Ada) | `bg-amber-100 text-amber-900 border-amber-300` | **7.8 : 1** | **Lolos AAA** |
| **Badge Stok Habis** | - (Belum Ada) | `bg-rose-100 text-rose-800 border-rose-300` | **6.8 : 1** | **Lolos AA** |

### 5.3 Perbaikan Kritis `text-slate-400`
Label satuan sebelumnya menggunakan kelas Tailwind `text-slate-400` pada latar putih. Nilai kontrasnya hanya **2.25 : 1**, yang mana secara resmi **GAGAL** standar minimum WCAG 2.1 AA (minimal 4.5:1 untuk teks normal). Di bawah sinar lampu toko, tulisan `PCS`, `DUS`, atau `BKS` tampak kabur atau tidak terbaca sama sekali oleh kasir.  
*Standar Wajib*: Mengganti seluruh label satuan dengan `text-slate-700` (`#334155`) atau `text-slate-600` dengan bobot `font-bold uppercase`.

---

## 6. Penanganan Multi-Satuan Bertingkat (UoM: Eceran vs Grosir)

### 6.1 Realitas Komoditas Bertingkat di Ritel Indonesia
Barang dagangan sembako dan kebutuhan sehari-hari di Indonesia hampir selalu dijual dalam beberapa level kemasan:
1. **Kopi Bubuk / Minuman Saset**:
   - Satuan 1 (Eceran): Sachet (`Sch`) @ Rp 2.000 (Rasio 1)
   - Satuan 2 (Rentengan): Renceng (`Rcg` - 10 sachet) @ Rp 18.500 (Rasio 10)
   - Satuan 3 (Karton): Dus (`Dus` - 8 renceng = 80 sachet) @ Rp 140.000 (Rasio 80)
2. **Mie Instan**:
   - Satuan 1 (Eceran): Bungkus (`Bks`) @ Rp 3.100 (Rasio 1)
   - Satuan 2 (Karton): Dus (`Dus` - 40 bungkus) @ Rp 118.000 (Rasio 40)
3. **Minyak Goreng Pouch**:
   - Satuan 1 (Eceran): Pcs (`Pcs` - 2 Liter) @ Rp 19.500 (Rasio 1)
   - Satuan 2 (Karton): Dus (`Dus` - 6 pcs) @ Rp 114.000 (Rasio 6)
4. **Rokok Filter**:
   - Satuan 1 (Eceran): Bungkus (`Bks`) @ Rp 34.500 (Rasio 1)
   - Satuan 2 (Slop): Slop (`Slp` - 10 bungkus) @ Rp 340.000 (Rasio 10)

### 6.2 Pola Desain Pemilihan Satuan (Multi-Unit UI Patterns)

#### Pola 1: Segmented Inline Unit Pills (Pola Kecepatan Maksimal - Olsera Style)
Di bagian bawah tubuh kartu produk, sistem menampilkan deretan tombol pil (*pill buttons*) horizontal sesuai dengan relasi `product.units`:
```
+-------------------------------------------------------------+
| [Indomie Goreng Spesial]                      [ ✏️ Edit ]   |
| Stok: 400 Bungkus · Sembako                                 |
+-------------------------------------------------------------+
| PILIH SATUAN:                                               |
| [ Bungkus · Rp 3.100 ]            [ Dus (40) · Rp 118.000 ] |
| (@click="addToCart(p, 0)")        (@click="addToCart(p, 1)")|
+-------------------------------------------------------------+
```
- **Kelebihan**: **0 dialog modal**. Kasir cukup menyentuh pil `[Dus]` sekali saja, dan sistem langsung menambahkan 1 Dus ke keranjang kasir. Ini memotong alur kerja kasir hingga 70%.
- **Spesifikasi Tombol Pil**: Ketinggian tombol minimal 36px (touch target 44px dengan margin), latar belakang `bg-slate-100 hover:bg-blue-600 hover:text-white transition`, font monospace kontras tinggi.

#### Pola 2: Single-Tap Default + Floating Popover Quick Selector (Pola Hibrida)
- Mengetuk tubuh utama kartu produk akan langsung mengeksekusi `addToCart(p, 0)` (satuan dasar).
- Jika produk memiliki lebih dari 1 satuan, kartu menampilkan lencana interaktif di sudut kanan atas: `[📦 2 Satuan ▾]`.
- Mengetuk lencana tersebut membuka *popover dropdown* mini tepat di bawah kartu tanpa menutup katalog kasir:
  - `• Bungkus: Rp 3.100 (Eceran)`
  - `• Dus (40x): Rp 118.000 [Hemat Rp 6.000]`

---

## 7. Spesifikasi Detail 3 Variasi Desain Kartu Produk

Untuk komponen mockup interaktif Vue 3 (`ProductGalleryMockup.vue`), dirancang 3 variasi alternatif yang dapat diuji secara langsung oleh kasir:

### 7.1 Variasi 1: Standard Fast Retail (Penyempurnaan Optimal Desain Eksisting)
* **Filosofi**: Mempertahankan tata letak visual yang sudah familiar bagi kasir TPKMart POS, namun memperbaiki seluruh standar ergonomi sentuh, kontras, dan indikator stok.
* **Fitur Utama**:
  1. **Thumbnail Kompak**: Berukuran 40 x 40 px (`w-10 h-10 rounded-xl`) dengan gambar asli atau emoji kategori yang tajam.
  2. **Badge Stok 3-Warna**:
     - Stok > 5: `bg-slate-100 text-slate-600` bertuliskan `Stok: 48 Pcs`.
     - Stok 1–5: `bg-amber-100 text-amber-800 border-amber-300` bertuliskan `⚠️ Sisa 3`.
     - Stok 0: `bg-rose-100 text-rose-800 border-rose-300` bertuliskan `🚫 Habis`.
  3. **Nama Produk 2 Baris**: Tipografi `text-xs font-bold text-slate-900 line-clamp-2 leading-snug`.
  4. **Badge Multi-Satuan Informatif**: Menampilkan lencana ungu jelas `[2 Satuan]` jika memiliki variasi kemasan.
  5. **Harga Kontras Tinggi**: Angka rupiah `font-mono font-black text-emerald-800 text-xs` dipadukan dengan label satuan tebal `text-slate-700`.
  6. **Tombol Edit Touch-Friendly**: Tombol pensil ✏️ di sudut kanan atas dengan area sentuh minimal **44 x 44 piksel** dan visual tombol `w-8 h-8 rounded-lg` berbingkai terisolasi.

### 7.2 Variasi 2: Hybrid Multi-Unit Segmented (Gaya Olsera Grosir)
* **Filosofi**: Dirancang untuk kecepatan mutlak toko kelontong semi-grosir yang sering menjual barang dalam kemasan renceng dan karton dus.
* **Fitur Utama**:
  1. **Zona Informasi Produk (Atas)**: Memuat thumbnail 36x36px, nama produk, kategori, stok, dan tombol edit mandiri 44x44px.
  2. **Zona Aksi Satuan Langsung (Bawah)**:
     - Jika produk hanya memiliki 1 satuan: Tombol aksi tunggal lebar `[ Beli Pcs · Rp 3.500 ]`.
     - Jika produk memiliki >1 satuan: Tombol pil terbagi (*segmented pills*) bersisian, contoh: `[ Bks · Rp 3.100 ]` dan `[ Dus (40) · Rp 118.000 ]`.
  3. **Kecepatan 1-Tap**: Kasir langsung menyentuh pil kemasan yang diinginkan tanpa dialog pop-up.
  4. **Lencana Grosir Spesial**: Menampilkan penanda hemat / harga grosir berwarna ungu indigo.

### 7.3 Variasi 3: Compact High-Density (Densitas Maksimal Grid 4-Kolom)
* **Filosofi**: Dirancang untuk kasir berpengalaman dengan layar sentuh tablet yang ingin melihat hingga 16–20 produk sekaligus tanpa perlu melakukan scrolling.
* **Fitur Utama**:
  1. **Grid 4 Kolom**: Setiap kartu berukuran lebih ramping (~164 px) dengan tinggi ringkas 92–98 px.
  2. **Identitas Barcode & SKU**: Menampilkan kode barcode/SKU produk dengan font monospace kecil `text-[9px] text-slate-500` tepat di atas nama produk.
  3. **Avatar Mini / Emoji Kategori**: Menghemat ruang dengan avatar mini 28 x 28 px.
  4. **Fokus Tipografi & Harga**: Nama produk tebal dan angka harga hijau gelap mendominasi kartu.
  5. **Aksi Sentuh Cepat**: Sentuhan kartu langsung menambahkan satuan dasar; tombol edit diletakkan di sudut kanan dengan menu ringkas.

---

## 8. Glosarium Standar Istilah UI Kasir Ritel Bahasa Indonesia

Seluruh antarmuka kasir TPKMart POS wajib menggunakan istilah baku Bahasa Indonesia yang telah distandarisasi berikut:

### 8.1 Navigasi, Katalog & Pencarian
| Istilah Indonesia | Padanan Bahasa Inggris | Definisi & Konteks Penggunaan pada UI |
|---|---|---|
| **Katalog Produk** | Product Catalog | Judul utama jendela modal galeri barang dagangan toko. |
| **Semua Kategori** | All Categories | Chip filter utama untuk memunculkan seluruh komoditas toko. |
| **Pindai Barcode** | Scan Barcode | Label tombol scanner kamera atau pemindai laser eksternal. |
| **Cari Produk / Barcode** | Search Product / Barcode | Teks placeholder bilah pencarian nama barang atau kode batang. |
| **Tambah Barang** | Add Product | Tombol pendaftaran produk baru langsung dari kasir. |
| **Tutup Katalog** | Close Catalog | Tombol untuk menutup modal galeri produk (tombol ✕ atau Escape). |
| **Saring Kategori** | Category Filter | Pengelompokan produk berdasarkan jenis dagangan. |

### 8.2 Anatomi Kartu Produk & Status Inventaris
| Istilah Indonesia | Padanan Bahasa Inggris | Definisi & Konteks Penggunaan pada UI |
|---|---|---|
| **Nama Produk / Barang** | Product Name | Nama lengkap komoditas dagang (maksimal 2 baris teks). |
| **Kode Barang / SKU** | Item Code / SKU | Kode unik identitas internal barang di database toko. |
| **Barcode / Kode Batang** | Barcode | Nomor kode batang standar kemasan pabrik (EAN-13, UPC). |
| **Satuan Dasar** | Base Unit | Satuan terkecil inventaris barang di toko (contoh: Pcs, Bungkus). |
| **Satuan Bertingkat** | Multi-Tier Units / UoM | Pilihan kemasan berjenjang barang (Pcs, Renceng, Dus). |
| **Stok Tersedia** | In Stock / Available | Sisa kuantitas barang fisik yang siap dijual di etalase. |
| **Stok Menipis** | Low Stock Warning | Status peringatan ketika stok barang tersisa ≤ 5 unit. |
| **Stok Habis** | Out of Stock | Status ketika jumlah stok fisik barang bernilai 0. |
| **Ubah Produk / Edit** | Edit Product | Tombol aksi untuk mengubah nama, harga, atau stok barang. |
| **Hapus Produk** | Delete Product | Tombol untuk menonaktifkan atau menghapus produk dari kasir. |

### 8.3 Satuan Kemasan Ritel Tradisional & Modern Indonesia
| Nama Satuan | Singkatan UI | Rasio / Konteks Pemakaian Ritel di Indonesia |
|---|---|---|
| **Pieces / Satuan** | `Pcs` | Satuan eceran tunggal universal (buah, biji, saset, item). |
| **Bungkus** | `Bks` | Kemasan plastik/kertas eceran (mie instan, rokok, snack). |
| **Sachet** | `Sch` | Kemasan saset kecil sekali pakai (kopi instan, sampo cair). |
| **Renceng** | `Rcg` | Rentengan kemasan saset gantung (biasanya 10 atau 12 saset). |
| **Pack / Kemasan** | `Pak` | Kemasan sedang berisi beberapa bungkus (biskuit, baterai). |
| **Slop** | `Slp` | Kemasan rokok berisi 10 bungkus rokok. |
| **Dus / Karton** | `Dus` / `Ktn` | Kotak karton karton besar pabrik (berisi 12 hingga 80 item). |
| **Bal** | `Bal` | Kemasan karung besar untuk kerupuk mentah atau pakaian. |
| **Botol** | `Btl` | Kemasan cairan (kecap botol, sirup, saus sambal, minuman). |
| **Kaleng** | `Klg` | Kemasan logam (susu kental manis, biskuit kaleng, sarden). |
| **Kilogram** | `Kg` | Satuan berat timbangan digital (beras curah, gula, telur, cabai). |
| **Liter** | `Ltr` | Satuan volume cairan (minyak goreng jeriken, beras literan). |
| **Ikat** | `Ikt` | Kemasan ikat sayuran segar atau bumbu dapur (bayam, kangkung). |
| **Butir** | `Btr` | Satuan butiran eceran (telur ayam eceran, buah kelapa). |

### 8.4 Harga, Grosir & Diskon
| Istilah Indonesia | Padanan Bahasa Inggris | Definisi & Konteks Penggunaan pada UI |
|---|---|---|
| **Harga Jual** | Selling Price | Nilai rupiah yang dikenakan kepada pelanggan toko. |
| **Harga Grosir** | Wholesale Price | Harga spesial yang lebih murah untuk pembelian kemasan besar. |
| **Harga Modal / Beli** | Cost Price | Harga pokok pembelian barang dari distributor (kulakan). |
| **Hemat / Potongan** | Savings / Discount | Selisih nilai penghematan belanja grosir dibanding eceran. |
| **Subtotal** | Subtotal | Akumulasi total belanja sebelum pemotongan nota atau pajak. |
| **Total Tagihan** | Total Amount | Angka rupiah akhir yang wajib dilunasi oleh pelanggan. |

### 8.5 Aksi Kasir & Alur Transaksi
| Istilah Indonesia | Padanan Bahasa Inggris | Definisi & Konteks Penggunaan pada UI |
|---|---|---|
| **Tambah ke Keranjang** | Add to Cart | Aksi memasukkan item barang ke daftar belanja kasir. |
| **Pilih Satuan** | Select Unit | Pemicu pemilihan tingkatan kemasan produk. |
| **Atur Jumlah** | Set Quantity | Menentukan kuantitas barang yang dibeli pelanggan. |
| **Tahan Transaksi** | Hold Transaction | Menunda nota belanja pelanggan saat ini untuk melayani antrean lain. |
| **Buka Nota Tertahan** | Recall Transaction | Membuka kembali transaksi belanja yang sebelumnya ditahan. |
| **Kasbon / Piutang** | Store Credit / Debt | Fasilitas pencatatan hutang belanja bagi member langganan. |
| **Bayar Tunai** | Cash Payment | Penyelesaian pembayaran kasir menggunakan uang tunai fisik. |
| **Bayar QRIS** | QRIS Payment | Pembayaran nontunai menggunakan kode QR standar Indonesia. |

---

## 9. Petunjuk Teknis Implementasi Komponen Vue 3 (`ProductGalleryMockup.vue`)

Komponen baru `ProductGalleryMockup.vue` harus memenuhi ketentuan teknis berikut:
1. **Komposisi Vue 3**: Ditulis menggunakan Composition API dengan sintaks `<script setup>`.
2. **Kepatuhan Props & Emits**:
   - Menerima props:
     - `products`: Array daftar produk lengkap beserta relasi `units` dan `category`.
     - `categories`: Array daftar kategori toko.
     - `initialStyle`: String `'standard' | 'multi-unit' | 'compact'` (default: `'standard'`).
     - `activeCartItems`: Array daftar barang belanjaan aktif (opsional untuk badge jumlah item).
   - Memancarkan emits:
     - `@addToCart(product, unitIndex)`: Dipicu saat kartu atau pil satuan disentuh.
     - `@editProduct(product)`: Dipicu saat tombol edit ✏️ disentuh (area sentuh aman 44x44px).
     - `@close`: Dipicu saat tombol tutup ✕ atau backdrop ditekan.
3. **Interactive Style Switcher**:
   - Di bagian atas header katalog, sediakan tombol tab pemilih variasi (`Standard Ritel`, `Multi-Satuan Olsera`, `Ultra-Padat 4-Kolom`) sehingga penguji atau kasir dapat langsung membandingkan 3 variasi kartu secara *real-time*.
4. **Pencegahan Event Bubbling**:
   - Tombol edit wajib menggunakan `@click.stop` untuk mencegah pemicu penambahan produk yang tidak disengaja.
5. **Kesesuaian Target Sentuh**:
   - Semua elemen interaktif memiliki dimensi sentuh minimal 44x44 piksel CSS.

---
*Dokumen ini disusun untuk menjamin keseragaman visual, kecepatan kasir, dan kepatuhan ergonomi tablet pada sistem POS TPKMart.*
