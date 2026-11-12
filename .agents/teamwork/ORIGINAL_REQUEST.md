# Original User Request

## Initial Request — 2026-11-11T17:24:36Z

Sediakan dokumen referensi desain dan komponen mockup interaktif Vue 3 + Tailwind CSS dalam Bahasa Indonesia untuk modal galeri produk POS (`showGalleryModal` pada `resources/js/Pages/POS/Index.vue`) di layar tablet Android mode landscape (~1097x686), terinspirasi dari sistem POS kasir cepat minimarket/retail (seperti Moka, Pawoon, Olsera).

Working directory: c:\Users\HP\.gemini\antigravity\scratch\tpkmart-pos
Integrity mode: development

Material referensi:
- Tangkapan layar: C:/Users/HP/.gemini/antigravity/brain/a434a221-a32a-4e9a-b4f6-ec972b2eedbc/.user_uploaded/media_1794417036293_2f70dd8e.png
- Implementasi saat ini: resources/js/Pages/POS/Index.vue (modal side-sheet showGalleryModal)
- Target viewport: Tablet Android landscape (~1097x686)

## Requirements

### R1. Analisis Referensi Desain POS Retail Cepat
Dokumentasikan referensi desain komprehensif dalam Bahasa Indonesia dari sistem POS retail cepat terkemuka (seperti Moka, Pawoon, Olsera) yang mencakup densitas kartu, hierarki visual, kontras harga, penanda multi-satuan, target sentuh (touch target minimal 44-48px), dan ergonomi kecepatan kasir pada layar tablet landscape.

### R2. Komponen Mockup Interaktif Vue 3 & Tailwind CSS
Buat komponen Vue 3 reusable yang menampilkan variasi alternatif gaya kartu produk dan grid katalog menggunakan Tailwind CSS dengan teks UI berbahasa Indonesia, mendemonstrasikan tata letak padat (high density), badge multi-satuan yang jelas, tampilan harga kontras tinggi, dan pemicu aksi kasir tanpa mengubah logika transaksi utama.

## Acceptance Criteria

### Dokumentasi Referensi
- [ ] Dokumen referensi ditulis lengkap dalam Bahasa Indonesia, merinci pola UI dari sistem POS retail cepat (Moka, Pawoon, Olsera) dengan perbandingan konkret struktur kartu produk, tipografi, spasi, dan penanganan multi-satuan.
- [ ] Dokumen memuat rekomendasi terarah yang disesuaikan khusus untuk resolusi layar tablet Android landscape (~1097x686) dan tema POS yang sedang digunakan.

### Implementasi Komponen
- [ ] Komponen mockup Vue 3 dengan Tailwind CSS berhasil dibuat di repository, menampilkan variasi alternatif gaya kartu produk dengan teks dan label berbahasa Indonesia.
- [ ] Setiap variasi kartu memuat gambar/thumbnail produk, nama produk, badge kategori, indikator multi-satuan, harga kontras tinggi, dan tombol aksi edit.
- [ ] Semua elemen interaktif pada kartu memenuhi standar ergonomi target sentuh tablet (area sentuh minimal 44x44px).
- [ ] Halaman POS yang sudah ada (`resources/js/Pages/POS/Index.vue`) tetap dapat di-build tanpa kesalahan sintaks atau kompilasi.


## Follow-up — 2026-11-11T17:44:13Z

Bagaimana status tahap audit & verifikasi saat ini? User juga meminta hasilnya ditampilkan di UI agar bisa dilihat langsung sebagai preview. Mohon kabari jika sudah selesai audit agar tidak bentrok dengan penyuntingan route/UI preview.
