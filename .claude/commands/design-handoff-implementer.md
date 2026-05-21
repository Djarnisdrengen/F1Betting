# design-handoff-implementer

Design Handoff Implementer
A comprehensive skill for systematically implementing design handoffs from claude.ai/design into production PHP/HTML/CSS/JS codebases. This skill guides you through the complete implementation process, from CSS tokens to final deployment.
What is a Design Handoff?
Design handoffs from claude.ai/design produce structured design packages that include:

Live design canvas (HTML file with interactive artboards)
JSX component references (React components showing structure, not for production use)
CSS design system (tokens, utilities, component styles)
Handover documentation (implementation guide with phases and acceptance criteria)
Acceptance criteria (explicit pass/fail tests for every feature)

Your job is to translate these design references into production code while maintaining visual fidelity and ensuring all acceptance criteria pass.
## Handoff to implement
$ARGUMENTS

When to Use This Skill
Use this skill whenever:

User shares a Claude Design handoff (zip file, folder, or link)
User mentions implementing a design from claude.ai/design
User asks to convert JSX components to PHP/HTML
User needs help with design system implementation
User mentions design tokens, CSS custom properties, or responsive breakpoints
User asks about mobile navigation patterns (hamburger menus, drawers)
User needs i18n (internationalization) implementation
User mentions acceptance criteria or design validation
User is working on a redesign project with structured phases

Core Principles
1. Phased Implementation
Always implement in phases - each phase should be independently shippable:

CSS tokens & shell - Foundation styles without breaking existing pages
Header/navigation - New navigation pattern
Bottom bar/footer - Persistent UI elements
Per-page templates - One page at a time
Interactive components - Modals, forms, complex interactions
Backend changes - Database migrations, API updates
Email templates - Transactional email redesigns

2. JSX is Reference, Not Production Code
The JSX files are visual specifications, not code to copy-paste:

Read JSX to understand structure and class names
Translate to PHP with proper templating (<?= ?>, foreach, escaping)
Convert React patterns to vanilla JavaScript where needed
Never try to use JSX directly in a PHP codebase

3. Additive, Not Destructive
New styles should be namespaced (e.g., .hf-* classes) to avoid breaking existing pages:

Append new CSS to existing stylesheets
Keep old styles until all pages are migrated
Only delete old code after new code is verified working

4. Mobile-First Validation
Every implementation must work on mobile before desktop:

Test at 320px width first
Navigation must always be accessible
Touch targets must be at least 44×44px
Content must be readable without zooming

Implementation Workflow
Phase 0: Understand the Handoff

Locate the files:

Unzip the handoff bundle if needed
Find README.md or main handoff doc
Locate HANDOVER-GUIDE.md if present
Note the hifi/ or similar folder with JSX references
Find style.css with design tokens


Read the documentation:

Read handoff doc end-to-end before coding
Note all acceptance criteria (usually in §7)
Understand backend changes required
Note any email template updates
Check for special requirements (i18n, accessibility, browser support)


Open the design canvas:

Open the .html canvas file in browser
Toggle dark/light themes to understand variants
Scroll through all sections/artboards
Use fullscreen mode to inspect details
Keep canvas open during implementation as reference



Phase 1: CSS Tokens & Foundation
Goal: Set up design tokens and base styles without breaking anything.

Copy design system CSS:

php   // Open public/assets/css/style.css
   // Append entire contents of hifi/style.css at the end
   // Namespaced classes (e.g., .hf-*) won't collide with existing styles

Add font imports:

css   /* Add at top of style.css if not present */
   @import url('https://fonts.googleapis.com/css2?family=Chivo:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700&display=swap');

Set up CSS custom properties:

css   :root {
     /* Colors - usually already present, verify they match handoff */
     --f1-red: #e10600;
     --bg-primary: #1c1c20;
     --text-primary: #ffffff;
     
     /* Typography - add if missing */
     --font-display: 'Chivo', sans-serif;
     --font-body: 'Inter', sans-serif;
     
     /* Spacing - add standard scale if missing */
     --space-xs: 4px;
     --space-sm: 8px;
     --space-md: 16px;
     --space-lg: 24px;
     --space-xl: 32px;
   }

