# Laporan Handoff: Survei Basis Kode & Arsitektur POS (Survey 1)

## 1. Observation (Pengamatan Langsung)

1. **Target Viewport & Layout POS**:
   - `resources/js/Pages/POS/Index.vue:3250`: Container utama menggunakan layar penuh edge-to-edge:
     ```html
     <div class="flex flex-row w-screen h-screen max-w-full max-h-screen overflow-hidden text-slate-800 bg-white select-none relative font-sans shadow-none">
     ```
   - Pembagian panel: Panel Kiri 65% (`w-[65%]`) untuk keranjang & side-sheet katalog, Panel Kanan 35% (`w-[35%]`) untuk kasir, pelanggan, dan pembayaran cepat.
   - Pada resolusi target tablet Android landscape (~1097x686), lebar panel kiri adalah ~713px dan panel kanan ~384px.

2. **Implementasi Modal Galeri Produk (`showGalleryModal`)**:
   - `Index.vue:2207`: State dideklarasikan `const showGalleryModal = ref(false);`.
   - Pemicu buka: Tombol header kiri (baris 3488), shortcut `F2` (baris 351–355), tombol keranjang kosong (baris 3573).
   - Pemicu tutup: Tombol `Escape` (baris 416), backdrop overlay (baris 3928), tombol silang `✕` (baris 3967).
   - `Index.vue:3927–3929`:
     ```html
     <div v-if="showGalleryModal" class="fixed inset-0 z-50 flex items-stretch justify-start">
       <div class="absolute inset-0 bg-black/40 backdrop-blur-2xs" @click="showGalleryModal = false"></div>
       <div class="relative w-[65%] h-full bg-white border-r border-slate-200 shadow-2xl flex flex-col overflow-hidden z-10">
     ```
     Side-sheet mendok di panel kiri 65%, sehingga kasir tetap melihat panel checkout kanan 35% saat galeri terbuka.

3. **Struktur Kartu Produk Saat Ini (`Index.vue:4009–4046`)**:
   - Grid: `grid-cols-3 gap-2.5 p-3 flex-1 overflow-y-auto content-start auto-rows-max`.
   - Kartu: `h-[115px]` dengan padding `p-2.5`.
   - Thumbnail: `w-8 h-8 rounded-lg` (32x32px) dengan gambar atau emoji `p.icon || '📦'`.
   - Tombol Edit: `w-5 h-5 rounded-md` (20x20px) memanggil `openEditProductModal(p)`.
   - Multi-Satuan: Hanya badge pasif `text-[8px] bg-purple-50 text-purple-700` bertuliskan `{{ p.units.length }} Satuan`.
   - Event Klik: Seluruh kartu di-bind ke `@click="addToCart(p, 0)"`, selalu menambahkan satuan index 0.
   - Sisa Stok (`stock`): Sama sekali tidak ditampilkan di kartu produk.

4. **Kontrak Data & Model**:
   - `app/Models/Product.php`: Atribut `id`, `name`, `barcode`, `category_id`, `icon`, `image`, `base_unit`, `stock`, `min_stock`, `cost_price`, `is_active`, relasi `category`, relasi `units`.
   - `app/Models/ProductUnit.php`: Atribut `id`, `product_id`, `name`, `price`, `ratio`, `is_base`.
   - `Index.vue:117`: `addToCart(product, unitIndex = 0, initialQty = 1)` sudah menerima `unitIndex`, mendukung penambahan unit non-base jika dipanggil dengan index yang sesuai.

5. **Toolchain & Lingkungan Kompilasi**:
   - `package.json`: Vue 3.4.0, Vite 8.0.0, Tailwind CSS 3.2.1 dengan `@tailwindcss/forms 0.5.3`. Tidak ada dependensi icon library eksternal (menggunakan inline SVG & emoji native).
   - Pengujian build `npm run build` berhasil 100% tanpa error dalam 4.36 detik:
     ```
     vite v8.3.6 building client environment for production...
     ✓ 831 modules transformed.
     ✓ built in 4.36s
     ```

## 2. Logic Chain (Rantai Logika & Penalaran)

