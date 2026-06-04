import axios from 'axios';

const api = axios.create({
  baseURL: 'http://127.0.0.1:8000/api', // Adres Twojego Laravela z php artisan serve
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  }
});

// Interceptor: Przed każdym zapytaniem sprawdź, czy mamy token w localStorage.
// Jeśli tak, doklej go jako nagłówek Bearer Token.
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export default api;