import { useLanguage } from "../App";
import { Card, CardContent, CardHeader, CardTitle } from "../components/ui/card";
import { Clock, Star, Ban, Lightbulb, Trophy } from "lucide-react";

export default function Rules() {
  const { language, t } = useLanguage();
  const da = language === "da";

  const pointsP1 = 25;
  const pointsP2 = 18;
  const pointsP3 = 15;
  const pointsWrongPos = 5;

  return (
    <div className="max-w-3xl mx-auto space-y-6 animate-fadeIn">
      <h1 className="text-3xl font-bold flex items-center gap-3" style={{ fontFamily: 'Chivo, sans-serif' }}>
        <Trophy className="w-8 h-8" style={{ color: 'var(--accent)' }} />
        {da ? 'Spilleregler' : 'Betting Rules'}
      </h1>

      {/* Betting Window */}
      <Card className="race-card">
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Clock className="w-5 h-5" style={{ color: 'var(--accent)' }} />
            {da ? 'Betting Vindue' : 'Betting Window'}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <table className="w-full">
            <tbody className="divide-y" style={{ borderColor: 'var(--border-color)' }}>
              <tr>
                <td className="py-3 font-medium">{da ? 'Åbner' : 'Opens'}</td>
                <td className="py-3" style={{ color: 'var(--text-secondary)' }}>
                  {da ? '48 timer før løbets starttid' : '48 hours before race start time'}
                </td>
              </tr>
              <tr>
                <td className="py-3 font-medium">{da ? 'Lukker' : 'Closes'}</td>
                <td className="py-3" style={{ color: 'var(--text-secondary)' }}>
                  {da ? 'Ved løbets starttid' : 'At race start time'}
                </td>
              </tr>
              <tr>
                <td className="py-3 font-medium">{da ? 'Rediger' : 'Edit'}</td>
                <td className="py-3" style={{ color: 'var(--text-secondary)' }}>
                  {da ? 'Bets kan redigeres så længe vinduet er åbent' : 'Bets can be edited while window is open'}
                </td>
              </tr>
            </tbody>
          </table>
        </CardContent>
      </Card>

      {/* Points System */}
      <Card className="race-card">
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Star className="w-5 h-5" style={{ color: 'var(--accent)' }} />
            {da ? 'Point System' : 'Points System'}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <table className="w-full">
            <thead>
              <tr className="border-b" style={{ borderColor: 'var(--border-color)' }}>
                <th className="py-3 text-left">{da ? 'Position' : 'Position'}</th>
                <th className="py-3 text-left">{da ? 'Korrekt Forudsigelse' : 'Correct Prediction'}</th>
              </tr>
            </thead>
            <tbody className="divide-y" style={{ borderColor: 'var(--border-color)' }}>
              <tr>
                <td className="py-3"><span className="position-badge position-1">P1</span></td>
                <td className="py-3 font-bold">{pointsP1} {da ? 'point' : 'points'}</td>
              </tr>
              <tr>
                <td className="py-3"><span className="position-badge position-2">P2</span></td>
                <td className="py-3 font-bold">{pointsP2} {da ? 'point' : 'points'}</td>
              </tr>
              <tr>
                <td className="py-3"><span className="position-badge position-3">P3</span></td>
                <td className="py-3 font-bold">{pointsP3} {da ? 'point' : 'points'}</td>
              </tr>
              <tr>
                <td className="py-3">
                  <span className="font-medium" style={{ color: 'var(--accent)' }}>+ Bonus</span>
                </td>
                <td className="py-3">
                  +{pointsWrongPos} {da ? 'point hvis kører er i top 3, men forkert position' : 'points if driver is in top 3 but wrong position'}
                </td>
              </tr>
            </tbody>
          </table>
        </CardContent>
      </Card>

      {/* Stars */}
      <Card className="race-card">
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <span className="text-yellow-500 text-xl">★</span>
            {da ? 'Stjerner' : 'Stars'}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <p>
            <strong>{da ? 'Perfekt bet' : 'Perfect bet'}:</strong>{' '}
            {da 
              ? 'Hvis alle 3 positioner er korrekte, modtager du' 
              : 'If all 3 positions are correct, you receive'}{' '}
            <span className="text-yellow-500">★</span> 1 {da ? 'stjerne' : 'star'}
          </p>
        </CardContent>
      </Card>

      {/* Restrictions */}
      <Card className="race-card">
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Ban className="w-5 h-5" style={{ color: 'var(--accent)' }} />
            {da ? 'Restriktioner' : 'Restrictions'}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <table className="w-full">
            <tbody className="divide-y" style={{ borderColor: 'var(--border-color)' }}>
              <tr>
                <td className="py-3 font-medium">{da ? 'Én bet per løb' : 'One bet per race'}</td>
                <td className="py-3" style={{ color: 'var(--text-secondary)' }}>
                  {da ? 'Hver bruger kan kun have ét bet per løb' : 'Each user can only have one bet per race'}
                </td>
              </tr>
              <tr>
                <td className="py-3 font-medium">{da ? 'Ingen duplikater' : 'No duplicates'}</td>
                <td className="py-3" style={{ color: 'var(--text-secondary)' }}>
                  {da ? 'Samme kører kan ikke vælges flere gange i ét bet' : 'Same driver cannot be selected multiple times in one bet'}
                </td>
              </tr>
              <tr>
                <td className="py-3 font-medium">{da ? 'Unik kombination' : 'Unique combination'}</td>
                <td className="py-3" style={{ color: 'var(--text-secondary)' }}>
                  {da ? 'To brugere kan ikke have identisk P1/P2/P3 kombination' : 'Two users cannot have identical P1/P2/P3 combination'}
                </td>
              </tr>
              <tr>
                <td className="py-3 font-medium">{da ? 'Ikke kvalifikation' : 'Not qualifying'}</td>
                <td className="py-3" style={{ color: 'var(--text-secondary)' }}>
                  {da ? 'Bet kan ikke matche kvalifikationsresultatet 100%' : 'Bet cannot match qualifying result 100%'}
                </td>
              </tr>
            </tbody>
          </table>
        </CardContent>
      </Card>

      {/* Example */}
      <Card className="race-card">
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Lightbulb className="w-5 h-5" style={{ color: 'var(--accent)' }} />
            {da ? 'Eksempel' : 'Example'}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="p-4 rounded-lg" style={{ background: 'var(--bg-secondary)', borderLeft: '4px solid var(--accent)' }}>
            <p><strong>{da ? 'Løbsresultat' : 'Race Result'}:</strong> P1 = Verstappen, P2 = Norris, P3 = Leclerc</p>
          </div>

          <div className="p-4 rounded-lg" style={{ background: 'var(--bg-hover)' }}>
            <h4 className="font-bold mb-2">{da ? 'Scenarie 1' : 'Scenario 1'}:</h4>
            <p className="mb-2"><strong>{da ? 'Dit bet' : 'Your bet'}:</strong> P1 = Verstappen, P2 = Leclerc, P3 = Norris</p>
            <ul className="space-y-1 text-sm">
              <li><span style={{ color: 'var(--accent)' }}>✓</span> P1 {da ? 'korrekt' : 'correct'}: <strong>+{pointsP1} {da ? 'point' : 'points'}</strong></li>
              <li><span style={{ color: 'var(--text-muted)' }}>○</span> P2 {da ? 'forkert, men Leclerc i top 3' : 'wrong, but Leclerc in top 3'}: <strong>+{pointsWrongPos} {da ? 'point' : 'points'}</strong></li>
              <li><span style={{ color: 'var(--text-muted)' }}>○</span> P3 {da ? 'forkert, men Norris i top 3' : 'wrong, but Norris in top 3'}: <strong>+{pointsWrongPos} {da ? 'point' : 'points'}</strong></li>
              <li className="pt-2 border-t font-bold" style={{ borderColor: 'var(--border-color)' }}>
                Total: {pointsP1 + pointsWrongPos + pointsWrongPos} {da ? 'point' : 'points'}
              </li>
            </ul>
          </div>

          <div className="p-4 rounded-lg" style={{ background: 'var(--bg-hover)' }}>
            <h4 className="font-bold mb-2 flex items-center gap-2">
              {da ? 'Scenarie 2 (Perfekt!)' : 'Scenario 2 (Perfect!)'} <span className="text-yellow-500">★</span>
            </h4>
            <p className="mb-2"><strong>{da ? 'Dit bet' : 'Your bet'}:</strong> P1 = Verstappen, P2 = Norris, P3 = Leclerc</p>
            <ul className="space-y-1 text-sm">
              <li><span style={{ color: 'var(--accent)' }}>✓</span> P1 {da ? 'korrekt' : 'correct'}: <strong>+{pointsP1} {da ? 'point' : 'points'}</strong></li>
              <li><span style={{ color: 'var(--accent)' }}>✓</span> P2 {da ? 'korrekt' : 'correct'}: <strong>+{pointsP2} {da ? 'point' : 'points'}</strong></li>
              <li><span style={{ color: 'var(--accent)' }}>✓</span> P3 {da ? 'korrekt' : 'correct'}: <strong>+{pointsP3} {da ? 'point' : 'points'}</strong></li>
              <li className="pt-2 border-t font-bold" style={{ borderColor: 'var(--border-color)' }}>
                Total: {pointsP1 + pointsP2 + pointsP3} {da ? 'point' : 'points'} + <span className="text-yellow-500">★</span> 1 {da ? 'stjerne' : 'star'}
              </li>
            </ul>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
