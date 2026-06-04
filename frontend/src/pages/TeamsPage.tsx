import React, { useState, useEffect } from 'react';
import { useAuth } from '../context/AuthContext';
import api from '../services/api';
import axios from 'axios';

// Interfejs zgodny z odpowiedzą z naszego kontrolera w Laravelu
interface TeamData {
  id: number;
  name: string;
  tag: string;
  players_count: number;
}

export const TeamsPage: React.FC = () => {
  const { user, fetchUser } = useAuth();
  
  const [teams, setTeams] = useState<TeamData[]>([]);
  const [loading, setLoading] = useState(true);
  
  const [teamName, setTeamName] = useState('');
  const [teamTag, setTeamTag] = useState('');
  const [error, setError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Pobieranie drużyn po załadowaniu strony
  const fetchTeams = async () => {
    try {
      const response = await api.get('/teams');
      setTeams(response.data);
    } catch (err) {
      console.error('Błąd pobierania drużyn', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchTeams();
  }, []);

  // Obsługa tworzenia nowej drużyny
  const handleCreateTeam = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setIsSubmitting(true);

    try {
      await api.post('/teams', {
        name: teamName,
        tag: teamTag,
      });

      // Sukces!
      setTeamName('');
      setTeamTag('');
      
      // 1. Odświeżamy listę drużyn
      fetchTeams();
      // 2. Odświeżamy profil użytkownika (żeby frontend dostał jego nowe team_id)
      await fetchUser();

    } catch (err) {
      if (axios.isAxiosError(err) && err.response?.data?.message) {
        setError(err.response.data.message);
      } else {
        setError('Wystąpił błąd podczas tworzenia drużyny.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <main className="flex-1 bg-dark-bg p-6 text-white overflow-y-auto h-screen">
      <div className="mb-8">
        <h1 className="text-3xl font-extrabold tracking-tight uppercase text-white">
          Strefa <span className="text-brand-red">Drużynowa</span>
        </h1>
        <p className="text-sm text-gray-400 mt-1">
          Zbuduj własny skład lub dołącz do istniejącej ekipy, aby zdominować turnieje CS2.
        </p>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-8">
        
        {/* LEWA KOLUMNA: LISTA DRUŻYN */}
        <div className="xl:col-span-2 flex flex-col gap-4">
          <h2 className="text-lg font-semibold uppercase tracking-wider text-gray-300 mb-2 flex items-center gap-2">
            <span className="w-2 h-2 rounded-full bg-brand-red animate-pulse" />
            Zarejestrowane Formacje
          </h2>

          {loading ? (
            <div className="text-gray-500 animate-pulse text-sm">Wczytywanie bazy zespołów...</div>
          ) : teams.length === 0 ? (
            <div className="text-gray-500 text-sm">Brak zarejestrowanych drużyn w systemie. Bądź pierwszy!</div>
          ) : (
            teams.map((team) => (
              <div 
                key={team.id} 
                className="p-5 bg-card-bg/40 border border-[#1a1a1a] rounded-xl flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 hover:border-brand-red/30 transition-all duration-300"
              >
                <div>
                  <div className="flex items-center gap-2">
                    <span className="text-xs font-bold bg-brand-red/10 text-brand-red px-2 py-0.5 rounded border border-brand-red/20">
                      {team.tag}
                    </span>
                    <h3 className="text-lg font-bold tracking-wide">{team.name}</h3>
                  </div>
                  <div className="mt-2 text-sm text-gray-400">
                    Skład: <strong className="text-white">{team.players_count}/6</strong> graczy
                  </div>
                </div>

                {/* Przycisk aplikacji pokażemy tylko, jeśli gracz nie ma drużyny i w drużynie jest miejsce */}
                {!user?.player?.team_id && team.players_count < 6 && (
                  <button 
                    onClick={() => alert('System aplikacji w przygotowaniu!')}
                    className="w-full sm:w-auto bg-white/5 hover:bg-brand-red text-white px-5 py-2 rounded-lg text-xs uppercase tracking-widest font-semibold transition-all duration-200 border border-white/10 hover:border-brand-red"
                  >
                    Aplikuj
                  </button>
                )}
              </div>
            ))
          )}
        </div>

        {/* PRAWA KOLUMNA: PANEL TWORZENIA DRUŻYNY */}
        <div className="flex flex-col gap-4">
          <h2 className="text-lg font-semibold uppercase tracking-wider text-gray-300 mb-2">
            Zgłoś swój skład
          </h2>

          <div className="p-6 bg-card-bg/60 border border-[#1a1a1a] rounded-2xl relative overflow-hidden">
            <div className="absolute top-0 right-0 w-32 h-32 bg-brand-red/5 rounded-full blur-3xl pointer-events-none" />

            {/* Jeśli gracz posiada team_id, wyświetlamy informację. W przeciwnym razie - formularz. */}
            {user?.player?.team_id ? (
              <div className="text-center py-4 relative z-10">
                <p className="text-sm text-gray-400">Należysz już do aktywnej drużyny.</p>
                <button className="mt-4 w-full border border-brand-red/20 text-brand-red/80 hover:bg-brand-red hover:text-white py-2 rounded-lg text-xs uppercase tracking-widest transition-all">
                  Przejdź do profilu drużyny
                </button>
              </div>
            ) : (
              <form onSubmit={handleCreateTeam} className="flex flex-col gap-4 relative z-10">
                {error && <div className="text-brand-red text-xs font-semibold mb-2">{error}</div>}
                
                <div>
                  <label className="block text-xs uppercase tracking-widest text-gray-400 font-medium mb-1.5">
                    Nazwa Drużyny
                  </label>
                  <input 
                    type="text" 
                    value={teamName}
                    onChange={(e) => setTeamName(e.target.value)}
                    required
                    className="w-full bg-[#0d0d0d] border border-[#1a1a1a] focus:border-brand-red/50 rounded-xl px-4 py-3 text-sm text-white focus:outline-none transition-all"
                  />
                </div>

                <div>
                  <label className="block text-xs uppercase tracking-widest text-gray-400 font-medium mb-1.5">
                    Tag (3-4 znaki)
                  </label>
                  <input 
                    type="text" 
                    maxLength={4}
                    value={teamTag}
                    onChange={(e) => setTeamTag(e.target.value)}
                    required
                    className="w-full bg-[#0d0d0d] border border-[#1a1a1a] focus:border-brand-red/50 rounded-xl px-4 py-3 text-sm text-white focus:outline-none transition-all uppercase"
                  />
                </div>

                <button 
                  type="submit"
                  disabled={isSubmitting}
                  className="w-full bg-brand-red hover:bg-[#ff1a40] disabled:bg-brand-red/50 text-white font-medium py-3 rounded-xl text-xs uppercase tracking-widest transition-all duration-200 mt-2 cursor-pointer"
                >
                  {isSubmitting ? 'Tworzenie...' : 'Załóż drużynę'}
                </button>
              </form>
            )}
          </div>
        </div>

      </div>
    </main>
  );
};