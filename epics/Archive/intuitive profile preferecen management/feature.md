# Feature: Improved Profile Page with Intuitive Name and Preferences Management

## Requirements

### Functional Requirements
- [REQ-001] Profile page must display user's current display name with clear visual indication it's editable
- [REQ-002] Users must be able to edit their display name directly on profile page with inline editing
- [REQ-003] Display name changes must validate for minimum 2 characters and maximum 30 characters
- [REQ-004] Display name must validate for uniqueness among active users before saving
- [REQ-005] System must provide immediate visual feedback during name editing (edit mode, saving state, success/error)
- [REQ-006] Profile page must display three user preference controls in a clearly labeled "Preferences" section
- [REQ-007] Preference 1: "Theme" - Dropdown/selector to choose visual theme (Light or Dark)
- [REQ-008] Preference 2: "Font" - Dropdown/selector to choose font family (sys or editorial)
- [REQ-009] Preference 3: "Language" - Dropdown/selector to choose interface language (Danish or English)
- [REQ-010] Preference controls must update immediately with optimistic UI updates when user makes a selection
- [REQ-011] All preference changes must persist to database and survive session refresh
- [REQ-012] Profile page must show validation errors inline without page reload
- [REQ-013] Cancel/undo functionality for name editing before save completes
- [REQ-014] Theme preference must apply immediately to the profile page (and optionally persist across entire app)
- [REQ-015] Font size preference must apply immediately to all text on profile page (and optionally persist across app)
- [REQ-016] Language preference must update interface labels immediately or after page reload depending on implementation

### Non-Functional Requirements
- [NFR-001] Profile name validation and save must complete within 800ms for responsive feel
- [NFR-002] Preference selection updates must complete within 500ms to feel instant
- [NFR-003] Touch targets for all interactive elements must be minimum 44px on mobile devices (including dropdown controls)
- [NFR-004] Input field for name editing must be minimum 16px font size to prevent iOS zoom
- [NFR-005] Profile page must work seamlessly on 320px mobile width up to desktop
- [NFR-006] All form interactions must work without page reload (AJAX/fetch updates)
- [NFR-007] Profile page load must complete in under 2 seconds on 3G mobile connection

### Technical Constraints
- Must work on simply.com shared hosting (PHP 8, MySQL, no Node.js)
- No build step - direct deployment of source files
- Frontend: Plain vanilla HTML/CSS/JS (no framework)
- Backend: PHP 8 with MySQL, JWT authentication
- Mobile-first responsive design with touch optimization
- Input fields minimum 16px to prevent iOS zoom
- Touch targets minimum 44px for iOS/Android

## User Story

### Primary User Goal
Allow users to easily personalize their Paddock Picks profile with their preferred display name and customize their visual experience (theme, font size) and language preferences without confusion or frustration, especially on mobile devices.

### User Story Format
**As a** Paddock Picks user competing with friends  
**I want to** easily edit my display name and customize my visual preferences (theme, font size, language) directly on my profile page  
**So that** I can personalize my experience to match my preferences and make the app more comfortable to use without navigating multiple screens or getting confused by hidden settings

### User Personas
- **Betting member**: Participates in predictions, manages their profile settings, wants easy access to preferences for personalized experience
- **Betting admin**: Manages races and results, also participates in betting, needs same profile customization options as members

## Functionality

### User Flow

1. **User navigates to profile page**
   - System loads profile page with current user data
   - Display name shows with subtle edit icon or pencil indicator
   - Three preference controls display in "Preferences" section with current values selected
   - Each preference has clear label and dropdown/selector control

2. **User edits display name**
   - User taps/clicks on display name or edit icon
   - Name field becomes editable with focus
   - Cancel/Save buttons appear (mobile: check/X icons)
   - User types new name with live character count (e.g., "18/30")
   - Save button enables when valid, stays disabled if invalid

