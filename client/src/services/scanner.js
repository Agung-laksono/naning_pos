import { Capacitor } from '@capacitor/core';

export class ScannerService {
  /**
   * Cek apakah platform mendukung hardware scanner native Capacitor
   */
  static isNative() {
    return Capacitor.isNativePlatform();
  }

  /**
   * Request permission kamera untuk scanner
   */
  static async requestPermissions() {
    if (this.isNative()) {
      try {
        const { BarcodeScanner } = await import('@capacitor-mlkit/barcode-scanning');
        const { camera } = await BarcodeScanner.requestPermissions();
        return camera === 'granted';
      } catch (e) {
        console.warn('Native barcode scanner unavailable:', e);
        return false;
      }
    }
    return true;
  }

  /**
   * Mulai scan menggunakan native Google ML Kit Barcode Scanner
   */
  static async startNativeScan(onBarcodeScanned) {
    if (!this.isNative()) return false;
    try {
      const { BarcodeScanner } = await import('@capacitor-mlkit/barcode-scanning');
      const granted = await this.requestPermissions();
      if (!granted) return false;

      // Start scan UI
      const listener = await BarcodeScanner.addListener('barcodeScanned', async (result) => {
        if (result.barcode && result.barcode.rawValue) {
          onBarcodeScanned(result.barcode.rawValue);
          await BarcodeScanner.stopScan();
          await listener.remove();
        }
      });

      await BarcodeScanner.startScan();
      return true;
    } catch (e) {
      console.error('Scan error:', e);
      return false;
    }
  }

  static async stopNativeScan() {
    if (this.isNative()) {
      try {
        const { BarcodeScanner } = await import('@capacitor-mlkit/barcode-scanning');
        await BarcodeScanner.stopScan();
      } catch (e) {
        console.warn(e);
      }
    }
  }
}

