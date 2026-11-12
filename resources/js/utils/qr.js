import * as ZXing from 'html5-qrcode/third_party/zxing-js.umd.js';

/**
 * 100% Offline Pure Vector SVG QR Code Generator
 * Menggunakan engine ZXing lokal tanpa butuh internet/API eksternal.
 * 
 * @param {string} text - Konten teks atau URL
 * @param {number} size - Ukuran tampilan pixel CSS (default: 240)
 * @param {number} margin - Margin border putih modul (default: 2)
 * @returns {string} - SVG markup string yang sangat ringan (~2-4 KB)
 */
export function generateQrSvg(text, size = 240, margin = 2) {
  if (!text) return '';
  try {
    const writer = new ZXing.QRCodeWriter();
    const hints = new Map();
    hints.set(ZXing.EncodeHintType.MARGIN, margin);
    // Gunakan 0, 0 agar ZXing menghasilkan modul asli (misal 29x29 atau 33x33)
    const matrix = writer.encode(text, ZXing.BarcodeFormat.QR_CODE, 0, 0, hints);
    const width = matrix.getWidth();
    const height = matrix.getHeight();

    let path = '';
    for (let y = 0; y < height; y++) {
      for (let x = 0; x < width; x++) {
        if (matrix.get(x, y)) {
          path += `M${x},${y}h1v1h-1z `;
        }
      }
    }

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${size}" height="${size}" shape-rendering="crispEdges">
      <rect width="100%" height="100%" fill="#ffffff" rx="1" ry="1"/>
      <path d="${path}" fill="#0f172a"/>
    </svg>`;
  } catch (err) {
    console.error('Offline QR generation error:', err);
    return '';
  }
}

/**
 * Menghasilkan Data URL SVG yang dapat langsung dipasang pada <img :src="...">
 * 100% Offline tanpa internet.
 */
export function generateQrDataUrl(text, size = 240, margin = 2) {
  const svg = generateQrSvg(text, size, margin);
  if (!svg) return '';
  return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}