3. **User saves display name**
   - User taps Save button
   - System shows loading spinner on button
   - Backend validates uniqueness and format
   - Success: Name updates visually, success message shows briefly ("Name updated!")
   - Error: Inline error appears ("Name already taken" or "Must be 2-30 characters")
   - Edit mode exits on success, stays open on error

4. **User changes a preference**
   - User clicks/taps on a preference dropdown (theme, font size, or language)
   - Dropdown opens with available options
   - User selects new value
   - System immediately applies visual change (for theme/font) or shows loading indicator
   - System persists preference change to user account
   - Dropdown closes and shows selected value

5. **Mobile experience**
   - All touch targets 44px minimum for easy tapping
   - Name input 16px+ font to prevent zoom
   - Preference dropdowns have large tap targets and clear visual feedback
   - No accidental edits - clear edit vs view mode
   - Keyboard-friendly for name editing

### Detailed Specifications

#### Profile Name Editing

**Display Mode (Default State)**
- Display name shown in larger font (18px minimum on mobile)
- Subtle edit icon (pencil) positioned to the right of name
- On hover (desktop): light background highlight to indicate clickable
- On mobile: edit icon always visible, no hover needed
- Current implementation: Display name shown as plain text with no edit capability

**Edit Mode (Active State)**
- Click/tap on name or edit icon triggers edit mode
- Name becomes editable input field with current value selected
- Character counter appears below input: "18/30 characters"
- Two action buttons appear:
  - **Save** (checkmark icon on mobile, "Save" text on desktop) - Primary action color
  - **Cancel** (X icon on mobile, "Cancel" text on desktop) - Secondary/gray
- Input field has clear focus ring for accessibility
- Validation runs on input:
  - Real-time character count update
  - Save button disabled if <2 or >30 characters
  - Visual indication (red border) if invalid length

**Validation Rules**
- Minimum 2 characters, maximum 30 characters
- Allowed characters: letters, numbers, spaces, hyphens, underscores
- No leading/trailing whitespace (auto-trimmed)
- Must be unique among all active users (checked on save)
- Case-insensitive uniqueness check (prevent "Alice" and "alice")

**Save Process**
1. User clicks Save button
2. Button shows loading spinner, becomes disabled
3. AJAX POST to `/api/profile/update-name` with JWT auth
4. Backend validation:
   - Length check (2-30 chars)
   - Character validation (alphanumeric + space/hyphen/underscore)
   - Uniqueness check against `users` table
5. Response handling:
   - **Success (200)**: Update display, show success toast "Name updated!", exit edit mode
   - **Conflict (409)**: Show error "Name already taken", stay in edit mode
   - **Validation error (400)**: Show error message, stay in edit mode
   - **Network error**: Show error "Update failed, please try again", stay in edit mode

**Cancel Process**
- User clicks Cancel button
- Revert input to original value
- Exit edit mode back to display mode
- No API call needed

#### Preference Controls Section

**Section Layout**
- Clear section heading: "Preferences"
- Subtitle/helper text: "Customize your Paddock Picks experience"
- Three preference rows, vertically stacked
- Each row contains:
  - Preference label (bold, 16px minimum)
  - Dropdown/select control (right-aligned on desktop, full-width on mobile)
  - Optional description text (gray, 14px, explains what it does)

**Preference 1: Theme**
- Label: "Theme"
- Description: "Choose your visual theme"
- Control type: Dropdown select
- Options:
  - "Light" - Light background with dark text
  - "Dark" - Dark background with light text
- Default value: "Light"
- Database field: `users.pref_theme` (VARCHAR(20))
- Behavior:
  - Selection immediately applies theme to profile page
  - CSS classes added/removed on `<html>` or `<body>` element (e.g., `theme-light`, `theme-dark`)
  - Theme preference stored in database for persistence across sessions
  - Optionally applies theme globally across entire app (not just profile page)

**Preference 2: Font**
- Label: "Font"
- Description: "Choose your preferred font style"
- Control type: Dropdown select
- Options:
  - "sys" - System font (native platform fonts like SF Pro on iOS, Roboto on Android)
  - "editorial" - Editorial font (serif font for reading-focused experience)
