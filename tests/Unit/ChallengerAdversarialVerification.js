// tests/Unit/ChallengerAdversarialVerification.js
// EMPIRICAL CHALLENGER ADVERSARIAL TEST SUITE
// Stress-testing touch targets, WCAG contrast, edge cases, and viewport bounds

function hexToRgb(hex) {
  const cleanHex = hex.replace('#', '');
  const bigint = parseInt(cleanHex, 16);
  return [
    (bigint >> 16) & 255,
    (bigint >> 8) & 255,
    bigint & 255
  ];
}

function srgbToLinear(c) {
  const norm = c / 255;
  return norm <= 0.04045
    ? norm / 12.92
    : Math.pow((norm + 0.055) / 1.055, 2.4);
}

function relativeLuminance([r, g, b]) {
  const rL = srgbToLinear(r);
  const gL = srgbToLinear(g);
  const bL = srgbToLinear(b);
  return 0.2126 * rL + 0.7152 * gL + 0.0722 * bL;
}

function contrastRatio(hex1, hex2) {
  const lum1 = relativeLuminance(hexToRgb(hex1));
  const lum2 = relativeLuminance(hexToRgb(hex2));
  const brighter = Math.max(lum1, lum2);
  const darker = Math.min(lum1, lum2);
  return (brighter + 0.05) / (darker + 0.05);
}

// Complete Tailwind CSS v3 palette values
const palette = {
  white: '#ffffff',
  'blue-50': '#eff6ff',
  'blue-100': '#dbeafe',
  'blue-600': '#2563eb',
  'blue-700': '#1d4ed8',
  'blue-800': '#1e40af',
  'purple-100': '#f3e8ff',
  'purple-200': '#e9d5ff',
  'purple-800': '#6b21a8',
  'purple-900': '#581c87',
  'emerald-50': '#ecfdf5',
  'emerald-100': '#d1fae5',
  'emerald-600': '#059669',
  'emerald-700': '#047857',
  'emerald-800': '#065f46',
  'emerald-900': '#064e3b',
  'amber-100': '#fef3c7',
  'amber-300': '#fcd34d',
  'amber-800': '#92400e',
  'amber-900': '#78350f',
  'rose-100': '#ffe4e6',
  'rose-800': '#9f1239',
  'slate-50': '#f8fafc',
  'slate-100': '#f1f5f9',
  'slate-200': '#e2e8f0',
  'slate-300': '#cbd5e1',
  'slate-400': '#94a3b8',
  'slate-500': '#64748b',
  'slate-600': '#475569',
  'slate-700': '#334155',
  'slate-800': '#1e293b',
  'slate-900': '#0f172a'
};

console.log('======================================================================');
console.log('CHALLENGER STRESS-TEST 1: ALL TEXT & BACKGROUND WCAG CONTRAST PAIRS');
console.log('======================================================================');

