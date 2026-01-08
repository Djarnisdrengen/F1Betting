import { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import axios from "axios";
import { useAuth, useLanguage } from "../App";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "../components/ui/card";
import { Badge } from "../components/ui/badge";
import { Flag, Clock, MapPin, ChevronDown, ChevronUp, Trophy, Star, Users } from "lucide-react";
import { format, parseISO, isBefore, isAfter, isValid } from "date-fns";
import { da, enUS } from "date-fns/locale";

const API = `${process.env.REACT_APP_BACKEND_URL}/api`;

export default function Home() {
  const { user } = useAuth();
  const { language, t } = useLanguage();
  const [races, setRaces] = useState([]);
  const [drivers, setDrivers] = useState([]);
  const [bets, setBets] = useState([]);
  const [leaderboard, setLeaderboard] = useState([]);
  const [settings, setSettings] = useState(null);
  const [expandedRaces, setExpandedRaces] = useState({});
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      axios.get(`${API}/races`),
      axios.get(`${API}/drivers`),
      axios.get(`${API}/bets`),
      axios.get(`${API}/leaderboard`),
      axios.get(`${API}/settings`)
    ]).then(([racesRes, driversRes, betsRes, leaderboardRes, settingsRes]) => {
      setRaces(racesRes.data);
      setDrivers(driversRes.data);
      setBets(betsRes.data);
      setLeaderboard(leaderboardRes.data);
      setSettings(settingsRes.data);
    }).catch(console.error).finally(() => setLoading(false));
  }, []);

  const getDriver = (id) => drivers.find(d => d.id === id);
  const locale = language === "da" ? da : enUS;

  const formatDate = (dateStr) => {
    if (!dateStr) return "N/A";
    try {
      const date = parseISO(dateStr);
      if (!isValid(date)) return "N/A";
      return format(date, "d MMM yyyy", { locale });
    } catch {
      return "N/A";
    }
  };

  const getBettingStatus = (race) => {
    if (!race.race_date || !race.race_time) {
      return { status: "pending", label: t("bettingNotOpen"), color: "status-pending" };
    }
    try {
      const raceDateTime = new Date(`${race.race_date}T${race.race_time}:00Z`);
      if (!isValid(raceDateTime)) {
        return { status: "pending", label: t("bettingNotOpen"), color: "status-pending" };
      }
      const now = new Date();
      const bettingOpens = new Date(raceDateTime.getTime() - 48 * 60 * 60 * 1000);

      if (race.result_p1) return { status: "completed", label: t("raceCompleted"), color: "bg-gray-500" };
      if (isBefore(now, bettingOpens)) return { status: "pending", label: t("bettingNotOpen"), color: "status-pending" };
      if (isAfter(now, raceDateTime)) return { status: "closed", label: t("bettingClosed"), color: "status-closed" };
      return { status: "open", label: t("bettingOpen"), color: "status-open" };
    } catch {
      return { status: "pending", label: t("bettingNotOpen"), color: "status-pending" };
    }
  };

  const getRaceBets = (raceId) => bets.filter(b => b.race_id === raceId);
  const userBetForRace = (raceId) => bets.find(b => b.race_id === raceId && b.user_id === user?.id);

  const toggleRaceExpansion = (raceId) => {
    setExpandedRaces(prev => ({ ...prev, [raceId]: !prev[raceId] }));
  };

  const upcomingRaces = races
    .filter(r => !r.result_p1)
    .sort((a, b) => new Date(a.race_date) - new Date(b.race_date));

  const heroTitle = language === "da" ? settings?.hero_title_da : settings?.hero_title_en;
  const heroText = language === "da" ? settings?.hero_text_da : settings?.hero_text_en;

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[50vh]">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2" style={{ borderColor: 'var(--accent)' }}></div>
      </div>
    );
  }

  return (
    <div className="space-y-8 animate-fadeIn">
      {/* Hero Section */}
      <section className="text-center py-12 px-4 rounded-2xl racing-stripe" style={{ background: 'var(--bg-card)', paddingLeft: '24px' }}>
        <h1 className="text-4xl sm:text-5xl font-bold mb-4" style={{ fontFamily: 'Chivo, sans-serif' }}>
          {heroTitle || t("placeBet")}
        </h1>
        <p className="text-lg max-w-2xl mx-auto mb-6" style={{ color: 'var(--text-secondary)' }}>
          {heroText || t("pointsSystem")}
        </p>
        {!user && (
          <div className="flex justify-center gap-4">
            <Link to="/register">
              <Button className="btn-f1" data-testid="hero-register-btn">{t("register")}</Button>
            </Link>
            <Link to="/login">
              <Button variant="outline" data-testid="hero-login-btn">{t("login")}</Button>
            </Link>
          </div>
        )}
      </section>

      <div className="grid lg:grid-cols-3 gap-8">
        {/* Upcoming Races */}
        <div className="lg:col-span-2 space-y-4">
          <h2 className="text-2xl font-bold flex items-center gap-2" style={{ fontFamily: 'Chivo, sans-serif' }}>
            <Flag className="w-6 h-6" style={{ color: 'var(--accent)' }} />
            {t("upcomingRaces")}
          </h2>

          {upcomingRaces.length === 0 ? (
            <Card className="race-card">
              <CardContent className="py-12 text-center" style={{ color: 'var(--text-muted)' }}>
                {language === "da" ? "Ingen kommende løb" : "No upcoming races"}
              </CardContent>
            </Card>
          ) : (
            upcomingRaces.map(race => {
              const status = getBettingStatus(race);
              const raceBets = getRaceBets(race.id);
              const userBet = userBetForRace(race.id);
              const isExpanded = expandedRaces[race.id];

              return (
                <Card key={race.id} className="race-card overflow-hidden" data-testid={`race-card-${race.id}`}>
                  <CardHeader className="pb-2">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                      <div>
                        <CardTitle className="text-xl" style={{ fontFamily: 'Chivo, sans-serif' }}>{race.name}</CardTitle>
                        <div className="flex items-center gap-4 mt-2" style={{ color: 'var(--text-secondary)' }}>
                          <span className="flex items-center gap-1">
                            <MapPin className="w-4 h-4" /> {race.location}
                          </span>
                          <span className="flex items-center gap-1">
                            <Clock className="w-4 h-4" />
                            {format(parseISO(race.race_date), "d MMM yyyy", { locale })} - {race.race_time}
                          </span>
                        </div>
                      </div>
                      <Badge className={`${status.color} text-white`}>{status.label}</Badge>
                    </div>
                  </CardHeader>

                  <CardContent className="space-y-4">
                    {/* Qualifying Results */}
                    {(race.quali_p1 || race.quali_p2 || race.quali_p3) && (
                      <div className="p-4 rounded-lg" style={{ background: 'var(--bg-secondary)' }}>
                        <p className="text-sm font-medium mb-2" style={{ color: 'var(--text-muted)' }}>{t("qualifying")}</p>
                        <div className="flex gap-4 flex-wrap">
                          {[race.quali_p1, race.quali_p2, race.quali_p3].map((driverId, idx) => {
                            const driver = getDriver(driverId);
                            return driver ? (
                              <div key={idx} className="flex items-center gap-2">
                                <span className={`position-badge position-${idx + 1}`}>P{idx + 1}</span>
                                <span>{driver.name}</span>
                              </div>
                            ) : null;
                          })}
                        </div>
                      </div>
                    )}

                    {/* User's bet */}
                    {userBet && (
                      <div className={`p-4 rounded-lg border ${userBet.is_perfect ? 'perfect-bet' : ''}`} 
                           style={{ background: 'var(--bg-hover)', borderColor: 'var(--border-color)' }}>
                        <p className="text-sm font-medium mb-2 flex items-center gap-2">
                          {t("yourBets")}
                          {userBet.is_perfect && <Star className="w-4 h-4 text-yellow-500 star-icon" />}
                        </p>
                        <div className="flex gap-4 flex-wrap">
                          {[userBet.p1, userBet.p2, userBet.p3].map((driverId, idx) => {
                            const driver = getDriver(driverId);
                            return driver ? (
                              <div key={idx} className="flex items-center gap-2">
                                <span className={`position-badge position-${idx + 1}`}>P{idx + 1}</span>
                                <span>{driver.name}</span>
                              </div>
                            ) : null;
                          })}
                        </div>
                        {userBet.points > 0 && (
                          <p className="mt-2 font-bold" style={{ color: 'var(--accent)' }}>
                            {userBet.points} {t("points")}
                          </p>
                        )}
                      </div>
                    )}

                    {/* Actions */}
                    <div className="flex flex-wrap items-center justify-between gap-4">
                      <div className="flex items-center gap-2" style={{ color: 'var(--text-muted)' }}>
                        <Users className="w-4 h-4" />
                        <span>{raceBets.length} bets</span>
                      </div>

                      <div className="flex gap-2">
                        {status.status === "open" && user && !userBet && (
                          <Link to={`/bet/${race.id}`}>
                            <Button className="btn-f1" data-testid={`place-bet-${race.id}`}>
                              {t("placeBet")}
                            </Button>
                          </Link>
                        )}
                        {raceBets.length > 0 && (
                          <Button
                            variant="ghost"
                            onClick={() => toggleRaceExpansion(race.id)}
                            data-testid={`toggle-bets-${race.id}`}
                          >
                            {t("allBets")}
                            {isExpanded ? <ChevronUp className="ml-2 w-4 h-4" /> : <ChevronDown className="ml-2 w-4 h-4" />}
                          </Button>
                        )}
                      </div>
                    </div>

                    {/* All Bets Expansion */}
                    {isExpanded && raceBets.length > 0 && (
                      <div className="border-t pt-4 space-y-3" style={{ borderColor: 'var(--border-color)' }}>
                        <h4 className="font-semibold">{t("allBets")} ({raceBets.length})</h4>
                        {raceBets.map(bet => (
                          <div 
                            key={bet.id} 
                            className={`p-3 rounded-lg flex flex-col sm:flex-row sm:items-center justify-between gap-2 ${bet.is_perfect ? 'perfect-bet' : ''}`}
                            style={{ background: 'var(--bg-secondary)', border: '1px solid var(--border-color)' }}
                            data-testid={`bet-${bet.id}`}
                          >
                            <div className="flex items-center gap-3">
                              <div className="w-8 h-8 rounded-full flex items-center justify-center" style={{ background: 'var(--accent)' }}>
                                {(bet.user_display_name || bet.user_email)?.[0]?.toUpperCase()}
                              </div>
                              <div>
                                <p className="font-medium flex items-center gap-2">
                                  {bet.user_display_name || bet.user_email}
                                  {bet.is_perfect && <Star className="w-4 h-4 text-yellow-500 star-icon" />}
                                </p>
                                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                                  {format(parseISO(bet.placed_at), "d MMM HH:mm", { locale })}
                                </p>
                              </div>
                            </div>
                            <div className="flex items-center gap-3">
                              <div className="flex gap-2">
                                {[bet.p1, bet.p2, bet.p3].map((driverId, idx) => {
                                  const driver = getDriver(driverId);
                                  return (
                                    <span key={idx} className="text-sm px-2 py-1 rounded" style={{ background: 'var(--bg-card)' }}>
                                      <span className="font-bold">P{idx + 1}:</span> {driver?.name?.split(' ').pop()}
                                    </span>
                                  );
                                })}
                              </div>
                              {bet.points > 0 && (
                                <Badge style={{ background: 'var(--accent)' }}>{bet.points} pts</Badge>
                              )}
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </CardContent>
                </Card>
              );
            })
          )}
        </div>

        {/* Leaderboard Sidebar */}
        <div>
          <h2 className="text-2xl font-bold flex items-center gap-2 mb-4" style={{ fontFamily: 'Chivo, sans-serif' }}>
            <Trophy className="w-6 h-6" style={{ color: 'var(--accent)' }} />
            {t("leaderboard")}
          </h2>

          <Card className="race-card">
            <CardContent className="p-0">
              {leaderboard.length === 0 ? (
                <p className="p-6 text-center" style={{ color: 'var(--text-muted)' }}>{t("noBets")}</p>
              ) : (
                <div className="divide-y" style={{ borderColor: 'var(--border-color)' }}>
                  {leaderboard.slice(0, 10).map((entry, index) => (
                    <div 
                      key={entry.user_id} 
                      className={`leaderboard-row p-4 flex items-center justify-between ${index < 3 ? 'top-3' : ''}`}
                      data-testid={`leaderboard-entry-${index}`}
                    >
                      <div className="flex items-center gap-3">
                        <span className={`position-badge ${index < 3 ? `position-${index + 1}` : ''}`} 
                              style={index >= 3 ? { background: 'var(--bg-secondary)' } : {}}>
                          {index + 1}
                        </span>
                        <div>
                          <p className="font-medium">{entry.display_name || entry.email}</p>
                          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                            {entry.bets_count} bets
                          </p>
                        </div>
                      </div>
                      <div className="text-right">
                        <p className="font-bold" style={{ color: 'var(--accent)' }}>{entry.points} pts</p>
                        {entry.stars > 0 && (
                          <p className="text-yellow-500 flex items-center gap-1 justify-end">
                            <Star className="w-3 h-3 star-icon" />
                            <span className="text-sm">{entry.stars}</span>
                          </p>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>

          <Link to="/leaderboard" className="block mt-4">
            <Button variant="outline" className="w-full">{t("leaderboard")}</Button>
          </Link>
        </div>
      </div>
    </div>
  );
}
