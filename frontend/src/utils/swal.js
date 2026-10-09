import SwalBase from 'sweetalert2';

// SweetAlert con los botones en español para toda la app (por defecto vienen en inglés: OK / Cancel).
// Importa siempre este módulo en lugar de 'sweetalert2'. Cada llamada puede seguir poniendo sus propios textos.
const Swal = SwalBase.mixin({
  confirmButtonText: 'Aceptar',
  cancelButtonText: 'Cancelar',
  denyButtonText: 'No',
});

export default Swal;
