import { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import axios from "axios";
import { useAuth, useLanguage } from "../App";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "../components/ui/card";
import { Badge } from "../components/ui/badge";
import { Flag, Clock, MapPin, CheckCircle, ChevronDown, ChevronUp, Star, Users } from "lucide-react";
import { format, parseISO, isBefore, isAfter, isValid } from "date-fns";
import { da, enUS } from "date-fns/locale";

const API = `${process.env.REACT_APP_BACKEND_URL}/api`;

export default function Races() {
  const { user } = useAuth();
  const { language, t } = useLanguage();
  const [races, setRaces] = useState([]);
  const [drivers, setDrivers] = useState([]);
  const [myBets, setMyBets] = useState([]);
  const [allBets, setAllBets] = useState([]);
  const [expandedRaces, setExpandedRaces] = useState({});
  const [loading, setLoading] = useState(true);

  const locale = language === "da" ? da : enUS;

  useEffect(() => {
    const token = localStorage.getItem("token");
    const requests = [
      axios.get(`${API}/races`),
      axios.get(`${API}/drivers`),
      axios.get(`${API}/bets`)
    ];
    if (token) {
      requests.push(axios.get(`${API}/bets/my`, { headers: { Authorization: `Bearer ${token}` }}));
    }

    Promise.all(requests).then(([racesRes, driversRes, allBetsRes, myBetsRes]) => {
      setRaces(racesRes.data.sort((a, b) => new Date(a.race_date) - new Date(b.race_date)));
      setDrivers(driversRes.data);
      setAllBets(allBetsRes.data);
      if (myBetsRes) setMyBets(myBetsRes.data);
    }).catch(console.error).finally(() => setLoading(false));
  }, []);

  const toggleRaceExpansion = (raceId) => {
    setExpandedRaces(prev => ({ ...prev, [raceId]: !prev[raceId] }));
  };

  const getRaceBets = (raceId) => allBets.filter(b => b.race_id === raceId);

  const getDriver = (id) => drivers.find(d => d.id === id);

  const getBettingStatus = (race) => {
    const raceDateTime = new Date(`${race.race_date}T${race.race_time}:00Z`);
    const now = new Date();
    const bettingOpens = new Date(raceDateTime.getTime() - 48 * 60 * 60 * 1000);

    if (race.result_p1) return { status: "completed", label: t("raceCompleted"), color: "bg-gray-500" };
    if (isBefore(now, bettingOpens)) return { status: "pending", label: t("bettingNotOpen"), color: "status-pending" };
    if (isAfter(now, raceDateTime)) return { status: "closed", label: t("bettingClosed"), color: "status-closed" };
    return { status: "open", label: t("bettingOpen"), color: "status-open" };
  };

  const hasBetForRace = (raceId) => myBets.some(b => b.race_id === raceId);
  const userBetForRace = (raceId) => myBets.find(b => b.race_id === raceId);

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[50vh]">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2" style={{ borderColor: 'var(--accent)' }}></div>
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-fadeIn">
      <h1 className="text-3xl font-bold flex items-center gap-3" style={{ fontFamily: 'Chivo, sans-serif' }}>
        <Flag className="w-8 h-8" style={{ color: 'var(--accent)' }} />
        {t("races")}
      </h1>

      <div className="grid gap-4">
        {races.map(race => {
          const status = getBettingStatus(race);
          const hasBet = hasBetForRace(race.id);
          const userBet = userBetForRace(race.id);
          const raceBets = getRaceBets(race.id);
          const isExpanded = expandedRaces[race.id];

          return (
            <Card key={race.id} className="race-card" data-testid={`race-${race.id}`}>
              <CardContent className="p-6">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                  <div className="flex-1">
                    <div className="flex items-center gap-3 mb-2">
                      <h3 className="text-xl font-bold" style={{ fontFamily: 'Chivo, sans-serif' }}>{race.name}</h3>
                      <Badge className={`${status.color} text-white`}>{status.label}</Badge>
                      {hasBet && (
                        <Badge variant="outline" className="border-green-500 text-green-500">
                          <CheckCircle className="w-3 h-3 mr-1" /> {language === "da" ? "Bet placeret" : "Bet placed"}
                        </Badge>
                      )}
                    </div>
                    <div className="flex flex-wrap items-center gap-4" style={{ color: 'var(--text-secondary)' }}>
                      <span className="flex items-center gap-1">
                        <MapPin className="w-4 h-4" /> {race.location}
                      </span>
                      <span className="flex items-center gap-1">
                        <Clock className="w-4 h-4" />
                        {format(parseISO(race.race_date), "d MMMM yyyy", { locale })} - {race.race_time}
                      </span>
                    </div>

                    {/* Quali/Results */}
                    <div className="flex flex-wrap gap-4 mt-3">
                      {(race.quali_p1 || race.quali_p2 || race.quali_p3) && (
                        <div>
                          <span className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{t("qualifying")}:</span>
                          <div className="flex gap-2 mt-1">
                            {[race.quali_p1, race.quali_p2, race.quali_p3].map((id, idx) => {
                              const d = getDriver(id);
                              return d ? (
                                <span key={idx} className="text-sm px-2 py-1 rounded" style={{ background: 'var(--bg-secondary)' }}>
                                  P{idx + 1}: {d.name.split(' ').pop()}
                                </span>
                              ) : null;
                            })}
                          </div>
                        </div>
                      )}
                      {(race.result_p1 || race.result_p2 || race.result_p3) && (
                        <div>
                          <span className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{t("results")}:</span>
                          <div className="flex gap-2 mt-1">
                            {[race.result_p1, race.result_p2, race.result_p3].map((id, idx) => {
                              const d = getDriver(id);
                              return d ? (
                                <span key={idx} className={`text-sm px-2 py-1 rounded position-${idx + 1}`}>
                                  P{idx + 1}: {d.name.split(' ').pop()}
                                </span>
                              ) : null;
                            })}
                          </div>
                        </div>
                      )}
                    </div>

                    {/* User's own bet */}
                    {userBet && (
                      <div className={`mt-3 p-3 rounded-lg border ${userBet.is_perfect ? 'perfect-bet' : ''}`}
                           style={{ background: 'var(--bg-hover)', borderColor: 'var(--border-color)' }}>
                        <p className="text-sm font-medium mb-2 flex items-center gap-2">
                          {t("yourBets")}
                          {userBet.is_perfect && <Star className="w-4 h-4 text-yellow-500 star-icon" />}
                        </p>
                        <div className="flex gap-3 flex-wrap">
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
                  </div>

                  <div className="flex flex-col gap-2">
                    {status.status === "open" && user && !hasBet && (
                      <Link to={`/bet/${race.id}`}>
                        <Button className="btn-f1" data-testid={`bet-btn-${race.id}`}>{t("placeBet")}</Button>
                      </Link>
                    )}
                  </div>
                </div>

                {/* Bets toggle and list */}
                <div className="mt-4 pt-4 border-t" style={{ borderColor: 'var(--border-color)' }}>
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2" style={{ color: 'var(--text-muted)' }}>
                      <Users className="w-4 h-4" />
                      <span>{raceBets.length} bets</span>
                    </div>
                    {raceBets.length > 0 && (
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => toggleRaceExpansion(race.id)}
                        data-testid={`toggle-bets-${race.id}`}
                      >
                        {t("allBets")}
                        {isExpanded ? <ChevronUp className="ml-2 w-4 h-4" /> : <ChevronDown className="ml-2 w-4 h-4" />}
                      </Button>
                    )}
                  </div>

                  {isExpanded && raceBets.length > 0 && (
                    <div className="mt-4 space-y-2">
                      {raceBets.map(bet => (
                        <div 
                          key={bet.id} 
                          className={`p-3 rounded-lg flex flex-col sm:flex-row sm:items-center justify-between gap-2 ${bet.is_perfect ? 'perfect-bet' : ''}`}
                          style={{ background: 'var(--bg-secondary)', border: '1px solid var(--border-color)' }}
                          data-testid={`bet-${bet.id}`}
                        >
                          <div className="flex items-center gap-3">
                            <div className="w-8 h-8 rounded-full flex items-center justify-center text-white text-sm font-bold" style={{ background: 'var(--accent)' }}>
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
                </div>
              </CardContent>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
