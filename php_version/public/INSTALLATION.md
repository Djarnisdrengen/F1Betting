# F1 Betting - Installationsguide til Simply.com

## Oversigt
Denne guide viser hvordan du installerer F1 Betting applikationen på Simply.com webhotel.

## Ny Mappestruktur (Sikkerhed)
Config-filen er nu placeret UDENFOR web-roden for bedre sikkerhed.

**Anbefalet mappestruktur:**
```
/home/dit-brugernavn/
├── config.php              # ← KONFIGURATION (udenfor web-rod!)
└── public_html/
    └── f1/                 # ← Web-rod (eller direkte i public_html)
        ├── index.php
        ├── login.php
        ├── register.php
        ├── logout.php
        ├── profile.php
        ├── races.php
        ├── leaderboard.php
        ├── bet.php
        ├── edit_bet.php
        ├── admin.php
        ├── forgot_password.php
        ├── reset_password.php
        ├── cron_notifications.php
        ├── database.sql
        ├── data_2026.sql
        ├── migration_points.sql
        ├── setup_admin.php
        ├── assets/
        │   ├── css/style.css
        │   ├── js/app.js
        │   ├── logo_header_dark.png
        │   ├── logo_header_light.png
        │   ├── favicon.ico
        │   └── favicon.png
        ├── includes/
        │   ├── header.php
        │   ├── footer.php
        │   └── smtp.php
        └── api/
            └── bet.php
```

**Fordele ved denne struktur:**
- `config.php` med database-adgangskoder er IKKE tilgængelig via web
- Bedre sikkerhed mod hacking og credential-lækage

---

## Trin 1: Upload Filer

### Via FTP/SFTP:
1. Upload `config.php` til `/home/dit-brugernavn/` (OVER public_html)
2. Upload indholdet af `public/` mappen til `/home/dit-brugernavn/public_html/f1/`

### Via Simply.com File Manager:
1. Naviger til rodmappen (over public_html)
2. Upload `config.php` her
3. Gå ind i `public_html/f1/`
4. Upload alle filer fra `public/` mappen

---

## Trin 2: Opret MySQL Database

1. Log ind på Simply.com kontrolpanel
2. Gå til **Databaser** → **MySQL**
3. Klik **Opret ny database**
4. Noter følgende oplysninger:
   - Database navn
   - Brugernavn
   - Password
   - Host (typisk `mysql.simply.com` eller lignende)

---

## Trin 3: Importér Database Schema

1. Gå til **phpMyAdmin** i Simply.com kontrolpanel
2. Vælg din nye database
3. Klik på **Import** fanen
4. Vælg `database.sql` og klik **Udfør**
5. Importér derefter `data_2026.sql` for 2026 sæsondata

---

## Trin 4: Konfigurér config.php

Rediger `config.php` (som ligger OVER public_html) med dine oplysninger:

```php
// Database forbindelse
define('DB_HOST', 'mysql.simply.com');  // Din MySQL host
define('DB_NAME', 'din_database');       // Database navn
define('DB_USER', 'dit_brugernavn');     // Database bruger
define('DB_PASS', 'dit_password');       // Database password

// Site URL (VIGTIGT: Uden trailing slash)
define('SITE_URL', 'https://dinside.dk');

// SMTP Email konfiguration
define('SMTP_HOST', 'asmtp.simply.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'din@email.dk');
define('SMTP_PASS', 'dit_email_password');
define('SMTP_FROM_EMAIL', 'din@email.dk');
define('SMTP_FROM_NAME', 'F1 Betting');
```

---

## Trin 5: Opret Admin Bruger

### Mulighed A: Via phpMyAdmin
Kør denne SQL i phpMyAdmin (erstat værdier):
```sql
INSERT INTO users (id, name, display_name, email, password, role) VALUES 
(UUID(), 'Djarnis', 'thomas@helvegpovlsen.dk', 
 '$2y$10$YourHashedPasswordHere', 'admin');
```

### Mulighed B: Via SSH/CLI
Hvis du har SSH-adgang:
```bash
cd /home/dit-brugernavn/public_html/f1
php setup_admin.php admin@example.com password123
```

---

## Trin 6: Test Installation

1. Besøg `https://dinside.dk/f1/`
2. Log ind med admin-kontoen
3. Gå til Admin panel og verificer at alt virker
4. Test "Glemt adgangskode" for at verificere email-afsendelse

---

## Trin 7: Opsæt Cron Job (Valgfrit)

For automatiske email-notifikationer når betting åbner:

1. Gå til Simply.com kontrolpanel → **Cron Jobs**
2. Tilføj nyt cron job:
   - **Kommando:** `php /home/dit-brugernavn/public_html/f1/cron_notifications.php`
   - **Interval:** Hver time (`0 * * * *`)

---

## Fejlfinding

### "Headers already sent" fejl
Sørg for at der ikke er mellemrum eller blanke linjer FØR `<?php` i config.php

### Email sendes ikke
1. Verificer SMTP-indstillinger i config.php
2. Tjek at Simply.com tillader SMTP på port 587
3. Kontakt Simply.com support for SMTP-serveradresse

### Database fejl
1. Verificer database-oplysninger i config.php
2. Sørg for at begge SQL-filer er importeret

### 500 Server Error
1. Tjek PHP error logs i Simply.com kontrolpanel
2. Verificer at alle filer er uploadet korrekt
3. Kontroller fil-rettigheder (644 for filer, 755 for mapper)

---

## Opdatering af Eksisterende Installation

Hvis du opdaterer fra en tidligere version:

1. **Backup database** via phpMyAdmin
2. Upload nye filer (overskriv eksisterende)
3. Kør `migration_points.sql` i phpMyAdmin for point-konfiguration
4. Ryd browser-cache

---

## Support

Ved problemer, kontakt udvikleren eller tjek Simply.com's dokumentation.