- Default value: "sys"
- Database field: `users.pref_font` (VARCHAR(20))
- Behavior:
  - Selection immediately changes font family on profile page
  - CSS class added to `<html>` or `<body>` (e.g., `font-sys`, `font-editorial`)
  - Font preference stored in database
  - Optionally applies globally across app
  - Maintains minimum 16px on form inputs to prevent iOS zoom

**Preference 3: Language**
- Label: "Language"
- Description: "Choose your preferred language"
- Control type: Dropdown select
- Options:
  - "English" - English interface labels
  - "Danish" - Danish interface labels
- Default value: "English"
- Database field: `users.pref_language` (VARCHAR(10), stores language code like 'en', 'da')
- Behavior:
  - Selection saves language preference to database
  - Page reloads or updates to show interface in selected language
  - All labels, buttons, messages translated accordingly
  - Language preference persists across sessions

**Dropdown Interaction Flow**
1. User clicks/taps on dropdown control
2. Dropdown expands to show all available options
3. User selects a new value
4. Dropdown closes immediately
5. For theme/font: Visual change applies immediately (optimistic update)
6. AJAX POST to `/api/profile/update-preference`
   - Payload: `{ preference: 'theme', value: 'dark' }`
7. Response handling:
   - **Success (200)**: Preference persists, visual state maintained
   - **Error (400/500)**: Show error toast, revert to previous value if needed
   - **Network error**: Show "Update failed" toast

**Visual Design for Dropdowns**
- Native `<select>` elements styled consistently with app design
- Clear dropdown indicator (arrow icon)
- Touch target: Full row height minimum 44px on mobile
- Focused state: Clear border or outline for accessibility
- Disabled state (during save): Slightly faded, not clickable
- Loading state: Small spinner next to dropdown (if needed for language changes)

#### Error Handling

**Display Name Errors**
- "Name must be between 2 and 30 characters" - shown if length invalid
- "Name already taken by another user" - shown if uniqueness fails
- "Name can only contain letters, numbers, spaces, hyphens, and underscores" - character validation
- "Unable to update name. Please try again." - network/server error

**Preference Selection Errors**
- Toast notification (bottom of screen, 3s duration)
- "Unable to save preference. Please try again."
- Revert dropdown to previous value if update fails
- User can immediately retry

**Network Errors**
- Detect offline state before API calls
- Show "You appear to be offline" message
- Disable Save/toggle actions until online

### Mobile Considerations

- **Touch Targets**: All interactive elements 44px minimum
  - Edit icon: 44x44px
  - Save/Cancel buttons: 44px height
  - Preference dropdown rows: 44px minimum height
  
- **Input Sizing**: Name input field 16px font minimum to prevent iOS zoom

- **Responsive Layout**:
  - Portrait mobile (320-767px): Single column, preference dropdowns full-width
  - Tablet (768-1023px): Larger spacing, wider dropdowns
  - Desktop (1024px+): More breathing room, dropdowns right-aligned with label on left

- **Keyboard Handling**:
  - Name input triggers iOS keyboard
  - "Done" button on iOS keyboard saves name
  - "Cancel" outside edit area cancels edit

- **Touch Feedback**:
  - Visual feedback on tap (ripple/highlight)
  - Dropdown opens smoothly on tap
  - Loading states visible and obvious

### Technical Implementation

#### Frontend Implementation (Vanilla HTML/CSS/JS)

