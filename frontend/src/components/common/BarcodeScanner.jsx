import { useRef, useCallback } from 'react';

/**
 * Hook de escaneo de código de barras.
 * Usa @zxing/browser (web). El scanner nativo de Capacitor se activa
 * únicamente cuando se compile la app nativa (iOS/Android).
 */
export function useBarcodeScanner() {
  const scan = useCallback(async () => {
    const { BrowserMultiFormatReader } = await import('@zxing/browser');
    return new Promise((resolve, reject) => {
      const reader = new BrowserMultiFormatReader();
      reader.decodeOnceFromVideoDevice(undefined, 'barcode-video')
        .then((result) => resolve(result.text))
        .catch(reject);
    });
  }, []);

  return { scan };
}

/**
 * Visor de cámara para escaneo en web.
 */
export function BarcodeScannerView() {
  const videoRef = useRef(null);

  return (
    <div className="relative">
      <video
        id="barcode-video"
        ref={videoRef}
        className="w-full rounded-xl border border-slate-200"
        style={{ maxHeight: 300 }}
      />
      <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
        <div className="w-48 h-1 bg-red-500/70 rounded-full" />
      </div>
      <p className="text-center text-xs text-slate-400 mt-2">
        Enfoca el código de barras en el recuadro
      </p>
    </div>
  );
}

export default useBarcodeScanner;
