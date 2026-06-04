import React from 'react';

export const Dashboard: React.FC = () => {
  return (
    <div className="dashboard-container">
      
      {/* <section className="panel-section">
        <div className="flex items-center gap-3 mb-4">
          <h2 className="live-badge">Na Żywo</h2>
          <span className="relative flex h-2.5 w-2.5">
            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-brand-red opacity-75"></span>
            <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-brand-red shadow-[0_0_10px_#ff003c]"></span>
          </span>
        </div>
        
        <div className="w-full aspect-video bg-[#0a0a0a] rounded-lg flex items-center justify-center border border-[#161616] relative overflow-hidden group">
          <div className="absolute inset-0 bg-gradient-to-t from-brand-red/5 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none" />
          <p className="text-gray-600 uppercase tracking-[4px] text-xs font-light">Odtwarzacz Twitch Embed</p>
        </div>
      </section> */}

      {/* Nadchodzące mecze */}
      <section className="panel-section">
        <h2 className="text-lg font-light tracking-[3px] uppercase mb-4 text-gray-300">Nadchodzące starcia</h2>
        <div className="flex flex-col w-full divide-y divide-[#151515]">
          <div className="flex justify-between items-center py-4 hover:bg-[#0a0a0a] px-4 transition-colors rounded-xl group">
            <span className="font-light tracking-wider w-[40%] text-right group-hover:text-brand-red transition-colors">Team Haven</span>
            <span className="font-medium text-xl text-brand-red/80 px-4 group-hover:scale-110 transition-transform">VS</span>
            <span className="font-light tracking-wider w-[40%] text-left">Clutch Kings</span>
          </div>
        </div>
      </section>

    </div>
  );
};