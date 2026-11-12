# Project: TPKMart POS Gallery Modal Redesign & Reference Documentation

## Architecture
- **Framework**: Laravel 11 + Inertia.js + Vue 3 (Composition API, `<script setup>`)
- **Styling**: Tailwind CSS v3.2.1 (Forms plugin, modern utility classes, dark/light theme ready)
- **Target Viewport**: Android Tablet Landscape ~1097x686 px (CSS viewport logic, 10-inch form factor)
- **POS Screen Layout**: Dual-pane edge-to-edge layout
  - Left Pane (65% / ~713px): Product catalog, category chips, search bar, active side-sheet modal (`showGalleryModal`)
  - Right Pane (35% / ~384px): Active transaction cart, customer selector, payment shortcuts, continuous cashier operations
- **State & Data Contracts**:
  - `Product`: `{ id, name, barcode, category_id, icon, image, base_unit, stock, min_stock, cost_price, is_active, category, units }`
  - `ProductUnit`: `{ id, product_id, name, price, ratio, is_base }`
  - Cart Action: `addToCart(product, unitIndex = 0, initialQty = 1)`
  - Edit Modal Action: `openEditProductModal(product)`

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| F1 | Analisis Benchmark POS Ritel (Moka, Pawoon, Olsera) | Komparasi pola UI, struktur kartu, kelebihan & kekurangan untuk ritel cepat Indonesia | M1 | Survey 3 |
| F2 | Standar Ergonomi Layar Sentuh Tablet Landscape | Evaluasi touch target (>= 44x44px), thumb zone, dan pencegahan mis-tap pada viewport ~1097x686 | M1 | Survey 2 |
| F3 | Analisis Hierarki Visual & Kontras Harga WCAG | Standar kontras teks satuan, harga emerald kontras tinggi, badge stok 3-warna | M1 | Survey 1 & 2 |
| F4 | Desain Penanganan Multi-Satuan Bertingkat (UoM) | Segmented inline unit pills vs quick popover vs unit cycle untuk kemasan grosir (Pcs, Renceng, Dus) | M1 | Survey 2 & 3 |
| F5 | Glosarium Istilah UI POS Ritel Bahasa Indonesia | Kamus terminologi navigasi, kartu, satuan, harga, dan aksi kasir | M1 | Survey 3 |
| F6 | Dokumen Referensi Desain Komprehensif | Dokumen panduan desain lengkap Bahasa Indonesia di `docs/POS_GALLERY_DESIGN_REFERENCE.md` | M1 | ORIGINAL_REQUEST R1 |
| F7 | Komponen Mockup Interaktif Vue 3 | Komponen reusable `resources/js/Components/POS/ProductGalleryMockup.vue` | M2 | ORIGINAL_REQUEST R2 |
| F8 | Variasi Gaya Kartu 1: Standard Retail Plus | Kartu ritel cepat dengan thumbnail 40x40px, badge stok, tombol edit 44px, badge multi-satuan | M2 | Survey 1, 2, 3 |
| F9 | Variasi Gaya Kartu 2: Hybrid Multi-Unit Segmented | Segmented pill satuan langsung (`[Pcs] [Dus]`) di badan kartu dengan harga terintegrasi | M2 | Survey 2 & 3 |
| F10 | Variasi Gaya Kartu 3: Compact High-Density | Grid 4 kolom ultra-padat (16 item) dengan barcode & fokus teks untuk kasir kecepatan tinggi | M2 | Survey 2 & 3 |
| F11 | Pemilih Variasi Interaktif (Interactive Style Switcher) | Kontrol tab/toggle untuk kasir/manajer menguji & beralih antar gaya kartu secara real-time | M2 | ORIGINAL_REQUEST R2 |
| F12 | Integrasi Tanpa Merusak (Non-breaking Integration) | Komponen dapat ditampilkan di `resources/js/Pages/POS/Index.vue` tanpa mengubah logika transaksi utama | M2 | ORIGINAL_REQUEST R2 |
| F13 | Ergonomi Touch Target Tablet (Minimal 44x44px) | Semua tombol interaktif (edit, satuan, add) memiliki target sentuh minimal 44x44px | M2 & M3 | ORIGINAL_REQUEST AC |
| F14 | Verifikasi Kompilasi & Build Bersih | Build `npm run build` berhasil 100% tanpa kesalahan sintaks atau kompilasi | M3 | ORIGINAL_REQUEST AC |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| M1 | Dokumentasi Referensi Desain POS Ritel Cepat | Pembuatan dokumen komprehensif Bahasa Indonesia di `docs/POS_GALLERY_DESIGN_REFERENCE.md` mencakup analisis Moka, Pawoon, Olsera, ergonomi tablet ~1097x686, touch target >=44px, kontras harga, penanganan multi-satuan bertingkat, dan glosarium kasir | Survey Selesai | DONE |
| M2 | Komponen Mockup Interaktif Vue 3 + Tailwind CSS | Implementasi `resources/js/Components/POS/ProductGalleryMockup.vue` berisi 3 variasi kartu produk (Standard Plus, Hybrid Multi-Unit, Compact High-Density), switcher interaktif, teks Bahasa Indonesia, touch target >=44px, dan integrasi demonstratif di `Index.vue` | M1 | DONE |
| M3 | Verifikasi Independen & Audit Forensik | Pengujian Reviewer (kelengkapan & standar UI/UX), Challenger (ergonomi sentuh tablet & build check), dan Forensic Auditor (integritas implementasi, zero hardcoding dummy) | M2 | DONE |

## Code Layout
- Dokumentasi Referensi: `docs/POS_GALLERY_DESIGN_REFERENCE.md`
- Komponen Mockup: `resources/js/Components/POS/ProductGalleryMockup.vue`
- Halaman POS Integrasi: `resources/js/Pages/POS/Index.vue` (switcher mode mockup vs standar aktif)
- Test Harnesses: `tests/Unit/ErgonomicsAndContrastVerification.js`, `tests/Unit/ChallengerAdversarialVerification.js`, `tests/Unit/ProductGalleryEmpiricalTest.js`, `tests/Unit/AdversarialProductGalleryStressTest.js`
- State & Artefak: `.agents/teamwork/`

## Interface Contracts
### ProductGalleryMockup.vue Props & Events
- **Props**:
  - `products`: `Array<Product>` (daftar produk aktif dengan relasi `units` dan `category`)
  - `categories`: `Array<Category>` (daftar kategori produk)
  - `initialStyle`: `'standard' | 'multi-unit' | 'compact'` (default: `'standard'`)
  - `activeCartItems`: `Array<CartItem>` (opsional untuk indikator kuantitas di kartu)
- **Emits**:
  - `addToCart(product, unitIndex)`: Dipicu saat kasir menyentuh kartu atau pil satuan tertentu
  - `editProduct(product)`: Dipicu saat kasir menyentuh tombol edit (target sentuh 44x44px)
  - `close`: Dipicu saat kasir menekan tombol tutup / backdrop