Verify nothing broke:

Reload existing pages
Check browser console for CSS errors
Existing pages should look identical



Phase 2: Navigation Shell
Goal: Implement new header/navigation pattern.
A. Header with Hamburger Menu
Pattern: Logo + hamburger button (replaces old inline nav)
php<!-- public/includes/header.php -->
<header class="hf-top">
  <a href="/" class="hf-logo">
    <span class="hf-logo-mark">F1</span>
    <span class="hf-logo-text">
      Frederikssund F1 Klub
      <span class="yr">2026</span>
    </span>
  </a>
  <button class="hf-hamburger" data-action="toggleDrawer" aria-label="Menu">
    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
      <path d="M3 5h14M3 10h14M3 15h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </svg>
  </button>
</header>
B. Navigation Drawer
Pattern: Slide-in overlay navigation
php<nav class="hf-drawer" data-drawer aria-hidden="true">
  <div class="hf-drawer-backdrop" data-action="closeDrawer"></div>
  <div class="hf-drawer-panel">
    <div class="hf-drawer-header">
      <span>Menu</span>
      <button class="hf-drawer-close" data-action="closeDrawer" aria-label="Luk menu">×</button>
    </div>
    <div class="hf-drawer-body">
      <?php foreach ($nav_items as $item): ?>
      <a href="<?= $item['url'] ?>" class="hf-drawer-row">
        <span class="hf-drawer-row-icon"><?= $item['icon'] ?></span>
        <span class="hf-drawer-row-label"><?= $item['label'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</nav>
C. Drawer Toggle JavaScript
javascript// Add to shared JS file or inline at bottom of header.php
(function() {
  const drawer = document.querySelector('[data-drawer]');
  const togglers = document.querySelectorAll('[data-action="toggleDrawer"]');
  const closers = document.querySelectorAll('[data-action="closeDrawer"]');
  
  function openDrawer() {
    drawer.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }
  
  function closeDrawer() {
    drawer.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }
  
  togglers.forEach(btn => btn.addEventListener('click', openDrawer));
  closers.forEach(btn => btn.addEventListener('click', closeDrawer));
  
  // Close on Escape key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && drawer.getAttribute('aria-hidden') === 'false') {
      closeDrawer();
    }
  });
})();
D. Remove Old Navigation
After verifying the new navigation works:

Delete old .nav classes and their CSS
Remove old mobile navigation blocks
Clean up unused media queries

Phase 3: Bottom Bar / Footer
Goal: Persistent bottom bar with Profile, Theme, Language, Font controls.
A. Create Bottom Bar Partial
php<!-- public/includes/bottom_bar.php -->
<div class="hf-bottom">
  <a href="/profile.php" class="hf-bottom-cell">
    <svg class="hf-bottom-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
      <circle cx="10" cy="7" r="3" stroke="currentColor" stroke-width="1.5"/>
      <path d="M4 18c0-3.314 2.686-6 6-6s6 2.686 6 6" stroke="currentColor" stroke-width="1.5"/>
    </svg>
    <span class="hf-bottom-label"><?= $lang['profile'] ?></span>
  </a>
  
  <a href="?toggle_theme" class="hf-bottom-cell">
    <svg class="hf-bottom-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
      <!-- Sun/Moon icon based on current theme -->
      <?php if ($theme === 'dark'): ?>
      <circle cx="10" cy="10" r="4" fill="currentColor"/>
      <path d="M10 2v2m0 12v2M18 10h-2M4 10H2m13.657-5.657l-1.414 1.414M6.343 14.243l-1.414 1.414m9.9 0l-1.414-1.414M6.343 5.757L4.93 4.343" stroke="currentColor" stroke-width="1.5"/>
      <?php else: ?>
      <path d="M17 12.5a7 7 0 1 1-5-6.7 5.5 5.5 0 0 0 5 6.7z" fill="currentColor"/>
      <?php endif; ?>
    </svg>
    <span class="hf-bottom-label"><?= $lang['theme'] ?></span>
  </a>
  
  <a href="?toggle_lang" class="hf-bottom-cell">
    <!-- Danish or English flag SVG -->
    <?php if ($lang_code === 'da'): ?>
    <svg class="hf-bottom-flag" width="24" height="18" viewBox="0 0 24 18">
      <rect width="24" height="18" fill="#C8102E"/>
      <rect x="7" width="3" height="18" fill="white"/>
      <rect y="7.5" width="24" height="3" fill="white"/>
    </svg>
    <?php else: ?>
    <!-- English flag (Union Jack or US flag based on preference) -->
    <?php endif; ?>
    <span class="hf-bottom-label"><?= $lang['language'] ?></span>
  </a>
  
  <a href="?toggle_font" class="hf-bottom-cell">
    <svg class="hf-bottom-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
      <path d="M4 15h12M10 3v12m-3-9h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
    </svg>
    <span class="hf-bottom-label"><?= $lang['font'] ?></span>
  </a>
