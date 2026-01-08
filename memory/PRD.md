# F1 Betting Application PRD

## Original Problem Statement
Create a web application with authentication where users can bet on top 3 drivers for upcoming F1 races. Admin section for managing users, drivers, races with dates and qualifying top 3 results.

## Architecture
- **Frontend**: React with Tailwind CSS, Shadcn UI components
- **Backend**: FastAPI with Python
- **Database**: MongoDB
- **Auth**: JWT-based authentication

## User Personas
1. **Regular User**: Registers, places bets on races, views leaderboard
2. **Admin**: First registered user, manages drivers/races/users/settings

## Core Requirements (Static)
- User authentication (register/login)
- Bet placement on top 3 for each race
- 48-hour betting window (opens 48h before race, closes at race start)
- Validation: No duplicate drivers, can't match quali results, unique combination per race
- Points system: P1=25, P2=18, P3=15, +5 for top 3 wrong position
- Stars for 100% correct predictions
- Admin panel for CRUD on drivers, races, users
- Light/dark theme toggle
- Danish/English language switcher
- App settings (title, year, hero text)

## What's Been Implemented (December 2025)
- ✅ Full authentication system with JWT
- ✅ User profile with display name (saves correctly)
- ✅ Complete CRUD for drivers, races, users
- ✅ Betting system with all validations
- ✅ Points calculation and stars for perfect bets
- ✅ Leaderboard with rankings
- ✅ Theme toggle (dark/light) - WCAG 2.2 AA compliant
- ✅ Language switcher (DA/EN)
- ✅ Admin settings panel
- ✅ Sample data seeded (10 drivers, 3 races)
- ✅ All bets visible per race on home page

## Prioritized Backlog
### P0 (Critical) - Done
- All core features implemented

### P1 (High Priority) - Future
- Email notifications for betting window
- Social sharing of predictions
- Race results auto-import from F1 API

### P2 (Medium Priority) - Future
- Bet editing before race start
- Team standings
- Season championship view

## Next Tasks
1. Add more drivers to roster
2. Set up upcoming 2025 race calendar
3. Configure production hero text and app title
