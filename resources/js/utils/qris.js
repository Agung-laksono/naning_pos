/**
 * QRIS EMVCo Dynamic Amount Injector (Standar ASPI / Bank Indonesia)
 * Mem-parse TLV (Tag-Length-Value) QRIS Statis toko (010211),
 * menyuntikkan nominal tagihan dinamis (Tag 54), mengubah menjadi 010212 (Dinamis),
 * dan menghitung ulang checksum CRC16-CCITT (Tag 63).
 */

export function crc16Ccitt(str) {
  let crc = 0xFFFF;
  for (let i = 0; i < str.length; i++) {
    crc ^= str.charCodeAt(i) << 8;
    for (let j = 0; j < 8; j++) {
      if ((crc & 0x8000) !== 0) {
        crc = ((crc << 1) ^ 0x1021) & 0xFFFF;
      } else {
        crc = (crc << 1) & 0xFFFF;
      }
    }
  }
  return crc.toString(16).toUpperCase().padStart(4, '0');
}

export function parseTLV(payload) {
  const tags = [];
  let i = 0;
  while (i < payload.length) {
    const id = payload.slice(i, i + 2);
    const len = parseInt(payload.slice(i + 2, i + 4), 10);
    if (isNaN(len)) break;
    const val = payload.slice(i + 4, i + 4 + len);
    tags.push({ id, len, val });
    i += 4 + len;
  }
  return tags;
}

/**
 * Generate QRIS Dinamis dari payload QRIS Statis toko
 * @param {string} rawPayload - String QRIS statis toko
 * @param {number} amount - Nominal tagihan
 * @returns {string} - Payload QRIS Dinamis lengkap dengan CRC16 valid
 */
export function generateDynamicQris(rawPayload, amount) {
  let qris = (rawPayload || '').trim();

  // Template bawaan untuk Rozak Mart jika toko belum menyalin string QRIS fisiknya
  if (!qris.startsWith('000201')) {
    qris = '00020101021126630014ID.GO.IDC.WWW01189360000000000000000215ROZAKMART00010303UMI5204541153033605802ID5910ROZAK MART6007JAKARTA61051234062070703A016304';
  }

  const tags = parseTLV(qris);
  const out = [];
  let tag54Inserted = false;
  const amtStr = String(Math.round(amount));

  for (const t of tags) {
    if (t.id === '63') continue; // CRC dihitung ulang di akhir

    if (t.id === '01') {
      out.push({ id: '01', val: '12' }); // Dynamic
      continue;
    }

    if (t.id === '54') {
      out.push({ id: '54', val: amtStr });
      tag54Inserted = true;
      continue;
    }

    if (t.id === '58' && !tag54Inserted) {
      out.push({ id: '54', val: amtStr });
      tag54Inserted = true;
    }

    out.push({ id: t.id, val: t.val });
  }

  if (!tag54Inserted) {
    out.push({ id: '54', val: amtStr });
  }

  let serialized = '';
  for (const t of out) {
    const len = String(t.val.length).padStart(2, '0');
    serialized += t.id + len + t.val;
  }
  serialized += '6304';
  const crc = crc16Ccitt(serialized);
  return serialized + crc;
}
