import { useState } from "react";
import { Link } from "react-router-dom";
import { useLanguage } from "../App";
import { Button } from "../components/ui/button";
import { Input } from "../components/ui/input";
import { Label } from "../components/ui/label";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "../components/ui/card";
import { toast } from "sonner";
import { Flag, Mail, ArrowLeft, CheckCircle } from "lucide-react";
import axios from "axios";

const API = `${process.env.REACT_APP_BACKEND_URL}/api`;

export default function ForgotPassword() {
  const { language } = useLanguage();
  const [email, setEmail] = useState("");
  const [loading, setLoading] = useState(false);
  const [sent, setSent] = useState(false);
  const [resetToken, setResetToken] = useState("");

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      const res = await axios.post(`${API}/auth/forgot-password`, { email });
      setSent(true);
      // For demo/testing, show token if returned
      if (res.data.token) {
        setResetToken(res.data.token);
      }
      toast.success(language === "da" ? "Tjek din email!" : "Check your email!");
    } catch (err) {
      toast.error(err.response?.data?.detail || "Error");
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
          <CardTitle className="text-2xl" style={{ fontFamily: 'Chivo, sans-serif' }}>
            {language === "da" ? "Glemt adgangskode" : "Forgot Password"}
          </CardTitle>
          <CardDescription style={{ color: 'var(--text-muted)' }}>
            {language === "da" 
              ? "Indtast din email for at nulstille din adgangskode" 
              : "Enter your email to reset your password"}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {sent ? (
            <div className="text-center space-y-4">
              <div className="mx-auto w-16 h-16 rounded-full flex items-center justify-center" style={{ background: 'rgba(5, 150, 105, 0.2)' }}>
                <CheckCircle className="w-8 h-8 text-green-500" />
              </div>
              <p style={{ color: 'var(--text-secondary)' }}>
                {language === "da" 
                  ? "Hvis emailen findes i systemet, vil du modtage et nulstillingslink." 
                  : "If the email exists in our system, you will receive a reset link."}
              </p>
              {resetToken && (
                <div className="p-4 rounded-lg text-left" style={{ background: 'var(--bg-secondary)', border: '1px solid var(--border-color)' }}>
                  <p className="text-sm mb-2" style={{ color: 'var(--text-muted)' }}>
                    {language === "da" ? "Test link (kun til udvikling):" : "Test link (development only):"}
                  </p>
                  <Link 
                    to={`/reset-password?token=${resetToken}`}
                    className="text-sm break-all"
                    style={{ color: 'var(--accent)' }}
                  >
                    /reset-password?token={resetToken.substring(0, 20)}...
                  </Link>
                </div>
              )}
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="email">{language === "da" ? "E-mail" : "Email"}</Label>
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
                    data-testid="forgot-email"
                  />
                </div>
              </div>
              <Button 
                type="submit" 
                className="w-full btn-f1" 
                disabled={loading}
                data-testid="forgot-submit"
              >
                {loading ? "..." : (language === "da" ? "Send nulstillingslink" : "Send reset link")}
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
