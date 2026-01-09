# F1 Betting - Installationsguide til Simply.com

## Oversigt
Denne guide viser hvordan du installerer F1 Betting applikationen på Simply.com webhotel.

## Filer der skal uploades
Upload hele indholdet af `php_version` mappen til din webserver.

**Eksempel på mappestruktur (i undermappe `/f1/`):**
```
public_html/
└── f1/
    ├── index.php          # Forside
    ├── login.php          # Login side
    ├── register.php       # Registrering
    ├── logout.php         # Log ud
    ├── profile.php        # Profil side
    ├── races.php          # Alle løb
    ├── leaderboard.php    # Rangliste
    ├── bet.php            # Placer bet
    ├── edit_bet.php       # Rediger bet (NY!)
    ├── admin.php          # Admin panel
    ├── forgot_password.php # Glemt adgangskode
    ├── reset_password.php  # Nulstil adgangskode
    ├── config.php         # KONFIGURATION (REDIGER DENNE!)
    ├── database.sql       # Database schema
    ├── data_2026.sql      # 2026 kørere og løb (NY!)
    ├── assets/
    │   ├── css/
    │   │   └── style.css
    │   └── js/
    │       └── app.js
    └── includes/
        ├── header.php
        ├── footer.php
        └── sendgrid.php   # SendGrid email integration (NY!)
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

### Import 2026 Data (valgfrit)
For at tilføje alle 22 kørere og 24 løb fra 2026 sæsonen:
1. Efter import af `database.sql`, klik **Import** igen
2. Upload filen `data_2026.sql`
3. Klik **Udfør**

Dette indsætter:
- 22 F1 kørere (2026 sæson med alle 11 teams inkl. Cadillac)
- 24 løb med datoer og starttider

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
define('SITE_URL', 'https://dit-domæne.dk/f1');
```

### Generér sikre nøgler
Brug denne side til at generere tilfældige strenge: https://randomkeygen.com/

---

## Trin 4: Konfigurer SendGrid Email (VALGFRIT men ANBEFALET)

SendGrid bruges til at sende password reset emails. Uden SendGrid falder systemet tilbage til PHP mail() som ofte ikke virker på webhostels.

### 4.1 Opret SendGrid konto
1. Gå til https://sendgrid.com/ og klik **Start for Free**
2. Opret en konto (100 gratis emails/dag)
3. Verificer din email

### 4.2 Opret API nøgle
1. Log ind på SendGrid
2. Gå til **Settings** → **API Keys**
3. Klik **Create API Key**
4. Vælg et navn (f.eks. "F1 Betting")
5. Vælg **Full Access** eller **Restricted Access** med "Mail Send" aktiveret
6. Klik **Create & View**
7. **KOPIER API NØGLEN NU** - den vises kun én gang!

### 4.3 Verificer afsender email
1. Gå til **Settings** → **Sender Authentication**
2. Vælg **Single Sender Verification** (nemmest for start)
3. Indtast din email (f.eks. `noreply@dit-domæne.dk`)
4. Bekræft emailen du modtager

### 4.4 Tilføj til config.php
```php
// SendGrid Email Konfiguration
define('SENDGRID_API_KEY', 'SG.din_api_nøgle_her');
define('SENDGRID_FROM_EMAIL', 'noreply@dit-domæne.dk');
define('SENDGRID_FROM_NAME', 'F1 Betting');
```

### Test email
Efter installation, gå til login siden og klik "Glemt adgangskode?" for at teste.

---

## Trin 5: Upload Filer

### Upload til undermappe (anbefalet)
1. Opret mappen `f1` i `public_html` via FTP eller filhåndtering
2. Upload alle filer til `public_html/f1/`
3. Din side vil være på: `https://dit-domæne.dk/f1/`

### Upload til rodmappe
1. Upload alle filer direkte til `public_html/`
2. Din side vil være på: `https://dit-domæne.dk/`

---

## Trin 6: Test Installation

1. Besøg dit domæne i browseren (f.eks. `https://dit-domæne.dk/f1/`)
2. Klik **Registrer** og opret din første bruger
3. **Første bruger bliver automatisk administrator!**
4. Log ind og gå til **Admin** for at:
   - Se kørere (22 stk hvis du importerede data_2026.sql)
   - Se løb (24 stk hvis du importerede data_2026.sql)
   - Tilføje kvalifikationsresultater
   - Administrere indstillinger

---

## Nye funktioner (Januar 2026)

### 📧 SendGrid Email Integration
- Professionelle HTML emails til password reset
- Fallback til PHP mail() hvis SendGrid ikke er konfigureret
- Flot F1-temaet email design

### ✏️ Rediger Bets
- Brugere kan nu redigere deres bets
- Kun muligt når betting-vinduet stadig er åbent
- Timestamp opdateres ved redigering
- Alle valideringsregler gælder stadig

### 🏎️ 2026 Sæson Data
- 22 kørere fra alle 11 teams (inkl. nye Cadillac team)
- 24 løb med officielle datoer og tider
- Klar til brug - bare importér `data_2026.sql`

---

## Funktioner

### Bruger funktioner
- ✅ Registrering og login
- ✅ Glemt/nulstil adgangskode (med SendGrid email)
- ✅ Placer bets på kommende løb (P1, P2, P3)
- ✅ **Rediger bets** før løbsstart (NY!)
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
- Kan redigere bet indtil betting lukker
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

### Password reset email kommer ikke
- Tjek at SENDGRID_API_KEY er korrekt
- Tjek at SENDGRID_FROM_EMAIL er verificeret i SendGrid
- Tjek SendGrid dashboard for fejl under Activity
- Uden SendGrid: emails sendes via PHP mail() som ofte blokeres

### Siden vises ikke korrekt
- Tjek at alle filer er uploadet
- Tjek at PHP version er 7.4 eller nyere
- Tjek at SITE_URL matcher din faktiske URL (inkl. undermappe)

### Kan ikke logge ind
- Tjek at `database.sql` er importeret korrekt
- Prøv at registrere en ny bruger

### Sletning virker ikke
- Tjek at JavaScript er aktiveret i browseren
- Der kommer en bekræftelsesdialog - klik "Slet" for at bekræfte

---

## Support

Har du problemer? Tjek:
1. PHP error logs i Simply.com kontrolpanel
2. At alle filer er uploadet korrekt
3. At database oplysninger er korrekte
4. At SITE_URL er sat korrekt i config.php
5. SendGrid Activity dashboard for email problemer

---

Held og lykke med din F1 betting app! 🏎️
