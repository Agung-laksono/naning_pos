import { Capacitor } from '@capacitor/core';

export class ScannerService {
  static isNative() {
    return Capacitor.isNativePlatform();
  }

  static async scanBarcode() {
    if (!this.isNative()) {
      return null;
    }

    try {
      const { BarcodeScanner } = await import('@capacitor-mlkit/barcode-scanning');
      const { camera } = await BarcodeScanner.requestPermissions();
      if (camera !== 'granted') {
        alert('Izin kamera ditolak. Harap izinkan akses kamera di pengaturan perangkat.');
        return null;
      }

      // Pastikan Google Barcode Scanner Module terinstal di Android
      const { available } = await BarcodeScanner.isGoogleBarcodeScannerModuleAvailable();
      if (!available) {
        await BarcodeScanner.installGoogleBarcodeScannerModule();
      }

      const { barcodes } = await BarcodeScanner.scan();
      if (barcodes && barcodes.length > 0) {
        return barcodes[0].rawValue;
      }
      return null;
    } catch (e) {
      console.warn('Native scan error:', e);
      return null;
    }
  }
}