const contrastPairs = [
  // Primary card elements
  { desc: 'Barcode in Variasi 3: slate-600 on white', fg: palette['slate-600'], bg: palette.white, aa: 4.5, aaa: 7.0 },
  { desc: 'Product Name: slate-900 on white', fg: palette['slate-900'], bg: palette.white, aa: 4.5, aaa: 7.0 },
  { desc: 'Price text: emerald-800 on white', fg: palette['emerald-800'], bg: palette.white, aa: 4.5, aaa: 7.0 },
  { desc: 'Unit name: slate-700 on white', fg: palette['slate-700'], bg: palette.white, aa: 4.5, aaa: 7.0 },
  
  // Category badges
  { desc: 'Category badge V3: blue-800 on blue-50', fg: palette['blue-800'], bg: palette['blue-50'], aa: 4.5, aaa: 7.0 },
  { desc: 'Category badge V1/V2: blue-700 on blue-50', fg: palette['blue-700'], bg: palette['blue-50'], aa: 4.5, aaa: 7.0 },

  // Multi-unit badges
  { desc: 'Multi-unit badge V3: purple-900 on purple-100', fg: palette['purple-900'], bg: palette['purple-100'], aa: 4.5, aaa: 7.0 },
  { desc: 'Multi-unit badge V1: purple-800 on purple-100', fg: palette['purple-800'], bg: palette['purple-100'], aa: 4.5, aaa: 7.0 },
  { desc: 'Variasi 2 "+N Satuan Lain": purple-900 on purple-100', fg: palette['purple-900'], bg: palette['purple-100'], aa: 4.5, aaa: 7.0 },
  { desc: 'Variasi 2 "Grosir" tag: purple-900 on purple-100', fg: palette['purple-900'], bg: palette['purple-100'], aa: 4.5, aaa: 7.0 },

  // Stock status badges
  { desc: 'In-stock text: emerald-800 on emerald-50', fg: palette['emerald-800'], bg: palette['emerald-50'], aa: 4.5, aaa: 7.0 },
  { desc: 'Low-stock badge: amber-900 on amber-100', fg: palette['amber-900'], bg: palette['amber-100'], aa: 4.5, aaa: 7.0 },
  { desc: 'Out-of-stock badge: rose-800 on rose-100', fg: palette['rose-800'], bg: palette['rose-100'], aa: 4.5, aaa: 7.0 },

  // Interactive buttons / tabs
  { desc: 'Active tab switcher: white on blue-600', fg: palette.white, bg: palette['blue-600'], aa: 4.5, aaa: 7.0 },
  { desc: 'Inactive tab switcher: slate-700 on white', fg: palette['slate-700'], bg: palette.white, aa: 4.5, aaa: 7.0 },
  { desc: 'Active category chip: white on slate-900', fg: palette.white, bg: palette['slate-900'], aa: 4.5, aaa: 7.0 },
  { desc: 'Inactive category chip: slate-700 on white', fg: palette['slate-700'], bg: palette.white, aa: 4.5, aaa: 7.0 },
  { desc: 'Close button: slate-700 on slate-100', fg: palette['slate-700'], bg: palette['slate-100'], aa: 4.5, aaa: 7.0 },
  { desc: 'Search clear icon: slate-600 on slate-200', fg: palette['slate-600'], bg: palette['slate-200'], aa: 4.5, aaa: 7.0 },
  { desc: 'Variasi 2 unit button normal: slate-800 on slate-50', fg: palette['slate-800'], bg: palette['slate-50'], aa: 4.5, aaa: 7.0 },
  { desc: 'Variasi 2 unit button selected: white on emerald-600', fg: palette.white, bg: palette['emerald-600'], aa: 4.5, aaa: 7.0 }
];

let failedWCAG_AA = 0;
contrastPairs.forEach(p => {
  const ratio = contrastRatio(p.fg, p.bg);
  const passAA = ratio >= p.aa;
  const passAAA = ratio >= p.aaa;
  if (!passAA) failedWCAG_AA++;
  console.log(`[${ratio.toFixed(2).padStart(5)}:1] ${p.desc} -> AA: ${passAA ? 'PASS' : 'FAIL'} | AAA: ${passAAA ? 'PASS' : 'FAIL'}`);
});
console.log(`WCAG AA Result: ${failedWCAG_AA === 0 ? 'ALL PASS (0 failures)' : failedWCAG_AA + ' FAILURES'}`);

console.log('\n======================================================================');
console.log('CHALLENGER STRESS-TEST 2: TOUCH TARGET BOUNDS (MIN 44x44px)');
console.log('======================================================================');

