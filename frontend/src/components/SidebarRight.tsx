import React from 'react';

export const SidebarRight: React.FC = () => {
  return (
    <aside className="sidebar-right">
      <div className="flex flex-col gap-4 w-full items-center">
        <button className="icon-btn"><i className="fa-solid fa-gear" /></button>
        <button className="icon-btn"><i className="fa-solid fa-bell" /></button>
      </div>

      <div className="flex flex-col gap-5 text-gray-500">
        <a href="#" className="hover:text-brand-red hover:drop-shadow-[0_0_8px_rgba(255,0,60,0.8)] transition-all"><i className="fa-brands fa-discord" /></a>
        <a href="#" className="hover:text-brand-red hover:drop-shadow-[0_0_8px_rgba(255,0,60,0.8)] transition-all"><i className="fa-brands fa-x-twitter" /></a>
      </div>
    </aside>
  );
};