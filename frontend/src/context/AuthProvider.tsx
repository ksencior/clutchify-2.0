import React, { useState, useEffect, useCallback } from 'react';
import api from '../services/api';
import { AuthContext } from './AuthContext';
import type { User } from './AuthContext'; // <-- POPRAWKA 1: Import typu wymagany przez verbatimModuleSyntax

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<User | null>(null);
  
  // Stan loading startuje jako TRUE tylko wtedy, gdy mamy token w localStorage
  const [loading, setLoading] = useState(() => {
    return !!localStorage.getItem('token');
  });

  // POPRAWKA 2: Owijamy w useCallback, żeby zapobiec niepotrzebnym re-renderom komponentów konsumujących context
  const fetchUser = useCallback(async () => {
    try {
      const response = await api.get('/user');
      setUser(response.data);
    } catch {
      localStorage.removeItem('token');
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/logout');
    } catch {
      console.error('Błąd podczas unieważniania tokenu na backendzie.');
    } finally {
      localStorage.removeItem('token');
      setUser(null);
      window.location.href = '/login';
    }
  }, []);

  useEffect(() => {
    const token = localStorage.getItem('token');
    if (token) {
      // POPRAWKA 3: setTimeout(..., 0) całkowicie eliminuje błąd "synchronous setState within effect".
      // Sprawia, że fetchUser odpala się asynchronicznie zaraz po zakończeniu cyklu renderowania.
      const timer = setTimeout(() => {
        fetchUser();
      }, 0);
      
      // Czyszczenie timera w przypadku szybkiego odmontowania komponentu
      return () => clearTimeout(timer);
    }
  }, [fetchUser]);

  return (
    <AuthContext.Provider value={{ user, loading, logout, fetchUser }}>
      {children}
    </AuthContext.Provider>
  );
};