**HTML Structure (profile.php)**
```html
<!-- Profile page with name editing and preferences -->
<div class="profile-page">
  <!-- Profile Name Section -->
  <div class="profile-name-section">
    <div id="name-display" class="name-display">
      <span id="display-name" class="display-name"><?= htmlspecialchars($user['display_name']) ?></span>
      <button id="edit-name-btn" class="edit-icon" aria-label="Edit name">
        ✏️
      </button>
    </div>
    
    <div id="name-edit" class="name-edit hidden">
      <input 
        type="text" 
        id="name-input" 
        class="name-input"
        value="<?= htmlspecialchars($user['display_name']) ?>"
        maxlength="30"
      />
      <span id="char-count" class="char-count">0/30</span>
      <div class="edit-actions">
        <button id="save-name-btn" class="btn-save" disabled>Save</button>
        <button id="cancel-name-btn" class="btn-cancel">Cancel</button>
      </div>
      <div id="name-error" class="error-message hidden"></div>
    </div>
  </div>

  <!-- Preferences Section -->
  <section class="preferences-section">
    <h2>Preferences</h2>
    <p class="subtitle">Customize your Paddock Picks experience</p>
    
    <div class="preference-row">
      <label for="pref-theme" class="pref-label">Theme</label>
      <select id="pref-theme" class="pref-select" data-pref="theme">
        <option value="light" <?= $user['pref_theme'] === 'light' ? 'selected' : '' ?>>Light</option>
        <option value="dark" <?= $user['pref_theme'] === 'dark' ? 'selected' : '' ?>>Dark</option>
      </select>
      <span class="pref-description">Choose your visual theme</span>
    </div>
    
    <div class="preference-row">
      <label for="pref-font" class="pref-label">Font</label>
      <select id="pref-font" class="pref-select" data-pref="font">
        <option value="sys" <?= $user['pref_font'] === 'sys' ? 'selected' : '' ?>>sys</option>
        <option value="editorial" <?= $user['pref_font'] === 'editorial' ? 'selected' : '' ?>>editorial</option>
      </select>
      <span class="pref-description">Choose your preferred font style</span>
    </div>
    
    <div class="preference-row">
      <label for="pref-language" class="pref-label">Language</label>
      <select id="pref-language" class="pref-select" data-pref="language">
        <option value="en" <?= $user['pref_language'] === 'en' ? 'selected' : '' ?>>English</option>
        <option value="da" <?= $user['pref_language'] === 'da' ? 'selected' : '' ?>>Danish</option>
      </select>
      <span class="pref-description">Choose your preferred language</span>
    </div>
  </section>
</div>
```

