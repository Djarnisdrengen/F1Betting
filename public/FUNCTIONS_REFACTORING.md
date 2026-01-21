# Functions Refactoring Summary

## Overview
Utility functions have been extracted from `config.php` to a separate `functions.php` file for better organization and maintainability.

## What Was Moved to `public/functions.php`

### Input Validation Functions
- `sanitizeString($str)` - Sanitize string input with HTML escaping
- `sanitizeEmail($email)` - Validate and sanitize email addresses
- `sanitizeInt($value, $min, $max)` - Validate and sanitize integer input

### Helper Functions
- `escape($str)` - HTML escape output
- `generateUUID()` - Generate UUIDs for database records

### Language/Internationalization Functions
- `getLang()` - Get current language setting from session
- `setLang($lang)` - Set language preference in session
- `t($key)` - Translate strings based on language (Danish/English)

### Theme Functions
- `getTheme()` - Get current theme preference from session
- `setTheme($theme)` - Set theme preference in session (dark/light)

### Betting & Settings Functions
- `getBettingStatus($race, $settings)` - Determine betting window status for a race
- `getSettings()` - Fetch application settings from database

## What Remained in `config.php` (Security-Sensitive)

### Configuration & Credentials
- Database connection settings
- JWT_SECRET and PASSWORD_PEPPER
- SMTP email configuration
- Cron job secrets
- API configuration

### Security Functions
- Session configuration
- CSRF protection functions
- Password hashing/verification
- User authentication functions

## Usage

The utility functions are now automatically available in all files that include `config.php`, since `config.php` includes `functions.php`:

```php
<?php
require_once __DIR__ . '/../config.php';  // This loads both files

// Now you can use functions from functions.php
echo t('home');  // Translate strings
$sanitized = sanitizeString($userInput);
```

## Files Modified
- **config.php** - Removed utility functions, added include for functions.php
- **public/functions.php** - NEW file containing all utility functions