</div>
B. Include on Every Page
php<!-- At bottom of each page template, after </main> -->
<?php include 'includes/bottom_bar.php'; ?>
C. Handle Toggle Actions
php// At top of each page or in shared include
if (isset($_GET['toggle_theme'])) {
  $theme = $_SESSION['theme'] ?? 'dark';
  $_SESSION['theme'] = ($theme === 'dark') ? 'light' : 'dark';
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
  exit;
}

if (isset($_GET['toggle_lang'])) {
  $lang_code = $_SESSION['lang'] ?? 'da';
  $_SESSION['lang'] = ($lang_code === 'da') ? 'en' : 'da';
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
  exit;
}

// Font toggle (stub for now, implement later if needed)
if (isset($_GET['toggle_font'])) {
  // Toggle between font sizes or font families
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
  exit;
}
Phase 4: Internationalization (i18n)
Goal: Centralized text storage with Danish/English switching.
A. Language Files Structure
php<!-- lang/da.php -->
<?php
return [
  'site_name' => 'Frederikssund F1 Klub',
  'profile' => 'Profil',
  'theme' => 'Tema',
  'language' => 'Sprog',
  'font' => 'Skrifttype',
  'home' => 'Hjem',
  'races' => 'Løb',
  'leaderboard' => 'Leaderboard',
  'rules' => 'Regler',
  'login' => 'Log ind',
  'logout' => 'Log ud',
  'welcome' => 'Velkommen',
  // ... all other text strings
];
php<!-- lang/en.php -->
<?php
return [
  'site_name' => 'Frederikssund F1 Club',
  'profile' => 'Profile',
  'theme' => 'Theme',
  'language' => 'Language',
  'font' => 'Font',
  'home' => 'Home',
  'races' => 'Races',
  'leaderboard' => 'Leaderboard',
  'rules' => 'Rules',
  'login' => 'Log in',
  'logout' => 'Log out',
  'welcome' => 'Welcome',
  // ... all other text strings
];
B. Language Loader
php<!-- includes/lang.php -->
<?php
session_start();

// Get language preference
$lang_code = $_SESSION['lang'] ?? 'da'; // Default to Danish

// Load language file
$lang_file = __DIR__ . "/../lang/{$lang_code}.php";
if (!file_exists($lang_file)) {
  $lang_file = __DIR__ . "/../lang/da.php"; // Fallback to Danish
}

$lang = require $lang_file;

// Helper function for translations
function t($key, $fallback = '') {
  global $lang;
  return $lang[$key] ?? $fallback ?? $key;
}
C. Usage in Templates
php<!-- Include at top of every page -->
<?php require_once 'includes/lang.php'; ?>

<!-- Use in markup -->
<h1><?= t('welcome') ?></h1>
<p><?= t('race_countdown', 'Next race in:') ?></p>

<!-- With dynamic content -->
<p><?= sprintf(t('points_format'), $points) ?></p>
<!-- Where da.php has: 'points_format' => 'Du har %d point' -->
<!-- And en.php has: 'points_format' => 'You have %d points' -->
Phase 5: Per-Page Implementation
Goal: Port each page's visual structure from JSX to PHP.
JSX to PHP Translation Patterns
JSX PatternPHP TranslationclassName="card"class="card"{variable}<?= htmlspecialchars($variable) ?>{variable} (trusted HTML)<?= $variable ?>{items.map(item => ...)}<?php foreach ($items as $item): ?> ... <?php endforeach; ?>{condition && <div>...</div>}<?php if ($condition): ?> <div>...</div> <?php endif; ?>{condition ? 'yes' : 'no'}<?= $condition ? 'yes' : 'no' ?>onClick={handler}onclick="handler()" (vanilla JS)style={{color: 'red'}}style="color: red;"React state/hooksVanilla JS variables + DOM manipulation
Page Implementation Recipe
For each page (Home, Races, Race Detail, Leaderboard, Profile, Rules, Login, Admin):