1. Berdasarkan pengamatan terhadap lebar tablet 1097px dan side-sheet galeri 65% (~713px), grid 3 kolom menghasilkan lebar kartu bersih ~223px per kartu.
2. Pada lebar ~223px dan tinggi layar ~686px, kartu produk dengan tinggi ~125–140px dapat menampilkan 3 baris (9 kartu) hingga 4 baris (12 kartu) sekaligus tanpa memicu crowding visual.
3. Tombol edit yang ada saat ini berukuran `w-5 h-5` (20x20px). Sesuai pedoman aksesibilitas sentuh W3C dan Android Material Design, target sentuh minimal adalah 44x44px atau 48x48dp. Tombol 20x20px secara langsung menyebabkan kegagalan ergonomi dan kesalahan input kasir di layar tablet.
4. Fungsi `addToCart` pada baris 117 sudah memiliki parameter `unitIndex` yang fleksibel. Ketiadaan pilihan multi-satuan di galeri bukan karena keterbatasan backend atau state cart, melainkan murni keterbatasan desain UI kartu yang hanya memanggil `addToCart(p, 0)`.
5. Oleh karena itu, merancang kartu baru dengan tombol pill/selector multi-satuan (misalnya tombol eceran `Bungkus` dan tombol grosir `Dus` terpisah dengan touch-target >= 44px) dapat langsung terintegrasi dengan logika transaksi POS tanpa perlu mengubah logika backend atau skema database.

## 3. Caveats (Batasan & Asumsi)

1. **File Referensi Pengguna di Direktori Antigravity**: Path gambar referensi `C:/Users/HP/.gemini/antigravity/brain/a434a221-a32a-4e9a-b4f6-ec972b2eedbc/...` dari sesi sebelumnya tidak dapat diakses secara langsung di sandbox lokal karena hak akses antar-sesi. Namun seluruh requirement fungsional, spesifikasi dimensi (~1097x686), dan benchmark retail (Moka, Pawoon, Olsera) sudah tertuang lengkap dalam `ORIGINAL_REQUEST.md` dan basis kode.
2. **Read-Only Scope**: Sesuai mandat Explorer, tidak ada perubahan kode aplikasi yang dilakukan pada tahap ini. Semua hasil disajikan dalam dokumen analisis `survey_report.md` dan handoff ini.

## 4. Conclusion (Kesimpulan Akhir)

Basis kode TPKMart POS sangat matang, modular, dan siap mendukung peremajaan modal galeri produk. Arsitektur side-sheet 65% di panel kiri memberikan pengalaman kasir yang efisien karena keranjang belanja di sisi kanan tetap terlihat aktif.

Solusi redesign yang direkomendasikan adalah:
1. Menyusun Dokumen Referensi Desain POS Ritel Cepat Bahasa Indonesia dengan perbandingan komparatif (Moka, Pawoon, Olsera).
2. Membuat komponen mockup Vue 3 interaktif (`ProductGalleryMockup.vue`) dengan 3 alternatif gaya kartu:
   - **Gaya 1 (Fast-Retail Multi-Unit Pill)**: Tombol satuan langsung (Pcs/Dus) pada kartu dengan touch-target 44px.
   - **Gaya 2 (Modern Visual Retail Card)**: Thumbnail 48x48px, badge stok dinamis, dan selector multi-satuan popover.
   - **Gaya 3 (Ultra-Dense Barcode Card)**: Densitas tinggi dengan barcode EAN-13 untuk kasir grosir/minimarket super cepat.

## 5. Verification Method (Metode Verifikasi Independen)

Untuk memverifikasi keabsahan laporan ini secara mandiri:
1. **Periksa File Laporan Lengkap**:
   Baca file `c:\Users\HP\.gemini\antigravity\scratch\tpkmart-pos\.agents\teamwork\explorer_survey_1\survey_report.md`.
2. **Verifikasi Baris Kode di Index.vue**:
   - `showGalleryModal`: Baris 2207, 3488, 3573, 3927.
   - Kartu produk: Baris 4009–4046.
   - `addToCart`: Baris 117–141.
   - `openEditProductModal`: Baris 2011–2036.
3. **Verifikasi Build Proyek**:
   Jalankan perintah berikut di terminal root:
   ```powershell
   npm run build
   ```
   Pastikan menghasilkan exit code 0 tanpa galat kompilasi sintaks.
