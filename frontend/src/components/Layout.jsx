import { Link, useLocation } from "react-router-dom";
import { useAuth, useLanguage, useTheme } from "../App";
import { Button } from "./ui/button";
import { 
  DropdownMenu, 
  DropdownMenuContent, 
  DropdownMenuItem, 
  DropdownMenuTrigger 
} from "./ui/dropdown-menu";
import { Sun, Moon, Globe, User, LogOut, Menu, Trophy, Flag, Home, Settings } from "lucide-react";
import { useState, useEffect } from "react";
import axios from "axios";

const API = `${process.env.REACT_APP_BACKEND_URL}/api`;

export default function Layout({ children }) {
  const { user, logout } = useAuth();
  const { language, setLanguage, t } = useLanguage();
  const { theme, setTheme } = useTheme();
  const location = useLocation();
  const [settings, setSettings] = useState(null);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  useEffect(() => {
    axios.get(`${API}/settings`).then(res => setSettings(res.data)).catch(() => {});
  }, []);

  const navLinks = [
    { to: "/", label: t("home"), icon: Home },
    { to: "/races", label: t("races"), icon: Flag },
    { to: "/leaderboard", label: t("leaderboard"), icon: Trophy },
  ];

  if (user?.role === "admin") {
    navLinks.push({ to: "/admin", label: t("admin"), icon: Settings });
  }

  const appTitle = settings?.app_title || "F1 Betting";
  const appYear = settings?.app_year || "2025";

  return (
    <div className="min-h-screen" style={{ background: 'var(--bg-primary)', color: 'var(--text-primary)' }}>
      {/* Header */}
      <header className="sticky top-0 z-50 border-b" style={{ background: 'var(--bg-secondary)', borderColor: 'var(--border-color)' }}>
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex items-center justify-between h-16">
            {/* Logo */}
            <Link to="/" className="flex items-center gap-2" data-testid="app-logo">
              <div className="w-10 h-10 rounded-lg flex items-center justify-center" style={{ background: 'var(--f1-red)' }}>
                <Flag className="w-5 h-5 text-white" />
              </div>
              <div>
                <span className="font-bold text-lg" style={{ fontFamily: 'Chivo, sans-serif' }}>{appTitle}</span>
                <span className="ml-2 text-sm" style={{ color: 'var(--text-muted)' }}>{appYear}</span>
              </div>
            </Link>

            {/* Desktop Navigation */}
            <nav className="hidden md:flex items-center gap-6">
              {navLinks.map(link => (
                <Link
                  key={link.to}
                  to={link.to}
                  className={`nav-link flex items-center gap-2 py-2 ${location.pathname === link.to ? 'active' : ''}`}
                  data-testid={`nav-${link.to.replace('/', '') || 'home'}`}
                >
                  <link.icon className="w-4 h-4" />
                  {link.label}
                </Link>
              ))}
            </nav>

            {/* Right side controls */}
            <div className="flex items-center gap-2">
              {/* Theme Toggle */}
              <Button
                variant="ghost"
                size="icon"
                onClick={() => setTheme(theme === "dark" ? "light" : "dark")}
                data-testid="theme-toggle"
                className="rounded-full"
              >
                {theme === "dark" ? <Sun className="w-5 h-5" /> : <Moon className="w-5 h-5" />}
              </Button>

              {/* Language Toggle */}
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="icon" data-testid="language-toggle" className="rounded-full">
                    <Globe className="w-5 h-5" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                  <DropdownMenuItem onClick={() => setLanguage("en")} data-testid="lang-en">
                    English {language === "en" && "✓"}
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => setLanguage("da")} data-testid="lang-da">
                    Dansk {language === "da" && "✓"}
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>

              {/* User Menu */}
              {user ? (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" className="flex items-center gap-2" data-testid="user-menu">
                      <div className="w-8 h-8 rounded-full flex items-center justify-center" style={{ background: 'var(--f1-red)' }}>
                        <User className="w-4 h-4 text-white" />
                      </div>
                      <span className="hidden sm:inline">{user.display_name || user.email}</span>
                      {user.stars > 0 && (
                        <span className="flex items-center gap-1 text-yellow-500">
                          <span className="star-icon">★</span>
                          <span className="text-sm">{user.stars}</span>
                        </span>
                      )}
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <DropdownMenuItem asChild>
                      <Link to="/profile" className="flex items-center gap-2" data-testid="profile-link">
                        <User className="w-4 h-4" /> {t("profile")}
                      </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={logout} data-testid="logout-btn">
                      <LogOut className="w-4 h-4 mr-2" /> {t("logout")}
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              ) : (
                <div className="flex items-center gap-2">
                  <Link to="/login">
                    <Button className="btn-f1" data-testid="login-link">{t("login")}</Button>
                  </Link>
                </div>
              )}

              {/* Mobile menu button */}
              <Button
                variant="ghost"
                size="icon"
                className="md:hidden"
                onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                data-testid="mobile-menu-btn"
              >
                <Menu className="w-5 h-5" />
              </Button>
            </div>
          </div>
        </div>

        {/* Mobile Navigation */}
        {mobileMenuOpen && (
          <nav className="md:hidden border-t py-4 px-4" style={{ borderColor: 'var(--border-color)' }}>
            {navLinks.map(link => (
              <Link
                key={link.to}
                to={link.to}
                onClick={() => setMobileMenuOpen(false)}
                className={`flex items-center gap-3 py-3 px-4 rounded-lg ${
                  location.pathname === link.to ? 'bg-red-500/10 text-red-500' : ''
                }`}
                style={{ color: location.pathname === link.to ? 'var(--accent)' : 'var(--text-primary)' }}
              >
                <link.icon className="w-5 h-5" />
                {link.label}
              </Link>
            ))}
          </nav>
        )}
      </header>

      {/* Main Content */}
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {children}
      </main>

      {/* Footer */}
      <footer className="border-t py-6 mt-12" style={{ borderColor: 'var(--border-color)', background: 'var(--bg-secondary)' }}>
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center" style={{ color: 'var(--text-muted)' }}>
          <p>{appTitle} {appYear} - {t("pointsSystem")}</p>
        </div>
      </footer>
    </div>
  );
}
