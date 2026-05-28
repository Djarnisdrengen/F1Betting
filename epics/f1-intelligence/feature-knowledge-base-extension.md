# Feature: F1 Intelligence Knowledge Base Extension

## Requirements

### Functional Requirements
- **[REQ-001]** The knowledge base is extended from 10 to ~25 documents covering additional drivers, circuits, team performance, and 2024–2025 season data.
- **[REQ-002]** New driver entries are added for: Lando Norris (2024 maiden win, McLaren strengths), Oscar Piastri (2024 wins, McLaren dynamics), Lewis Hamilton 2024 (final Mercedes season, Ferrari move 2025), Carlos Sainz 2024 (Ferrari peak year).
- **[REQ-003]** New circuit entries are added for: Monza (slipstream, low-downforce), Singapore (safety cars, tyre management, night race), Suzuka (technical, weather, Verstappen dominance), Baku (wall proximity, safety car frequency).
- **[REQ-004]** New team performance entries: McLaren 2024 (Constructors champion, MCL38), Ferrari 2024 (strategy errors, SF-24), Red Bull 2024–2025 (competitiveness drop, Perez exit).
- **[REQ-005]** New 2024 season highlights: key race results (Miami, Hungary, Singapore, Las Vegas, Abu Dhabi), championship fight summary.
- **[REQ-006]** After editing the knowledge base, the vector index is rebuilt locally and the Vercel API is redeployed with the updated index.
- **[REQ-007]** The existing 10 entries are not removed or modified — only new entries are appended.

### Non-Functional Requirements
- **[NFR-001]** Each new document follows the existing schema: `{ id, title, content }` — no schema changes.
- **[NFR-002]** Document IDs follow kebab-case convention (e.g., `norris-2024-performance`).
- **[NFR-003]** Content strings are factual and concise (200–400 words each) — no opinion or speculation.
- **[NFR-004]** Rebuild cost: ~$0.0003 for 25 documents (negligible).

### Technical Constraints
- Edit `f1-intelligence/api/data/f1-knowledge-base.json` directly.
- Rebuild index: `cd f1-intelligence/api && npm run build-index` (requires `OPENAI_API_KEY` in terminal).
- Commit `f1-vector-index.json` to git before deploying.
- Deploy: `vercel deploy --prod` from `f1-intelligence/api/`.

## User Story

**As an** admin of Paddock Picks
**I want to** expand the F1 knowledge base with current driver, circuit, and team data through 2025
**So that** the AI assistant gives accurate, relevant answers when members ask about recent form and upcoming races

## Acceptance Criteria
- [ ] ~25 documents in `f1-knowledge-base.json` (was 10)
- [ ] Query "How is Norris performing in 2024?" returns a useful answer
- [ ] Query "What should I know about Singapore strategy?" returns circuit-specific advice
- [ ] Query "How is McLaren performing compared to Red Bull?" returns 2024-relevant answer
- [ ] `f1-vector-index.json` rebuilt and committed
- [ ] Vercel deployment live with new index
