# Feature: Per-Race AI Analysis Page

## Requirements

### Functional Requirements
- **[REQ-001]** A cron job runs daily and generates an AI analysis for each race whose start datetime is between 88–96 hours from now and has no existing analysis.
- **[REQ-002]** The analysis question is: "For the upcoming {name} Grand Prix at {location}: based on historical performance at this circuit, recent driver form, and typical race characteristics, who are the most likely top-3 podium finishers? Give specific reasoning and key risk factors."
- **[REQ-003]** Generated analyses are stored in a new `race_analyses` DB table (one row per race; re-generation is not supported in this feature).
- **[REQ-004]** A standalone page `/race-analysis.php?race_id=X` displays the analysis for a specific race, accessible to all logged-in members.
- **[REQ-005]** If no analysis exists yet, the page shows: "AI analysis will be available approximately 4 days before the race."
- **[REQ-006]** Each race card on `races.php` links to the analysis page when the race is upcoming (has no result yet).
- **[REQ-007]** The analysis page displays: race name, circuit, generated timestamp, answer text, and sources list.

### Non-Functional Requirements
- **[NFR-001]** Cron job completes in under 60 seconds for a single race analysis.
- **[NFR-002]** The cron job is idempotent — re-running it for the same race window does not generate duplicate analyses.
- **[NFR-003]** API failures during cron are logged to `CRON_QUALIFYING_LOG_FILE` and do not crash the cron process.
- **[NFR-004]** The display page renders correctly on mobile.

### Technical Constraints
- New DB table `race_analyses` required — must be created on both servers manually.
- Race start = `CONCAT(race_date, ' ', race_time)` (two separate columns in `races` table).
- Cron schedule: daily at 06:00 — added alongside existing cron entries on both servers.
- Uses `F1Intelligence` class; config constants from `config.php`.
- No new admin UI for managing analyses in this feature.

## User Story

**As a** member of Paddock Picks
**I want to** read an AI-generated pre-race analysis before placing my podium prediction
**So that** I have data-driven context about likely top-3 finishers at the specific circuit

## DB Schema

```sql
CREATE TABLE race_analyses (
  id            VARCHAR(36)  PRIMARY KEY,
  race_id       VARCHAR(36)  NOT NULL,
  generated_at  DATETIME     NOT NULL,
  question      TEXT         NOT NULL,
  answer        LONGTEXT     NOT NULL,
  sources_json  TEXT         NOT NULL,
  FOREIGN KEY (race_id) REFERENCES races(id) ON DELETE CASCADE
);
```

## Functionality

### Cron Job — `public/cron/f1-analysis.php`
1. Query races where start is 88–96h away AND no row in `race_analyses` for that `race_id`
2. Call `F1Intelligence::query()` with the standard prompt
3. Insert result into `race_analyses` using `generateUUID()`
4. Log outcome to `CRON_QUALIFYING_LOG_FILE`

### Display Page — `public/race-analysis.php`
- `requireLogin()`
- Load race by `$_GET['race_id']`
- Load analysis from `race_analyses WHERE race_id = ?`
- Render answer (nl2br + htmlspecialchars), sources, timestamp
- "No analysis yet" message if missing

### races.php Link
- Add "AI Analysis" badge/link on upcoming race cards
- Link: `/race-analysis.php?race_id={id}`
- Only shown for races without results (upcoming)

## Acceptance Criteria
- [ ] `race_analyses` table exists on both servers
- [ ] Running cron manually generates an analysis for a race 90–96h away
- [ ] Duplicate run of cron does not create a second analysis for the same race
- [ ] `/race-analysis.php?race_id=X` renders answer and sources for a race with an analysis
- [ ] Page shows "not yet available" message for races without analysis
- [ ] Analysis link appears on races.php for upcoming races
- [ ] Non-logged-in users are redirected to login