Open JSX reference side-by-side with PHP template:

bash   # Example for home page
   code hifi/home.jsx public/index.php

Read JSX structure top-to-bottom:

Note main containers (.hf-body, sections, cards)
Identify data sources (props, state → PHP variables, DB queries)
Find conditional rendering (→ PHP if/foreach)
Spot interactive elements (→ vanilla JS)


Wrap page in new shell:

php   <?php require_once 'includes/header.php'; ?>
   
   <main class="hf-body">
     <!-- Page content here -->
   </main>
   
   <footer class="hf-footer">
     <span>Frederikssund F1 Klub · v1.3.0 · Sæson 2026</span>
   </footer>
   
   <?php require_once 'includes/bottom_bar.php'; ?>

Translate each JSX block:

jsx   // JSX reference (home.jsx)
   function HomeHero({ nextRace }) {
     return (
       <div className="hero-card">
         <h1>{nextRace.name}</h1>
         <p>{nextRace.location}</p>
       </div>
     );
   }
php   <!-- PHP translation (index.php) -->
   <div class="hero-card">
     <h1><?= htmlspecialchars($next_race['name']) ?></h1>
     <p><?= htmlspecialchars($next_race['location']) ?></p>
   </div>

Handle dynamic content:

jsx   // JSX
   {races.map(race => (
     <div key={race.id} className="race-row">
       <span>{race.name}</span>
       <span>{race.date}</span>
     </div>
   ))}
php   <!-- PHP -->
   <?php foreach ($races as $race): ?>
   <div class="race-row">
     <span><?= htmlspecialchars($race['name']) ?></span>
     <span><?= htmlspecialchars($race['date']) ?></span>
   </div>
   <?php endforeach; ?>

Add interactive JavaScript:

javascript   // Countdown timer example
   function startCountdown(targetDate) {
     const target = new Date(targetDate).getTime();
     const countdownEl = document.getElementById('countdown');
     
     setInterval(() => {
       const now = new Date().getTime();
       const distance = target - now;
       
       const days = Math.floor(distance / (1000 * 60 * 60 * 24));
       const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
       const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
       const seconds = Math.floor((distance % (1000 * 60)) / 1000);
       
       countdownEl.textContent = `${days}d ${hours}h ${minutes}m ${seconds}s`;
     }, 1000);
   }

Compare with design canvas:

Open canvas fullscreen for this page
Toggle dark/light theme
Verify spacing, colors, typography match
Test at XS (320px), SM (640px), MD (768px), LG (1024px), XL (1280px)


Verify page-specific acceptance criteria:

Find the page's AC section in handoff doc (e.g., AC-HOME-01 through AC-HOME-06)
Check each criterion before moving to next page



Phase 6: Responsive Breakpoints
Goal: Ensure layouts adapt correctly across all breakpoints.
Standard Breakpoint System
css/* Mobile-first approach */

/* XS: 320px - 639px (mobile phones) */
/* Base styles - no media query needed */

/* SM: 640px - 767px (large phones, small tablets) */
@media (min-width: 640px) {
  /* Small tablet adjustments */
}

/* MD: 768px - 1023px (tablets) */
@media (min-width: 768px) {
  /* Tablet layouts */
}

/* LG: 1024px - 1279px (small desktops) */
@media (min-width: 1024px) {
  /* Desktop layouts */
}

/* XL: 1280px+ (large desktops) */
@media (min-width: 1280px) {
  /* Wide screen optimizations */
}
Common Responsive Patterns
Navigation:

XS/SM: Hamburger menu only
MD+: Can add inline nav if desired, but hamburger should still work

