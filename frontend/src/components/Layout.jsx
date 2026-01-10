import { Link, useLocation, useNavigate } from "react-router-dom";
import { useAuth, useLanguage, useTheme } from "../App";
import { Button } from "./ui/button";
import { 
  DropdownMenu, 
  DropdownMenuContent, 
  DropdownMenuItem, 
  DropdownMenuTrigger 
} from "./ui/dropdown-menu";
import { Sun, Moon, Globe, User, LogOut, Menu, Trophy, Flag, Home, Settings, BookOpen, X } from "lucide-react";
import { useState, useEffect } from "react";
import axios from "axios";

const API = `${process.env.REACT_APP_BACKEND_URL}/api`;

export default function Layout({ children }) {
  const { user, logout } = useAuth();
  const { language, setLanguage, t } = useLanguage();
  const { theme, setTheme } = useTheme();
  const location = useLocation();
  const navigate = useNavigate();
  const [settings, setSettings] = useState(null);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  useEffect(() => {
    axios.get(`${API}/settings`).then(res => setSettings(res.data)).catch(() => {});
  }, []);

  // Close mobile menu on route change
  useEffect(() => {
    if (mobileMenuOpen) {
      setMobileMenuOpen(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname]);

  const navLinks = [
    { to: "/", label: t("home"), icon: Home },
    { to: "/leaderboard", label: t("leaderboard"), icon: Trophy },
    { to: "/races", label: t("races"), icon: Flag },
  ];

  if (user) {
    navLinks.push({ to: "/rules", label: language === "da" ? "Regler" : "Rules", icon: BookOpen });
  }

  if (user?.role === "admin") {
    navLinks.push({ to: "/admin", label: t("admin"), icon: Settings });
  }

  const appTitle = settings?.app_title || "F1 Betting";
  const appYear = settings?.app_year || "2025";

  const handleLogout = () => {
    logout();
    navigate("/");
  };

  return (
    <div className="min-h-screen" style={{ background: 'var(--bg-primary)', color: 'var(--text-primary)' }}>
      {/* Mobile menu overlay */}
      {mobileMenuOpen && (
        <div 
          className="fixed inset-0 bg-black/50 z-40 md:hidden"
          onClick={() => setMobileMenuOpen(false)}
        />
      )}

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
                <span className="ml-2 text-sm hidden sm:inline" style={{ color: 'var(--text-muted)' }}>{appYear}</span>
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
              {/* Theme Toggle - Desktop only */}
              <Button
                variant="ghost"
                size="icon"
                onClick={() => setTheme(theme === "dark" ? "light" : "dark")}
                data-testid="theme-toggle"
                className="rounded-full hidden sm:flex"
              >
                {theme === "dark" ? <Sun className="w-5 h-5" /> : <Moon className="w-5 h-5" />}
              </Button>

              {/* Language Toggle - Desktop only */}
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="icon" data-testid="language-toggle" className="rounded-full hidden sm:flex">
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

              {/* User Menu - Desktop only */}
              {user ? (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" className="hidden sm:flex items-center gap-2" data-testid="user-menu">
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
                    <DropdownMenuItem onClick={handleLogout} data-testid="logout-btn">
                      <LogOut className="w-4 h-4 mr-2" /> {t("logout")}
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              ) : (
                <div className="hidden sm:flex items-center gap-2">
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
                {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
              </Button>
            </div>
          </div>
        </div>
      </header>

      {/* Mobile Navigation - Slide from right */}
      <div 
        className={`fixed top-0 right-0 w-72 h-full z-50 transform transition-transform duration-300 ease-in-out md:hidden ${
          mobileMenuOpen ? 'translate-x-0' : 'translate-x-full'
        }`}
        style={{ background: 'var(--bg-card)' }}
      >
        <div className="flex flex-col h-full">
          {/* Mobile menu header */}
          <div className="flex items-center justify-between p-4 border-b" style={{ borderColor: 'var(--border-color)' }}>
            <span className="font-bold" style={{ fontFamily: 'Chivo, sans-serif' }}>{appTitle}</span>
            <Button variant="ghost" size="icon" onClick={() => setMobileMenuOpen(false)}>
              <X className="w-5 h-5" />
            </Button>
          </div>

          {/* Navigation links */}
          <nav className="flex-1 py-4">
            {navLinks.map(link => (
              <Link
                key={link.to}
                to={link.to}
                className={`flex items-center gap-3 py-3 px-6 border-r-4 ${
                  location.pathname === link.to 
                    ? 'border-red-500 bg-red-500/10' 
                    : 'border-transparent'
                }`}
                style={{ color: location.pathname === link.to ? 'var(--accent)' : 'var(--text-primary)' }}
              >
                <link.icon className="w-5 h-5" />
                {link.label}
              </Link>
            ))}

            {/* Divider */}
            <div className="my-4 border-t mx-4" style={{ borderColor: 'var(--border-color)' }} />

            {/* User section in mobile */}
            {user ? (
              <>
                <Link
                  to="/profile"
                  className="flex items-center gap-3 py-3 px-6"
                  style={{ color: 'var(--text-primary)' }}
                >
                  <User className="w-5 h-5" />
                  {t("profile")}
                </Link>
                <button
                  onClick={handleLogout}
                  className="flex items-center gap-3 py-3 px-6 w-full text-left"
                  style={{ color: 'var(--text-secondary)' }}
                >
                  <LogOut className="w-5 h-5" />
                  {t("logout")}
                </button>
              </>
            ) : (
              <Link
                to="/login"
                className="flex items-center gap-3 py-3 px-6"
                style={{ color: 'var(--accent)' }}
              >
                <User className="w-5 h-5" />
                {t("login")}
              </Link>
            )}
          </nav>

          {/* Mobile theme/language controls */}
          <div className="p-4 border-t" style={{ borderColor: 'var(--border-color)' }}>
            <div className="flex gap-2">
              <Button
                variant="outline"
                className="flex-1"
                onClick={() => setTheme(theme === "dark" ? "light" : "dark")}
              >
                {theme === "dark" ? <Sun className="w-4 h-4 mr-2" /> : <Moon className="w-4 h-4 mr-2" />}
                {theme === "dark" ? "Light" : "Dark"}
              </Button>
              <Button
                variant="outline"
                className="flex-1"
                onClick={() => setLanguage(language === "da" ? "en" : "da")}
              >
                <Globe className="w-4 h-4 mr-2" />
                {language === "da" ? "EN" : "DA"}
              </Button>
            </div>
          </div>
        </div>
      </div>

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
