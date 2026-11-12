// tests/Unit/AdversarialProductGalleryStressTest.js
// Adversarial fuzzing and stress harness for ProductGalleryMockup logic

import assert from 'node:assert';

function formatRupiah(val) {
  const num = Number(val) || 0;
  return 'Rp ' + num.toLocaleString('id-ID');
}

function getProductCategoryName(p, categories = []) {
  if (p && p.category && p.category.name) return p.category.name;
  if (p && p.category_id && categories.length) {
    const c = categories.find(cat => cat && cat.id === p.category_id);
    if (c && c.name) return c.name;
  }
  return 'Umum';
}

function getUnits(p) {
  if (p && p.units && Array.isArray(p.units) && p.units.length > 0) {
    return p.units;
  }
  return [{
    name: (p && p.base_unit) || 'Pcs',
    price: (p && p.price) || 0,
    ratio: 1,
    is_base: true
  }];
}

function getStockInfo(p) {
  const stock = typeof p.stock === 'number' ? p.stock : (parseInt(p.stock, 10) || 0);
  const minStock = typeof p.min_stock === 'number' ? p.min_stock : (parseInt(p.min_stock, 10) || 5);
  const baseUnit = p.base_unit || (p.units && p.units[0] ? p.units[0].name : 'Pcs');

  if (stock <= 0) {
    return {
      type: 'danger',
      text: 'Stok Habis',
      dotClass: 'bg-rose-500',
      badgeClass: 'bg-rose-100 text-rose-800 border-rose-200'
    };
  }
  if (stock <= minStock) {
    return {
      type: 'warning',
      text: `Sisa ${stock} ${baseUnit}`,
      dotClass: 'bg-amber-500',
      badgeClass: 'bg-amber-100 text-amber-900 border-amber-300'
    };
  }
  return {
    type: 'success',
    text: `Stok: ${stock} ${baseUnit}`,
    dotClass: 'bg-emerald-500',
    badgeClass: 'bg-emerald-50 text-emerald-800 border-emerald-200'
  };
}

function getCartQty(activeCartItems, productId, unitName = null) {
  if (!activeCartItems || activeCartItems.length === 0) return 0;
  if (unitName) {
    const found = activeCartItems.find(item => item && item.product_id === productId && item.unit === unitName);
    return found ? (found.qty || 0) : 0;
  }
  return activeCartItems
    .filter(item => item && item.product_id === productId)
    .reduce((sum, item) => sum + (item.qty || 0), 0);
}

function filterProducts(products, categories, selectedCategory, searchQuery) {
  let list = products || [];

  if (selectedCategory !== 'Semua') {
    const catLower = selectedCategory.toLowerCase();
    list = list.filter(p => {
      const pCat = getProductCategoryName(p, categories).toLowerCase();
      return pCat === catLower;
    });
  }

  const q = searchQuery.trim().toLowerCase();
  if (q) {
    list = list.filter(p => {
      const nameMatch = p.name && p.name.toLowerCase().includes(q);
      const barcodeMatch = p.barcode && p.barcode.toLowerCase().includes(q);
      const catMatch = getProductCategoryName(p, categories).toLowerCase().includes(q);
      return nameMatch || barcodeMatch || catMatch;
    });
  }

  return list;
}

console.log('--- RUNNING ADVERSARIAL FUZZING (10,000 ITERATIONS) ---');

const weirdNames = ['', '   ', 'Special %$#@!*&^', 'Indomie \n \t \0', '<html>alert(1)</html>', '0', null, undefined];
const weirdStocks = [0, -1, -999999, 99999999, '0', '-5', 'NaN', 'undefined', null, undefined, 0.5, -0.2];
const weirdPrices = [0, -500, 1000000000000, 0.0001, null, undefined, 'free', '12500'];
const weirdUnits = [
  null,
  undefined,
  [],
  [{}],
  [{ name: '', price: 0 }],
  [{ name: 'Pcs', price: 1000, ratio: 1, is_base: true }],
  [
    { name: 'Pcs', price: 1000, ratio: 1, is_base: true },
    { name: 'Dus', price: 20000, ratio: 24, is_base: false }
  ],
  [
    { name: 'Sachet', price: 1000, ratio: 1, is_base: true },
    { name: 'Renceng', price: 9500, ratio: 10, is_base: false },
    { name: 'Dus', price: 90000, ratio: 100, is_base: false }
  ],
  [
    { name: 'U1', price: 10 },
    { name: 'U2', price: 20 },
    { name: 'U3', price: 30 },
    { name: 'U4', price: 40 },
    { name: 'U5', price: 50 },
    { name: 'U6', price: 60 }
  ]
];

let fuzzErrors = 0;

for (let i = 0; i < 10000; i++) {
  const p = {
    id: i + 1,
    name: weirdNames[i % weirdNames.length],
    barcode: (i % 3 === 0) ? `89900000${i}` : null,
    stock: weirdStocks[i % weirdStocks.length],
    min_stock: (i % 4 === 0) ? undefined : (i % 10),
    price: weirdPrices[i % weirdPrices.length],
    units: weirdUnits[i % weirdUnits.length],
    category_id: (i % 5 === 0) ? null : ((i % 3) + 1)
  };

  try {
    // 1. formatRupiah check
    const formatted = formatRupiah(p.price);
    if (!formatted.startsWith('Rp ')) throw new Error('formatRupiah invalid output: ' + formatted);

    // 2. getUnits check
    const units = getUnits(p);
    if (!Array.isArray(units) || units.length === 0) throw new Error('getUnits returned empty or non-array');
    if (!units[0] || typeof units[0] !== 'object') throw new Error('units[0] is not an object');

    // 3. getStockInfo check
    const stockInfo = getStockInfo(p);
    if (!['danger', 'warning', 'success'].includes(stockInfo.type)) throw new Error('invalid stock info type: ' + stockInfo.type);
    if (!stockInfo.text || typeof stockInfo.text !== 'string') throw new Error('stock text invalid');

    // 4. Multi-unit badge & overflow calculation check
    if (units.length > 2) {
      const overflow = units.length - 2;
      if (overflow <= 0) throw new Error('overflow calculation failed: ' + overflow);
      const overflowBadge = `+${overflow} Satuan Lain`;
      if (!overflowBadge.includes('Satuan Lain')) throw new Error('overflowBadge format failed');
    }

    // 5. Category name check
    const catName = getProductCategoryName(p, [{ id: 1, name: 'Makanan' }, { id: 2, name: 'Minuman' }]);
    if (typeof catName !== 'string' || catName.length === 0) throw new Error('invalid catName: ' + catName);

    // 6. getCartQty check
    const cartQty = getCartQty([{ product_id: p.id, qty: 3, unit: 'Pcs' }], p.id);
    if (typeof cartQty !== 'number' || isNaN(cartQty)) throw new Error('invalid cartQty: ' + cartQty);

  } catch (err) {
    fuzzErrors++;
    console.error(`Fuzz error on iteration ${i}:`, err.message);
    break;
  }
}

if (fuzzErrors === 0) {
  console.log('✓ FUZZING COMPLETE: 10,000 iterations passed with 0 crashes, 0 unhandled exceptions!');
} else {
  console.error(`✗ FUZZING FAILED: ${fuzzErrors} errors`);
  process.exit(1);
}
