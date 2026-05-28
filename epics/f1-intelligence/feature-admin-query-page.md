# Feature: F1 Intelligence Admin Query Page

## Requirements

### Functional Requirements
- **[REQ-001]** An admin-only page at `/f1-intelligence/query.php` allows submitting a free-text question to the F1 Intelligence RAG API.
- **[REQ-002]** The page displays the AI-generated answer and a list of source documents used to construct it (title + similarity score as %).
- **[REQ-003]** Submission is asynchronous (AJAX) — the page shows a loading state while the API call is in progress.
- **[REQ-004]** The answer renders with preserved line breaks; markdown-style formatting (headings, bold, tables) is rendered, not escaped as plain text.
- **[REQ-005]** Query history within the current session is not persisted — each page load starts fresh.
- **[REQ-006]** The page is accessible only to logged-in admins (`requireAdmin()`); unauthenticated or non-admin users are redirected to login.

### Non-Functional Requirements
- **[NFR-001]** The loading spinner appears within 100ms of submit; the answer renders as soon as the API responds (typically 3–16 seconds).
- **[NFR-002]** Errors from the API (timeout, 5xx) are shown as a clear inline error message — no PHP fatal errors exposed to the browser.
- **[NFR-003]** The page renders correctly on mobile (single-column) and desktop.
- **[NFR-004]** CSRF token is included on every POST to the page's own handler.

### Technical Constraints
- PHP procedural pattern — no framework, no Composer.
- Must work on simply.com shared hosting (PHP 8, MySQL).
- Uses `F1Intelligence` class from `public/f1-intelligence/F1Intelligence.php`.
- Config constants `F1_INTELLIGENCE_API_URL`, `F1_INTELLIGENCE_TIMEOUT`, `F1_INTELLIGENCE_DEBUG` sourced from `config.php` + `config.shared.php`.
- No new DB tables required for this feature.
- Vanilla JS only — no build step, no npm packages on the PHP server.

## User Story

### Primary User Goal
Admin wants to test and explore the F1 Intelligence API directly from the browser, without using curl or the terminal, to assess answer quality and refine the knowledge base.

### User Story
**As an** admin of Paddock Picks
**I want to** submit any F1 question from a web form and see the AI answer with sources
**So that** I can evaluate the quality of the RAG system, test new knowledge base entries, and decide which queries to expose to members

## Functionality

### User Flow
1. Admin navigates to `/f1-intelligence/query.php`.
2. Page loads with a text area and a "Ask" submit button.
3. Admin types a question (e.g., "Who will win at Monza given the current grid?").
4. Admin clicks "Ask" — button disables and spinner appears.
5. Answer and sources render below the form when the API responds.
6. Admin can submit another question without reloading the page.

### Detailed Specifications

**Form**
- Textarea: min 3 rows, max 500 characters, placeholder "Ask an F1 question…"
- Submit button: disabled during loading, re-enabled on response
- Inline character counter

**Answer display**
- Renders inside a Bootstrap card below the form
- Answer text: `nl2br` + `htmlspecialchars`, or parsed markdown if a lightweight JS renderer is available
- Sources: ordered list with title and similarity % per source
- Response time shown (e.g., "Answered in 4.2s")

**Error states**
- API timeout: "The AI took too long to respond. Try again."
- API error (5xx): "Something went wrong. Check Vercel logs."
- Empty question: inline validation before submit

## Acceptance Criteria
- [ ] Page returns 403/redirect for non-admin users
- [ ] Submitting a question returns an answer within 30 seconds
- [ ] Sources list shows at least 1 source with similarity %
- [ ] Loading spinner visible during API call
- [ ] Error message shown cleanly on API failure (no stack trace)
- [ ] CSRF token validated on POST
- [ ] Works on mobile viewport (375px)
