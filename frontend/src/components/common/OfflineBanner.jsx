import { WifiIcon } from '@heroicons/react/24/outline';
import { useOffline } from '../../contexts/OfflineContext';

export function OfflineBanner() {
  const { pendingCount } = useOffline();

  return (
    <div className="fixed top-0 left-0 right-0 z-[100] bg-warning-600 text-white px-4 py-2 flex items-center justify-center gap-2 text-sm font-medium">
      <WifiIcon className="w-4 h-4" />
      <span>Sin conexión — modo offline</span>
      {pendingCount > 0 && (
        <span className="ml-2 bg-white/20 px-2 py-0.5 rounded-full text-xs">
          {pendingCount} {pendingCount === 1 ? 'operación pendiente' : 'operaciones pendientes'}
        </span>
      )}
    </div>
  );
}

export default OfflineBanner;
