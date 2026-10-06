import apiClient from './apiClient';

const usuariosService = {
  async listar() {
    const res = await apiClient.get('/auth/usuarios');
    return res.data;
  },
  async crear(data) {
    const res = await apiClient.post('/auth/usuarios', data);
    return res.data;
  },
  async actualizar(id, data) {
    const res = await apiClient.put(`/auth/usuarios/${id}`, data);
    return res.data;
  },
  async desactivar(id) {
    const res = await apiClient.delete(`/auth/usuarios/${id}`);
    return res.data;
  },
  async reactivar(id) {
    const res = await apiClient.put(`/auth/usuarios/${id}`, { is_active: 'Si' });
    return res.data;
  },
  async resetPassword(id, newPassword) {
    const res = await apiClient.post(`/auth/usuarios/${id}/reset-password`, { newPassword });
    return res.data;
  },
};

export default usuariosService;