Grid Layouts:
css.grid {
  display: grid;
  gap: var(--space-md);
  
  /* XS: 1 column */
  grid-template-columns: 1fr;
}

@media (min-width: 640px) {
  .grid {
    /* SM: 2 columns */
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (min-width: 1024px) {
  .grid {
    /* LG: 3 columns */
    grid-template-columns: repeat(3, 1fr);
  }
}
Typography:
cssh1 {
  font-size: 28px;
  line-height: 1.2;
}

@media (min-width: 768px) {
  h1 {
    font-size: 36px;
  }
}

@media (min-width: 1024px) {
  h1 {
    font-size: 48px;
  }
}
Touch Targets:
css/* All interactive elements must be at least 44×44px on mobile */
button, a, input, select {
  min-height: 44px;
  min-width: 44px; /* Only if it's a square button */
  padding: 12px 16px; /* For text buttons */
}

@media (min-width: 1024px) {
  /* Can be smaller on desktop since mouse is more precise */
  button {
    min-height: 36px;
    padding: 8px 12px;
  }
}
Phase 7: Email Templates
Goal: Transactional emails that work in all email clients, especially Outlook.
Email Template Rules

Use <table> for layout (not flexbox or grid):

html   <table width="100%" cellpadding="0" cellspacing="0" border="0">
     <tr>
       <td>Content here</td>
     </tr>
   </table>

Inline all styles:

html   <td style="padding: 20px; background-color: #1c1c20; color: #ffffff;">
     Content
   </td>

System font stack (no Google Fonts):

html   <td style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;">

Button CTA with table-based structure:

html   <table cellpadding="0" cellspacing="0" border="0">
     <tr>
       <td style="background-color: #e10600; border-radius: 8px; padding: 12px 24px;">
         <a href="https://example.com" style="color: #ffffff; text-decoration: none; font-weight: 600;">
           Click Here
         </a>
       </td>
     </tr>
   </table>

Header with inline logo:

html   <table width="100%" cellpadding="0" cellspacing="0">
     <tr>
       <td style="padding: 20px; background-color: #1c1c20; border-bottom: 1px solid #333;">
         <table cellpadding="0" cellspacing="0">
           <tr>
             <td width="32" height="32" style="background-color: #e10600; border-radius: 7px; color: #ffffff; text-align: center; font-weight: 900; font-size: 13px; font-family: 'Chivo', sans-serif;">
               F1
             </td>
             <td style="padding-left: 10px; font-family: 'Chivo', sans-serif; font-weight: 800; font-size: 14px; color: #ffffff;">
               Frederikssund F1 Klub
             </td>
           </tr>
         </table>
       </td>
     </tr>
   </table>

Footer:

html   <table width="100%" cellpadding="0" cellspacing="0">
     <tr>
       <td style="padding: 20px; text-align: center; color: #666; font-size: 12px; font-family: sans-serif;">
         Frederikssund F1 Klub · v1.3.0 · Sæson 2026
       </td>
     </tr>
   </table>

Test in multiple clients:

Gmail (web, iOS, Android)
Outlook (Windows desktop - the hardest!)
Apple Mail (macOS, iOS)
Use Litmus or Email on Acid for testing



Phase 8: Backend Changes
Goal: Database migrations and query updates to support new features.
Migration Pattern
php<?php
// migrations/add_leaderboard_rank_delta.php

class AddLeaderboardRankDelta {
  public function up($db) {
    // Option A: Snapshot table (recommended for performance)
    $db->query("
      CREATE TABLE leaderboard_snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        race_id INT NOT NULL,
        rank INT NOT NULL,
        points INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_race (user_id, race_id),
        INDEX idx_race (race_id)
      )
    ");
    
    // OR Option B: Window function (simpler but potentially slower)
    // Add 'previous_rank' column to leaderboard table
    // $db->query("ALTER TABLE leaderboard ADD COLUMN previous_rank INT NULL");
  }
  
  public function down($db) {
    // Always provide a rollback
    $db->query("DROP TABLE IF EXISTS leaderboard_snapshots");
    
    // OR for Option B:
    // $db->query("ALTER TABLE leaderboard DROP COLUMN previous_rank");
  }
}
Query Updates
php// Example: Leaderboard with rank delta

// Option A: Using snapshot table
$query = "
  SELECT 
    l.user_id,
    l.rank,
    l.points,
    u.display_name,
    IFNULL(s.rank, l.rank) as previous_rank,
    (IFNULL(s.rank, l.rank) - l.rank) as rank_delta
  FROM leaderboard l
  JOIN users u ON l.user_id = u.id
  LEFT JOIN leaderboard_snapshots s ON (
    s.user_id = l.user_id 
    AND s.race_id = ?
  )
  WHERE l.season_id = ?
  ORDER BY l.rank ASC
";

// Option B: Window function (MySQL 8.0+)
$query = "
  SELECT 
    user_id,
    rank,
    points,
    display_name,
    LAG(rank) OVER (PARTITION BY user_id ORDER BY race_id) as previous_rank,
    (LAG(rank) OVER (PARTITION BY user_id ORDER BY race_id) - rank) as rank_delta
  FROM leaderboard
  JOIN users USING (user_id)
  WHERE season_id = ?
  ORDER BY rank ASC
";
Phase 9: Acceptance Criteria Validation
Goal: Verify every acceptance criterion passes before deployment.
Validation Checklist Process

Locate acceptance criteria in handoff doc (usually §7)
Group by category:

Shell (navigation, footer, responsive shell)
Theme & Language (switching, persistence)
Per-page (Home, Races, Leaderboard, etc.)
Email templates
Backend (data correctness, migrations)
Accessibility (keyboard nav, focus states)
Browser compatibility
Performance


Test systematically:

Use actual device for mobile testing (not just browser resize)
Test in all required browsers (Chrome, Safari, Firefox, Edge)
Verify on both macOS/Windows and iOS/Android
Check both light and dark themes
Test both languages (Danish and English)


Document results:

markdown   ## Acceptance Criteria Results
   
   ### Shell
   - [x] AC-SHELL-01: Header renders on all pages
   - [x] AC-SHELL-02: Hamburger opens drawer
   - [x] AC-SHELL-03: Drawer closes on outside click
   - [x] AC-SHELL-04: Drawer closes on Esc key
   - [x] AC-SHELL-05: Bottom bar sticky on all pages
   
   ### Home Page
   - [x] AC-HOME-01: Hero card shows next race
   - [x] AC-HOME-02: Countdown updates every second
   - [ ] AC-HOME-03: Stats strip shows pool size (FAILED - format wrong)
   - ...

Fix failures before moving on:

Each page's criteria must pass before implementing next page
Don't accumulate technical debt
Re-test after fixes



Common Pitfalls & Solutions
Problem: CSS not applying
Cause: Browser cached old stylesheet
Solution: Hard reload (Cmd+Shift+R / Ctrl+Shift+F5), verify file saved, check Network tab in DevTools
Problem: Drawer and old mobile nav both visible
Cause: Old media query styles not deleted
Solution: Find and remove old @media (max-width: 768px) blocks per handoff instructions
Problem: Hamburger doesn't open drawer
Cause: JavaScript not loaded or data-action attribute missing
Solution: Verify toggle script is in shared JS bundle, check console for errors, verify data-action="toggleDrawer" on button
Problem: Bottom bar overlaps content
Cause: <main> not accounting for bottom bar height
Solution: Add min-height: calc(100vh - 56px - 64px) to <main> (56px = header, 64px = bottom bar)
Problem: Theme/language toggle navigates but doesn't toggle
Cause: Query param handler not running before includes
Solution: Move handler to top of file, before any output or includes
Problem: Email looks fine in Gmail, broken in Outlook
Cause: Used flexbox/grid instead of <table> layout
Solution: Rewrite email with <table> markup, inline all styles, test in Outlook on Windows
Problem: Leaderboard rank delta shows NaN or null
Cause: First-ever race has no previous snapshot to compare
Solution: Handle null case explicitly: <?= $rank_delta !== null ? $rank_delta : '—' ?>
Problem: Mobile navigation breaks at certain width
Cause: Wrong breakpoint or missing media query
Solution: Verify exact pixel widths match design system (320, 640, 768, 1024, 1280), test at each boundary
Problem: Text not translating when language switched
Cause: Hardcoded strings instead of using t() function
Solution: Replace all hardcoded text with <?= t('key') ?>, add translations to language files
Problem: Design tokens not working
Cause: CSS custom properties not supported (IE11) or typo in variable name
Solution: Verify browser support (drop IE11), check exact variable name matches :root declaration
Quick Reference: File Locations
your-php-project/
├── public/
│   ├── index.php              ← Home page
│   ├── races.php              ← Races list
│   ├── race.php               ← Race detail + bet modal
│   ├── leaderboard.php        ← Leaderboard
│   ├── profile.php            ← User profile
│   ├── rules.php              ← Rules/FAQ
│   ├── login.php              ← Login/register
│   ├── admin.php              ← Admin panel
│   ├── assets/
│   │   └── css/
│   │       └── style.css      ← Main stylesheet (append new CSS here)
│   └── includes/
│       ├── header.php         ← Header with nav (rewrite in Phase 2)
│       ├── bottom_bar.php     ← Bottom bar (create in Phase 3)
│       └── lang.php           ← Language loader
├── lang/
│   ├── da.php                 ← Danish translations
│   └── en.php                 ← English translations
├── migrations/
│   └── *.php                  ← Database migrations
└── emails/
    ├── invite.html            ← Email templates
    ├── password-reset.html
    ├── race-reminder.html
    ├── results-posted.html
    └── welcome.html
When to Ask for Help
Stop and ask the user for clarification when:

The handoff doc has conflicting instructions
A required PHP variable/function isn't in the existing codebase
The database schema doesn't match expectations
An acceptance criterion is ambiguous or seems impossible
The design canvas shows something that contradicts the JSX reference
Backend changes require choosing between multiple options (Option A vs B)
Email testing reveals a critical rendering bug that can't be fixed with tables

Success Metrics
You've successfully implemented the handoff when:

 All acceptance criteria in §7 are checked and passing
 Every page renders correctly at XS, SM, MD, LG, XL breakpoints
 Both light and dark themes work on all pages
 Both languages (Danish/English) display correctly
 Mobile navigation works on actual devices
 All 5 email templates pass Outlook-on-Windows test
 Backend migrations run cleanly with both up() and down()
 No console errors on any page
 Real club member has approved on their phone
 Screenshots and Loom recording documented in PR

When all these are true, the handoff is complete and ready for merge to production.

## Project-specific note: font/theme/lang toggles

All UI state toggles (theme, language, font stack) use `$_SESSION`, not cookies. The pattern is:

```php
if (isset($_GET['toggle_font'])) {
    $_SESSION['font_stack'] = ($_SESSION['font_stack'] ?? 'editorial') === 'editorial' ? 'system' : 'editorial';
    $currentUrl = $_SERVER['REQUEST_URI'];
    $currentUrl = preg_replace('/([&?])toggle_font=1(&|$)/', '$1', $currentUrl);
    $currentUrl = rtrim($currentUrl, '?&');
    header('Location: ' . $currentUrl);
    exit;
}
$fontStack = $_SESSION['font_stack'] ?? 'editorial';
```

**Do not use `setcookie()`** for these toggles. On this server, calling `setcookie()` after the security headers have been sent (they are sent unconditionally in `config.shared.php`) can silently fail and also prevent the subsequent `header('Location:')` redirect from firing — leaving `?toggle_font=1` permanently in the URL. `$_SESSION` writes do not emit HTTP headers and are always safe.

The preg_replace pattern preserves other query params (e.g. `?tab=upcoming&toggle_font=1` → `?tab=upcoming`). The same pattern is used for `toggle_theme` and `toggle_lang`.

## Project-specific note: i18n
This project already has a translation system — do NOT create new `lang/da.php` / `lang/en.php` files or a new `t()` function. Instead:
- Use the existing `t('key')` helper defined in `public/includes/functions.php`
- Add new translation strings to the appropriate existing lang file: `public/lang/user.php` (user-facing), `public/lang/admin.php` (admin panel), or `public/lang/email.php` (transactional emails)
- The existing `getLang()` function reads the user's stored language preference from session/DB — do not replace it with a session-only toggle
