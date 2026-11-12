/**
 * Helper pemformatan dan parsing nominal Rupiah (separator titik ribuan Indonesia)
 */

export function parseRupiahInt(val, defaultValue = 0) {
  if (typeof val === 'number') {
    return Number.isFinite(val) ? Math.max(0, Math.round(val)) : defaultValue;
  }
  const digits = String(val ?? '').replace(/\D/g, '');
  if (!digits) return defaultValue;
  const parsed = parseInt(digits, 10);
  return Number.isFinite(parsed) ? parsed : defaultValue;
}

export function formatThousandId(val, emptyWhenZero = false) {
  if (val === null || val === undefined || val === '') return '';
  const num = parseRupiahInt(val, NaN);
  if (!Number.isFinite(num)) return '';
  if (emptyWhenZero && num === 0) return '';
  return num.toLocaleString('id-ID');
}

/**
 * Menghitung string terformat dan posisi kursor berikutnya agar tidak melompat ke akhir saat edit di tengah.
 */
export function processCurrencyInput(event, { maxDigits = 12, max = null, emptyWhenZero = false } = {}) {
  const input = event?.target;
  const rawValue = String(input?.value ?? '');
  const caretPos = input?.selectionStart ?? rawValue.length;

  const digitsBeforeCaret = rawValue.slice(0, caretPos).replace(/\D/g, '').length;
  let digits = rawValue.replace(/\D/g, '');

  if (maxDigits > 0 && digits.length > maxDigits) {
    digits = digits.slice(0, maxDigits);
  }

  if (!digits) {
    return { numericValue: 0, isEmpty: true, formatted: '', nextCaret: 0 };
  }

  let num = parseInt(digits, 10);
  if (!Number.isFinite(num)) num = 0;

  if (max !== null && max !== undefined && Number.isFinite(Number(max))) {
    num = Math.min(num, Math.max(0, Number(max)));
  }

  if (emptyWhenZero && num === 0) {
    return { numericValue: 0, isEmpty: true, formatted: '', nextCaret: 0 };
  }

  const formatted = num.toLocaleString('id-ID');

  // Hitung ulang posisi kursor tepat setelah jumlah digit yang sama
  let seenDigits = 0;
  let nextCaret = 0;
  for (let i = 0; i < formatted.length; i++) {
    if (seenDigits >= digitsBeforeCaret) break;
    nextCaret = i + 1;
    if (/\d/.test(formatted[i])) {
      seenDigits++;
    }
  }

  return { numericValue: num, isEmpty: false, formatted, nextCaret };
}

/**
 * Memproses tombol numpad virtual kasir ('0'-'9', '000', 'DEL', 'C') dengan batas digit maksimum.
 */
export function applyNumpadKey(currentFormatted, key, maxDigits = 12) {
  let digits = String(currentFormatted ?? '').replace(/\D/g, '');

  if (key === 'C') {
    return '0';
  }
  if (key === 'DEL') {
    digits = digits.slice(0, -1);
    if (!digits) return '0';
    return (parseInt(digits, 10) || 0).toLocaleString('id-ID');
  }
  if (key === '000') {
    if (!digits || digits === '0') {
      digits = '1000';
    } else if (digits.length + 3 <= maxDigits) {
      digits += '000';
    }
    return (parseInt(digits, 10) || 0).toLocaleString('id-ID');
  }

  const cleanKey = String(key).replace(/\D/g, '');
  if (!cleanKey) return (parseInt(digits, 10) || 0).toLocaleString('id-ID');

  if (!digits || digits === '0') {
    digits = cleanKey;
  } else if (digits.length + cleanKey.length <= maxDigits) {
    digits += cleanKey;
  }

  return (parseInt(digits, 10) || 0).toLocaleString('id-ID');
}
