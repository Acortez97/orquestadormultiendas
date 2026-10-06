import { createContext, useContext, useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import { initSyncManager } from '../services/offline/syncManager';

const OfflineContext = createContext(null);

export function OfflineProvider({ children }) {
  const [isOnline, setIsOnline] = useState(navigator.onLine);
  const [pendingCount, setPendingCount] = useState(0);

  useEffect(() => {
    const handleOnline  = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);

    window.addEventListener('online',  handleOnline);
    window.addEventListener('offline', handleOffline);

    // Intentar usar Capacitor Network si está disponible (app nativa)
    let capListener = null;
    import('@capacitor/network').then(({ Network }) => {
      Network.addListener('networkStatusChange', (status) => {
        setIsOnline(status.connected);
      });
      capListener = Network;
    }).catch(() => { /* web — usa window events */ });

    initSyncManager(setPendingCount);

    return () => {
      window.removeEventListener('online',  handleOnline);
      window.removeEventListener('offline', handleOffline);
      capListener?.removeAllListeners();
    };
  }, []);

  return (
    <OfflineContext.Provider value={{ isOnline, pendingCount, setPendingCount }}>
      {children}
    </OfflineContext.Provider>
  );
}

OfflineProvider.propTypes = { children: PropTypes.node.isRequired };

export function useOffline() {
  const ctx = useContext(OfflineContext);
  if (!ctx) throw new Error('useOffline must be used within OfflineProvider');
  return ctx;
}