**JavaScript Logic (profile.js)**
```javascript
// /js/profile.js

// Profile name editing
document.addEventListener('DOMContentLoaded', function() {
  const nameDisplay = document.getElementById('name-display');
  const nameEdit = document.getElementById('name-edit');
  const displayNameSpan = document.getElementById('display-name');
  const editBtn = document.getElementById('edit-name-btn');
  const nameInput = document.getElementById('name-input');
  const saveBtn = document.getElementById('save-name-btn');
  const cancelBtn = document.getElementById('cancel-name-btn');
  const charCount = document.getElementById('char-count');
  const nameError = document.getElementById('name-error');
  
  let originalName = displayNameSpan.textContent;
  
  // Enter edit mode
  function enterEditMode() {
    nameDisplay.classList.add('hidden');
    nameEdit.classList.remove('hidden');
    nameInput.focus();
    nameInput.select();
    updateCharCount();
  }
  
  // Exit edit mode
  function exitEditMode() {
    nameDisplay.classList.remove('hidden');
    nameEdit.classList.add('hidden');
    nameInput.value = originalName;
    nameError.classList.add('hidden');
  }
  
  // Update character count and save button state
  function updateCharCount() {
    const length = nameInput.value.trim().length;
    charCount.textContent = `${length}/30`;
    saveBtn.disabled = length < 2 || length > 30;
  }
  
  // Save display name
  async function saveName() {
    const newName = nameInput.value.trim();
    
    if (newName.length < 2 || newName.length > 30) return;
    
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';
    nameError.classList.add('hidden');
    
    try {
      const response = await fetch('/api/profile/update-name.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${getAuthToken()}`
        },
        body: JSON.stringify({ display_name: newName })
      });
      
      const data = await response.json();
      
      if (!response.ok) {
        throw new Error(data.error || 'Update failed');
      }
      
      // Success
      displayNameSpan.textContent = newName;
      originalName = newName;
      exitEditMode();
      showToast('Name updated!', 'success');
      
    } catch (error) {
      nameError.textContent = error.message;
      nameError.classList.remove('hidden');
    } finally {
      saveBtn.disabled = false;
      saveBtn.textContent = 'Save';
    }
  }
  
  // Event listeners
  editBtn.addEventListener('click', enterEditMode);
  displayNameSpan.addEventListener('click', enterEditMode);
  cancelBtn.addEventListener('click', exitEditMode);
  saveBtn.addEventListener('click', saveName);
  nameInput.addEventListener('input', updateCharCount);
  
  nameInput.addEventListener('keyup', function(e) {
    if (e.key === 'Enter' && !saveBtn.disabled) {
      saveName();
    } else if (e.key === 'Escape') {
      exitEditMode();
    }
  });
  
  // Preference dropdowns
  const preferenceSelects = document.querySelectorAll('.pref-select');
  
  preferenceSelects.forEach(select => {
    select.addEventListener('change', async function() {
      const preference = this.dataset.pref;
      const value = this.value;
      const previousValue = this.querySelector('option[selected]')?.value || this.value;
      
      try {
        // Apply visual changes immediately for theme/font
        if (preference === 'theme') {
          applyTheme(value);
        } else if (preference === 'font') {
          applyFont(value);
        }
        
        // Save to backend
        const response = await fetch('/api/profile/update-preference.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${getAuthToken()}`
          },
          body: JSON.stringify({ preference, value })
        });
        
        if (!response.ok) {
          throw new Error('Update failed');
        }
        
        // For language change, reload page
        if (preference === 'language') {
          showToast('Language updated. Reloading...', 'success');
          setTimeout(() => window.location.reload(), 1000);
        } else {
          showToast('Preference updated!', 'success');
        }
        
      } catch (error) {
        // Revert on error
        this.value = previousValue;
        if (preference === 'theme') {
          applyTheme(previousValue);
        } else if (preference === 'font') {
          applyFont(previousValue);
        }
        showToast('Unable to save preference', 'error');
      }
    });
  });
  
  // Apply theme CSS class
  function applyTheme(theme) {
    document.documentElement.classList.remove('theme-light', 'theme-dark');
    document.documentElement.classList.add(`theme-${theme}`);
  }
  
  // Apply font family CSS class
  function applyFont(font) {
    document.documentElement.classList.remove('font-sys', 'font-editorial');
    document.documentElement.classList.add(`font-${font}`);
  }
  
  // Helper: Get auth token from cookie or localStorage
  function getAuthToken() {
    return localStorage.getItem('auth_token') || '';
  }
  
  // Helper: Show toast notification
  function showToast(message, type = 'info') {
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    // Remove after 3 seconds
    setTimeout(() => {
      toast.classList.add('fade-out');
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }
});
```

**CSS Styling (profile.css)**
```css
/* Profile name section */
.profile-name-section {
  margin-bottom: 2rem;
}

.name-display {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
}

.display-name {
  font-size: 1.5rem;
  font-weight: bold;
}

.edit-icon {
  background: none;
  border: none;
  cursor: pointer;
  padding: 0.5rem;
  font-size: 1.2rem;
  opacity: 0.6;
  transition: opacity 0.2s;
  min-width: 44px;
  min-height: 44px;
}

.edit-icon:hover {
  opacity: 1;
}

