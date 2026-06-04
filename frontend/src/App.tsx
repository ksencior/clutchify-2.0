import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { SidebarLeft } from './components/SidebarLeft';
import { SidebarRight } from './components/SidebarRight';

import { Dashboard } from './pages/Dashboard';
import { LoginPage } from './pages/LoginPage';
import { RegisterPage } from './pages/RegisterPage';
import { TeamsPage } from './pages/TeamsPage';

import { AuthProvider } from './context/AuthProvider';
import { useAuth } from './context/AuthContext';

const ProtectedRoute = ({children}: {children: React.ReactNode}) => {
  const {user, loading} = useAuth();
  const token = localStorage.getItem('token');

  if (loading) {
    return (
      <div className="w-screen h-screen bg-dark-bg flex items-center justify-center text-brand-red font-light tracking-widest uppercase animate-pulse">
        Weryfikacja zawodnika...
      </div>
    );
  }

  if (!token || !user) {
    return <Navigate to="/login" replace />;
  }

  return <>{children}</>
}

const GuestRoute = ({children}: {children: React.ReactNode}) => {
  const {user, loading} = useAuth();
  if (loading) {
    return (
      <div className="w-screen h-screen bg-dark-bg flex items-center justify-center text-brand-red font-light tracking-widest uppercase animate-pulse">
        Weryfikacja zawodnika...
      </div>
    );
  }
  
  // Jeśli użytkownik jest już zalogowany, przekieruj go na stronę główną
  if (user) {
    return <Navigate to="/" replace />;
  }
  
  return <>{children}</>;
}

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path='/login' element={<GuestRoute><LoginPage/></GuestRoute>}/>
          <Route path='/register' element={<GuestRoute><RegisterPage/></GuestRoute>}/>

          <Route 
            path='/'
            element={
              <ProtectedRoute>
                <div className="app-container">
                  <SidebarLeft />
                  <Dashboard />
                  <SidebarRight />
                </div>
              </ProtectedRoute>
            }
          />

          <Route 
            path='/teams'
            element={
              <ProtectedRoute>
                <div className="app-container">
                  <SidebarLeft />
                  <TeamsPage />
                  <SidebarRight />
                </div>
              </ProtectedRoute>
            }
          />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}

export default App;