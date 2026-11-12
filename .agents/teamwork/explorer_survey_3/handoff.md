# Handoff Report — Explorer Survey 3 (Fast Retail POS Benchmark)

## 1. Observation
- **Tujuan Sub-tugas**: Melakukan riset komparatif terhadap sistem POS ritel cepat Indonesia (Moka POS, Pawoon, Olsera) untuk menetapkan pola UX/UI, struktur kartu produk, penanganan satuan bertingkat (multi-unit: Pcs, Pack, Dus, Renceng), kontras harga, target sentuh ergonomis tablet (~1097x686), dan glosarium istilah UI Bahasa Indonesia.
- **Analisis Kode POS Saat Ini (`resources/js/Pages/POS/Index.vue`)**:
  - Modal katalog (`showGalleryModal`) terpasang sebagai panel *side-sheet* kiri 65% (`w-[65%] h-full`, baris 3929).
  - Pada baris 4012, kartu produk memanggil `@click="addToCart(p, 0)"`, di mana indeks unit di-hardcode ke `0` (satuan dasar). Tidak ada cara langsung pada kartu untuk memilih satuan kedua atau ketiga (misal Dus atau Renceng).
  - Pada baris 4028–4034, tombol edit produk berukuran `w-5 h-5` (hanya 20x20 px), jauh di bawah batas minimal ergonomi sentuh jari (44–48 px).
  - Daftar satuan yang didukung aplikasi (`availableUnits`, baris 1739–1741) mencakup: `'Pcs', 'Bungkus', 'Sachet', 'Botol', 'Kg', 'Kaleng', 'Dus', 'Renceng', 'Slop', 'Bal', 'Butir', 'Liter', 'Ikat'`.
- **Temuan Benchmark Industri**:
  - **Moka POS**: Menggunakan dialog pop-up varian di tengah layar setiap kali item multi-varian ditekan. Bagus untuk kafe/butik, namun menimbulkan friksi 2–3 tap yang memperlambat antrean kasir minimarket.
  - **Pawoon POS**: Menyajikan tata letak bersih dan pencarian cepat barcode, namun penanganan multi-satuan bertingkat di kartu masih terbatas.
  - **Olsera POS**: Paling optimal untuk minimarket dan grosir berkat sistem UoM (*Unit of Measurement* bertingkat) dengan *inline segmented button* atau tombol pilihan satuan langsung di kartu tanpa membuka pop-up berlapis.

## 2. Logic Chain
1. *Dari Observasi Kode Eksisting*: Pada `Index.vue` baris 4012, `@click="addToCart(p, 0)"` selalu memasukkan unit ke-0. Jika pembeli ingin membeli 1 Dus Indomie (isi 40 bungkus), kasir terpaksa memasukkan 1 Pcs ke keranjang lalu mengklik tombol satuan di keranjang berulang kali untuk memutar siklus (*cycleItemUnit*, baris 155–165).
2. *Dari Benchmark Olsera POS*: Pada ritel cepat dan toko kelontong Indonesia, efisiensi transaksi diukur dalam detik per item. Olsera memecahkan masalah ini dengan menyematkan tombol satuan bertingkat (`[Pcs] [Renceng] [Dus]`) langsung di kartu produk atau melalui *quick-popover* satu sentuhan.
3. *Dari Analisis Target Sentuh*: Tombol edit 20x20 px (`w-5 h-5`) pada layar tablet landscape 10" rentan tersentuh secara tidak sengaja (*mis-tap*), yang justru memicu penambahan barang ke keranjang. Memperbesar area sentuh menjadi ≥ 44x44 px menjamin akurasi sentuhan kasir.
4. *Dari Rasio Layar Tablet Landscape 1097x686*: Panel 65% memiliki lebar bersih ~689 px. Grid 3 kolom menghasilkan lebar kartu ~220 px dan tinggi ~115–128 px, sangat ideal untuk memuat thumbnail kompak (40x40 px), nama barang 2 baris, badge stok 3-warna, harga kontras tinggi monospace, dan tombol multi-satuan segmented.

## 3. Caveats
- Benchmark dilakukan berdasarkan arsitektur UX/UI publik, dokumentasi teknis, dan alur transaksi aplikasi versi tablet Android/iPad dari Moka POS, Pawoon POS, dan Olsera POS hingga rilis stabil 2024–2026.
- Implementasi komponen mockup Vue 3 nantinya harus tetap mempertahankan kontrak data props yang ada (`product.id`, `product.name`, `product.units`, `product.stock`, `product.image`, `product.icon`, `product.category`) tanpa merusak fungsionalitas keranjang saat ini.

## 4. Conclusion
1. Dokumen survey komprehensif telah berhasil disusun di `c:\Users\HP\.gemini\antigravity\scratch\tpkmart-pos\.agents\teamwork\explorer_survey_3\survey_report.md`.
2. Direkomendasikan 3 variasi kartu produk untuk komponen mockup Vue 3:
   - **Variasi 1 (Standard Fast Retail Card)**: Thumbnail 40x40 px, badge stok 3-warna, tombol edit 44 px, badge multi-satuan dengan popover mikro.
   - **Variasi 2 (Direct Multi-Unit Segmented Card - Olsera Style)**: Segmented pill buttons langsung di kartu (`[Pcs: 3.5k] [Renceng: 32k] [Dus: 245k]`), nol modal dialog.
   - **Variasi 3 (High-Density Minimalist Card)**: Kartu kompak tanpa foto besar (4 kolom), berfokus pada barcode/SKU, nama, dan harga untuk kasir kecepatan kilat.
3. Telah disusun Glosarium Istilah UI POS Ritel Indonesia yang mencakup navigasi, kartu, satuan tradisional/modern, harga/grosir, dan aksi kasir.

## 5. Verification Method
1. Buka dan periksa dokumen hasil survey di `.agents/teamwork/explorer_survey_3/survey_report.md`.
2. Validasi kelengkapan 10 bagian laporan termasuk tabel matriks komparatif dan kamus istilah UI Indonesia.
3. Konfirmasi keselarasan referensi kode baris 3927–4070 pada `resources/js/Pages/POS/Index.vue`.
