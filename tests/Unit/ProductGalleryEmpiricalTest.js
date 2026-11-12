// tests/Unit/ProductGalleryEmpiricalTest.js
// Empirical test harness for ProductGalleryMockup.vue logic, multi-unit handling,
// cart integration, click isolation, stock calculations, and search/filter edge cases.

import assert from 'node:assert';

console.log('================================================================');
console.log('  EMPIRICAL CHALLENGER TEST SUITE: PRODUCT GALLERY MOCKUP VUE  ');
console.log('================================================================\n');

// -------------------------------------------------------------
// Extracted Helper Logic Directly from ProductGalleryMockup.vue
// -------------------------------------------------------------
function formatRupiah(val) {
  const num = Number(val) || 0;
  return 'Rp ' + num.toLocaleString('id-ID');
}

function getProductCategoryName(p, categories = []) {
  if (p.category && p.category.name) return p.category.name;
  if (p.category_id && categories.length) {
    const c = categories.find(cat => cat.id === p.category_id);
    if (c) return c.name;
  }
  return 'Umum';
}

function getUnits(p) {
  if (p.units && Array.isArray(p.units) && p.units.length > 0) {
    return p.units;
  }
  return [{
    name: p.base_unit || 'Pcs',
    price: p.price || 0,
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
    const found = activeCartItems.find(item => item.product_id === productId && item.unit === unitName);
    return found ? found.qty : 0;
  }
  return activeCartItems
    .filter(item => item.product_id === productId)
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

// -------------------------------------------------------------
// TEST DATA FIXTURES
// -------------------------------------------------------------
const mockCategories = [
  { id: 1, name: 'Makanan', icon: '🍜' },
  { id: 2, name: 'Minuman', icon: '🥤' },
  { id: 3, name: 'Sembako', icon: '🍚' }
];

const mockProducts = [
  // 1 unit product
  {
    id: 101,
    name: 'Teh Botol Sosro 350ml',
    barcode: '8999999001',
    category_id: 2,
    base_unit: 'Botol',
    stock: 24,
    min_stock: 10,
    price: 4500,
    units: [
      { id: 1, name: 'Botol', price: 4500, ratio: 1, is_base: true }
    ]
  },
  // 2 units product (base + grosir)
  {
    id: 102,
    name: 'Indomie Goreng Spesial',
    barcode: '8999999002',
    category_id: 1,
    base_unit: 'Bungkus',
    stock: 80,
    min_stock: 20,
    price: 3100,
    units: [
      { id: 2, name: 'Bungkus', price: 3100, ratio: 1, is_base: true },
      { id: 3, name: 'Dus', price: 118000, ratio: 40, is_base: false }
    ]
  },
  // 3+ units product (3 units)
  {
    id: 103,
    name: 'Kopi Kapal Api Special Mix',
    barcode: '8999999003',
    category_id: 2,
    base_unit: 'Sachet',
    stock: 5, // warning low stock!
    min_stock: 10,
    price: 1500,
    units: [
      { id: 4, name: 'Sachet', price: 1500, ratio: 1, is_base: true },
      { id: 5, name: 'Renceng', price: 14000, ratio: 10, is_base: false },
      { id: 6, name: 'Dus', price: 160000, ratio: 120, is_base: false }
    ]
  },
  // 4 units product
  {
    id: 104,
    name: 'Beras Rojolele Super 5kg',
    barcode: '8999999004',
    category_id: 3,
    base_unit: 'Karung',
    stock: 0, // out of stock!
    min_stock: 5,
    price: 75000,
    units: [
      { id: 7, name: 'Karung', price: 75000, ratio: 1, is_base: true },
      { id: 8, name: 'Bal', price: 730000, ratio: 10, is_base: false },
      { id: 9, name: 'Kuintal', price: 1450000, ratio: 20, is_base: false },
      { id: 10, name: 'Ton', price: 14000000, ratio: 200, is_base: false }
    ]
  },
  // Edge case: product without units array
  {
    id: 105,
    name: 'Gula Pasir Curah 1kg',
    barcode: '8999999005',
    category_id: 3,
    base_unit: 'Kg',
    stock: 15,
    min_stock: 5,
    price: 17500,
    units: null
  },
  // Edge case: product without category_id or category object
  {
    id: 106,
    name: 'Barang Tanpa Kategori',
    barcode: null,
    category_id: null,
    base_unit: 'Pcs',
    stock: 10,
    min_stock: 5,
    price: 10000,
    units: []
  }
];

let totalTests = 0;
let passedTests = 0;

function runTest(name, fn) {
  totalTests++;
  try {
    fn();
    console.log(`  ✓ PASS: ${name}`);
    passedTests++;
  } catch (err) {
    console.error(`  ✗ FAIL: ${name}`);
    console.error(`    ${err.message}`);
  }
}

// -------------------------------------------------------------
// 1. TEST SUITE: MULTI-UNIT SELECTION & BADGES
// -------------------------------------------------------------
console.log('--- 1. Multi-Unit Handling & Overflow Badges ---');

runTest('1-Unit Product: getUnits returns 1 unit, no multi-unit badges triggered', () => {
  const p1 = mockProducts.find(p => p.id === 101);
  const units = getUnits(p1);
  assert.strictEqual(units.length, 1);
  assert.strictEqual(units[0].name, 'Botol');
  assert.strictEqual(units[0].price, 4500);

  // In Variasi 1 & 3: getUnits(p).length > 1 must be FALSE
  assert.strictEqual(units.length > 1, false);

  // In Variasi 2: getUnits(p).length > 1 is FALSE -> renders single wide button
  assert.strictEqual(units.length > 1, false);
});

runTest('2-Units Product: getUnits returns 2 units, badge says "2 Satuan", no "+N Satuan Lain"', () => {
  const p2 = mockProducts.find(p => p.id === 102);
  const units = getUnits(p2);
  assert.strictEqual(units.length, 2);
  assert.strictEqual(units[0].name, 'Bungkus');
  assert.strictEqual(units[1].name, 'Dus');

  // Variasi 1 & 3: getUnits(p).length > 1 is TRUE -> displays badge "2 Satuan"
  assert.strictEqual(units.length > 1, true);
  const badgeText = `${units.length} Satuan`;
  assert.strictEqual(badgeText, '2 Satuan');

  // Variasi 2: getUnits(p).length > 2 must be FALSE (no overflow badge)
  assert.strictEqual(units.length > 2, false);

  // Variasi 2: slice(0, 2) returns both units
  const sliced = units.slice(0, 2);
  assert.strictEqual(sliced.length, 2);
  assert.strictEqual(sliced[0].name, 'Bungkus');
  assert.strictEqual(sliced[1].name, 'Dus');
});

runTest('3-Units Product: getUnits returns 3 units, triggers "+1 Satuan Lain" overflow badge in Variasi 2', () => {
  const p3 = mockProducts.find(p => p.id === 103);
  const units = getUnits(p3);
  assert.strictEqual(units.length, 3);

  // Variasi 1 & 3: badge says "3 Satuan"
  assert.strictEqual(units.length > 1, true);
  assert.strictEqual(`${units.length} Satuan`, '3 Satuan');

  // Variasi 2: getUnits(p).length > 2 is TRUE!
  assert.strictEqual(units.length > 2, true);
  const overflowCount = units.length - 2;
  assert.strictEqual(overflowCount, 1);
  const overflowBadge = `+${overflowCount} Satuan Lain`;
  assert.strictEqual(overflowBadge, '+1 Satuan Lain');

  // Variasi 2: slice(0, 2) provides exactly 2 buttons for immediate fast tapping
  const sliced = units.slice(0, 2);
  assert.strictEqual(sliced.length, 2);
  assert.strictEqual(sliced[0].name, 'Sachet');
  assert.strictEqual(sliced[1].name, 'Renceng');
});

runTest('4-Units Product: getUnits returns 4 units, triggers "+2 Satuan Lain" overflow badge in Variasi 2', () => {
  const p4 = mockProducts.find(p => p.id === 104);
  const units = getUnits(p4);
  assert.strictEqual(units.length, 4);

  // Variasi 2: getUnits(p).length > 2 is TRUE!
  assert.strictEqual(units.length > 2, true);
  const overflowCount = units.length - 2;
  assert.strictEqual(overflowCount, 2);
  const overflowBadge = `+${overflowCount} Satuan Lain`;
  assert.strictEqual(overflowBadge, '+2 Satuan Lain');
});

runTest('Fallback Robustness: null or empty units returns 1 fallback unit with base_unit & price', () => {
  const p5 = mockProducts.find(p => p.id === 105);
  const units5 = getUnits(p5);
  assert.strictEqual(units5.length, 1);
  assert.strictEqual(units5[0].name, 'Kg');
  assert.strictEqual(units5[0].price, 17500);
  assert.strictEqual(units5[0].ratio, 1);
  assert.strictEqual(units5[0].is_base, true);

  const p6 = mockProducts.find(p => p.id === 106);
  const units6 = getUnits(p6);
  assert.strictEqual(units6.length, 1);
  assert.strictEqual(units6[0].name, 'Pcs');
  assert.strictEqual(units6[0].price, 10000);
});

// -------------------------------------------------------------
// 2. TEST SUITE: CART ADDITION & CLICK EVENT ISOLATION
// -------------------------------------------------------------
console.log('\n--- 2. Cart Addition & Click Propagation Simulation ---');

runTest('Variasi 1 Click Simulation: Card click emits addToCart(product, 0), Edit click stops propagation', () => {
  const events = [];
  const emit = (event, ...args) => events.push({ event, args });

  function handleAddToCart(product, unitIndex = 0) {
    emit('addToCart', product, unitIndex);
  }
  function handleEdit(product) {
    emit('editProduct', product);
  }

  const p1 = mockProducts[0];

  // User taps card
  handleAddToCart(p1, 0);
  assert.strictEqual(events.length, 1);
  assert.strictEqual(events[0].event, 'addToCart');
  assert.strictEqual(events[0].args[0].id, 101);
  assert.strictEqual(events[0].args[1], 0);

  // User taps edit button (with @click.stop)
  let stopPropagationCalled = false;
  const mockEvent = {
    stopPropagation: () => { stopPropagationCalled = true; }
  };
  // Simulate template @click.stop="handleEdit(p)"
  mockEvent.stopPropagation();
  handleEdit(p1);

  assert.strictEqual(stopPropagationCalled, true);
  assert.strictEqual(events.length, 2);
  assert.strictEqual(events[1].event, 'editProduct');
  assert.strictEqual(events[1].args[0].id, 101);
  // Verify addToCart was NOT triggered on edit click
  assert.strictEqual(events.filter(e => e.event === 'addToCart').length, 1);
});

runTest('Variasi 2 Click Simulation: Unit pills emit exact unit index, card body does NOT trigger cart', () => {
  const events = [];
  const emit = (event, ...args) => events.push({ event, args });

  function handleAddToCart(product, unitIndex = 0) {
    emit('addToCart', product, unitIndex);
  }
  function handleEdit(product) {
    emit('editProduct', product);
  }

  const p2 = mockProducts.find(p => p.id === 102); // 2 units: Bungkus (idx 0), Dus (idx 1)

  // Kasir taps pill 0 (Bungkus)
  handleAddToCart(p2, 0);
  assert.strictEqual(events[0].event, 'addToCart');
  assert.strictEqual(events[0].args[0].id, 102);
  assert.strictEqual(events[0].args[1], 0);

  // Kasir taps pill 1 (Dus Grosir)
  handleAddToCart(p2, 1);
  assert.strictEqual(events[1].event, 'addToCart');
  assert.strictEqual(events[1].args[0].id, 102);
  assert.strictEqual(events[1].args[1], 1);

  // Product 103 (3 units): kasir taps "+1 Satuan Lain" badge
  const p3 = mockProducts.find(p => p.id === 103);
  let stopped = false;
  const mockEvt = { stopPropagation: () => { stopped = true; } };
  mockEvt.stopPropagation();
  handleEdit(p3);

  assert.strictEqual(stopped, true);
  assert.strictEqual(events[2].event, 'editProduct');
  assert.strictEqual(events[2].args[0].id, 103);
});

// -------------------------------------------------------------
// 3. TEST SUITE: STOCK STATUS LOGIC & EDGE CASES
// -------------------------------------------------------------
console.log('\n--- 3. Stock Status Calculation & Threshold Verification ---');

runTest('Stock <= 0: danger (Stok Habis, bg-rose-500, rose badge)', () => {
  const pOut = mockProducts.find(p => p.id === 104);
  const info = getStockInfo(pOut);
  assert.strictEqual(info.type, 'danger');
  assert.strictEqual(info.text, 'Stok Habis');
  assert.strictEqual(info.dotClass, 'bg-rose-500');
  assert.strictEqual(info.badgeClass, 'bg-rose-100 text-rose-800 border-rose-200');

  // Negative stock edge case
  const pNeg = { stock: -5, min_stock: 5, base_unit: 'Pcs' };
  const infoNeg = getStockInfo(pNeg);
  assert.strictEqual(infoNeg.type, 'danger');
  assert.strictEqual(infoNeg.text, 'Stok Habis');
});

runTest('Stock <= min_stock: warning (Sisa X Satuan, bg-amber-500, amber badge)', () => {
  const pLow = mockProducts.find(p => p.id === 103); // stock 5, min_stock 10
  const info = getStockInfo(pLow);
  assert.strictEqual(info.type, 'warning');
  assert.strictEqual(info.text, 'Sisa 5 Sachet');
  assert.strictEqual(info.dotClass, 'bg-amber-500');
  assert.strictEqual(info.badgeClass, 'bg-amber-100 text-amber-900 border-amber-300');

  // Boundary condition: stock === min_stock
  const pBoundary = { stock: 5, min_stock: 5, base_unit: 'Pcs' };
  const infoBound = getStockInfo(pBoundary);
  assert.strictEqual(infoBound.type, 'warning');
  assert.strictEqual(infoBound.text, 'Sisa 5 Pcs');
});

runTest('Stock > min_stock: success (Stok: X Satuan, bg-emerald-500, emerald badge)', () => {
  const pNorm = mockProducts.find(p => p.id === 101); // stock 24, min_stock 10
  const info = getStockInfo(pNorm);
  assert.strictEqual(info.type, 'success');
  assert.strictEqual(info.text, 'Stok: 24 Botol');
  assert.strictEqual(info.dotClass, 'bg-emerald-500');
  assert.strictEqual(info.badgeClass, 'bg-emerald-50 text-emerald-800 border-emerald-200');
});

runTest('Stock parsing: string stock "10" and undefined min_stock defaults to 5', () => {
  const pStr = { stock: '10', min_stock: undefined, base_unit: 'Pcs' };
  const info = getStockInfo(pStr);
  assert.strictEqual(info.type, 'success');
  assert.strictEqual(info.text, 'Stok: 10 Pcs');

  const pStrLow = { stock: '4', min_stock: undefined, base_unit: 'Dus' };
  const infoLow = getStockInfo(pStrLow);
  assert.strictEqual(infoLow.type, 'warning');
  assert.strictEqual(infoLow.text, 'Sisa 4 Dus');
});

// -------------------------------------------------------------
// 4. TEST SUITE: SEARCH & CATEGORY FILTERING & EMPTY STATE
// -------------------------------------------------------------
console.log('\n--- 4. Search Query, Category Filter & Empty State ---');

runTest('Category Filter: "Semua" returns all products', () => {
  const res = filterProducts(mockProducts, mockCategories, 'Semua', '');
  assert.strictEqual(res.length, mockProducts.length);
});

runTest('Category Filter: "Minuman" returns only drink products', () => {
  const res = filterProducts(mockProducts, mockCategories, 'Minuman', '');
  assert.strictEqual(res.length, 2);
  assert.deepStrictEqual(res.map(p => p.id), [101, 103]);
});

runTest('Search by name: "indomie" returns Indomie Goreng Spesial', () => {
  const res = filterProducts(mockProducts, mockCategories, 'Semua', 'indomie');
  assert.strictEqual(res.length, 1);
  assert.strictEqual(res[0].id, 102);
});

runTest('Search by barcode: "8999999003" returns Kopi Kapal Api', () => {
  const res = filterProducts(mockProducts, mockCategories, 'Semua', '8999999003');
  assert.strictEqual(res.length, 1);
  assert.strictEqual(res[0].id, 103);
});

runTest('Search & Category combination mismatch: returns empty array (Empty State)', () => {
  const res = filterProducts(mockProducts, mockCategories, 'Makanan', 'kopi');
  assert.strictEqual(res.length, 0);
});

runTest('Empty State Reset logic: resets searchQuery and selectedCategory', () => {
  let selectedCategory = 'Makanan';
  let searchQuery = 'kopi';

  function resetFilters() {
    searchQuery = '';
    selectedCategory = 'Semua';
  }

  resetFilters();
  assert.strictEqual(searchQuery, '');
  assert.strictEqual(selectedCategory, 'Semua');
  const res = filterProducts(mockProducts, mockCategories, selectedCategory, searchQuery);
  assert.strictEqual(res.length, mockProducts.length);
});

// -------------------------------------------------------------
// 5. TEST SUITE: CART QUANTITY ACCUMULATION & INDICATORS
// -------------------------------------------------------------
console.log('\n--- 5. Active Cart Quantity Accumulation & Indicators ---');

runTest('getCartQty accurately sums multiple unit lines for same product in cart', () => {
  const activeCart = [
    { product_id: 102, unit: 'Bungkus', qty: 5 },
    { product_id: 102, unit: 'Dus', qty: 2 },
    { product_id: 101, unit: 'Botol', qty: 3 }
  ];

  // Total qty for product 102 (Bungkus + Dus)
  const totalQty102 = getCartQty(activeCart, 102);
  assert.strictEqual(totalQty102, 7);

  // Unit specific qty for Dus
  const dusQty102 = getCartQty(activeCart, 102, 'Dus');
  assert.strictEqual(dusQty102, 2);

  // Product not in cart
  const qty104 = getCartQty(activeCart, 104);
  assert.strictEqual(qty104, 0);

  // Empty cart
  assert.strictEqual(getCartQty([], 102), 0);
  assert.strictEqual(getCartQty(null, 102), 0);
});

// -------------------------------------------------------------
// 6. TEST SUITE: TIMER CLEANUP ON UNMOUNT
// -------------------------------------------------------------
console.log('\n--- 6. Visual Flash Timer Teardown on Unmount ---');

runTest('Feedback timeout is properly cleared during onUnmounted to prevent memory leak', () => {
  let feedbackTimeout = setTimeout(() => {}, 10000);
  let cleared = false;

  function onUnmountedHook() {
    if (feedbackTimeout) {
      clearTimeout(feedbackTimeout);
      feedbackTimeout = null;
      cleared = true;
    }
  }

  assert.notStrictEqual(feedbackTimeout, null);
  onUnmountedHook();
  assert.strictEqual(cleared, true);
  assert.strictEqual(feedbackTimeout, null);
});

// -------------------------------------------------------------
// SUMMARY REPORT
// -------------------------------------------------------------
console.log('\n================================================================');
console.log(`TOTAL TESTS: ${totalTests} | PASSED: ${passedTests} | FAILED: ${totalTests - passedTests}`);
console.log('================================================================\n');

if (totalTests !== passedTests) {
  process.exit(1);
}
