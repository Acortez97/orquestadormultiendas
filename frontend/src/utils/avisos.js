import Swal from 'sweetalert2';

// Aviso discreto abajo de la pantalla (no bloquea): «Guardado», «Pago registrado»…
const toast = Swal.mixin({
  toast: true,
  position: 'bottom',
  showConfirmButton: false,
  timer: 2400,
  timerProgressBar: false,
  customClass: { popup: 'aviso-toast' },
});

/** Confirma una accion que salio bien sin interrumpir al usuario */
export function aviso(titulo, icon = 'success') {
  return toast.fire({ icon, title: titulo });
}

export default aviso;
