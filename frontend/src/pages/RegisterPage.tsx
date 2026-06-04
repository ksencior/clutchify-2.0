import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import axios from 'axios';
import api from '../services/api';

export const RegisterPage: React.FC = () => {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');

    // Szybka walidacja na froncie, zanim uderzymy do bazy
    if (password !== passwordConfirmation) {
      setError('Podane hasła nie są identyczne.');
      return;
    }

    setLoading(true);

    try {
      // Strzał do Laravela pod /api/register
      const response = await api.post('/register', {
        name,
        email,
        password,
        password_confirmation: passwordConfirmation, // Laravel wymaga dokładnie takiej nazwy klucza dla reguły 'confirmed'
      });

      // Zapisujemy token automatycznie po rejestracji, żeby od razu zalogować gracza
      localStorage.setItem('token', response.data.access_token);
      
      // Przekierowanie na Dashboard
      navigate('/');
    } catch (err) {
      if (axios.isAxiosError(err)) {
        // Jeśli Laravel zwróci błędy walidacji (np. za krótkie hasło lub zajęty email)
        setError(err.response?.data?.message || 'Wystąpił błąd podczas rejestracji.');
      } else {
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
          <h1 className="text-2xl font-light tracking-[4px] uppercase mb-1">
            Clutchify <span className="text-brand-red font-normal">2.0</span>
          </h1>
          <p className="text-xs text-gray-500 uppercase tracking-widest font-light">
            Rejestracja nowego zawodnika
          </p>
        </div>

        {error && <div className="error-message">{error}</div>}

        <form onSubmit={handleSubmit} className="flex flex-col gap-4">
          <div className="form-group">
            <label className="form-label">Nazwa użytkownika (Nick)</label>
            <input
              type="text"
              className="form-input"
              placeholder="np. Ksentaks"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
          </div>

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
              placeholder="Minimum 8 znaków"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </div>

          <div className="form-group">
            <label className="form-label">Powtórz hasło</label>
            <input
              type="password"
              className="form-input"
              placeholder="••••••••"
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
              required
            />
          </div>

          <button type="submit" disabled={loading} className="btn-submit mt-2">
            {loading ? 'Tworzenie konta...' : 'Zarejestruj się'}
          </button>
        </form>

        <div className="text-center text-xs text-gray-500 font-light tracking-wider mt-2">
          Masz już konto?{' '}
          <Link to="/login" className="text-brand-red hover:underline ml-1">
            Zaloguj się
          </Link>
        </div>
      </div>
    </div>
  );
};