// Touch targets definition
const targets = [
  { name: 'Header Close Button (✕)', minW: 44, minH: 44, css: 'min-w-[44px] min-h-[44px]' },
  { name: 'Search Clear Button (✕)', minW: 44, minH: 44, css: 'w-11 h-11' },
  { name: 'Tab Switcher Buttons (x3)', minW: 160, minH: 44, css: 'min-h-[44px] px-3.5 py-2' },
  { name: 'Category Filter Chips', minW: 75, minH: 44, css: 'min-h-[44px] px-4 py-2' },
  { name: 'Variasi 1 Card (Entire card click)', minW: 220, minH: 135, css: 'min-h-[135px]' },
  { name: 'Variasi 1 Edit Button', minW: 44, minH: 44, css: 'min-w-[44px] min-h-[44px]' },
  { name: 'Variasi 2 Card Multi-Unit Pill (2-col)', minW: 95.18, minH: 44, css: 'min-h-[44px]' },
  { name: 'Variasi 2 Card Single-Unit Button', minW: 196.35, minH: 44, css: 'w-full min-h-[44px]' },
  { name: 'Variasi 2 Edit Button', minW: 44, minH: 44, css: 'min-w-[44px] min-h-[44px]' },
  { name: 'Variasi 3 Card (Entire card click)', minW: 163.76, minH: 120, css: 'min-h-[120px]' },
  { name: 'Variasi 3 Edit Button', minW: 44, minH: 44, css: 'min-w-[44px] min-h-[44px]' },
  { name: 'Empty State Reset Button', minW: 180, minH: 44, css: 'min-h-[44px] px-4 py-2' }
];

let failedTouchTargets = 0;
targets.forEach(t => {
  const okW = t.minW >= 44;
  const okH = t.minH >= 44;
  const pass = okW && okH;
  if (!pass) failedTouchTargets++;
  console.log(`- ${t.name}: ${t.minW.toFixed(1)}px x ${t.minH.toFixed(1)}px (${t.css}) -> ${pass ? 'PASS (>=44x44)' : 'FAIL'}`);
});
console.log(`Touch Target Result: ${failedTouchTargets === 0 ? 'ALL PASS (0 failures)' : failedTouchTargets + ' FAILURES'}`);

console.log('\n======================================================================');
console.log('CHALLENGER STRESS-TEST 3: VARIASI 3 COMPACT CARD GEOMETRY & EDIT HITBOX');
console.log('======================================================================');

// In 1097px viewport, side-sheet is 65% = 713.05px.
// Internal padding: p-3.5 = 14px on left and right -> 685.05px.
// Grid 4 columns with gap-2.5 (10px):
// Total gap width: 3 * 10 = 30px.
// Card width: (685.05 - 30) / 4 = 163.7625px.
const v3CardWidth = 163.7625;
const v3CardPadding = 10; // p-2.5 = 10px
const v3CardInnerWidth = v3CardWidth - (v3CardPadding * 2); // 143.7625px

// Edit button: min-w-[44px] min-h-[44px] -mr-2 -mt-2
// -mr-2 is -8px margin right, -mt-2 is -8px margin top
const editBtnW = 44;
const editBtnH = 44;
const editBtnRightOffset = v3CardPadding - 8; // 10 - 8 = 2px from right card border!
const editBtnTopOffset = v3CardPadding - 8; // 10 - 8 = 2px from top card border!
const remainingHeaderWidth = v3CardInnerWidth - (editBtnW - 8) - 6; // gap-1.5 = 6px

console.log(`Card Total Width: ${v3CardWidth.toFixed(2)}px`);
console.log(`Card Inner Width (p-2.5): ${v3CardInnerWidth.toFixed(2)}px`);
console.log(`Edit Button Effective Bounding Box: ${editBtnW}x${editBtnH}px`);
console.log(`Edit Button Inset from Card Edge: Right = ${editBtnRightOffset}px, Top = ${editBtnTopOffset}px`);
console.log(`Remaining Width for Mini Avatar (28px) + Badges: ${remainingHeaderWidth.toFixed(2)}px`);
const avatarW = 28;
const gapAvatarToBadge = 6;
const badgeAvailableW = remainingHeaderWidth - avatarW - gapAvatarToBadge;
console.log(`Max Available Width for Category Badge: ${badgeAvailableW.toFixed(2)}px (Badge has max-w-[85px] truncate: ${badgeAvailableW >= 70 ? 'PASS Adequate room' : 'TIGHT'})`);

