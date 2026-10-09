import { ReceiptPercentIcon } from '@heroicons/react/24/outline';
import { useAuth } from '../../contexts/AuthContext';

export default function FacturacionPage() {
  const { user } = useAuth();
  return (
    <div className="mx-auto max-w-7xl space-y-4">
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Facturación (CFDI)</h1>
      <div className="card p-6 max-w-2xl">
        <div className="flex items-start gap-4">
          <div className="p-3 rounded-xl bg-primary-50"><ReceiptPercentIcon className="w-7 h-7 text-primary-600" /></div>
          <div className="space-y-2">
            <p className="font-semibold text-slate-800">Timbrado vía Facturama</p>
            <p className="text-sm text-slate-600">
              La integración con <b>Facturama</b> (api-lite multi-emisor) está configurada en el backend
              (<code className="text-xs bg-slate-100 px-1 rounded">src/integrations/facturama.service.js</code>),
              con CSD por RFC y entorno <span className="badge-warning">sandbox</span>.
            </p>
            <p className="text-sm text-slate-600">
              Desde una venta se puede generar el CFDI (serie, folio, UUID, XML/PDF), cancelar y emitir
              complemento de pago (PUE/PPD) para ventas a crédito.
            </p>
            <p className="text-xs text-slate-400">Empresa emisora: {user?.tienda?.nombre || '—'}</p>
          </div>
        </div>
      </div>
    </div>
  );
}
