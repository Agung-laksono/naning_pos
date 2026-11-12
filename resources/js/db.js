import Dexie from 'dexie';

export const db = new Dexie('TPKmartPOS');

db.version(1).stores({
  offline_transactions: '++id, invoice_no, payment_method, grand_total, created_at, synced',
  offline_products: '++id, barcode, name, synced',
  offline_hold_orders: '++id, name, created_at',
});

db.version(2).stores({
  customer_banners: '++id, title, image, created_at',
});

db.version(3).stores({
  customer_banners: '++id, title, image, placement, created_at',
});

db.version(4).stores({
  customer_banners: '++id, title, type, url, image, placement, created_at',
});

