/**
 * Menghasilkan inisial 2 huruf dari nama toko untuk badge logo.
 * Contoh: "TOKO ROZAK" -> "TR", "Rozak" -> "RO".
 *
 * @param {string} storeName
 * @param {string} fallback
 * @returns {string}
 */
export function getStoreInitials(storeName, fallback = 'RM') {
  const cleaned = String(storeName || '').trim();
  if (!cleaned) return fallback;

  const words = cleaned.split(/\s+/).filter(Boolean);
  if (words.length >= 2) {
    return `${words[0][0]}${words[1][0]}`.toUpperCase();
  }

  return cleaned.slice(0, 2).toUpperCase();
}

