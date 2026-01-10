# F1 Betting Application PRD

## Original Problem Statement
Create a web application with authentication where users can bet on top 3 drivers for upcoming F1 races. Admin section for managing users, drivers, races with dates and qualifying top 3 results.

## Architecture
- **Primary Stack (React/FastAPI)**: React with Tailwind CSS, FastAPI (Python), MongoDB
- **Secondary Stack (PHP/MySQL)**: Procedural PHP, MySQL - for deployment on standard web hosts

## User Personas
1. **Regular User**: Registers, places bets on races, views leaderboard
2. **Admin**: First registered user, manages drivers/races/users/settings

## Core Requirements (Static)
- User authentication (register/login/forgot password)
- Bet placement on top 3 for each race
- Bet editing before race start
- 48-hour betting window (opens 48h before race, closes at race start)
- Validation: No duplicate drivers, can't match quali results, unique combination per race
- Points system: P1=25, P2=18, P3=15, +5 for top 3 wrong position
- Stars for 100% correct predictions
- Admin panel for CRUD on drivers, races, users
- Light/dark theme toggle
- Danish/English language switcher
- App settings (title, year, hero text)

## What's Been Implemented (January 2026)

### React/FastAPI Version (Preview)
- ✅ Full authentication system with JWT
- ✅ **Invite-only registration** - Public signup removed (Jan 9, 2026)
- ✅ Admin invite management (create, resend, delete, copy link)
- ✅ Forgot/Reset Password flow
- ✅ **Edit bets** before race start (Jan 9, 2026)
- ✅ User profile with display name
- ✅ Complete CRUD for drivers, races, users
- ✅ Betting system with all validations
- ✅ Points calculation and stars for perfect bets
- ✅ Leaderboard with rankings
- ✅ Theme toggle (dark/light) - WCAG 2.2 AA compliant
- ✅ Language switcher (DA/EN)
- ✅ Admin settings panel
- ✅ 2026 F1 season data ready in seed (22 drivers, 24 races)

### PHP/MySQL Version (for Simply.com)
- ✅ **Invite-only registration** - Public signup removed
- ✅ Admin invite management (create, resend, delete invites)
- ✅ **Custom SMTP email** - SendGrid replaced with generic SMTP for Simply.com
- ✅ Edit bets functionality (before race start)
- ✅ 2026 F1 data SQL script (22 drivers, 24 races in CET)
- ✅ setup_admin.php CLI script for initial admin creation
- ✅ Complete installation guide for Simply.com
- ✅ **Custom logo & favicon** - Based on user-provided image (Jan 2026)
- ✅ **Cron notifications** - Email when betting window opens
- ✅ **Admin bet deletion** - With email notification to user (only when betting open)
- ✅ **Auto-scroll to upcoming race** on homepage
- ✅ **Collapsible admin forms** - Cleaner admin UX
- ✅ **Tab counts** in admin panel
- ✅ **Timezone configured** - CET/Copenhagen with documentation
- ✅ **Countdown timer** - Live countdown til betting åbner/lukker (Jan 10, 2026)
- ✅ **CET på alle løbstider** - Synlig på race cards, bet forms, admin
- ✅ **Admin tab persistence** - Bevarer tab ved tema/sprog skift
- ✅ **Races som default tab** - Admin panel åbner på Races
- ✅ **Annuller knap på bet form** - Bedre UX
- ✅ **Point genberegning** - Ved bet sletning trækkes point fra bruger
- ✅ **Bet sletning restriktion** - Kun muligt når betting vindue er åbent
- ✅ **Unified email templates** - Alle emails bruger samme professionelle HTML template (Jan 10, 2026)
- ✅ **Mobile-responsive design** - Hamburger menu og media queries
- ✅ **Rules page** - Betting regler forklaring
- ✅ **Configurable points** - Admin kan justere P1/P2/P3 point i settings
- ✅ **Theme-specific logos** - Separate logos til light/dark mode
- ✅ **Security refactor** - config.php flyttet udenfor public webroot

## Prioritized Backlog

### P0 (Critical) - Done
All core features implemented

### P1 (High Priority) - Future
- Email notifications for betting window opening
- Social sharing of predictions
- Race results auto-import from F1 API

### P2 (Medium Priority) - Future
- Team standings
- Season championship view
- Historical statistics

## PHP Version Files
Located at `/app/php_version/`:
- `database.sql` - Base database schema
- `data_2026.sql` - 2026 season data (22 drivers, 24 races, CET times)
- `config.php` - Configuration med CET timezone dokumentation
- `INSTALLATION.md` - Complete setup guide including SMTP
- `includes/smtp.php` - Custom SMTP email integration
- `cron_notifications.php` - Cron job for betting window notifications
- `edit_bet.php` - Bet editing page
- `assets/logo.svg` - Custom app logo
- `assets/favicon.*` - Multi-format favicons

**Zip archive**: `/app/f1betting_php.zip`
**Download URL**: `https://f1punters.preview.emergentagent.com/f1betting_php.zip`

## 2026 F1 Season Data

### Teams & Drivers (22 total)
| Team | Driver 1 | Driver 2 |
|------|----------|----------|
| Red Bull Racing | Max Verstappen | Isack Hadjar |
| Ferrari | Charles Leclerc | Lewis Hamilton |
| Mercedes | George Russell | Kimi Antonelli |
| McLaren | Lando Norris | Oscar Piastri |
| Aston Martin | Fernando Alonso | Lance Stroll |
| Alpine | Pierre Gasly | Franco Colapinto |
| Williams | Alex Albon | Carlos Sainz |
| Visa Cash App RB | Liam Lawson | Arvid Lindblad |
| Haas | Esteban Ocon | Oliver Bearman |
| Audi | Nico Hülkenberg | Gabriel Bortoleto |
| Cadillac | Sergio Pérez | Valtteri Bottas |

### 2026 Calendar (24 races)
March 8 - December 6, 2026