.name-edit {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.name-input {
  font-size: 1.125rem; /* 18px - prevents iOS zoom */
  padding: 0.75rem;
  border: 2px solid #ccc;
  border-radius: 4px;
}

.char-count {
  font-size: 0.875rem;
  color: #666;
}

.edit-actions {
  display: flex;
  gap: 0.5rem;
}

.btn-save, .btn-cancel {
  padding: 0.75rem 1.5rem;
  min-height: 44px;
  border-radius: 4px;
  font-size: 1rem;
  cursor: pointer;
}

.btn-save {
  background: #007bff;
  color: white;
  border: none;
}

.btn-save:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-cancel {
  background: #f8f9fa;
  color: #333;
  border: 1px solid #ccc;
}

/* Preferences section */
.preferences-section {
  margin-top: 2rem;
}

.preference-row {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 1rem 0;
  border-bottom: 1px solid #eee;
  min-height: 44px;
}

.pref-label {
  font-weight: bold;
  font-size: 1rem;
}

.pref-select {
  font-size: 1rem;
  padding: 0.75rem;
  border: 1px solid #ccc;
  border-radius: 4px;
  background: white;
  min-height: 44px;
}

.pref-description {
  font-size: 0.875rem;
  color: #666;
}

/* Responsive */
@media (min-width: 768px) {
  .preference-row {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }
  
  .pref-select {
    max-width: 200px;
  }
}

/* Hidden utility */
.hidden {
  display: none;
}

/* Toast notifications */
.toast {
  position: fixed;
  bottom: 2rem;
  left: 50%;
  transform: translateX(-50%);
  padding: 1rem 2rem;
  border-radius: 4px;
  z-index: 1000;
  animation: slideUp 0.3s ease;
}

.toast-success {
  background: #28a745;
  color: white;
}

.toast-error {
  background: #dc3545;
  color: white;
}

@keyframes slideUp {
  from {
    transform: translate(-50%, 100%);
    opacity: 0;
  }
  to {
    transform: translate(-50%, 0);
    opacity: 1;
  }
}
```

#### Backend API Endpoints (PHP 8)

**Update Display Name**
```php
// /api/profile/update-name.php
<?php
require_once '../auth/jwt-middleware.php';
require_once '../db/connection.php';

// JWT authentication
$user = authenticateJWT();

// Get request body
$data = json_decode(file_get_contents('php://input'), true);
$newName = trim($data['display_name'] ?? '');

// Validation
if (strlen($newName) < 2 || strlen($newName) > 30) {
    http_response_code(400);
    echo json_encode(['error' => 'Name must be between 2 and 30 characters']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9\s\-_]+$/', $newName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Name can only contain letters, numbers, spaces, hyphens, and underscores']);
    exit;
}

// Check uniqueness (case-insensitive)
$stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(display_name) = LOWER(:name) AND id != :user_id');
$stmt->execute(['name' => $newName, 'user_id' => $user['id']]);
if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['error' => 'Name already taken by another user']);
    exit;
}

// Update name
$stmt = $pdo->prepare('UPDATE users SET display_name = :name WHERE id = :user_id');
$stmt->execute(['name' => $newName, 'user_id' => $user['id']]);

http_response_code(200);
echo json_encode(['success' => true, 'display_name' => $newName]);
```

**Update Preference**
```php
// /api/profile/update-preference.php
<?php
require_once '../auth/jwt-middleware.php';
require_once '../db/connection.php';

$user = authenticateJWT();
$data = json_decode(file_get_contents('php://input'), true);

$preference = $data['preference'] ?? '';
$value = $data['value'] ?? '';

// Whitelist allowed preferences with validation
$allowedPrefs = [
    'theme' => [
        'column' => 'pref_theme',
        'allowed_values' => ['light', 'dark']
    ],
    'font' => [
        'column' => 'pref_font',
        'allowed_values' => ['sys', 'editorial']
    ],
    'language' => [
        'column' => 'pref_language',
        'allowed_values' => ['en', 'da']
    ]
];

if (!isset($allowedPrefs[$preference])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid preference']);
    exit;
}

$prefConfig = $allowedPrefs[$preference];

