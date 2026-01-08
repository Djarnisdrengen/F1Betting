# F1 Betting - Installationsguide til Simply.com

## Oversigt
Denne guide viser hvordan du installerer F1 Betting applikationen på Simply.com webhotel.

## Filer der skal uploades
Upload hele indholdet af `php_version` mappen til din webserver (typisk `/www/` eller `/public_html/`).

```
├── index.php          # Forside
├── login.php          # Login side
├── register.php       # Registrering
├── logout.php         # Log ud
├── profile.php        # Profil side
├── races.php          # Alle løb
├── leaderboard.php    # Rangliste
├── bet.php            # Placer bet
├── admin.php          # Admin panel
├── config.php         # KONFIGURATION (REDIGER DENNE!)
├── database.sql       # Database schema
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── app.js
└── includes/
    ├── header.php
    └── footer.php
```

---

## Trin 1: Opret MySQL Database

1. Log ind på Simply.com kontrolpanel
2. Gå til **Databaser** → **MySQL**
3. Klik **Opret ny database**
4. Noter følgende oplysninger:
   - Database navn
   - Brugernavn
   - Password
   - Host (typisk `mysql.simply.com` eller lignende)

---

## Trin 2: Importér Database Schema

1. Gå til **phpMyAdmin** i Simply.com kontrolpanel
2. Vælg din nye database
3. Klik på **Import** fanen
4. Upload filen `database.sql`
5. Klik **Udfør**

Dette opretter alle tabeller og indsætter:
- 10 F1 kørere (2025 sæson)
- Standard indstillinger

---

## Trin 3: Konfigurer config.php

Åbn `config.php` og rediger disse værdier:

```php
// Database indstillinger (fra Simply.com kontrolpanel)
define('DB_HOST', 'mysql.simply.com');     // Din MySQL host
define('DB_NAME', 'dit_database_navn');    // Dit database navn
define('DB_USER', 'dit_brugernavn');       // Dit MySQL brugernavn
define('DB_PASS', 'dit_password');         // Dit MySQL password

// Sikkerhed - SKIFT DISSE TIL TILFÆLDIGE STRENGE!
define('JWT_SECRET', 'skift-denne-til-en-lang-tilfaeldig-streng-1234567890');
define('PASSWORD_PEPPER', 'skift-ogsaa-denne-streng');

// Site URL (uden trailing slash)
define('SITE_URL', 'https://dit-domæne.dk');
```

### Generér sikre nøgler
Brug denne side til at generere tilfældige strenge: https://randomkeygen.com/

---

## Trin 4: Upload Filer

1. Brug FTP eller Simply.com's filhåndtering
2. Upload alle filer til din webserver rod (typisk `/www/`)
3. Sørg for at `config.php` har de korrekte database oplysninger

---

## Trin 5: Test Installation

1. Besøg dit domæne i browseren
2. Klik **Registrer** og opret din første bruger
3. **Første bruger bliver automatisk administrator!**
4. Log ind og gå til **Admin** for at:
   - Tilføje flere kørere
   - Oprette løb med datoer og kvalifikationsresultater
   - Administrere indstillinger

---

## Funktioner

### Bruger funktioner
- ✅ Registrering og login
- ✅ Placer bets på kommende løb (P1, P2, P3)
- ✅ Se alle bets pr. løb
- ✅ Rangliste med point og stjerner
- ✅ Profil med visningsnavn
- ✅ Lys/mørk tema
- ✅ Dansk/engelsk sprog

### Admin funktioner
- ✅ Administrer kørere (tilføj, rediger, slet)
- ✅ Administrer løb (dato, tid, kvalifikation, resultater)
- ✅ Administrer brugere (roller, slet)
- ✅ Se alle bets
- ✅ Indstillinger (app titel, år, velkomsttekst)

### Betting regler
- Betting åbner 48 timer før løbsstart
- Betting lukker når løbet starter
- Kan ikke vælge samme kører flere gange
- Kan ikke matche kvalifikationsresultatet præcist
- Samme kombination kan kun bruges én gang

### Point system
- P1 korrekt: 25 point
- P2 korrekt: 18 point
- P3 korrekt: 15 point
- Kører i top 3 men forkert position: +5 point
- Perfekt bet (alle 3 korrekte): ⭐ stjerne

---

## Fejlfinding

### "Database forbindelse fejlede"
- Tjek at DB_HOST, DB_NAME, DB_USER og DB_PASS er korrekte
- Tjek at databasen er oprettet i Simply.com

### Siden vises ikke korrekt
- Tjek at alle filer er uploadet
- Tjek at PHP version er 7.4 eller nyere

### Kan ikke logge ind
- Tjek at `database.sql` er importeret korrekt
- Prøv at registrere en ny bruger

---

## Support

Har du problemer? Tjek:
1. PHP error logs i Simply.com kontrolpanel
2. At alle filer er uploadet korrekt
3. At database oplysninger er korrekte

---

Held og lykke med din F1 betting app! 🏎️
