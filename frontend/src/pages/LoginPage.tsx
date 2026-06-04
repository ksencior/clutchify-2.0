import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import axios from 'axios';
import api from '../services/api';

export const LoginPage: React.FC = () => {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const response = await api.post('/login', { email, password });
      localStorage.setItem('token', response.data.access_token);
      navigate('/');
    } catch (err) { // <-- Usuwamy ": any", TS domyślnie traktuje to jako 'unknown'
      
      // Sprawdzamy, czy błąd pochodzi z Axiosa (czyli z backendu Laravel)
      if (axios.isAxiosError(err)) {
        setError(err.response?.data?.message || 'Błędne dane logowania.');
      } else {
        // Obsługa innych, niespodziewanych błędów (np. brak internetu)
        setError('Wystąpił nieoczekiwany błąd połączenia.');
      }

    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-container">
      <div className="login-box">
        <div className="text-center">
          <h1 className="text-2xl font-light tracking-[4px] uppercase mb-1">Clutchify <span className="text-brand-red font-normal">2.0</span></h1>
          <p className="text-xs text-gray-500 uppercase tracking-widest font-light">Panel Zawodnika</p>
        </div>

        {error && <div className="error-message">{error}</div>}

        <form onSubmit={handleSubmit} className="flex flex-col gap-4">
          <div className="form-group">
            <label className="form-label">E-mail</label>
            <input 
              type="email" 
              className="form-input" 
              placeholder="twoj@email.com"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </div>

          <div className="form-group">
            <label className="form-label">Hasło</label>
            <input 
              type="password" 
              className="form-input" 
              placeholder="••••••••"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </div>

          <button type="submit" disabled={loading} className="btn-submit mt-2">
            {loading ? 'Logowanie...' : 'Zaloguj się'}
          </button>
        </form>
        <div className="text-center text-xs text-gray-500 font-light tracking-wider mt-2">
          Nie masz jeszcze konta?{' '}
          <Link to="/register" className="text-brand-red hover:underline ml-1">
            Zarejestruj się
          </Link>
        </div>
      </div>
    </div>
  );
};