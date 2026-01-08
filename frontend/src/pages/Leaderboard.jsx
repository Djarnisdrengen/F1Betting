import { useState, useEffect } from "react";
import axios from "axios";
import { useLanguage } from "../App";
import { Card, CardContent, CardHeader, CardTitle } from "../components/ui/card";
import { Trophy, Star, Medal } from "lucide-react";

const API = `${process.env.REACT_APP_BACKEND_URL}/api`;

export default function Leaderboard() {
  const { language, t } = useLanguage();
  const [leaderboard, setLeaderboard] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    axios.get(`${API}/leaderboard`)
      .then(res => setLeaderboard(res.data))
      .catch(console.error)
      .finally(() => setLoading(false));
  }, []);

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
        <Trophy className="w-8 h-8" style={{ color: 'var(--accent)' }} />
        {t("leaderboard")}
      </h1>

      {/* Top 3 Podium */}
      {leaderboard.length >= 3 && (
        <div className="grid grid-cols-3 gap-4 mb-8">
          {[1, 0, 2].map(idx => {
            const entry = leaderboard[idx];
            if (!entry) return null;
            const heights = ["h-32", "h-40", "h-24"];
            const positions = ["P2", "P1", "P3"];
            const colors = ["position-2", "position-1", "position-3"];
            
            return (
              <div key={idx} className="flex flex-col items-center justify-end">
                <div className="text-center mb-2">
                  <p className="font-bold" style={{ fontFamily: 'Chivo, sans-serif' }}>
                    {entry.display_name || entry.email}
                  </p>
                  <p className="text-2xl font-bold" style={{ color: 'var(--accent)' }}>{entry.points} pts</p>
                  {entry.stars > 0 && (
                    <p className="flex items-center justify-center gap-1 text-yellow-500">
                      {Array(entry.stars).fill(0).map((_, i) => (
                        <Star key={i} className="w-4 h-4 star-icon fill-yellow-500" />
                      ))}
                    </p>
                  )}
                </div>
                <div 
                  className={`w-full ${heights[idx]} rounded-t-lg flex items-start justify-center pt-4 ${colors[idx]}`}
                >
                  <span className="text-2xl font-bold" style={{ fontFamily: 'Chivo, sans-serif' }}>
                    {positions[idx]}
                  </span>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Full Leaderboard */}
      <Card className="race-card overflow-hidden">
        <CardContent className="p-0">
          <table className="w-full">
            <thead>
              <tr style={{ background: 'var(--bg-secondary)', borderBottom: '1px solid var(--border-color)' }}>
                <th className="text-left py-4 px-6">{t("rank")}</th>
                <th className="text-left py-4 px-6">{t("user")}</th>
                <th className="text-center py-4 px-6">{t("bets")}</th>
                <th className="text-center py-4 px-6">{t("stars")}</th>
                <th className="text-right py-4 px-6">{t("points")}</th>
              </tr>
            </thead>
            <tbody>
              {leaderboard.map((entry, index) => (
                <tr 
                  key={entry.user_id} 
                  className={`leaderboard-row ${index < 3 ? 'top-3' : ''}`}
                  style={{ borderBottom: '1px solid var(--border-color)' }}
                  data-testid={`leaderboard-row-${index}`}
                >
                  <td className="py-4 px-6">
                    <span className={`position-badge ${index < 3 ? `position-${index + 1}` : ''}`}
                          style={index >= 3 ? { background: 'var(--bg-secondary)' } : {}}>
                      {index + 1}
                    </span>
                  </td>
                  <td className="py-4 px-6">
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 rounded-full flex items-center justify-center" 
                           style={{ background: index < 3 ? 'var(--accent)' : 'var(--bg-secondary)' }}>
                        {(entry.display_name || entry.email)?.[0]?.toUpperCase()}
                      </div>
                      <span className="font-medium">{entry.display_name || entry.email}</span>
                    </div>
                  </td>
                  <td className="py-4 px-6 text-center" style={{ color: 'var(--text-muted)' }}>
                    {entry.bets_count}
                  </td>
                  <td className="py-4 px-6 text-center">
                    {entry.stars > 0 ? (
                      <span className="flex items-center justify-center gap-1 text-yellow-500">
                        <Star className="w-4 h-4 star-icon fill-yellow-500" />
                        {entry.stars}
                      </span>
                    ) : (
                      <span style={{ color: 'var(--text-muted)' }}>-</span>
                    )}
                  </td>
                  <td className="py-4 px-6 text-right">
                    <span className="text-lg font-bold" style={{ color: 'var(--accent)' }}>
                      {entry.points}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {leaderboard.length === 0 && (
            <p className="text-center py-12" style={{ color: 'var(--text-muted)' }}>{t("noBets")}</p>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