// Validate value is in allowed list
if (!in_array($value, $prefConfig['allowed_values'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid preference value']);
    exit;
}

$dbColumn = $prefConfig['column'];

$stmt = $pdo->prepare("UPDATE users SET $dbColumn = :value WHERE id = :user_id");
$stmt->execute(['value' => $value, 'user_id' => $user['id']]);

http_response_code(200);
echo json_encode(['success' => true]);
```
```

#### Database Schema Updates

```sql
-- Add preference columns to users table
ALTER TABLE users 
ADD COLUMN pref_theme VARCHAR(20) DEFAULT 'light',
ADD COLUMN pref_font VARCHAR(20) DEFAULT 'sys',
ADD COLUMN pref_language VARCHAR(10) DEFAULT 'en';

-- Create index for display name uniqueness check
CREATE INDEX idx_users_display_name_lower ON users ((LOWER(display_name)));
```

## Test Scenarios

Feature: Improved Profile Page with Intuitive Name and Preferences Management
  
  Scenario: User views profile page and sees current name and preferences
    Given I am logged into Paddock Picks as "Alice"
    And my theme preference is set to "dark"
    And my font size preference is set to "medium"
    And my language preference is set to "en"
    When I navigate to my profile page
    Then I should see my display name "Alice" with an edit icon
    And I should see a "Preferences" section
    And the "Theme" dropdown should show "Dark" selected
    And the "Font Size" dropdown should show "Medium" selected
    And the "Language" dropdown should show "English" selected
  
  Scenario: User successfully edits display name
    Given I am on my profile page
    And my current display name is "Bob"
    When I click on my display name or edit icon
    Then the name field should become editable with "Bob" selected
    And I should see Save and Cancel buttons
    When I type "Bobby Racing"
    And I click the Save button
    Then I should see a loading spinner on the Save button
    And after the API responds successfully
    Then my display name should update to "Bobby Racing"
    And I should see a success message "Name updated!"
    And the edit mode should close
  
  Scenario: User changes theme preference
    Given I am on my profile page
    And my theme is currently set to "Light"
    When I click the "Theme" dropdown
    And select "Dark"
    Then the theme should immediately apply to the profile page
    And I should see dark background colors
    And the preference should save to the backend
    And the dark theme should persist on page reload
  
  Scenario: User changes font size preference
    Given I am on my profile page
    And my font size is currently "Medium"
    When I select "Large" from the "Font Size" dropdown
    Then the text on the page should immediately increase in size
    And the preference should save to the backend
    And the large font size should persist on page reload

## Test Cases

Feature: Improved Profile Page with Intuitive Name and Preferences Management
  
  Scenario: Display name validation - minimum length
    Given I am editing my display name
    And I enter "A" (1 character)
    When I try to save
    Then the Save button should be disabled
    And I should see character count "1/30"
    And I should see validation hint "Must be 2-30 characters"
  
  Scenario: Display name validation - maximum length
    Given I am editing my display name
    And I enter a name with 31 characters
    When I try to save
    Then the Save button should be disabled
    And I should see character count "31/30" in red
    And the input field should have a red border
  
  Scenario: Display name uniqueness validation
    Given another user "Charlie" already exists in the system
    And I am editing my display name
    When I enter "Charlie" and click Save
    Then the API should return 409 Conflict
    And I should see error message "Name already taken by another user"
    And the edit mode should stay open with "Charlie" in the field
    And I can try a different name
  
  Scenario: Display name case-insensitive uniqueness
    Given another user has display name "Alice"
    When I try to save my name as "alice" (lowercase)
    Then the system should detect the conflict
    And show "Name already taken by another user"
    Because case-insensitive check prevents "Alice" and "alice"
  
  Scenario: Display name with special characters validation
    Given I am editing my display name
    When I enter "Bob@Racing!" (contains @ and !)
    And click Save
    Then the API should return 400 Bad Request
    And show error "Name can only contain letters, numbers, spaces, hyphens, and underscores"
  
  Scenario: Display name edit cancellation
    Given I am editing my display name
    And my current name is "David"
    When I type "DavidTheGreat"
    And I click the Cancel button
    Then the name field should revert to "David"
    And edit mode should close
    And no API call should be made
  
  Scenario: Successful theme preference change with immediate visual update
    Given I am on my profile page
    And my theme is currently "Light"
    When I click the "Theme" dropdown
    And select "Dark"
    Then the profile page should immediately apply dark theme styling
    And a POST request should go to /api/profile/update-preference.php
    With payload: {"preference": "theme", "value": "dark"}
    When the API responds 200 OK
    Then the theme preference should persist
    And the dropdown should show "Dark" as selected
  
  Scenario: Preference selection failure with revert
    Given my font size is currently "Medium"
    When I select "Large" from the dropdown
    Then the text should immediately increase in size (optimistic update)
    When the API returns 500 Server Error
    Then the font size should revert back to "Medium"
    And I should see error toast "Unable to save preference"
    And the preference should remain "Medium" in the database
  
  Scenario: Mobile touch target validation for dropdowns
    Given I am on my profile page on iPhone (375px width)
    When I inspect the edit icon touch target
    Then it should be at least 44x44 pixels
    When I inspect the preference dropdown rows
    Then each dropdown row should be at least 44px in height
    And the entire preference row should be tappable
  
  Scenario: Mobile name input zoom prevention
    Given I am on my profile page on iPhone
    When I tap the display name to edit
    And the name input field becomes active
    Then the input field font size should be 16px or larger
    And iOS should NOT zoom into the input field
    And the keyboard should appear without layout shift
  
  Scenario: Theme preference applies system default correctly
    Given my theme preference is set to "System"
    And my device is configured to use dark mode
    When I load my profile page
    Then the page should display in dark theme
    And the "Theme" dropdown should show "System" selected
    
    When my device changes to light mode
    And I reload the page
    Then the page should display in light theme
  
  Scenario: Font size preference affects all text on profile page
    Given my font size is set to "Medium"
    When I select "Large" from the "Font Size" dropdown
    Then all body text should increase to 18px base font
    And headings should scale proportionally
    And form inputs should maintain minimum 16px to prevent zoom
    And the preference should save successfully
    
    When I refresh the page
    Then the large font size should still be applied
  
  Scenario: Language preference triggers page reload
    Given my language is set to "English"
    When I select "Nederlands" from the "Language" dropdown
    Then the preference should save to the backend
    And I should see a toast "Language updated. Reloading..."
    And the page should reload after 1 second
    And all interface labels should display in Dutch
    And the "Language" dropdown should show "Nederlands" selected
  
  Scenario: Rapid name edits handle race conditions
    Given I am editing my display name
    When I type "Fast" and immediately click Save
    And before the first save completes, I click Edit again
    And type "Faster" and click Save again
    Then the system should queue both requests
    And the second request should wait for the first to complete
    And the final saved name should be "Faster"
    And the UI should show loading state until complete
  
  Scenario: Profile page responsive layout on tablet
    Given I am viewing the profile page on iPad (768px width)
    Then the display name should have more breathing room
    And the Save/Cancel buttons should show full text labels (not just icons)
    And preference dropdowns should have wider spacing
    And all elements should remain touch-friendly (44px targets)
  
  Scenario: Offline detection before save attempts
    Given I am on the profile page
    And my device loses internet connection
    When I try to edit my display name and click Save
    Then the system should detect offline state
    And show message "You appear to be offline"
    And prevent the API call attempt
    And keep the edit mode open for retry when online
  
  Scenario: Accessibility - keyboard navigation for name editing
    Given I am on the profile page using keyboard only
    When I tab to the display name area
    And press Enter to activate edit mode
    Then the name input should receive focus
    And I can type a new name
    When I tab to the Save button
    And press Enter
    Then the save should trigger
    
  Scenario: Accessibility - screen reader announcements for dropdowns
    Given I am using VoiceOver on iOS
    When I focus on the "Theme" dropdown
    Then VoiceOver should announce "Theme, popup button, Dark"
    And announce the description "Choose your visual theme"
    When I activate the dropdown and select "Light"
    Then VoiceOver should announce "Light, selected"
    And the dropdown should update to show "Light"