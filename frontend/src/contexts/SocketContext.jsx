import { createContext, useContext, useEffect, useRef, useState } from 'react';
import PropTypes from 'prop-types';
import { io } from 'socket.io-client';
import { useAuth } from './AuthContext';

const SocketContext = createContext(null);

export function SocketProvider({ children }) {
  const { user, token } = useAuth();
  const socketRef = useRef(null);
  const [notifications, setNotifications] = useState([]);

  useEffect(() => {
    const socketUrl = import.meta.env.VITE_SOCKET_URL;
    // Sin servidor de tiempo real (ej. despliegue PHP/GoDaddy) no se conecta.
    if (!user || !token || !socketUrl) {
      socketRef.current?.disconnect();
      socketRef.current = null;
      return;
    }

    socketRef.current = io(socketUrl, {
      auth: { userId: user._id },
      reconnectionAttempts: 5,
      reconnectionDelay: 2000,
    });

    const socket = socketRef.current;

    // Eventos LEVOTEK
    socket.on('traspaso_recibido', (data) => {
      addNotification({ tipo: 'traspaso', mensaje: 'Tienes un traspaso por recibir', data });
    });

    socket.on('inventario_actualizado', (data) => {
      addNotification({ tipo: 'inventario', mensaje: 'Inventario actualizado', data });
    });

    socket.on('venta_registrada', (data) => {
      addNotification({ tipo: 'venta', mensaje: 'Venta registrada', data });
    });

    return () => {
      socket.disconnect();
      socketRef.current = null;
    };
  }, [user, token]);

  function addNotification(notif) {
    const id = Date.now();
    setNotifications((prev) => [{ id, leida: false, timestamp: new Date(), ...notif }, ...prev].slice(0, 50));
  }

  function marcarLeida(id) {
    setNotifications((prev) =>
      prev.map((n) => (n.id === id ? { ...n, leida: true } : n))
    );
  }

  function limpiarTodas() {
    setNotifications([]);
  }

  const noLeidas = notifications.filter((n) => !n.leida).length;

  return (
    <SocketContext.Provider value={{ socket: socketRef.current, notifications, noLeidas, marcarLeida, limpiarTodas }}>
      {children}
    </SocketContext.Provider>
  );
}

SocketProvider.propTypes = { children: PropTypes.node.isRequired };

export function useSocket() {
  const ctx = useContext(SocketContext);
  if (!ctx) throw new Error('useSocket must be used within SocketProvider');
  return ctx;
}
