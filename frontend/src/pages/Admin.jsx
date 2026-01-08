import { useState, useEffect } from "react";
import axios from "axios";
import { useAuth, useLanguage } from "../App";
import { Button } from "../components/ui/button";
import { Input } from "../components/ui/input";
import { Label } from "../components/ui/label";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "../components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "../components/ui/tabs";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "../components/ui/select";
import { Textarea } from "../components/ui/textarea";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from "../components/ui/dialog";
import { Badge } from "../components/ui/badge";
import { toast } from "sonner";
import { Settings, Users, Flag, Car, Trophy, Plus, Trash2, Edit, Star, Save } from "lucide-react";
import { format, parseISO } from "date-fns";
import { da, enUS } from "date-fns/locale";

const API = `${process.env.REACT_APP_BACKEND_URL}/api`;

export default function Admin() {
  const { user } = useAuth();
  const { language, t } = useLanguage();
  const locale = language === "da" ? da : enUS;

  const [drivers, setDrivers] = useState([]);
  const [races, setRaces] = useState([]);
  const [users, setUsers] = useState([]);
  const [bets, setBets] = useState([]);
  const [settings, setSettings] = useState(null);
  const [loading, setLoading] = useState(true);

  const token = localStorage.getItem("token");
  const authHeaders = { headers: { Authorization: `Bearer ${token}` }};

  useEffect(() => {
    loadAllData();
  }, []);

  const loadAllData = async () => {
    try {
      const [driversRes, racesRes, usersRes, betsRes, settingsRes] = await Promise.all([
        axios.get(`${API}/drivers`),
        axios.get(`${API}/races`),
        axios.get(`${API}/admin/users`, authHeaders),
        axios.get(`${API}/bets`),
        axios.get(`${API}/settings`)
      ]);
      setDrivers(driversRes.data);
      setRaces(racesRes.data);
      setUsers(usersRes.data);
      setBets(betsRes.data);
      setSettings(settingsRes.data);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  // ============ DRIVERS ============
  const [newDriver, setNewDriver] = useState({ name: "", team: "", number: "" });
  const [editingDriver, setEditingDriver] = useState(null);

  const addDriver = async () => {
    try {
      await axios.post(`${API}/drivers`, { ...newDriver, number: parseInt(newDriver.number) }, authHeaders);
      toast.success(language === "da" ? "Kører tilføjet!" : "Driver added!");
      setNewDriver({ name: "", team: "", number: "" });
      loadAllData();
    } catch (err) {
      toast.error(err.response?.data?.detail || "Error");
    }
  };

  const updateDriver = async () => {
    try {
      await axios.put(`${API}/drivers/${editingDriver.id}`, 
        { name: editingDriver.name, team: editingDriver.team, number: parseInt(editingDriver.number) }, 
        authHeaders
      );
      toast.success(language === "da" ? "Kører opdateret!" : "Driver updated!");
      setEditingDriver(null);
      loadAllData();
    } catch (err) {
      toast.error(err.response?.data?.detail || "Error");
    }
  };

  const deleteDriver = async (id) => {
    if (!window.confirm(language === "da" ? "Slet denne kører?" : "Delete this driver?")) return;
    try {
      await axios.delete(`${API}/drivers/${id}`, authHeaders);
      toast.success(language === "da" ? "Kører slettet!" : "Driver deleted!");
      loadAllData();
    } catch (err) {
      toast.error(err.response?.data?.detail || "Error");
    }
  };

  // ============ RACES ============
  const [newRace, setNewRace] = useState({ 
    name: "", location: "", race_date: "", race_time: "", 
    quali_p1: "", quali_p2: "", quali_p3: "" 
  });
  const [editingRace, setEditingRace] = useState(null);

  const addRace = async () => {
    try {
      await axios.post(`${API}/races`, newRace, authHeaders);
      toast.success(language === "da" ? "Løb tilføjet!" : "Race added!");
      setNewRace({ name: "", location: "", race_date: "", race_time: "", quali_p1: "", quali_p2: "", quali_p3: "" });
      loadAllData();
    } catch (err) {
      toast.error(err.response?.data?.detail || "Error");
    }
  };

  const updateRace = async () => {
    try {
      await axios.put(`${API}/races/${editingRace.id}`, editingRace, authHeaders);
      toast.success(language === "da" ? "Løb opdateret!" : "Race updated!");
      setEditingRace(null);
      loadAllData();
    } catch (err) {
      toast.error(err.response?.data?.detail || "Error");
    }
  };

  const deleteRace = async (id) => {
    if (!window.confirm(language === "da" ? "Slet dette løb?" : "Delete this race?")) return;
    try {
      await axios.delete(`${API}/races/${id}`, authHeaders);
      toast.success(language === "da" ? "Løb slettet!" : "Race deleted!");
      loadAllData();
    } catch (err) {
      toast.error(err.response?.data?.detail || "Error");
    }
  };

  // ============ USERS ============
  const toggleUserRole = async (userId, currentRole) => {
    const newRole = currentRole === "admin" ? "user" : "admin";
    try {
      await axios.put(`${API}/admin/users/${userId}/role?role=${newRole}`, {}, authHeaders);
      toast.success(language === "da" ? "Rolle opdateret!" : "Role updated!");
      loadAllData();
    } catch (err) {
      toast.error(err.response?.data?.detail || "Error");
    }
  };

  const [deleteConfirm, setDeleteConfirm] = useState(null);

  const deleteUser = async (userId) => {
    try {
      await axios.delete(`${API}/admin/users/${userId}`, authHeaders);
      toast.success(language === "da" ? "Bruger slettet!" : "User deleted!");
      setDeleteConfirm(null);
      loadAllData();
    } catch (err) {
      toast.error(err.response?.data?.detail || "Error deleting user");
    }
  };

  // ============ SETTINGS ============
  const [editSettings, setEditSettings] = useState(null);

  useEffect(() => {
    if (settings) setEditSettings({ ...settings });
  }, [settings]);

  const saveSettings = async () => {
    try {
      await axios.put(`${API}/settings`, editSettings, authHeaders);
      toast.success(language === "da" ? "Indstillinger gemt!" : "Settings saved!");
      loadAllData();
    } catch (err) {
      toast.error(err.response?.data?.detail || "Error");
    }
  };

  const getDriver = (id) => drivers.find(d => d.id === id);

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
        <Settings className="w-8 h-8" style={{ color: 'var(--accent)' }} />
        {t("admin")}
      </h1>

      <Tabs defaultValue="drivers" className="space-y-4">
        <TabsList className="grid grid-cols-5 w-full max-w-2xl">
          <TabsTrigger value="drivers" data-testid="tab-drivers"><Car className="w-4 h-4 mr-2" />{t("drivers")}</TabsTrigger>
          <TabsTrigger value="races" data-testid="tab-races"><Flag className="w-4 h-4 mr-2" />{t("races")}</TabsTrigger>
          <TabsTrigger value="users" data-testid="tab-users"><Users className="w-4 h-4 mr-2" />{t("users")}</TabsTrigger>
          <TabsTrigger value="bets" data-testid="tab-bets"><Trophy className="w-4 h-4 mr-2" />{t("bets")}</TabsTrigger>
          <TabsTrigger value="settings" data-testid="tab-settings"><Settings className="w-4 h-4 mr-2" />{t("settings")}</TabsTrigger>
        </TabsList>

        {/* DRIVERS TAB */}
        <TabsContent value="drivers">
          <Card className="race-card mb-4">
            <CardHeader>
              <CardTitle>{language === "da" ? "Tilføj Kører" : "Add Driver"}</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                <Input 
                  placeholder={t("name")} 
                  value={newDriver.name} 
                  onChange={e => setNewDriver({...newDriver, name: e.target.value})}
                  data-testid="new-driver-name"
                />
                <Input 
                  placeholder={t("team")} 
                  value={newDriver.team} 
                  onChange={e => setNewDriver({...newDriver, team: e.target.value})}
                  data-testid="new-driver-team"
                />
                <Input 
                  placeholder={t("number")} 
                  type="number"
                  value={newDriver.number} 
                  onChange={e => setNewDriver({...newDriver, number: e.target.value})}
                  data-testid="new-driver-number"
                />
                <Button onClick={addDriver} className="btn-f1" data-testid="add-driver-btn">
                  <Plus className="w-4 h-4 mr-2" />{t("add")}
                </Button>
              </div>
            </CardContent>
          </Card>

          <div className="grid gap-2">
            {drivers.map(driver => (
              <Card key={driver.id} className="race-card">
                <CardContent className="p-4 flex items-center justify-between">
                  <div className="flex items-center gap-4">
                    <span className="font-bold text-xl" style={{ color: 'var(--accent)' }}>#{driver.number}</span>
                    <div>
                      <p className="font-semibold">{driver.name}</p>
                      <p style={{ color: 'var(--text-muted)' }}>{driver.team}</p>
                    </div>
                  </div>
                  <div className="flex gap-2">
                    <Dialog>
                      <DialogTrigger asChild>
                        <Button variant="outline" size="sm" onClick={() => setEditingDriver({...driver})}>
                          <Edit className="w-4 h-4" />
                        </Button>
                      </DialogTrigger>
                      <DialogContent>
                        <DialogHeader>
                          <DialogTitle>{language === "da" ? "Rediger Kører" : "Edit Driver"}</DialogTitle>
                        </DialogHeader>
                        {editingDriver && (
                          <div className="space-y-4">
                            <Input 
                              value={editingDriver.name} 
                              onChange={e => setEditingDriver({...editingDriver, name: e.target.value})}
                              placeholder={t("name")}
                            />
                            <Input 
                              value={editingDriver.team} 
                              onChange={e => setEditingDriver({...editingDriver, team: e.target.value})}
                              placeholder={t("team")}
                            />
                            <Input 
                              type="number"
                              value={editingDriver.number} 
                              onChange={e => setEditingDriver({...editingDriver, number: e.target.value})}
                              placeholder={t("number")}
                            />
                            <Button onClick={updateDriver} className="w-full btn-f1">{t("save")}</Button>
                          </div>
                        )}
                      </DialogContent>
                    </Dialog>
                    <Button variant="destructive" size="sm" onClick={() => deleteDriver(driver.id)}>
                      <Trash2 className="w-4 h-4" />
                    </Button>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </TabsContent>

        {/* RACES TAB */}
        <TabsContent value="races">
          <Card className="race-card mb-4">
            <CardHeader>
              <CardTitle>{language === "da" ? "Tilføj Løb" : "Add Race"}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Input placeholder={t("name")} value={newRace.name} onChange={e => setNewRace({...newRace, name: e.target.value})} />
                <Input placeholder={t("location")} value={newRace.location} onChange={e => setNewRace({...newRace, location: e.target.value})} />
                <Input type="date" value={newRace.race_date} onChange={e => setNewRace({...newRace, race_date: e.target.value})} />
                <Input type="time" value={newRace.race_time} onChange={e => setNewRace({...newRace, race_time: e.target.value})} />
              </div>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <Label>Quali P1</Label>
                  <Select value={newRace.quali_p1} onValueChange={v => setNewRace({...newRace, quali_p1: v})}>
                    <SelectTrigger><SelectValue placeholder={t("selectDriver")} /></SelectTrigger>
                    <SelectContent>
                      {drivers.map(d => <SelectItem key={d.id} value={d.id}>{d.name}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>Quali P2</Label>
                  <Select value={newRace.quali_p2} onValueChange={v => setNewRace({...newRace, quali_p2: v})}>
                    <SelectTrigger><SelectValue placeholder={t("selectDriver")} /></SelectTrigger>
                    <SelectContent>
                      {drivers.map(d => <SelectItem key={d.id} value={d.id}>{d.name}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>Quali P3</Label>
                  <Select value={newRace.quali_p3} onValueChange={v => setNewRace({...newRace, quali_p3: v})}>
                    <SelectTrigger><SelectValue placeholder={t("selectDriver")} /></SelectTrigger>
                    <SelectContent>
                      {drivers.map(d => <SelectItem key={d.id} value={d.id}>{d.name}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <Button onClick={addRace} className="btn-f1" data-testid="add-race-btn">
                <Plus className="w-4 h-4 mr-2" />{t("add")}
              </Button>
            </CardContent>
          </Card>

          <div className="space-y-2">
            {races.map(race => (
              <Card key={race.id} className="race-card">
                <CardContent className="p-4">
                  <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                      <h3 className="font-bold">{race.name}</h3>
                      <p style={{ color: 'var(--text-muted)' }}>{race.location} - {race.race_date} {race.race_time}</p>
                      {race.quali_p1 && (
                        <p className="text-sm mt-1">
                          {t("qualifying")}: {getDriver(race.quali_p1)?.name}, {getDriver(race.quali_p2)?.name}, {getDriver(race.quali_p3)?.name}
                        </p>
                      )}
                      {race.result_p1 && (
                        <p className="text-sm mt-1" style={{ color: 'var(--accent)' }}>
                          {t("results")}: {getDriver(race.result_p1)?.name}, {getDriver(race.result_p2)?.name}, {getDriver(race.result_p3)?.name}
                        </p>
                      )}
                    </div>
                    <div className="flex gap-2">
                      <Dialog>
                        <DialogTrigger asChild>
                          <Button variant="outline" size="sm" onClick={() => setEditingRace({...race})}>
                            <Edit className="w-4 h-4" />
                          </Button>
                        </DialogTrigger>
                        <DialogContent className="max-w-2xl">
                          <DialogHeader>
                            <DialogTitle>{language === "da" ? "Rediger Løb" : "Edit Race"}</DialogTitle>
                          </DialogHeader>
                          {editingRace && (
                            <div className="space-y-4">
                              <div className="grid grid-cols-2 gap-4">
                                <Input value={editingRace.name} onChange={e => setEditingRace({...editingRace, name: e.target.value})} placeholder={t("name")} />
                                <Input value={editingRace.location} onChange={e => setEditingRace({...editingRace, location: e.target.value})} placeholder={t("location")} />
                                <Input type="date" value={editingRace.race_date} onChange={e => setEditingRace({...editingRace, race_date: e.target.value})} />
                                <Input type="time" value={editingRace.race_time} onChange={e => setEditingRace({...editingRace, race_time: e.target.value})} />
                              </div>
                              <div>
                                <Label className="mb-2 block">{t("qualifying")}</Label>
                                <div className="grid grid-cols-3 gap-2">
                                  {["quali_p1", "quali_p2", "quali_p3"].map((key, idx) => (
                                    <Select key={key} value={editingRace[key] || ""} onValueChange={v => setEditingRace({...editingRace, [key]: v})}>
                                      <SelectTrigger><SelectValue placeholder={`P${idx+1}`} /></SelectTrigger>
                                      <SelectContent>
                                        {drivers.map(d => <SelectItem key={d.id} value={d.id}>{d.name}</SelectItem>)}
                                      </SelectContent>
                                    </Select>
                                  ))}
                                </div>
                              </div>
                              <div>
                                <Label className="mb-2 block">{t("results")}</Label>
                                <div className="grid grid-cols-3 gap-2">
                                  {["result_p1", "result_p2", "result_p3"].map((key, idx) => (
                                    <Select key={key} value={editingRace[key] || ""} onValueChange={v => setEditingRace({...editingRace, [key]: v})}>
                                      <SelectTrigger><SelectValue placeholder={`P${idx+1}`} /></SelectTrigger>
                                      <SelectContent>
                                        {drivers.map(d => <SelectItem key={d.id} value={d.id}>{d.name}</SelectItem>)}
                                      </SelectContent>
                                    </Select>
                                  ))}
                                </div>
                              </div>
                              <Button onClick={updateRace} className="w-full btn-f1">{t("save")}</Button>
                            </div>
                          )}
                        </DialogContent>
                      </Dialog>
                      <Button variant="destructive" size="sm" onClick={() => deleteRace(race.id)}>
                        <Trash2 className="w-4 h-4" />
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </TabsContent>

        {/* USERS TAB */}
        <TabsContent value="users">
          <div className="space-y-2">
            {users.map(u => (
              <Card key={u.id} className="race-card">
                <CardContent className="p-4 flex items-center justify-between">
                  <div className="flex items-center gap-4">
                    <div className="w-10 h-10 rounded-full flex items-center justify-center" style={{ background: 'var(--accent)' }}>
                      {(u.display_name || u.email)?.[0]?.toUpperCase()}
                    </div>
                    <div>
                      <p className="font-semibold">{u.display_name || u.email}</p>
                      <p className="text-sm" style={{ color: 'var(--text-muted)' }}>{u.email}</p>
                    </div>
                    <Badge variant={u.role === "admin" ? "default" : "secondary"}>{u.role}</Badge>
                    {u.stars > 0 && (
                      <span className="flex items-center gap-1 text-yellow-500">
                        <Star className="w-4 h-4 star-icon" /> {u.stars}
                      </span>
                    )}
                    <span style={{ color: 'var(--accent)' }}>{u.points} pts</span>
                  </div>
                  <div className="flex gap-2">
                    {u.id !== user?.id && (
                      <>
                        <Button variant="outline" size="sm" type="button" onClick={() => toggleUserRole(u.id, u.role)}>
                          {u.role === "admin" ? t("makeUser") : t("makeAdmin")}
                        </Button>
                        <Button 
                          variant="destructive" 
                          size="sm" 
                          type="button"
                          onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            deleteUser(u.id);
                          }} 
                          data-testid={`delete-user-${u.id}`}
                        >
                          <Trash2 className="w-4 h-4" />
                        </Button>
                      </>
                    )}
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </TabsContent>

        {/* BETS TAB */}
        <TabsContent value="bets">
          {races.map(race => {
            const raceBets = bets.filter(b => b.race_id === race.id);
            if (raceBets.length === 0) return null;
            return (
              <Card key={race.id} className="race-card mb-4">
                <CardHeader>
                  <CardTitle className="flex items-center justify-between">
                    <span>{race.name}</span>
                    <Badge>{raceBets.length} bets</Badge>
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="space-y-2">
                    {raceBets.map(bet => (
                      <div 
                        key={bet.id} 
                        className={`p-3 rounded-lg flex items-center justify-between ${bet.is_perfect ? 'perfect-bet' : ''}`}
                        style={{ background: 'var(--bg-secondary)', border: '1px solid var(--border-color)' }}
                      >
                        <div className="flex items-center gap-3">
                          <div>
                            <p className="font-medium flex items-center gap-2">
                              {bet.user_display_name || bet.user_email}
                              {bet.is_perfect && <Star className="w-4 h-4 text-yellow-500 star-icon" />}
                            </p>
                            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                              {format(parseISO(bet.placed_at), "d MMM HH:mm", { locale })}
                            </p>
                          </div>
                        </div>
                        <div className="flex items-center gap-3">
                          <div className="flex gap-2">
                            {[bet.p1, bet.p2, bet.p3].map((driverId, idx) => (
                              <span key={idx} className="text-sm px-2 py-1 rounded" style={{ background: 'var(--bg-card)' }}>
                                P{idx + 1}: {getDriver(driverId)?.name?.split(' ').pop()}
                              </span>
                            ))}
                          </div>
                          {bet.points > 0 && <Badge style={{ background: 'var(--accent)' }}>{bet.points} pts</Badge>}
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            );
          })}
        </TabsContent>

        {/* SETTINGS TAB */}
        <TabsContent value="settings">
          {editSettings && (
            <Card className="race-card">
              <CardHeader>
                <CardTitle>{t("settings")}</CardTitle>
              </CardHeader>
              <CardContent className="space-y-6">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <Label>{t("appTitle")}</Label>
                    <Input 
                      value={editSettings.app_title} 
                      onChange={e => setEditSettings({...editSettings, app_title: e.target.value})}
                      data-testid="settings-app-title"
                    />
                  </div>
                  <div>
                    <Label>{t("appYear")}</Label>
                    <Input 
                      value={editSettings.app_year} 
                      onChange={e => setEditSettings({...editSettings, app_year: e.target.value})}
                      data-testid="settings-app-year"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <Label>{t("heroTitle")} (English)</Label>
                    <Input 
                      value={editSettings.hero_title_en} 
                      onChange={e => setEditSettings({...editSettings, hero_title_en: e.target.value})}
                    />
                  </div>
                  <div>
                    <Label>{t("heroTitle")} (Dansk)</Label>
                    <Input 
                      value={editSettings.hero_title_da} 
                      onChange={e => setEditSettings({...editSettings, hero_title_da: e.target.value})}
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <Label>{t("heroText")} (English)</Label>
                    <Textarea 
                      value={editSettings.hero_text_en} 
                      onChange={e => setEditSettings({...editSettings, hero_text_en: e.target.value})}
                      rows={3}
                    />
                  </div>
                  <div>
                    <Label>{t("heroText")} (Dansk)</Label>
                    <Textarea 
                      value={editSettings.hero_text_da} 
                      onChange={e => setEditSettings({...editSettings, hero_text_da: e.target.value})}
                      rows={3}
                    />
                  </div>
                </div>

                <Button onClick={saveSettings} className="btn-f1" data-testid="save-settings-btn">
                  <Save className="w-4 h-4 mr-2" />{t("save")}
                </Button>
              </CardContent>
            </Card>
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
}
