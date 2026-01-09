import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useAuth, useLanguage } from "../App";
import { Button } from "../components/ui/button";
import { Input } from "../components/ui/input";
import { Label } from "../components/ui/label";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "../components/ui/card";
import { toast } from "sonner";
import { Flag, Mail, Lock } from "lucide-react";

export default function Login() {
  const { login } = useAuth();
  const { t } = useLanguage();
  const navigate = useNavigate();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      await login(email, password);
      toast.success(t("login") + " OK!");
      navigate("/");
    } catch (err) {
      toast.error(err.response?.data?.detail || "Login failed");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-[70vh] flex items-center justify-center animate-fadeIn">
      <Card className="w-full max-w-md race-card" style={{ background: 'var(--bg-card)' }}>
        <CardHeader className="text-center">
          <div className="mx-auto w-16 h-16 rounded-2xl flex items-center justify-center mb-4" style={{ background: 'var(--accent)' }}>
            <Flag className="w-8 h-8 text-white" />
          </div>
          <CardTitle className="text-2xl" style={{ fontFamily: 'Chivo, sans-serif' }}>{t("login")}</CardTitle>
          <CardDescription style={{ color: 'var(--text-muted)' }}>
            {t("email")} & {t("password")}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="email">{t("email")}</Label>
              <div className="relative">
                <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{ color: 'var(--text-muted)' }} />
                <Input
                  id="email"
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="pl-10"
                  placeholder="name@example.com"
                  required
                  data-testid="login-email"
                />
              </div>
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
                  data-testid="login-password"
                />
              </div>
              <div className="text-right">
                <Link 
                  to="/forgot-password" 
                  className="text-sm hover:underline" 
                  style={{ color: 'var(--accent)' }}
                  data-testid="forgot-password-link"
                >
                  {t("forgotPassword")}
                </Link>
              </div>
            </div>
            <Button 
              type="submit" 
              className="w-full btn-f1" 
              disabled={loading}
              data-testid="login-submit"
            >
              {loading ? "..." : t("login")}
            </Button>
          </form>
          <p className="text-center mt-6" style={{ color: 'var(--text-muted)' }}>
            {t("register")}?{" "}
            <Link to="/register" className="font-medium" style={{ color: 'var(--accent)' }} data-testid="register-link">
              {t("register")}
            </Link>
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
