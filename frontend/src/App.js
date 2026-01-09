import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import { useState, useEffect, createContext, useContext } from "react";
import axios from "axios";
import { Toaster } from "./components/ui/sonner";
import Layout from "./components/Layout";
import Home from "./pages/Home";
import Login from "./pages/Login";
import Register from "./pages/Register";
import PlaceBet from "./pages/PlaceBet";
import Races from "./pages/Races";
import Leaderboard from "./pages/Leaderboard";
import Profile from "./pages/Profile";
import Admin from "./pages/Admin";
import ForgotPassword from "./pages/ForgotPassword";
import ResetPassword from "./pages/ResetPassword";
import "./App.css";

const API = `${process.env.REACT_APP_BACKEND_URL}/api`;

// Auth Context
export const AuthContext = createContext(null);

// Language Context
export const LanguageContext = createContext(null);

// Theme Context  
export const ThemeContext = createContext(null);

const translations = {
  en: {
    home: "Home",
    races: "Races",
    leaderboard: "Leaderboard",
    admin: "Admin",
    profile: "Profile",
    login: "Login",
    register: "Register",
    logout: "Logout",
    placeBet: "Place Bet",
    upcomingRaces: "Upcoming Races",
    yourBets: "Your Bets",
    allBets: "All Bets",
    points: "Points",
    stars: "Stars",
    rank: "Rank",
    user: "User",
    p1: "P1",
    p2: "P2", 
    p3: "P3",
    placedAt: "Placed At",
    bettingOpen: "Betting Open",
    bettingClosed: "Betting Closed",
    bettingNotOpen: "Betting Not Open Yet",
    raceCompleted: "Race Completed",
    submit: "Submit",
    save: "Save",
    delete: "Delete",
    edit: "Edit",
    add: "Add",
    cancel: "Cancel",
    drivers: "Drivers",
    users: "Users",
    bets: "Bets",
    settings: "Settings",
    displayName: "Display Name",
    email: "Email",
    password: "Password",
    team: "Team",
    number: "Number",
    name: "Name",
    location: "Location",
    raceDate: "Race Date",
    raceTime: "Race Time",
    qualifying: "Qualifying",
    results: "Results",
    appTitle: "App Title",
    appYear: "Year",
    heroTitle: "Hero Title",
    heroText: "Hero Text",
    perfectBet: "Perfect!",
    noBets: "No bets yet",
    selectDriver: "Select driver",
    bettingWindow: "Betting opens 48h before race",
    pointsSystem: "Points: P1=25, P2=18, P3=15, +5 for top 3 wrong position",
    role: "Role",
    makeAdmin: "Make Admin",
    makeUser: "Make User",
  },
  da: {
    home: "Hjem",
    races: "Løb",
    leaderboard: "Rangliste",
    admin: "Admin",
    profile: "Profil",
    login: "Log ind",
    register: "Registrer",
    logout: "Log ud",
    placeBet: "Placer Bet",
    upcomingRaces: "Kommende Løb",
    yourBets: "Dine Bets",
    allBets: "Alle Bets",
    points: "Point",
    stars: "Stjerner",
    rank: "Rang",
    user: "Bruger",
    p1: "P1",
    p2: "P2",
    p3: "P3",
    placedAt: "Placeret",
    bettingOpen: "Betting Åben",
    bettingClosed: "Betting Lukket",
    bettingNotOpen: "Betting Ikke Åben Endnu",
    raceCompleted: "Løb Afsluttet",
    submit: "Indsend",
    save: "Gem",
    delete: "Slet",
    edit: "Rediger",
    add: "Tilføj",
    cancel: "Annuller",
    drivers: "Kørere",
    users: "Brugere",
    bets: "Bets",
    settings: "Indstillinger",
    displayName: "Visningsnavn",
    email: "E-mail",
    password: "Adgangskode",
    team: "Hold",
    number: "Nummer",
    name: "Navn",
    location: "Sted",
    raceDate: "Løbsdato",
    raceTime: "Starttid",
    qualifying: "Kvalifikation",
    results: "Resultater",
    appTitle: "App Titel",
    appYear: "År",
    heroTitle: "Overskrift",
    heroText: "Velkomsttekst",
    perfectBet: "Perfekt!",
    noBets: "Ingen bets endnu",
    selectDriver: "Vælg kører",
    bettingWindow: "Betting åbner 48t før løb",
    pointsSystem: "Point: P1=25, P2=18, P3=15, +5 for top 3 forkert position",
    role: "Rolle",
    makeAdmin: "Gør Admin",
    makeUser: "Gør Bruger",
  }
};

export const useAuth = () => useContext(AuthContext);
export const useLanguage = () => useContext(LanguageContext);
export const useTheme = () => useContext(ThemeContext);

function App() {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [language, setLanguage] = useState(() => localStorage.getItem("language") || "da");
  const [theme, setTheme] = useState(() => localStorage.getItem("theme") || "dark");

  const t = (key) => translations[language]?.[key] || key;

  useEffect(() => {
    const token = localStorage.getItem("token");
    if (token) {
      axios.get(`${API}/auth/me`, {
        headers: { Authorization: `Bearer ${token}` }
      })
      .then(res => setUser(res.data))
      .catch(() => localStorage.removeItem("token"))
      .finally(() => setLoading(false));
    } else {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    localStorage.setItem("language", language);
  }, [language]);

  useEffect(() => {
    localStorage.setItem("theme", theme);
    document.documentElement.classList.toggle("dark", theme === "dark");
  }, [theme]);

  const login = async (email, password) => {
    const res = await axios.post(`${API}/auth/login`, { email, password });
    localStorage.setItem("token", res.data.token);
    setUser(res.data.user);
    return res.data;
  };

  const register = async (email, password, displayName) => {
    const res = await axios.post(`${API}/auth/register`, { 
      email, 
      password, 
      display_name: displayName 
    });
    localStorage.setItem("token", res.data.token);
    setUser(res.data.user);
    return res.data;
  };

  const logout = () => {
    localStorage.removeItem("token");
    setUser(null);
  };

  const updateUser = (updates) => {
    setUser(prev => ({ ...prev, ...updates }));
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-red-500"></div>
      </div>
    );
  }

  return (
    <ThemeContext.Provider value={{ theme, setTheme }}>
      <LanguageContext.Provider value={{ language, setLanguage, t }}>
        <AuthContext.Provider value={{ user, login, register, logout, updateUser }}>
          <BrowserRouter>
            <div className={theme}>
              <Layout>
                <Routes>
                  <Route path="/" element={<Home />} />
                  <Route path="/login" element={!user ? <Login /> : <Navigate to="/" />} />
                  <Route path="/register" element={!user ? <Register /> : <Navigate to="/" />} />
                  <Route path="/forgot-password" element={!user ? <ForgotPassword /> : <Navigate to="/" />} />
                  <Route path="/reset-password" element={!user ? <ResetPassword /> : <Navigate to="/" />} />
                  <Route path="/races" element={<Races />} />
                  <Route path="/bet/:raceId" element={user ? <PlaceBet /> : <Navigate to="/login" />} />
                  <Route path="/leaderboard" element={<Leaderboard />} />
                  <Route path="/profile" element={user ? <Profile /> : <Navigate to="/login" />} />
                  <Route path="/admin" element={user?.role === "admin" ? <Admin /> : <Navigate to="/" />} />
                </Routes>
              </Layout>
              <Toaster />
            </div>
          </BrowserRouter>
        </AuthContext.Provider>
      </LanguageContext.Provider>
    </ThemeContext.Provider>
  );
}

export default App;
