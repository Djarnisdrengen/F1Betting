import { useState, useEffect } from "react";
import { useParams, useNavigate } from "react-router-dom";
import axios from "axios";
import { useAuth, useLanguage } from "../App";
import { Button } from "../components/ui/button";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "../components/ui/card";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "../components/ui/select";
import { Label } from "../components/ui/label";
import { toast } from "sonner";
import { Flag, MapPin, Clock, AlertTriangle } from "lucide-react";
import { format, parseISO, differenceInHours, isBefore, isAfter } from "date-fns";
import { da, enUS } from "date-fns/locale";

const API = `${process.env.REACT_APP_BACKEND_URL}/api`;

export default function PlaceBet() {
  const { raceId } = useParams();
  const { user } = useAuth();
  const { language, t } = useLanguage();
  const navigate = useNavigate();
  
  const [race, setRace] = useState(null);
  const [drivers, setDrivers] = useState([]);
  const [existingBets, setExistingBets] = useState([]);
  const [p1, setP1] = useState("");
  const [p2, setP2] = useState("");
  const [p3, setP3] = useState("");
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");

  const locale = language === "da" ? da : enUS;

  useEffect(() => {
    const token = localStorage.getItem("token");
    Promise.all([
      axios.get(`${API}/races`),
      axios.get(`${API}/drivers`),
      axios.get(`${API}/bets/race/${raceId}`)
    ]).then(([racesRes, driversRes, betsRes]) => {
      const foundRace = racesRes.data.find(r => r.id === raceId);
      setRace(foundRace);
      setDrivers(driversRes.data);
      setExistingBets(betsRes.data);
    }).catch(console.error).finally(() => setLoading(false));
  }, [raceId]);

  const validateBet = () => {
    if (!p1 || !p2 || !p3) {
      setError(language === "da" ? "Vælg alle 3 positioner" : "Select all 3 positions");
      return false;
    }

    if (p1 === p2 || p1 === p3 || p2 === p3) {
      setError(language === "da" ? "Kan ikke vælge samme kører flere gange" : "Cannot select same driver multiple times");
      return false;
    }

    // Check if matches qualifying
    if (race?.quali_p1 && race?.quali_p2 && race?.quali_p3) {
      if (p1 === race.quali_p1 && p2 === race.quali_p2 && p3 === race.quali_p3) {
        setError(language === "da" ? "Bet kan ikke matche kvalifikationsresultatet" : "Bet cannot match qualifying result");
        return false;
      }
    }

    // Check if combination is taken
    const isTaken = existingBets.some(b => b.p1 === p1 && b.p2 === p2 && b.p3 === p3);
    if (isTaken) {
      setError(language === "da" ? "Denne kombination er allerede taget" : "This combination is already taken");
      return false;
    }

    setError("");
    return true;
  };

  useEffect(() => {
    if (p1 || p2 || p3) validateBet();
  }, [p1, p2, p3]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!validateBet()) return;

    setSubmitting(true);
    try {
      const token = localStorage.getItem("token");
      await axios.post(`${API}/bets`, 
        { race_id: raceId, p1, p2, p3 },
        { headers: { Authorization: `Bearer ${token}` }}
      );
      toast.success(language === "da" ? "Bet placeret!" : "Bet placed!");
      navigate("/");
    } catch (err) {
      toast.error(err.response?.data?.detail || "Failed to place bet");
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[50vh]">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2" style={{ borderColor: 'var(--accent)' }}></div>
      </div>
    );
  }

  if (!race) {
    return (
      <div className="text-center py-12">
        <p style={{ color: 'var(--text-muted)' }}>Race not found</p>
      </div>
    );
  }

  const raceDateTime = new Date(`${race.race_date}T${race.race_time}:00Z`);
  const now = new Date();
  const bettingOpens = new Date(raceDateTime.getTime() - 48 * 60 * 60 * 1000);
  const canBet = isAfter(now, bettingOpens) && isBefore(now, raceDateTime);
  const hoursUntilOpen = differenceInHours(bettingOpens, now);

  const getDriver = (id) => drivers.find(d => d.id === id);
  const selectedIds = [p1, p2, p3].filter(Boolean);

  return (
    <div className="max-w-2xl mx-auto animate-fadeIn">
      <Card className="race-card" style={{ background: 'var(--bg-card)' }}>
        <CardHeader>
          <div className="flex items-center gap-3 mb-4">
            <div className="w-12 h-12 rounded-xl flex items-center justify-center" style={{ background: 'var(--accent)' }}>
              <Flag className="w-6 h-6 text-white" />
            </div>
            <div>
              <CardTitle className="text-2xl" style={{ fontFamily: 'Chivo, sans-serif' }}>{race.name}</CardTitle>
              <CardDescription className="flex items-center gap-4" style={{ color: 'var(--text-muted)' }}>
                <span className="flex items-center gap-1"><MapPin className="w-4 h-4" /> {race.location}</span>
                <span className="flex items-center gap-1">
                  <Clock className="w-4 h-4" />
                  {format(parseISO(race.race_date), "d MMMM yyyy", { locale })} - {race.race_time}
                </span>
              </CardDescription>
            </div>
          </div>

          {/* Qualifying results */}
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
        </CardHeader>

        <CardContent>
          {!canBet ? (
            <div className="text-center py-8">
              <AlertTriangle className="w-12 h-12 mx-auto mb-4" style={{ color: 'var(--accent)' }} />
              <p className="text-lg font-medium">
                {isBefore(now, bettingOpens) 
                  ? `${t("bettingNotOpen")} - ${language === "da" ? "Åbner om" : "Opens in"} ${hoursUntilOpen}h`
                  : t("bettingClosed")}
              </p>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-6">
              <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
                {t("bettingWindow")} • {t("pointsSystem")}
              </p>

              {error && (
                <div className="p-3 rounded-lg border border-red-500 bg-red-500/10 text-red-500 flex items-center gap-2">
                  <AlertTriangle className="w-4 h-4" />
                  {error}
                </div>
              )}

              {[
                { label: "P1 (25 pts)", value: p1, setValue: setP1, position: 1 },
                { label: "P2 (18 pts)", value: p2, setValue: setP2, position: 2 },
                { label: "P3 (15 pts)", value: p3, setValue: setP3, position: 3 },
              ].map(({ label, value, setValue, position }) => (
                <div key={position} className="space-y-2">
                  <Label className="flex items-center gap-2">
                    <span className={`position-badge position-${position}`}>P{position}</span>
                    {label}
                  </Label>
                  <Select value={value} onValueChange={setValue}>
                    <SelectTrigger data-testid={`select-p${position}`}>
                      <SelectValue placeholder={t("selectDriver")} />
                    </SelectTrigger>
                    <SelectContent>
                      {drivers.map(driver => (
                        <SelectItem 
                          key={driver.id} 
                          value={driver.id}
                          disabled={selectedIds.includes(driver.id) && value !== driver.id}
                        >
                          #{driver.number} {driver.name} - {driver.team}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              ))}

              <Button 
                type="submit" 
                className="w-full btn-f1" 
                disabled={submitting || !!error || !p1 || !p2 || !p3}
                data-testid="submit-bet"
              >
                {submitting ? "..." : t("placeBet")}
              </Button>
            </form>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
