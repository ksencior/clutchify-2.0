import { createContext, useContext } from 'react';

export interface PlayerProfile {
  id: number;
  user_id: number;
  team_id: number | null;
  steam_id: string | null;
  avatar: string | null;
  isAdmin: boolean;
  isSpectator: boolean;
  created_at: string;
  updated_at: string;
}

export interface User {
  id: number;
  name: string;
  email: string;
  player?: PlayerProfile;
}

export interface AuthContextType {
  user: User | null;
  loading: boolean;
  logout: () => Promise<void>;
  fetchUser: () => Promise<void>;
}

export const AuthContext = createContext<AuthContextType | undefined>(undefined);

// Hook do współdzielenia funkcji między komponentami - Fast Refresh go teraz pokocha
export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth musi być używane wewnątrz AuthProvider');
  }
  return context;
};