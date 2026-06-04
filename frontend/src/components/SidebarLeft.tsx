import React from 'react';
import Logo from '../assets/logo.png'
import { useAuth } from '../context/AuthContext.ts';

export const SidebarLeft: React.FC = () => {
    const { user, logout } = useAuth();
  return (
    <aside className="sidebar-left">
      <div className="w-full">
        {/* Logo */}
        <div className="w-[45%] mb-8 p-1 cursor-pointer">
          <img src={Logo} alt="Logo" className="w-full h-auto drop-shadow-[0_0_15px_rgba(255,0,60,0.4)]" />
        </div>

        {/* Menu na wzór image_3de7dc.png */}
        <nav className="flex flex-col gap-3">
          <a href="#" className="nav-link nav-link-active">
            <span>Home</span>
            <i className="fa-solid fa-house text-sm" />
          </a>
          <a href="#" className="nav-link">
            <span>Turnieje</span>
            <i className="fa-solid fa-trophy text-sm" />
          </a>
          <a href="#" className="nav-link">
            <span>Drużyny</span>
            <i className="fa-solid fa-users text-sm" />
          </a>
        </nav>
      </div>

      {/* Profil dolny */}
      <div className="w-full flex items-center gap-3 p-2 border-t border-[#1a1a1a] pt-4">
        <img 
          src="https://avatars.githubusercontent.com/u/1?v=4" 
          alt="Avatar" 
          className="w-9 h-9 rounded-full border border-brand-red/50 shadow-glow-red-sm hover:scale-105 transition-transform duration-200 cursor-pointer"
        />
        <span className="text-xs font-light tracking-widest text-gray-400 uppercase truncate">{user?.name}</span>
      </div>
      <div className="px-4">
          <button 
            onClick={logout}
            className="w-full border border-brand-red/20 text-brand-red/80 hover:text-white hover:bg-brand-red hover:border-brand-red py-2.5 px-4 rounded-lg text-xs uppercase tracking-widest font-medium transition-all duration-200 cursor-pointer shadow-glow-red-sm/10 hover:scale-[1.02]"
          >
            Wyloguj się
          </button>
        </div>
    </aside>
  );
};