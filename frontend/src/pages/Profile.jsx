import { useState } from "react";
import axios from "axios";
import { useAuth, useLanguage } from "../App";
import { Button } from "../components/ui/button";
import { Input } from "../components/ui/input";
import { Label } from "../components/ui/label";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "../components/ui/card";
import { toast } from "sonner";
import { User, Star, Trophy, Mail, Save } from "lucide-react";

const API = `${process.env.REACT_APP_BACKEND_URL}/api`;

export default function Profile() {
  const { user, updateUser } = useAuth();
  const { language, t } = useLanguage();
  const [displayName, setDisplayName] = useState(user?.display_name || "");
  const [saving, setSaving] = useState(false);

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      const token = localStorage.getItem("token");
      const res = await axios.put(
        `${API}/auth/profile`,
        { display_name: displayName },
        { headers: { Authorization: `Bearer ${token}` }}
      );
      updateUser(res.data);
      toast.success(language === "da" ? "Profil opdateret!" : "Profile updated!");
    } catch (err) {
      toast.error(err.response?.data?.detail || "Failed to update profile");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="max-w-2xl mx-auto animate-fadeIn">
      <h1 className="text-3xl font-bold flex items-center gap-3 mb-6" style={{ fontFamily: 'Chivo, sans-serif' }}>
        <User className="w-8 h-8" style={{ color: 'var(--accent)' }} />
        {t("profile")}
      </h1>

      {/* Stats Cards */}
      <div className="grid grid-cols-2 gap-4 mb-6">
        <Card className="race-card">
          <CardContent className="p-6 text-center">
            <Trophy className="w-8 h-8 mx-auto mb-2" style={{ color: 'var(--accent)' }} />
            <p className="text-3xl font-bold" style={{ fontFamily: 'Chivo, sans-serif' }}>{user?.points || 0}</p>
            <p style={{ color: 'var(--text-muted)' }}>{t("points")}</p>
          </CardContent>
        </Card>
        <Card className="race-card">
          <CardContent className="p-6 text-center">
            <Star className="w-8 h-8 mx-auto mb-2 text-yellow-500 star-icon" />
            <p className="text-3xl font-bold" style={{ fontFamily: 'Chivo, sans-serif' }}>{user?.stars || 0}</p>
            <p style={{ color: 'var(--text-muted)' }}>{t("stars")}</p>
          </CardContent>
        </Card>
      </div>

      {/* Profile Form */}
      <Card className="race-card">
        <CardHeader>
          <CardTitle style={{ fontFamily: 'Chivo, sans-serif' }}>
            {language === "da" ? "Rediger Profil" : "Edit Profile"}
          </CardTitle>
          <CardDescription style={{ color: 'var(--text-muted)' }}>
            {language === "da" ? "Opdater dit visningsnavn" : "Update your display name"}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSave} className="space-y-4">
            <div className="space-y-2">
              <Label>{t("email")}</Label>
              <div className="relative">
                <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{ color: 'var(--text-muted)' }} />
                <Input
                  value={user?.email || ""}
                  disabled
                  className="pl-10"
                  style={{ background: 'var(--bg-secondary)', opacity: 0.7 }}
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label>{t("displayName")}</Label>
              <div className="relative">
                <User className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{ color: 'var(--text-muted)' }} />
                <Input
                  value={displayName}
                  onChange={(e) => setDisplayName(e.target.value)}
                  className="pl-10"
                  placeholder={language === "da" ? "Dit visningsnavn" : "Your display name"}
                  data-testid="profile-display-name"
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label>{t("role")}</Label>
              <Input
                value={user?.role === "admin" ? "Administrator" : (language === "da" ? "Bruger" : "User")}
                disabled
                style={{ background: 'var(--bg-secondary)', opacity: 0.7 }}
              />
            </div>
            <Button 
              type="submit" 
              className="w-full btn-f1" 
              disabled={saving}
              data-testid="profile-save"
            >
              <Save className="w-4 h-4 mr-2" />
              {saving ? "..." : t("save")}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
