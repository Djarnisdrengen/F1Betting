import { useState, useEffect } from "react";
import { Link, useSearchParams, useNavigate } from "react-router-dom";
import { useLanguage } from "../App";
import { Button } from "../components/ui/button";
import { Input } from "../components/ui/input";
import { Label } from "../components/ui/label";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "../components/ui/card";
import { toast } from "sonner";
import { Lock, ArrowLeft, CheckCircle, AlertTriangle } from "lucide-react";
import axios from "axios";

const API = `${process.env.REACT_APP_BACKEND_URL}/api`;

export default function ResetPassword() {
  const { language } = useLanguage();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const token = searchParams.get("token");
  
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!token) {
      setError(language === "da" ? "Ugyldigt link" : "Invalid link");
    }
  }, [token, language]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    if (password.length < 6) {
      toast.error(language === "da" ? "Adgangskoden skal være mindst 6 tegn" : "Password must be at least 6 characters");
      return;
    }
    
    if (password !== confirmPassword) {
      toast.error(language === "da" ? "Adgangskoderne matcher ikke" : "Passwords do not match");
      return;
    }

    setLoading(true);
    try {
      await axios.post(`${API}/auth/reset-password`, { token, password });
      setSuccess(true);
      toast.success(language === "da" ? "Adgangskode nulstillet!" : "Password reset!");
      setTimeout(() => navigate("/login"), 2000);
    } catch (err) {
      const detail = err.response?.data?.detail || "Error";
      setError(detail);
      toast.error(detail);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-[70vh] flex items-center justify-center animate-fadeIn">
      <Card className="w-full max-w-md race-card" style={{ background: 'var(--bg-card)' }}>
        <CardHeader className="text-center">
          <div className="mx-auto w-16 h-16 rounded-2xl flex items-center justify-center mb-4" style={{ background: 'var(--accent)' }}>
            <Lock className="w-8 h-8 text-white" />
          </div>
          <CardTitle className="text-2xl" style={{ fontFamily: 'Chivo, sans-serif' }}>
            {language === "da" ? "Nulstil adgangskode" : "Reset Password"}
          </CardTitle>
          <CardDescription style={{ color: 'var(--text-muted)' }}>
            {language === "da" ? "Indtast din nye adgangskode" : "Enter your new password"}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {success ? (
            <div className="text-center space-y-4">
              <div className="mx-auto w-16 h-16 rounded-full flex items-center justify-center" style={{ background: 'rgba(5, 150, 105, 0.2)' }}>
                <CheckCircle className="w-8 h-8 text-green-500" />
              </div>
              <p style={{ color: 'var(--text-secondary)' }}>
                {language === "da" 
                  ? "Din adgangskode er blevet nulstillet. Du omdirigeres til login..." 
                  : "Your password has been reset. Redirecting to login..."}
              </p>
            </div>
          ) : error && !token ? (
            <div className="text-center space-y-4">
              <div className="mx-auto w-16 h-16 rounded-full flex items-center justify-center" style={{ background: 'rgba(220, 38, 38, 0.2)' }}>
                <AlertTriangle className="w-8 h-8 text-red-500" />
              </div>
              <p style={{ color: 'var(--text-secondary)' }}>{error}</p>
              <Link to="/forgot-password">
                <Button className="btn-f1">
                  {language === "da" ? "Anmod om nyt link" : "Request new link"}
                </Button>
              </Link>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="password">
                  {language === "da" ? "Ny adgangskode" : "New password"}
                </Label>
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
                    data-testid="reset-password"
                  />
                </div>
              </div>
              <div className="space-y-2">
                <Label htmlFor="confirmPassword">
                  {language === "da" ? "Bekræft adgangskode" : "Confirm password"}
                </Label>
                <div className="relative">
                  <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{ color: 'var(--text-muted)' }} />
                  <Input
                    id="confirmPassword"
                    type="password"
                    value={confirmPassword}
                    onChange={(e) => setConfirmPassword(e.target.value)}
                    className="pl-10"
                    placeholder="••••••••"
                    required
                    minLength={6}
                    data-testid="reset-confirm-password"
                  />
                </div>
              </div>
              {error && (
                <div className="p-3 rounded-lg" style={{ background: 'rgba(220, 38, 38, 0.2)', border: '1px solid #dc2626' }}>
                  <p className="text-red-500 text-sm">{error}</p>
                </div>
              )}
              <Button 
                type="submit" 
                className="w-full btn-f1" 
                disabled={loading}
                data-testid="reset-submit"
              >
                {loading ? "..." : (language === "da" ? "Nulstil adgangskode" : "Reset password")}
              </Button>
            </form>
          )}
          <p className="text-center mt-6" style={{ color: 'var(--text-muted)' }}>
            <Link to="/login" className="flex items-center justify-center gap-2" style={{ color: 'var(--accent)' }}>
              <ArrowLeft className="w-4 h-4" />
              {language === "da" ? "Tilbage til login" : "Back to login"}
            </Link>
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