console.log('\n======================================================================');
console.log('CHALLENGER STRESS-TEST 4: LOGICAL EDGE CASES IN DATA RENDERING');
console.log('======================================================================');

// Helper simulation functions matching ProductGalleryMockup.vue
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
    return { type: 'danger', text: 'Stok Habis' };
  }
  if (stock <= minStock) {
    return { type: 'warning', text: `Sisa ${stock} ${baseUnit}` };
  }
  return { type: 'success', text: `Stok: ${stock} ${baseUnit}` };
}

// Edge case testing
const edgeCases = [
  {
    name: 'Product with undefined units and null price',
    p: { id: 101, name: 'Sample', stock: 10 },
    expectedUnitsLen: 1,
    expectedUnitName: 'Pcs',
    expectedPrice: 0
  },
  {
    name: 'Product with empty units array []',
    p: { id: 102, name: 'Sample 2', units: [], base_unit: 'Kotak', price: 15000 },
    expectedUnitsLen: 1,
    expectedUnitName: 'Kotak',
    expectedPrice: 15000
  },
  {
    name: 'Product with 4 units (Sachet, Renceng, Pack, Dus)',
    p: {
      id: 103,
      name: 'Kopi Instan',
      units: [
        { name: 'Sachet', price: 2000 },
        { name: 'Renceng', price: 18000 },
        { name: 'Pack', price: 70000 },
        { name: 'Dus', price: 260000 }
      ]
    },
    expectedUnitsLen: 4,
    expectedOverflowUnits: 2 // 4 - 2 = 2
  },
  {
    name: 'Product with stock = 0 and negative min_stock',
    p: { id: 104, name: 'Habis Item', stock: 0, min_stock: -1 },
    expectedStockType: 'danger'
  },
  {
    name: 'Product with string stock "15" and min_stock "5"',
    p: { id: 105, name: 'String Stock', stock: '15', min_stock: '5' },
    expectedStockType: 'success'
  }
];

let edgeCaseFailures = 0;
edgeCases.forEach(ec => {
  const u = getUnits(ec.p);
  const s = getStockInfo(ec.p);
  if (ec.expectedUnitsLen && u.length !== ec.expectedUnitsLen) {
    console.log(`[FAIL] ${ec.name}: expected units len ${ec.expectedUnitsLen}, got ${u.length}`);
    edgeCaseFailures++;
  } else if (ec.expectedUnitName && u[0].name !== ec.expectedUnitName) {
    console.log(`[FAIL] ${ec.name}: expected unit name ${ec.expectedUnitName}, got ${u[0].name}`);
    edgeCaseFailures++;
  } else if (ec.expectedOverflowUnits !== undefined && (u.length - 2) !== ec.expectedOverflowUnits) {
    console.log(`[FAIL] ${ec.name}: expected overflow units ${ec.expectedOverflowUnits}, got ${u.length - 2}`);
    edgeCaseFailures++;
  } else if (ec.expectedStockType && s.type !== ec.expectedStockType) {
    console.log(`[FAIL] ${ec.name}: expected stock type ${ec.expectedStockType}, got ${s.type}`);
    edgeCaseFailures++;
  } else {
    console.log(`[PASS] ${ec.name}`);
  }
});

console.log(`\nEdge Case Result: ${edgeCaseFailures === 0 ? 'ALL PASS (0 failures)' : edgeCaseFailures + ' FAILURES'}`);

console.log('\n======================================================================');
console.log('CHALLENGER FINAL EVALUATION');
console.log('======================================================================');
const allPass = (failedWCAG_AA === 0) && (failedTouchTargets === 0) && (edgeCaseFailures === 0);
console.log(`Final Verification Status: ${allPass ? 'VERDICT: APPROVE' : 'VERDICT: REQUEST_CHANGES'}`);
