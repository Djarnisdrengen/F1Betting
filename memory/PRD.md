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

### React/FastAPI Version
- ✅ Full authentication system with JWT
- ✅ Forgot/Reset Password flow (tokens expire after 1 hour)
- ✅ User profile with display name
- ✅ Complete CRUD for drivers, races, users
- ✅ Betting system with all validations
- ✅ Points calculation and stars for perfect bets
- ✅ Leaderboard with rankings
- ✅ Theme toggle (dark/light) - WCAG 2.2 AA compliant
- ✅ Language switcher (DA/EN)
- ✅ Admin settings panel

### PHP/MySQL Version (Jan 9, 2026)
- ✅ **Invite-only registration** - Public signup removed
- ✅ Admin invite management (create, resend, delete invites)
- ✅ Invite emails via SendGrid
- ✅ SendGrid email integration for password reset
- ✅ Edit bets functionality (before race start)
- ✅ 2026 F1 data SQL script (22 drivers, 24 races)
- ✅ setup_admin.php CLI script for initial admin creation
- ✅ Complete installation guide for Simply.com

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
- `data_2026.sql` - 2026 season data (22 drivers, 24 races)
- `INSTALLATION.md` - Complete setup guide including SendGrid
- `includes/sendgrid.php` - Email integration
- `edit_bet.php` - Bet editing page

**Zip archive**: `/app/f1betting_php.zip`

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
