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
    ├── register.php       # Registrering (kun via invitation)
    ├── logout.php         # Log ud
    ├── profile.php        # Profil side
    ├── races.php          # Alle løb
    ├── leaderboard.php    # Rangliste
    ├── bet.php            # Placer bet
    ├── edit_bet.php       # Rediger bet
    ├── admin.php          # Admin panel
    ├── forgot_password.php # Glemt adgangskode
    ├── reset_password.php  # Nulstil adgangskode
    ├── config.php         # KONFIGURATION (REDIGER DENNE!)
    ├── database.sql       # Database schema
    ├── data_2026.sql      # 2026 kørere og løb
    ├── setup_admin.php    # CLI script til første admin
    ├── assets/
    │   ├── css/style.css
    │   ├── js/app.js
    │   ├── logo.svg       # App logo
    │   ├── favicon.ico    # Browser favicon
    │   └── favicon.png
    └── includes/
        ├── header.php
        ├── footer.php
        └── smtp.php       # SMTP email funktioner
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

## Trin 4: Konfigurer SMTP Email (Simply.com)

SMTP bruges til at sende password reset og invitation emails.

### 4.1 Find dine SMTP indstillinger

1. Log ind på Simply.com kontrolpanel
2. Gå til **E-mail** → **E-mail konti**
3. Opret en email konto (f.eks. `noreply@dit-domæne.dk`) eller brug en eksisterende
4. Noter indstillingerne:
   - **SMTP Server**: `asmtp.unoeuro.com` (eller `mail.dit-domæne.dk`)
   - **Port**: `587` (TLS) eller `465` (SSL)
   - **Brugernavn**: Din fulde email adresse
   - **Password**: Din email adgangskode

### 4.2 Tilføj SMTP til config.php

```php
// SMTP Email Konfiguration (Simply.com)
define('SMTP_HOST', 'asmtp.unoeuro.com');        // Simply.com SMTP server
define('SMTP_PORT', 587);                         // 587 for TLS, 465 for SSL
define('SMTP_USER', 'noreply@dit-domæne.dk');    // Din email adresse
define('SMTP_PASS', 'din_email_adgangskode');    // Din email adgangskode
define('SMTP_FROM_EMAIL', 'noreply@dit-domæne.dk'); // Afsender email
define('SMTP_FROM_NAME', 'F1 Betting');          // Afsender navn
```

### 4.3 Test email
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

## Trin 6: Opret Første Admin Bruger

Da offentlig registrering er deaktiveret, skal du oprette første admin bruger manuelt.

### Option 1: Via phpMyAdmin (nemmest)

Kør denne SQL i phpMyAdmin (husk at ændre email og password):

```sql
INSERT INTO users (id, email, password, display_name, role, points, stars) VALUES (
    UUID(),
    'din@email.dk',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',  -- password: "password"
    'Admin',
    'admin',
    0,
    0
);
```

**VIGTIGT:** Gå derefter til Profil og skift din adgangskode!

### Option 2: Via setup script

1. Upload `setup_admin.php` til serveren
2. Kør via SSH/terminal: `php setup_admin.php`
3. Følg instruktionerne
4. **SLET `setup_admin.php` bagefter!**

---

## Trin 7: Test Installation

1. Besøg dit domæne (f.eks. `https://dit-domæne.dk/f1/`)
2. Log ind som admin
3. Gå til **Admin** → **Invitationer** for at invitere nye brugere
4. Test email ved at sende en invitation

---

## Trin 8: Opsæt Email Notifikationer (Valgfrit)

For at sende automatiske email-påmindelser når betting-vinduer åbner/lukker:

### Opsæt Cron Job

1. Log ind på Simply.com kontrolpanel
2. Gå til **Cron Jobs** / **Planlagte opgaver**
3. Tilføj nyt cron job:
   - **Kommando**: `php /var/www/dit-domæne.dk/public_html/f1/cron_notifications.php`
   - **Timing**: Hver time (0 * * * *)
4. Gem

### Hvad gør cron jobbet?
- Tjekker for løb hvor betting lige er åbnet (sender "Betting åbent!" email)
- Tjekker for løb hvor betting lukker om 2 timer (sender "Sidste chance!" email)
- Springer brugere over der allerede har placeret bet

---

## Funktioner

### Bruger funktioner
- ✅ Login (kun via invitation)
- ✅ Glemt/nulstil adgangskode
- ✅ Placer bets på kommende løb (P1, P2, P3)
- ✅ Rediger bets før løbsstart
- ✅ Se alle bets pr. løb
- ✅ Rangliste med point og stjerner
- ✅ Profil med visningsnavn
- ✅ Lys/mørk tema
- ✅ Dansk/engelsk sprog

### Admin funktioner
- ✅ Inviter nye brugere via email
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

### Email sendes ikke
- Tjek at SMTP indstillingerne er korrekte
- Tjek at email kontoen findes i Simply.com
- Prøv port 465 i stedet for 587
- Tjek at SMTP_USER er den fulde email adresse
- Tjek spam/junk mappen

### Siden vises ikke korrekt
- Tjek at alle filer er uploadet
- Tjek at PHP version er 7.4 eller nyere
- Tjek at SITE_URL matcher din faktiske URL

### Kan ikke logge ind
- Tjek at `database.sql` er importeret korrekt
- Opret admin bruger via phpMyAdmin

### Tema/sprog skifter ikke
- Tjek at cookies er aktiveret i browseren
- Tjek at der ikke er PHP fejl i loggen

---

## Support

Har du problemer? Tjek:
1. PHP error logs i Simply.com kontrolpanel
2. At alle filer er uploadet korrekt
3. At database og SMTP oplysninger er korrekte
4. At SITE_URL er sat korrekt i config.php

---

Held og lykke med din F1 betting app! 🏎️
