import { useState, useEffect } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { useAuth, useLanguage } from "../App";
import { Button } from "../components/ui/button";
import { Input } from "../components/ui/input";
import { Label } from "../components/ui/label";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "../components/ui/card";
import { toast } from "sonner";
import { Flag, Mail, Lock, User, AlertTriangle } from "lucide-react";
import axios from "axios";

const API = `${process.env.REACT_APP_BACKEND_URL}/api`;

export default function Register() {
  const { register } = useAuth();
  const { t, language } = useLanguage();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [displayName, setDisplayName] = useState("");
  const [loading, setLoading] = useState(false);
  const [validating, setValidating] = useState(true);
  const [inviteValid, setInviteValid] = useState(false);
  const [error, setError] = useState("");
  
  const token = searchParams.get("token");

  useEffect(() => {
    const validateToken = async () => {
      if (!token) {
        setError(language === "da" 
          ? "Du skal have en invitation for at registrere dig. Kontakt administrator."
          : "You need an invitation to register. Contact administrator.");
        setValidating(false);
        return;
      }

      try {
        const res = await axios.get(`${API}/invites/validate/${token}`);
        setInviteValid(true);
        setEmail(res.data.email);
      } catch (err) {
        setError(language === "da"
          ? "Ugyldigt eller udløbet invitation. Kontakt administrator for en ny invitation."
          : "Invalid or expired invitation. Contact administrator for a new invitation.");
      } finally {
        setValidating(false);
      }
    };

    validateToken();
  }, [token, language]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      await register(email, password, displayName, token);
      toast.success(language === "da" ? "Velkommen!" : "Welcome!");
      navigate("/");
    } catch (err) {
      toast.error(err.response?.data?.detail || "Registration failed");
    } finally {
      setLoading(false);
    }
  };

  if (validating) {
    return (
      <div className="min-h-[70vh] flex items-center justify-center">
        <div className="animate-pulse" style={{ color: 'var(--text-muted)' }}>
          {language === "da" ? "Validerer invitation..." : "Validating invitation..."}
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-[70vh] flex items-center justify-center animate-fadeIn">
      <Card className="w-full max-w-md race-card" style={{ background: 'var(--bg-card)' }}>
        <CardHeader className="text-center">
          <div className="mx-auto w-16 h-16 rounded-2xl flex items-center justify-center mb-4" style={{ background: inviteValid ? 'var(--accent)' : '#ef4444' }}>
            {inviteValid ? (
              <Flag className="w-8 h-8 text-white" />
            ) : (
              <AlertTriangle className="w-8 h-8 text-white" />
            )}
          </div>
          <CardTitle className="text-2xl" style={{ fontFamily: 'Chivo, sans-serif' }}>
            {inviteValid ? t("register") : (language === "da" ? "Invitation påkrævet" : "Invitation Required")}
          </CardTitle>
          {inviteValid && (
            <CardDescription style={{ color: 'var(--text-muted)' }}>
              {language === "da" ? "Du er inviteret!" : "You are invited!"}
            </CardDescription>
          )}
        </CardHeader>
        <CardContent>
          {error && (
            <div className="p-4 rounded-lg mb-4" style={{ background: 'rgba(239, 68, 68, 0.1)', border: '1px solid rgba(239, 68, 68, 0.3)' }}>
              <p style={{ color: '#ef4444' }}>{error}</p>
            </div>
          )}
          
          {inviteValid && (
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="displayName">{t("displayName")}</Label>
                <div className="relative">
                  <User className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{ color: 'var(--text-muted)' }} />
                  <Input
                    id="displayName"
                    type="text"
                    value={displayName}
                    onChange={(e) => setDisplayName(e.target.value)}
                    className="pl-10"
                    placeholder="Max Verstappen"
                    data-testid="register-display-name"
                  />
                </div>
              </div>
              <div className="space-y-2">
                <Label htmlFor="email">{t("email")}</Label>
                <div className="relative">
                  <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{ color: 'var(--text-muted)' }} />
                  <Input
                    id="email"
                    type="email"
                    value={email}
                    className="pl-10"
                    readOnly
                    style={{ background: 'var(--bg-secondary)', cursor: 'not-allowed' }}
                    data-testid="register-email"
                  />
                </div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                  {language === "da" ? "Email er sat af invitation" : "Email is set by invitation"}
                </p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="password">{t("password")}</Label>
                <div className="relative">
                  <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{ color: 'var(--text-muted)' }} />
                  <Input
                    id="password"
                    type="password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    className="pl-10"
                    placeholder="••••••••"
                    required
                    minLength={6}
                    data-testid="register-password"
                  />
                </div>
              </div>
              <Button 
                type="submit" 
                className="w-full btn-f1" 
                disabled={loading}
                data-testid="register-submit"
              >
                {loading ? "..." : t("register")}
              </Button>
            </form>
          )}
          
          <p className="text-center mt-6" style={{ color: 'var(--text-muted)' }}>
            {language === "da" ? "Har du allerede en konto?" : "Already have an account?"}{" "}
            <Link to="/login" className="font-medium" style={{ color: 'var(--accent)' }} data-testid="login-link">
              {t("login")}
            </Link>
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
