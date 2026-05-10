# Translations Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract all translatable strings from functions.php and inline PHP ternaries across all template files into three separate lang files under `public/lang/`.

**Architecture:** Create `public/lang/user.php`, `public/lang/admin.php`, and `public/lang/email.php` — each returning a `['da' => [...], 'en' => [...]]` array. Update `t($key, $lang = null)` in `functions.php` to load and merge all three files once (static cache). All inline `$lang === 'da' ? 'da string' : 'en string'` ternaries become `t('key')` calls (or `sprintf(t('key'), $var)` when interpolation is needed).

**Tech Stack:** PHP 8+, no new dependencies. No test framework changes needed — verify by toggling language on all affected pages and checking for PHP errors.

---

## File Map

| Action | File |
|--------|------|
| Create | `public/lang/user.php` |
| Create | `public/lang/admin.php` |
| Create | `public/lang/email.php` |
| Modify | `public/includes/functions.php` — replace t() |
| Modify | `public/includes/header.php` |
| Modify | `public/includes/footer.php` |
| Modify | `public/index.php` |
| Modify | `public/races.php` |
| Modify | `public/bet.php` |
| Modify | `public/edit_bet.php` |
| Modify | `public/login.php` |
| Modify | `public/register.php` |
| Modify | `public/profile.php` |
| Modify | `public/forgot_password.php` |
| Modify | `public/reset_password.php` |
| Modify | `public/rules.php` |
| Modify | `public/admin.php` |
| Modify | `public/includes/admin/races.php` |
| Modify | `public/includes/admin/drivers.php` |
| Modify | `public/includes/admin/users.php` |
| Modify | `public/includes/admin/bets.php` |
| Modify | `public/includes/admin/invites.php` |
| Modify | `public/includes/admin/settings.php` |
| Modify | `public/includes/smtp.php` |

---

## Task 1: Create `public/lang/user.php`

**Files:**
- Create: `public/lang/user.php`

- [ ] **Step 1: Create the file**

```php
<?php
return [
    'da' => [
        // Navigation
        'home'                  => 'Hjem',
        'races'                 => 'Løb',
        'leaderboard'           => 'Rangliste',
        'admin'                 => 'Admin',
        'profile'               => 'Profil',
        'login'                 => 'Log ind',
        'register'              => 'Registrer',
        'logout'                => 'Log ud',
        'rules'                 => 'Regler',
        'toggle_theme'          => 'Skift tema',
        'lang_switch_label'     => 'English',
        'change_language'       => 'Skift sprog',

        // Footer
        'contact'               => 'Kontakt:',

        // Actions
        'place_bet'             => 'Placer Bet',
        'submit'                => 'Indsend',
        'save'                  => 'Gem',
        'delete'                => 'Slet',
        'edit'                  => 'Rediger',
        'add'                   => 'Tilføj',
        'cancel'                => 'Annuller',

        // Labels
        'upcoming_races'        => 'Kommende Løb',
        'your_bets'             => 'Dine Bets',
        'all_bets'              => 'Alle Bets',
        'points'                => 'Point',
        'stars'                 => 'Stjerner',
        'rank'                  => 'Rang',
        'user'                  => 'Bruger',
        'placed_at'             => 'Placeret',
        'display_name'          => 'Visningsnavn',
        'email'                 => 'E-mail',
        'password'              => 'Adgangskode',
        'team'                  => 'Hold',
        'number'                => 'Nummer',
        'name'                  => 'Navn',
        'location'              => 'Sted',
        'race_date'             => 'Løbsdato',
        'race_time'             => 'Starttid',
        'qualifying'            => 'Kvalifikation',
        'result'                => 'Resultat',
        'results'               => 'Resultater',
        'select_driver'         => 'Vælg kører',
        'drivers'               => 'Kørere',
        'users'                 => 'Brugere',
        'bets'                  => 'Bets',
        'settings'              => 'Indstillinger',
        'role'                  => 'Rolle',
        'in_competition'        => 'I konkurrence',
        'yes'                   => 'Ja',
        'no'                    => 'Nej',

        // Betting status
        'betting_open'          => 'Betting Åben',
        'betting_closed'        => 'Betting Lukket',
        'betting_not_open'      => 'Betting Ikke Åben',
        'race_completed'        => 'Løb Afsluttet',
        'betting_window'        => 'Betting åbner 48t før løb',
        'betting_opens_in'      => 'Betting åbner om',
        'betting_closes_in'     => 'Betting lukker om',
        'pool_size'             => 'Puljestørrelse:',
        'you_badge'             => 'DIG',
        'countdown_now'         => 'Nu!',
        'bet_placed_label'      => 'Bet placeret',
        'pool_won'              => 'Puljen vundet',

        // Index/races
        'no_upcoming_races'     => 'Ingen kommende løb',
        'points_system'         => 'Point: P1=25, P2=18, P3=15, +5 for top 3 forkert position',
        'no_bets'               => 'Ingen bets endnu',

        // Bet messages
        'perfect_bet'           => 'Perfekt!',
        'already_bet'           => 'Du har allerede et bet på dette løb',
        'already_bet_long'      => 'Du har allerede placed et bet på dette løb.',
        'not_in_competition'    => 'Du er ikke medlem af konkurrencen. Kontakt administrator.',
        'bet_placed'            => 'Bet placeret!',
        'bet_updated'           => 'Bet opdateret!',

        // Edit bet
        'edit_bet_title'        => 'Rediger Bet',
        'timestamp_update_info' => 'Timestamp vil blive opdateret når du gemmer ændringer.',

        // Validation errors
        'error'                 => 'Der opstod en fejl',
        'invalid_credentials'   => 'Forkert email eller adgangskode',
        'email_exists'          => 'Email er allerede registreret',
        'forgot_password'       => 'Glemt adgangskode?',
        'password_min_length'   => 'Adgangskode skal være mindst 6 tegn',
        'passwords_min_6'       => 'Adgangskoden skal være mindst 6 tegn',
        'passwords_no_match'    => 'Adgangskoderne matcher ikke',
        'enter_valid_email'     => 'Indtast en gyldig email',

        // Registration
        'registration_success'  => 'Registrering gennemført!',
        'you_are_invited'       => 'Du er inviteret!',
        'email_set_by_invite'   => 'Email er sat af invitation',
        'already_have_account'  => 'Har du allerede en konto?',
        'invalid_invite'        => 'Ugyldigt eller udløbet invitation. Kontakt administrator for en ny invitation.',
        'invite_required'       => 'Du skal have en invitation for at registrere dig. Kontakt administrator.',
        'email_must_match_invite' => 'Email skal matche invitationens email: %s',

        // Profile
        'profile_updated'       => 'Profil opdateret!',

        // Forgot / reset password
        'forgot_password_title' => 'Glemt adgangskode',
        'forgot_password_desc'  => 'Indtast din email for at modtage et nulstillingslink',
        'send_reset_link'       => 'Send nulstillingslink',
        'back_to_login'         => 'Tilbage til login',
        'reset_link_sent'       => 'En email med nulstillingslink er sendt til din email.',
        'reset_link_failed'     => 'Email kunne ikke sendes. Kontakt administrator.',
        'reset_link_check_email' => 'Hvis emailen findes i systemet, vil du modtage et nulstillingslink.',
        'reset_password_title'  => 'Nulstil adgangskode',
        'go_to_login'           => 'Gå til login',
        'new_password'          => 'Ny adgangskode',
        'confirm_password'      => 'Bekræft adgangskode',
        'reset_password_btn'    => 'Nulstil adgangskode',
        'link_invalid_expired'  => 'Linket er ugyldigt eller udløbet.',
        'request_new_link'      => 'Anmod om nyt link',
        'token_invalid_expired' => 'Ugyldigt eller udløbet link. Anmod om et nyt.',
        'password_reset_done'   => 'Din adgangskode er blevet nulstillet. Du kan nu logge ind.',

        // Rules page
        'rules_title'           => 'Spilleregler',
        'rules_betting_window'  => 'Betting Vindue',
        'opens'                 => 'Åbner',
        'betting_opens_hours'   => '%d timer før løbets starttid',
        'closes'                => 'Lukker',
        'at_race_start'         => 'Ved løbets starttid',
        'edit_label'            => 'Rediger',
        'bets_editable'         => 'Bets kan redigeres så længe vinduet er åbent',
        'rules_points_system'   => 'Point System',
        'position'              => 'Position',
        'correct_prediction'    => 'Korrekt Forudsigelse',
        'points_label'          => 'point',
        'wrong_pos_rule'        => 'point hvis kører er i top 3, men forkert position',
        'rules_stars'           => 'Stjerner',
        'perfect_bet_stars_desc' => 'Hvis alle 3 positioner er korrekte, modtager du',
        'star'                  => 'stjerne',
        'rules_pool'            => 'Puljen',
        'pool_win_desc'         => 'Når du får en stjerne vinder du også puljen',
        'rules_restrictions'    => 'Restriktioner',
        'one_bet_per_race'      => 'Én bet per løb',
        'one_bet_per_race_desc' => 'Hver bruger kan kun have ét bet per løb',
        'no_duplicates'         => 'Ingen duplikater',
        'no_duplicates_desc'    => 'Samme kører kan ikke vælges flere gange i ét bet',
        'unique_combo'          => 'Unik kombination',
        'unique_combo_desc'     => 'To brugere kan ikke have identisk P1/P2/P3 kombination',
        'quali_restriction'     => 'Kvalifikationsresultat',
        'quali_restriction_desc' => 'Bet kan ikke matche kvalifikationsresultatet 100%. Uanset om systemet har fået blokeret for det eller ej er bettet ugyldigt.',
        'betting_pool_label'    => 'Betting pool',
    ],
    'en' => [
        // Navigation
        'home'                  => 'Home',
        'races'                 => 'Races',
        'leaderboard'           => 'Leaderboard',
        'admin'                 => 'Admin',
        'profile'               => 'Profile',
        'login'                 => 'Login',
        'register'              => 'Register',
        'logout'                => 'Logout',
        'rules'                 => 'Rules',
        'toggle_theme'          => 'Toggle theme',
        'lang_switch_label'     => 'Dansk',
        'change_language'       => 'Change language',

        // Footer
        'contact'               => 'Contact:',

        // Actions
        'place_bet'             => 'Place Bet',
        'submit'                => 'Submit',
        'save'                  => 'Save',
        'delete'                => 'Delete',
        'edit'                  => 'Edit',
        'add'                   => 'Add',
        'cancel'                => 'Cancel',

        // Labels
        'upcoming_races'        => 'Upcoming Races',
        'your_bets'             => 'Your Bets',
        'all_bets'              => 'All Bets',
        'points'                => 'Points',
        'stars'                 => 'Stars',
        'rank'                  => 'Rank',
        'user'                  => 'User',
        'placed_at'             => 'Placed At',
        'display_name'          => 'Display Name',
        'email'                 => 'Email',
        'password'              => 'Password',
        'team'                  => 'Team',
        'number'                => 'Number',
        'name'                  => 'Name',
        'location'              => 'Location',
        'race_date'             => 'Race Date',
        'race_time'             => 'Race Time',
        'qualifying'            => 'Qualifying',
        'result'                => 'Result',
        'results'               => 'Results',
        'select_driver'         => 'Select driver',
        'drivers'               => 'Drivers',
        'users'                 => 'Users',
        'bets'                  => 'Bets',
        'settings'              => 'Settings',
        'role'                  => 'Role',
        'in_competition'        => 'In competition',
        'yes'                   => 'Yes',
        'no'                    => 'No',

        // Betting status
        'betting_open'          => 'Betting Open',
        'betting_closed'        => 'Betting Closed',
        'betting_not_open'      => 'Betting Not Open',
        'race_completed'        => 'Race Completed',
        'betting_window'        => 'Betting opens 48h before race',
        'betting_opens_in'      => 'Betting opens in',
        'betting_closes_in'     => 'Betting closes in',
        'pool_size'             => 'Pool size:',
        'you_badge'             => 'YOU',
        'countdown_now'         => 'Now!',
        'bet_placed_label'      => 'Bet placed',
        'pool_won'              => 'Bettingpool won',

        // Index/races
        'no_upcoming_races'     => 'No upcoming races',
        'points_system'         => 'Points: P1=25, P2=18, P3=15, +5 for top 3 wrong position',
        'no_bets'               => 'No bets yet',

        // Bet messages
        'perfect_bet'           => 'Perfect!',
        'already_bet'           => 'You already have a bet for this race',
        'already_bet_long'      => 'You have already placed a bet on this race.',
        'not_in_competition'    => 'You are not a member of the competition. Contact administrator.',
        'bet_placed'            => 'Bet placed!',
        'bet_updated'           => 'Bet updated!',

        // Edit bet
        'edit_bet_title'        => 'Edit Bet',
        'timestamp_update_info' => 'Timestamp will be updated when you save changes.',

        // Validation errors
        'error'                 => 'An error occurred',
        'invalid_credentials'   => 'Invalid email or password',
        'email_exists'          => 'Email already registered',
        'forgot_password'       => 'Forgot password?',
        'password_min_length'   => 'Password must be at least 6 characters',
        'passwords_min_6'       => 'Password must be at least 6 characters',
        'passwords_no_match'    => 'Passwords do not match',
        'enter_valid_email'     => 'Enter a valid email',

        // Registration
        'registration_success'  => 'Registration successful!',
        'you_are_invited'       => 'You are invited!',
        'email_set_by_invite'   => 'Email is set by invitation',
        'already_have_account'  => 'Already have an account?',
        'invalid_invite'        => 'Invalid or expired invite. Contact administrator for a new invitation.',
        'invite_required'       => 'You need an invitation to register. Contact administrator.',
        'email_must_match_invite' => 'Email must match the invitation email: %s',

        // Profile
        'profile_updated'       => 'Profile updated!',

        // Forgot / reset password
        'forgot_password_title' => 'Forgot Password',
        'forgot_password_desc'  => 'Enter your email to receive a reset link',
        'send_reset_link'       => 'Send reset link',
        'back_to_login'         => 'Back to login',
        'reset_link_sent'       => 'An email with reset link has been sent to your email.',
        'reset_link_failed'     => 'Email could not be sent. Contact administrator.',
        'reset_link_check_email' => 'If the email exists in our system, you will receive a reset link.',
        'reset_password_title'  => 'Reset Password',
        'go_to_login'           => 'Go to login',
        'new_password'          => 'New password',
        'confirm_password'      => 'Confirm password',
        'reset_password_btn'    => 'Reset password',
        'link_invalid_expired'  => 'The link is invalid or expired.',
        'request_new_link'      => 'Request new link',
        'token_invalid_expired' => 'Invalid or expired link. Request a new one.',
        'password_reset_done'   => 'Your password has been reset. You can now log in.',

        // Rules page
        'rules_title'           => 'Betting Rules',
        'rules_betting_window'  => 'Betting Window',
        'opens'                 => 'Opens',
        'betting_opens_hours'   => '%d hours before race start time',
        'closes'                => 'Closes',
        'at_race_start'         => 'At race start time',
        'edit_label'            => 'Edit',
        'bets_editable'         => 'Bets can be edited while window is open',
        'rules_points_system'   => 'Points System',
        'position'              => 'Position',
        'correct_prediction'    => 'Correct Prediction',
        'points_label'          => 'points',
        'wrong_pos_rule'        => 'points if driver is in top 3 but wrong position',
        'rules_stars'           => 'Stars',
        'perfect_bet_stars_desc' => 'If all 3 positions are correct, you receive',
        'star'                  => 'star',
        'rules_pool'            => 'Betting pool',
        'pool_win_desc'         => 'If you get a star you also win the betting pool',
        'rules_restrictions'    => 'Restrictions',
        'one_bet_per_race'      => 'One bet per race',
        'one_bet_per_race_desc' => 'Each user can only have one bet per race',
        'no_duplicates'         => 'No duplicates',
        'no_duplicates_desc'    => 'Same driver cannot be selected multiple times in one bet',
        'unique_combo'          => 'Unique combination',
        'unique_combo_desc'     => 'Two users cannot have identical P1/P2/P3 combination',
        'quali_restriction'     => 'Qualifying result',
        'quali_restriction_desc' => 'Bet cannot match qualifying result 100%. Even if the system did not block for it.',
        'betting_pool_label'    => 'Betting pool',
    ],
];
```

- [ ] **Step 2: Verify file syntax**

```bash
php -l public/lang/user.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add public/lang/user.php
git commit -m "feat: add public/lang/user.php with all user-facing translations"
```

---

## Task 2: Create `public/lang/admin.php`

**Files:**
- Create: `public/lang/admin.php`

- [ ] **Step 1: Create the file**

```php
<?php
return [
    'da' => [
        // Tab labels
        'invites'                   => 'Invitationer',
        'add_race'                  => 'Tilføj Løb',
        'add_driver'                => 'Tilføj Kører',
        'reset_result'              => 'Nulstil Resultat',

        // Driver action messages
        'driver_added'              => 'Kører tilføjet!',
        'driver_updated'            => 'Kører opdateret!',
        'driver_fields_error'       => 'Udfyld alle felter korrekt (nummer skal være 1–99)',

        // Race action messages
        'race_added'                => 'Løb tilføjet!',
        'race_updated'              => 'Løb opdateret!',
        'invalid_date_time'         => 'Ugyldig dato eller tid',
        'fill_all_fields'           => 'Udfyld alle felter',
        'name_required'             => 'Navn er påkrævet',
        'result_reset'              => 'Resultat nulstillet! Indtast det korrekte resultat nedenfor.',
        'reset_most_recent_only'    => 'Kan kun nulstille det seneste løb med resultat',

        // User management
        'user_added_to_competition'     => 'Bruger tilføjet til konkurrence',
        'user_removed_from_competition' => 'Bruger fjernet fra konkurrence',
        'password_reset_success'        => 'Adgangskode nulstillet!',
        'password_min_6_admin'          => 'Adgangskoden skal være mindst 6 tegn',
        'in_competition_label'          => 'I Konkurrence',
        'not_in_competition_label'      => 'Ikke I Konkurrence',
        'make_user'                     => 'Gør Bruger',
        'make_admin'                    => 'Gør Admin',
        'new_password'                  => 'Ny adgangskode',
        'reset'                         => 'Nulstil',
        'last_seen'                     => 'Sidst set: ',
        'never'                         => 'Aldrig',

        // Bet management
        'bet_deleted_notified'      => 'Bet slettet og bruger notificeret!',
        'bet_delete_open_only'      => 'Kan kun slette bets hvor betting vindue er åbent',
        'betting_open_label'        => 'Betting åben',
        'delete_notify_user'        => 'Slet og notificer bruger',

        // Invite management
        'email_already_user'        => 'Email er allerede registreret som bruger',
        'active_invite_exists'      => 'Der er allerede en aktiv invitation til denne email',
        'invite_sent_to'            => 'Invitation sendt til %s',
        'invalid_email'             => 'Ugyldig email',
        'invite_resent'             => 'Invitation gensendt!',
        'invite_new_user'           => 'Inviter ny bruger',
        'send_invite'               => 'Send invitation',
        'invite_expires_desc'       => 'Invitationen udløber efter 7 dage. Brugeren modtager en email med et registreringslink.',
        'pending_invites'           => 'Afventende invitationer',
        'invited_by'                => 'Inviteret af',
        'expires'                   => 'Udløber',
        'resend'                    => 'Gensend',
        'copy_link'                 => 'Kopiér link',
        'used_invites'              => 'Brugte invitationer',
        'registered_badge'          => 'Registreret',
        'invited_label'             => 'Inviteret',
        'expired_invites'           => 'Udløbne invitationer',
        'expired_badge'             => 'Udløbet',
        'expired_label'             => 'Udløb',
        'renew'                     => 'Forny',
        'no_invites'                => 'Ingen invitationer endnu',
        'link_copied'               => 'Link kopieret!',

        // Settings
        'settings_saved'            => 'Indstillinger gemt!',
        'year'                      => 'År',
        'betting_window_section'    => 'Betting Vindue',
        'betting_window_config'     => 'Konfigurer hvornår betting åbner før løbsstart.',
        'hours_before_race'         => 'Timer før løb',
        'betting_window_summary'    => 'Betting åbner %d timer før løbsstart og lukker ved løbsstart.',
        'points_system_section'     => 'Point System',
        'points_config'             => 'Konfigurer hvor mange point der gives for korrekte forudsigelser.',
        'points_label'              => 'Point',
        'wrong_position'            => 'Forkert position',
        'wrong_pos_desc'            => '"Forkert position" point gives når en kører er i top 3, men på forkert position.',
        'bet_size_section'          => 'Betting Størrelse',
        'bet_size_desc'             => 'Standardstørrelse for hver indsats.',
        'bet_size_label'            => 'Indsatsstørrelse',
    ],
    'en' => [
        // Tab labels
        'invites'                   => 'Invites',
        'add_race'                  => 'Add Race',
        'add_driver'                => 'Add Driver',
        'reset_result'              => 'Reset Result',

        // Driver action messages
        'driver_added'              => 'Driver added!',
        'driver_updated'            => 'Driver updated!',
        'driver_fields_error'       => 'Fill in all fields correctly (number must be 1–99)',

        // Race action messages
        'race_added'                => 'Race added!',
        'race_updated'              => 'Race updated!',
        'invalid_date_time'         => 'Invalid date or time',
        'fill_all_fields'           => 'Fill in all fields',
        'name_required'             => 'Name is required',
        'result_reset'              => 'Result reset! Enter the correct result below.',
        'reset_most_recent_only'    => 'Can only reset the most recently completed race',

        // User management
        'user_added_to_competition'     => 'User added to competition',
        'user_removed_from_competition' => 'User removed from competition',
        'password_reset_success'        => 'Password reset!',
        'password_min_6_admin'          => 'Password must be at least 6 characters',
        'in_competition_label'          => 'In Competition',
        'not_in_competition_label'      => 'Not In Competition',
        'make_user'                     => 'Make User',
        'make_admin'                    => 'Make Admin',
        'new_password'                  => 'New password',
        'reset'                         => 'Reset',
        'last_seen'                     => 'Last seen: ',
        'never'                         => 'Never',

        // Bet management
        'bet_deleted_notified'      => 'Bet deleted and user notified!',
        'bet_delete_open_only'      => 'Can only delete bets where betting window is open',
        'betting_open_label'        => 'Betting open',
        'delete_notify_user'        => 'Delete and notify user',

        // Invite management
        'email_already_user'        => 'Email is already registered as user',
        'active_invite_exists'      => 'There is already an active invite for this email',
        'invite_sent_to'            => 'Invitation sent to %s',
        'invalid_email'             => 'Invalid email',
        'invite_resent'             => 'Invitation resent!',
        'invite_new_user'           => 'Invite new user',
        'send_invite'               => 'Send invite',
        'invite_expires_desc'       => 'Invite expires after 7 days. User will receive an email with a registration link.',
        'pending_invites'           => 'Pending invites',
        'invited_by'                => 'Invited by',
        'expires'                   => 'Expires',
        'resend'                    => 'Resend',
        'copy_link'                 => 'Copy link',
        'used_invites'              => 'Used invites',
        'registered_badge'          => 'Registered',
        'invited_label'             => 'Invited',
        'expired_invites'           => 'Expired invites',
        'expired_badge'             => 'Expired',
        'expired_label'             => 'Expired',
        'renew'                     => 'Renew',
        'no_invites'                => 'No invites yet',
        'link_copied'               => 'Link copied!',

        // Settings
        'settings_saved'            => 'Settings saved!',
        'year'                      => 'Year',
        'betting_window_section'    => 'Betting Window',
        'betting_window_config'     => 'Configure when betting opens before race start.',
        'hours_before_race'         => 'Hours before race',
        'betting_window_summary'    => 'Betting opens %d hours before race start and closes at race start.',
        'points_system_section'     => 'Points System',
        'points_config'             => 'Configure how many points are awarded for correct predictions.',
        'points_label'              => 'Points',
        'wrong_position'            => 'Wrong position',
        'wrong_pos_desc'            => '"Wrong position" points are awarded when a driver is in top 3 but in wrong position.',
        'bet_size_section'          => 'Bet Size',
        'bet_size_desc'             => 'Default size for each bet.',
        'bet_size_label'            => 'Bet Size',
    ],
];
```

- [ ] **Step 2: Verify file syntax**

```bash
php -l public/lang/admin.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add public/lang/admin.php
git commit -m "feat: add public/lang/admin.php with all admin translations"
```

---

## Task 3: Create `public/lang/email.php`

**Files:**
- Create: `public/lang/email.php`

Note: Email functions receive `$lang` as a parameter, so they call `t($key, $lang)` explicitly (the updated t() signature supports this).

- [ ] **Step 1: Create the file**

```php
<?php
return [
    'da' => [
        // Password reset email (self-service via forgot_password.php)
        'email_reset_subject'       => 'Nulstil din adgangskode - %s',
        'email_reset_greeting'      => 'Hej %s,',
        'email_reset_intro'         => 'Du har anmodet om at nulstille din adgangskode til %s.',
        'email_reset_button'        => 'Nulstil adgangskode',
        'email_reset_expiry'        => 'Dette link udløber om 1 time.',
        'email_reset_ignore'        => 'Hvis du ikke har anmodet om dette, kan du ignorere denne email.',
        'email_footer'              => 'Med venlig hilsen,<br>%s',

        // Invite email
        'email_invite_subject'      => 'Du er inviteret til %s!',
        'email_invite_greeting'     => 'Hej!',
        'email_invite_intro'        => '%s har inviteret dig til at deltage i %s.',
        'email_invite_desc'         => 'Forudsig top 3 for hvert F1 Grand Prix og konkurrér mod andre om point og stjerner!',
        'email_invite_button'       => 'Opret din konto',
        'email_invite_expiry'       => 'Denne invitation udløber om 7 dage.',

        // Admin password reset email (admin resets another user's password)
        'email_admin_reset_subject' => 'Dit kodeord er nulstillet! - %s',
        'email_admin_reset_greeting' => 'Hej %s,',
        'email_admin_reset_intro'   => 'Dit kodeord er blevet nulstillet af %s. Den nye kode er: \'%s\'',
        'email_admin_reset_button'  => 'Gå til appen',
        'email_admin_contact'       => 'Kontakt administrator hvis du har spørgsmål.',

        // Bet deleted notification email
        'email_bet_deleted_subject' => 'Dit bet er blevet slettet - %s',
        'email_bet_deleted_greeting' => 'Hej %s,',
        'email_bet_deleted_intro'   => 'Dit bet på <strong>%s</strong> er blevet slettet af en administrator.',
        'email_go_to_app'           => 'Gå til appen',
        'email_contact_admin'       => 'Kontakt administrator hvis du har spørgsmål.',
        'email_regards'             => 'Venlig hilsen,<br>%s',
    ],
    'en' => [
        // Password reset email (self-service)
        'email_reset_subject'       => 'Reset your password - %s',
        'email_reset_greeting'      => 'Hi %s,',
        'email_reset_intro'         => 'You requested to reset your password for %s.',
        'email_reset_button'        => 'Reset Password',
        'email_reset_expiry'        => 'This link expires in 1 hour.',
        'email_reset_ignore'        => "If you didn't request this, you can ignore this email.",
        'email_footer'              => 'Best regards,<br>%s',

        // Invite email
        'email_invite_subject'      => "You're invited to %s!",
        'email_invite_greeting'     => 'Hi!',
        'email_invite_intro'        => '%s has invited you to join %s.',
        'email_invite_desc'         => 'Predict the top 3 for each F1 Grand Prix and compete against others for points and stars!',
        'email_invite_button'       => 'Create your account',
        'email_invite_expiry'       => 'This invitation expires in 7 days.',

        // Admin password reset email
        'email_admin_reset_subject' => 'Your password has been reset! - %s',
        'email_admin_reset_greeting' => 'Hi %s,',
        'email_admin_reset_intro'   => "Your password has been reset by %s. The new password is: '%s'",
        'email_admin_reset_button'  => 'Go to app',
        'email_admin_contact'       => 'Contact administrator if you have questions.',

        // Bet deleted notification email
        'email_bet_deleted_subject' => 'Your bet has been deleted - %s',
        'email_bet_deleted_greeting' => 'Hi %s,',
        'email_bet_deleted_intro'   => 'Your bet on <strong>%s</strong> has been deleted by an administrator.',
        'email_go_to_app'           => 'Go to app',
        'email_contact_admin'       => 'Contact administrator if you have questions.',
        'email_regards'             => 'Best regards,<br>%s',
    ],
];
```

- [ ] **Step 2: Verify file syntax**

```bash
php -l public/lang/email.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add public/lang/email.php
git commit -m "feat: add public/lang/email.php with all email template translations"
```

---

## Task 4: Update `t()` in `functions.php`

**Files:**
- Modify: `public/includes/functions.php`

Replace the entire `t()` function (lines 63–182 in the current file). The new version loads all three lang files once via a static cache and merges them. It also accepts an optional `$lang` parameter so email functions can call `t($key, 'da')` or `t($key, 'en')` explicitly without depending on the session.

- [ ] **Step 1: Replace the t() function**

Find the existing function:
```php
function t($key) {
    $translations = [
        'da' => [
            // ... 54-key array
        ],
        'en' => [
            // ... 54-key array
        ]
    ];
    $lang = getLang();
    return $translations[$lang][$key] ?? $key;
}
```

Replace with:
```php
function t($key, $lang = null) {
    static $translations = null;
    if ($translations === null) {
        $base = __DIR__ . '/../lang/';
        $user  = require $base . 'user.php';
        $admin = require $base . 'admin.php';
        $email = require $base . 'email.php';
        foreach (['da', 'en'] as $l) {
            $translations[$l] = array_merge(
                $user[$l]  ?? [],
                $admin[$l] ?? [],
                $email[$l] ?? []
            );
        }
    }
    $useLang = $lang ?? getLang();
    return $translations[$useLang][$key] ?? $key;
}
```

- [ ] **Step 2: Verify no syntax errors**

```bash
php -l public/includes/functions.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Smoke test — load any page**

Open `http://localhost/` (or your local test URL) in a browser. No PHP errors means the lang files load correctly.

- [ ] **Step 4: Commit**

```bash
git add public/includes/functions.php
git commit -m "refactor: t() loads from lang files instead of hardcoded array"
```

---

## Task 5: Migrate layout files — header.php, footer.php, index.php, races.php

**Files:**
- Modify: `public/includes/header.php`
- Modify: `public/includes/footer.php`
- Modify: `public/index.php`
- Modify: `public/races.php`

Pattern: Replace `$lang === 'da' ? 'da string' : 'en string'` → `t('key')`.
For strings with a variable use `sprintf(t('key'), $var)`.
The `$lang` variable is always available in these files (set at top of each page or in header).

- [ ] **Step 1: Migrate header.php**

Find and replace each ternary:

```php
// Before:
<?= $lang === 'da' ? 'Regler' : 'Rules' ?>
<?= $lang === 'da' ? 'Skift tema' : 'Toggle theme' ?>
<?= $lang === 'da' ? 'English' : 'Dansk' ?>
<?= $lang === 'da' ? 'Skift tema' : 'Toggle theme' ?>   // title attr
<?= $lang === 'da' ? 'Skift sprog' : 'Change language' ?>

// After:
<?= t('rules') ?>
<?= t('toggle_theme') ?>
<?= t('lang_switch_label') ?>
<?= t('toggle_theme') ?>
<?= t('change_language') ?>
```

The `setLang(...)` call on line 45 is logic, not a display string — leave it untouched.

The JS countdown `'Nu!' : 'Now!'` is embedded in a PHP echo inside a `<script>` tag:
```php
// Before:
countdownEl.textContent = '<?= $lang === 'da' ? 'Nu!' : 'Now!' ?>';
// After:
countdownEl.textContent = '<?= t('countdown_now') ?>';
```

- [ ] **Step 2: Migrate footer.php**

```php
// Before:
<?= $lang === 'da' ? 'Kontakt:' : 'Contact:' ?>
// After:
<?= t('contact') ?>
```

- [ ] **Step 3: Migrate index.php**

The hero title/text lines use DB settings, not t() keys — leave them as `$lang === 'da' ? $settings['hero_title_da'] : $settings['hero_title_en']` since these are not static strings.

Replace the static ternaries:
```php
// Before:
<?= $lang === 'da' ? 'Ingen kommende løb' : 'No upcoming races' ?>
<?= $lang === 'da' ? 'Betting åbner om' : 'Betting opens in' ?>:
<?= $lang === 'da' ? 'Betting lukker om' : 'Betting closes in' ?>:
<?= $lang === 'da' ? 'Puljestørrelse: ' : 'Pool size: ' ?>
<?= $lang === 'da' ? 'DIG' : 'YOU' ?>

// After:
<?= t('no_upcoming_races') ?>
<?= t('betting_opens_in') ?>:
<?= t('betting_closes_in') ?>:
<?= t('pool_size') ?>
<?= t('you_badge') ?>
```

- [ ] **Step 4: Migrate races.php**

```php
// Before:
$errors['already_bet']      = $lang === 'da' ? 'Du har allerede placed et bet...' : 'You have already...';
$errors['not_in_competition'] = $lang === 'da' ? 'Du er ikke...' : 'You are not...';
<?= $lang === 'da' ? 'Bet placeret' : 'Bet placed' ?>
<?= $lang === 'da' ? 'Puljen vundet' : 'Bettingpool won' ?>
<?= $lang === 'da' ? 'Betting åbner om' : 'Betting opens in' ?>:
<?= $lang === 'da' ? 'Betting lukker om' : 'Betting closes in' ?>:
<?= $lang === 'da' ? 'Puljestørrelse: ' : 'Pool size: ' ?>
<?= $lang === 'da' ? 'DIG' : 'YOU' ?>

// After:
$errors['already_bet']       = t('already_bet_long');
$errors['not_in_competition'] = t('not_in_competition');
<?= t('bet_placed_label') ?>
<?= t('pool_won') ?>
<?= t('betting_opens_in') ?>:
<?= t('betting_closes_in') ?>:
<?= t('pool_size') ?>
<?= t('you_badge') ?>
```

- [ ] **Step 5: Verify syntax**

```bash
php -l public/includes/header.php && php -l public/includes/footer.php && php -l public/index.php && php -l public/races.php
```

Expected: All `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add public/includes/header.php public/includes/footer.php public/index.php public/races.php
git commit -m "refactor: migrate layout and race page strings to t() calls"
```

---

## Task 6: Migrate auth/profile pages

**Files:**
- Modify: `public/login.php`
- Modify: `public/register.php`
- Modify: `public/profile.php`
- Modify: `public/forgot_password.php`
- Modify: `public/reset_password.php`

- [ ] **Step 1: Migrate login.php** (1 ternary)

```php
// Before:
<a href="forgot_password.php" class="text-accent"><?= $lang === 'da' ? 'Glemt adgangskode?' : 'Forgot password?' ?></a>
// After:
<a href="forgot_password.php" class="text-accent"><?= t('forgot_password') ?></a>
```

- [ ] **Step 2: Migrate register.php** (7 ternaries)

```php
// Before:
$error = $lang === 'da'
    ? 'Ugyldigt eller udløbet invitation. Kontakt administrator for en ny invitation.'
    : 'Invalid or expired invite. Contact administrator for a new invitation.';
// After:
$error = t('invalid_invite');

// Before:
$error = $lang === 'da'
    ? 'Du skal have en invitation for at registrere dig. Kontakt administrator.'
    : 'You need an invitation to register. Contact administrator.';
// After:
$error = t('invite_required');

// Before:
$error = $lang === 'da'
    ? 'Email skal matche invitationens email: ' . escape($inviteEmail)
    : 'Email must match the invitation email: ' . escape($inviteEmail);
// After:
$error = sprintf(t('email_must_match_invite'), escape($inviteEmail));

// Before:
$error = $lang === 'da' ? 'Adgangskode skal være mindst 6 tegn' : 'Password must be at least 6 characters';
// After:
$error = t('password_min_length');

// Before (HTML template):
<?= $lang === 'da' ? 'Du er inviteret!' : 'You are invited!' ?>
<?= $lang === 'da' ? 'Email er sat af invitation' : 'Email is set by invitation' ?>
<?= $lang === 'da' ? 'Har du allerede en konto?' : 'Already have an account?' ?>
// After:
<?= t('you_are_invited') ?>
<?= t('email_set_by_invite') ?>
<?= t('already_have_account') ?>
```

- [ ] **Step 3: Migrate profile.php** (4 ternaries)

```php
// Before:
<h2><?= $currentUser['role'] === 'admin' ? 'Admin' : ($lang === 'da' ? 'Bruger' : 'User') ?></h2>
<p class="text-muted"><?= $lang === 'da' ? 'Rolle' : 'Role' ?></p>
<h2><?= $currentUser['in_competition'] ? ($lang === 'da' ? 'Ja' : 'Yes') : ($lang === 'da' ? 'Nej' : 'No') ?></h2>
<p class="text-muted"><?= $lang === 'da' ? 'I konkurrence' : 'In competition' ?></p>

// After:
<h2><?= $currentUser['role'] === 'admin' ? 'Admin' : t('user') ?></h2>
<p class="text-muted"><?= t('role') ?></p>
<h2><?= $currentUser['in_competition'] ? t('yes') : t('no') ?></h2>
<p class="text-muted"><?= t('in_competition') ?></p>
```

- [ ] **Step 4: Migrate forgot_password.php** (8 ternaries)

```php
// PHP logic section:
$success = $lang === 'da' ? 'En email med nulstillingslink er sendt til din email.' : 'An email with reset link has been sent to your email.';
// → $success = t('reset_link_sent');

$success = $lang === 'da' ? 'Email kunne ikke sendes. Kontakt administrator.' : 'Email could not be sent. Contact administrator.';
// → $success = t('reset_link_failed');

$success = $lang === 'da' ? 'Hvis emailen findes i systemet, vil du modtage et nulstillingslink.' : 'If the email exists...';
// → $success = t('reset_link_check_email');

$error = $lang === 'da' ? 'Indtast en gyldig email' : 'Enter a valid email';
// → $error = t('enter_valid_email');

// HTML template section:
<?= $lang === 'da' ? 'Glemt adgangskode' : 'Forgot Password' ?>
<?= $lang === 'da' ? 'Indtast din email for at modtage et nulstillingslink' : 'Enter your email to receive a reset link' ?>
<?= $lang === 'da' ? 'Send nulstillingslink' : 'Send reset link' ?>
<?= $lang === 'da' ? 'Tilbage til login' : 'Back to login' ?>
// →
<?= t('forgot_password_title') ?>
<?= t('forgot_password_desc') ?>
<?= t('send_reset_link') ?>
<?= t('back_to_login') ?>
```

- [ ] **Step 5: Migrate reset_password.php** (9 ternaries)

```php
// PHP logic:
$error = $lang === 'da' ? 'Ugyldigt eller udløbet link. Anmod om et nyt.' : 'Invalid or expired link. Request a new one.';
// → $error = t('token_invalid_expired');

$error = $lang === 'da' ? 'Adgangskoden skal være mindst 6 tegn' : 'Password must be at least 6 characters';
// → $error = t('passwords_min_6');

$error = $lang === 'da' ? 'Adgangskoderne matcher ikke' : 'Passwords do not match';
// → $error = t('passwords_no_match');

$success = $lang === 'da' ? 'Din adgangskode er blevet nulstillet. Du kan nu logge ind.' : 'Your password has been reset. You can now log in.';
// → $success = t('password_reset_done');

// HTML template:
<?= $lang === 'da' ? 'Nulstil adgangskode' : 'Reset Password' ?>  (h2 title)
<?= $lang === 'da' ? 'Gå til login' : 'Go to login' ?>
<?= $lang === 'da' ? 'Ny adgangskode' : 'New password' ?>
<?= $lang === 'da' ? 'Bekræft adgangskode' : 'Confirm password' ?>
<?= $lang === 'da' ? 'Nulstil adgangskode' : 'Reset password' ?>  (button)
<?= $lang === 'da' ? 'Linket er ugyldigt eller udløbet.' : 'The link is invalid or expired.' ?>
<?= $lang === 'da' ? 'Anmod om nyt link' : 'Request new link' ?>
<?= $lang === 'da' ? 'Tilbage til login' : 'Back to login' ?>
// →
<?= t('reset_password_title') ?>
<?= t('go_to_login') ?>
<?= t('new_password') ?>
<?= t('confirm_password') ?>
<?= t('reset_password_btn') ?>
<?= t('link_invalid_expired') ?>
<?= t('request_new_link') ?>
<?= t('back_to_login') ?>
```

- [ ] **Step 6: Verify syntax**

```bash
php -l public/login.php && php -l public/register.php && php -l public/profile.php && php -l public/forgot_password.php && php -l public/reset_password.php
```

Expected: All `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add public/login.php public/register.php public/profile.php public/forgot_password.php public/reset_password.php
git commit -m "refactor: migrate auth and profile page strings to t() calls"
```

---

## Task 7: Migrate bet pages + rules.php

**Files:**
- Modify: `public/bet.php`
- Modify: `public/edit_bet.php`
- Modify: `public/rules.php`

- [ ] **Step 1: Migrate bet.php** (4 ternaries)

```php
// Before (2 in PHP logic, 2 in HTML):
$errors['already_bet']        = $lang === 'da' ? 'Du har allerede placed et bet...' : 'You have already...';
$errors['not_in_competition'] = $lang === 'da' ? 'Du er ikke...' : 'You are not...';
<?= $lang === 'da' ? 'Betting åbner om' : 'Betting opens in' ?>:
<?= $lang === 'da' ? 'Betting lukker om' : 'Betting closes in' ?>:
// After:
$errors['already_bet']        = t('already_bet_long');
$errors['not_in_competition'] = t('not_in_competition');
<?= t('betting_opens_in') ?>:
<?= t('betting_closes_in') ?>:
```

- [ ] **Step 2: Migrate edit_bet.php** (2 ternaries)

```php
// Before:
<h2 style="margin: 0;"><?= $lang === 'da' ? 'Rediger Bet' : 'Edit Bet' ?></h2>
<?= $lang === 'da' ? 'Timestamp vil blive opdateret når du gemmer ændringer.' : 'Timestamp will be updated when you save changes.' ?>
// After:
<h2 style="margin: 0;"><?= t('edit_bet_title') ?></h2>
<?= t('timestamp_update_info') ?>
```

- [ ] **Step 3: Migrate rules.php** (47 ternaries)

rules.php has the most ternaries. Work through the file top-to-bottom:

```php
// Title and sections:
<?= $lang === 'da' ? 'Spilleregler' : 'Betting Rules' ?>
<?= $lang === 'da' ? 'Betting Vindue' : 'Betting Window' ?>
<td><strong><?= $lang === 'da' ? 'Åbner' : 'Opens' ?></strong></td>
<td><?= $lang === 'da' ? $bettingWindowHours . ' timer før løbets starttid' : $bettingWindowHours . ' hours before race start time' ?></td>
<td><strong><?= $lang === 'da' ? 'Lukker' : 'Closes' ?></strong></td>
<td><?= $lang === 'da' ? 'Ved løbets starttid' : 'At race start time' ?></td>
<td><strong><?= $lang === 'da' ? 'Rediger' : 'Edit' ?></strong></td>
<td><?= $lang === 'da' ? 'Bets kan redigeres så længe vinduet er åbent' : 'Bets can be edited while window is open' ?></td>
<?= $lang === 'da' ? 'Point System' : 'Points System' ?>
<th><?= $lang === 'da' ? 'Position' : 'Position' ?></th>
<th><?= $lang === 'da' ? 'Korrekt Forudsigelse' : 'Correct Prediction' ?></th>
<td><strong><?= $pointsP1 ?> <?= $lang === 'da' ? 'point' : 'points' ?></strong></td>
<td><strong><?= $pointsP2 ?> <?= $lang === 'da' ? 'point' : 'points' ?></strong></td>
<td><strong><?= $pointsP3 ?> <?= $lang === 'da' ? 'point' : 'points' ?></strong></td>
<td>+<?= $pointsWrongPos ?> <?= $lang === 'da' ? 'point hvis kører er i top 3, men forkert position' : 'points if driver is in top 3 but wrong position' ?></td>
<?= $lang === 'da' ? 'Stjerner' : 'Stars' ?>
<?= $lang === 'da' ? 'Puljen' : 'Betting pool' ?>
<?= $lang === 'da' ? 'Restriktioner' : 'Restrictions' ?>
... (and ~25 more table cell ternaries)

// After:
<?= t('rules_title') ?>
<?= t('rules_betting_window') ?>
<td><strong><?= t('opens') ?></strong></td>
<td><?= sprintf(t('betting_opens_hours'), $bettingWindowHours) ?></td>
<td><strong><?= t('closes') ?></strong></td>
<td><?= t('at_race_start') ?></td>
<td><strong><?= t('edit_label') ?></strong></td>
<td><?= t('bets_editable') ?></td>
<?= t('rules_points_system') ?>
<th><?= t('position') ?></th>
<th><?= t('correct_prediction') ?></th>
<td><strong><?= $pointsP1 ?> <?= t('points_label') ?></strong></td>
<td><strong><?= $pointsP2 ?> <?= t('points_label') ?></strong></td>
<td><strong><?= $pointsP3 ?> <?= t('points_label') ?></strong></td>
<td>+<?= $pointsWrongPos ?> <?= t('wrong_pos_rule') ?></td>
<?= t('rules_stars') ?>
<?= t('rules_pool') ?>
<?= t('rules_restrictions') ?>
```

For the remaining rules.php ternaries (restrictions table rows, pool/stars descriptions), follow the same pattern using the keys defined in user.php:
- `one_bet_per_race`, `one_bet_per_race_desc`, `no_duplicates`, `no_duplicates_desc`
- `unique_combo`, `unique_combo_desc`, `quali_restriction`, `quali_restriction_desc`
- `perfect_bet_stars_desc`, `star`, `pool_win_desc`

For the `Stjerner` heading under the stars section (which is the t('stars') key that already exists), use `t('stars')` directly.

- [ ] **Step 4: Verify syntax**

```bash
php -l public/bet.php && php -l public/edit_bet.php && php -l public/rules.php
```

Expected: All `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add public/bet.php public/edit_bet.php public/rules.php
git commit -m "refactor: migrate bet and rules page strings to t() calls"
```

---

## Task 8: Migrate admin.php action blocks + HTML

**Files:**
- Modify: `public/admin.php`

admin.php has two kinds of ternaries: PHP action message blocks (top of file) and one HTML inline (tab label). Migrate both.

- [ ] **Step 1: Migrate driver action messages (lines ~31–48)**

```php
// Before:
$message = $lang === 'da' ? 'Kører tilføjet!' : 'Driver added!';
$error   = $lang === 'da' ? 'Udfyld alle felter korrekt (nummer skal være 1–99)' : 'Fill in all fields correctly (number must be 1–99)';
$message = $lang === 'da' ? 'Kører opdateret!' : 'Driver updated!';

// After:
$message = t('driver_added');
$error   = t('driver_fields_error');
$message = t('driver_updated');
```

- [ ] **Step 2: Migrate race action messages (lines ~79–171)**

```php
// Before:
$message = $lang === 'da' ? 'Løb tilføjet!' : 'Race added!';
$error   = $lang === 'da' ? 'Ugyldig dato eller tid' : 'Invalid date or time';
$error   = $lang === 'da' ? 'Udfyld alle felter' : 'Fill in all fields';
$message = $lang === 'da' ? 'Løb opdateret!' : 'Race updated!';
$error   = $lang === 'da' ? 'Navn er påkrævet' : 'Name is required';
$resetMsg = $lang === 'da' ? 'Resultat nulstillet! Indtast det korrekte resultat nedenfor.' : 'Result reset! Enter the correct result below.';
$error   = $lang === 'da' ? 'Kan kun nulstille det seneste løb med resultat' : 'Can only reset the most recently completed race';

// After:
$message  = t('race_added');
$error    = t('invalid_date_time');
$error    = t('fill_all_fields');
$message  = t('race_updated');
$error    = t('name_required');
$resetMsg = t('result_reset');
$error    = t('reset_most_recent_only');
```

- [ ] **Step 3: Migrate user management messages (lines ~199–259)**

```php
// Before (toggle competition):
$message = $lang === 'da'
    ? ($newStatus ? 'Bruger tilføjet til konkurrence' : 'Bruger fjernet fra konkurrence')
    : ($newStatus ? 'User added to competition' : 'User removed from competition');
// After:
$message = $newStatus ? t('user_added_to_competition') : t('user_removed_from_competition');

// Before (password reset success):
$message = $lang === 'da' ? 'Adgangskode nulstillet!' : 'Password reset!';
// After:
$message = t('password_reset_success');

// Before (password too short):
$error = $lang === 'da' ? 'Adgangskoden skal være mindst 6 tegn' : 'Password must be at least 6 characters';
// After:
$error = t('password_min_6_admin');
```

- [ ] **Step 4: Migrate bet deletion messages (lines ~313–333)**

```php
// Before:
$message = $lang === 'da' ? 'Bet slettet og bruger notificeret!' : 'Bet deleted and user notified!';
$message = $lang === 'da' ? 'Kan kun slette bets hvor betting vindue er åbent' : 'Can only delete bets where betting window is open';
// After:
$message = t('bet_deleted_notified');
$message = t('bet_delete_open_only');
```

- [ ] **Step 5: Migrate invite management messages (lines ~350–441)**

```php
// Before:
$error = $lang === 'da' ? 'Email er allerede registreret som bruger' : 'Email is already registered as user';
$error = $lang === 'da' ? 'Der er allerede en aktiv invitation til denne email' : 'There is already an active invite for this email';
$message = $lang === 'da' ? 'Invitation sendt til ' . $inviteEmail : 'Invitation sent to ' . $inviteEmail;
$error = $lang === 'da' ? 'Ugyldig email' : 'Invalid email';
$message = $lang === 'da' ? 'Invitation gensendt!' : 'Invitation resent!';
$message = $lang === 'da' ? 'Indstillinger gemt!' : 'Settings saved!';

// After:
$error   = t('email_already_user');
$error   = t('active_invite_exists');
$message = sprintf(t('invite_sent_to'), $inviteEmail);
$error   = t('invalid_email');
$message = t('invite_resent');
$message = t('settings_saved');

// LEAVE AS-IS (complex HTML with dynamic links — not worth migrating):
// $message = $lang === 'da' ? 'Invitation oprettet! Email kunne ikke sendes. Del linket manuelt:...' : '...'
// $message = $lang === 'da' ? 'Invitation forlænget! Email kunne ikke sendes. Del linket manuelt:...' : '...'
```

- [ ] **Step 6: Migrate tab label HTML (line ~508)**

```php
// Before:
<i class="fas fa-envelope"></i> <?= $lang === 'da' ? 'Invitationer' : 'Invites' ?> <span class="tab-count">...
// After:
<i class="fas fa-envelope"></i> <?= t('invites') ?> <span class="tab-count">...
```

- [ ] **Step 7: Verify syntax**

```bash
php -l public/admin.php
```

Expected: `No syntax errors detected`

- [ ] **Step 8: Commit**

```bash
git add public/admin.php
git commit -m "refactor: migrate admin.php action messages and tab label to t() calls"
```

---

## Task 9: Migrate admin partial templates

**Files:**
- Modify: `public/includes/admin/races.php`
- Modify: `public/includes/admin/drivers.php`
- Modify: `public/includes/admin/users.php`
- Modify: `public/includes/admin/bets.php`
- Modify: `public/includes/admin/invites.php`
- Modify: `public/includes/admin/settings.php`

- [ ] **Step 1: Migrate admin/races.php** (2 ternaries)

```php
// Before:
<h3>... <?= $lang === 'da' ? 'Tilføj Løb' : 'Add Race' ?></h3>
<button ... name="reset_race_result" ...><?= ... ?> <?= $lang === 'da' ? 'Nulstil Resultat' : 'Reset Result' ?>
// After:
<h3>... <?= t('add_race') ?></h3>
<button ...><?= ... ?> <?= t('reset_result') ?>
```

- [ ] **Step 2: Migrate admin/drivers.php** (1 ternary)

```php
// Before:
<h3>... <?= $lang === 'da' ? 'Tilføj Kører' : 'Add Driver' ?></h3>
// After:
<h3>... <?= t('add_driver') ?></h3>
```

- [ ] **Step 3: Migrate admin/users.php** (5 ternaries)

```php
// Before:
<?= $lang === 'da' ? 'Sidst set: ' : 'Last seen: ' ?><?= ... ? ($lang === 'da' ? 'Aldrig' : 'Never') ?>
<?= $user['in_competition'] ? ($lang === 'da' ? 'I Konkurrence' : 'In Competition') : ($lang === 'da' ? 'Ikke I Konkurrence' : 'Not In Competition') ?>
<?= $user['role'] === 'admin' ? ($lang === 'da' ? 'Gør Bruger' : 'Make User') : ($lang === 'da' ? 'Gør Admin' : 'Make Admin') ?>
<label class="form-label"><?= $lang === 'da' ? 'Ny adgangskode' : 'New password' ?></label>
<?= $lang === 'da' ? 'Nulstil' : 'Reset' ?>

// After:
<?= t('last_seen') ?><?= $user['last_login'] ? date('d M Y, H:i', strtotime($user['last_login'])) : t('never') ?>
<?= $user['in_competition'] ? t('in_competition_label') : t('not_in_competition_label') ?>
<?= $user['role'] === 'admin' ? t('make_user') : t('make_admin') ?>
<label class="form-label"><?= t('new_password') ?></label>
<?= t('reset') ?>
```

- [ ] **Step 4: Migrate admin/bets.php** (2 ternaries)

```php
// Before:
<span class="badge status-open"><?= $lang === 'da' ? 'Betting åben' : 'Betting open' ?></span>
title="<?= $lang === 'da' ? 'Slet og notificer bruger' : 'Delete and notify user' ?>"

// After:
<span class="badge status-open"><?= t('betting_open_label') ?></span>
title="<?= t('delete_notify_user') ?>"
```

- [ ] **Step 5: Migrate admin/invites.php** (18 ternaries)

```php
// Before (card header):
<h3><?= $lang === 'da' ? 'Inviter ny bruger' : 'Invite new user' ?></h3>
<i class="fas fa-paper-plane"></i> <?= $lang === 'da' ? 'Send invitation' : 'Send invite' ?>
<?= $lang === 'da' ? 'Invitationen udløber efter 7 dage...' : 'Invite expires after 7 days...' ?>

// After:
<h3><?= t('invite_new_user') ?></h3>
<i class="fas fa-paper-plane"></i> <?= t('send_invite') ?>
<?= t('invite_expires_desc') ?>

// Before (pending section):
<?= $lang === 'da' ? 'Afventende invitationer' : 'Pending invites' ?>
<?= $lang === 'da' ? 'Inviteret af' : 'Invited by' ?>
<?= $lang === 'da' ? 'Udløber' : 'Expires' ?>
title="<?= $lang === 'da' ? 'Gensend' : 'Resend' ?>"
title="<?= $lang === 'da' ? 'Kopiér link' : 'Copy link' ?>"

// After:
<?= t('pending_invites') ?>
<?= t('invited_by') ?>
<?= t('expires') ?>
title="<?= t('resend') ?>"
title="<?= t('copy_link') ?>"

// Before (used section):
<?= $lang === 'da' ? 'Brugte invitationer' : 'Used invites' ?>
<?= $lang === 'da' ? 'Registreret' : 'Registered' ?>
<?= $lang === 'da' ? 'Inviteret' : 'Invited' ?>

// After:
<?= t('used_invites') ?>
<?= t('registered_badge') ?>
<?= t('invited_label') ?>

// Before (expired section):
<?= $lang === 'da' ? 'Udløbne invitationer' : 'Expired invites' ?>
<?= $lang === 'da' ? 'Udløbet' : 'Expired' ?>      (badge)
<?= $lang === 'da' ? 'Udløb' : 'Expired' ?>         (label prefix)
title="<?= $lang === 'da' ? 'Gensend' : 'Resend' ?>"
<i class="fas fa-redo"></i> <?= $lang === 'da' ? 'Forny' : 'Renew' ?>

// After:
<?= t('expired_invites') ?>
<?= t('expired_badge') ?>
<?= t('expired_label') ?>
title="<?= t('resend') ?>"
<i class="fas fa-redo"></i> <?= t('renew') ?>

// Before (empty state + JS):
<?= $lang === 'da' ? 'Ingen invitationer endnu' : 'No invites yet' ?>
alert('<?= $lang === 'da' ? 'Link kopieret!' : 'Link copied!' ?>');

// After:
<?= t('no_invites') ?>
alert('<?= t('link_copied') ?>');
```

- [ ] **Step 6: Migrate admin/settings.php** (15 ternaries)

```php
// Before:
<label class="form-label"><?= $lang === 'da' ? 'År' : 'Year' ?></label>
<h4>... <?= $lang === 'da' ? 'Betting Vindue' : 'Betting Window' ?></h4>
<?= $lang === 'da' ? 'Konfigurer hvornår betting åbner...' : 'Configure when betting opens...' ?>
<label class="form-label"><?= $lang === 'da' ? 'Timer før løb' : 'Hours before race' ?></label>
<?= $lang === 'da' ? 'Betting åbner ' . intval(...) . ' timer...' : 'Betting opens ' . intval(...) . ' hours...' ?>
<h4>... <?= $lang === 'da' ? 'Point System' : 'Points System' ?></h4>
<?= $lang === 'da' ? 'Konfigurer hvor mange point...' : 'Configure how many points...' ?>
... <?= $lang === 'da' ? 'Point' : 'Points' ?>  (×3 for P1/P2/P3)
<label class="form-label"><?= $lang === 'da' ? 'Forkert position' : 'Wrong position' ?></label>
<?= $lang === 'da' ? '"Forkert position" point gives...' : '"Wrong position" points...' ?>
<h4>... <?= $lang === 'da' ? 'Betting Størrelse' : 'Bet Size' ?></h4>
<?= $lang === 'da' ? 'Standardstørrelse for hver indsats.' : 'Default size for each bet.' ?>
<label class="form-label"><?= $lang === 'da' ? 'Indsatsstørrelse' : 'Bet Size' ?></label>

// After:
<label class="form-label"><?= t('year') ?></label>
<h4>... <?= t('betting_window_section') ?></h4>
<?= t('betting_window_config') ?>
<label class="form-label"><?= t('hours_before_race') ?></label>
<?= sprintf(t('betting_window_summary'), intval($settings['betting_window_hours'] ?? 48)) ?>
<h4>... <?= t('points_system_section') ?></h4>
<?= t('points_config') ?>
... <?= t('points_label') ?>  (×3)
<label class="form-label"><?= t('wrong_position') ?></label>
<?= t('wrong_pos_desc') ?>
<h4>... <?= t('bet_size_section') ?></h4>
<?= t('bet_size_desc') ?>
<label class="form-label"><?= t('bet_size_label') ?></label>
```

- [ ] **Step 7: Verify all partials syntax**

```bash
php -l public/includes/admin/races.php && \
php -l public/includes/admin/drivers.php && \
php -l public/includes/admin/users.php && \
php -l public/includes/admin/bets.php && \
php -l public/includes/admin/invites.php && \
php -l public/includes/admin/settings.php
```

Expected: All `No syntax errors detected`

- [ ] **Step 8: Commit**

```bash
git add public/includes/admin/
git commit -m "refactor: migrate all admin partial template strings to t() calls"
```

---

## Task 10: Migrate email functions in smtp.php and admin.php

**Files:**
- Modify: `public/includes/smtp.php`
- Modify: `public/admin.php`

Email functions receive `$lang` as a parameter. Replace the if/else blocks that assign per-language variables with `t($key, $lang)` calls.

- [ ] **Step 1: Migrate sendPasswordResetEmail() in smtp.php**

Find the function body (around line 337–365). Replace the if/else block:

```php
// Before:
if ($lang === 'da') {
    $subject     = "Nulstil din adgangskode - $appName";
    $greeting    = "Hej $name,";
    $intro       = "Du har anmodet om at nulstille din adgangskode til $appName.";
    $buttonText  = "Nulstil adgangskode";
    $expiry      = "Dette link udløber om 1 time.";
    $ignore      = "Hvis du ikke har anmodet om dette, kan du ignorere denne email.";
    $footer      = "Med venlig hilsen,<br>$appName";
} else {
    $subject     = "Reset your password - $appName";
    $greeting    = "Hi $name,";
    $intro       = "You requested to reset your password for $appName.";
    $buttonText  = "Reset Password";
    $expiry      = "This link expires in 1 hour.";
    $ignore      = "If you didn't request this, you can ignore this email.";
    $footer      = "Best regards,<br>$appName";
}

// After:
$subject    = sprintf(t('email_reset_subject', $lang), $appName);
$greeting   = sprintf(t('email_reset_greeting', $lang), $name);
$intro      = sprintf(t('email_reset_intro', $lang), $appName);
$buttonText = t('email_reset_button', $lang);
$expiry     = t('email_reset_expiry', $lang);
$ignore     = t('email_reset_ignore', $lang);
$footer     = sprintf(t('email_footer', $lang), $appName);
```

- [ ] **Step 2: Migrate sendInviteEmail() in smtp.php**

```php
// Before:
if ($lang === 'da') {
    $subject    = "Du er inviteret til $appName!";
    $greeting   = "Hej!";
    $intro      = "$inviterName har inviteret dig til at deltage i $appName.";
    $desc       = "Forudsig top 3 for hvert F1 Grand Prix...";
    $buttonText = "Opret din konto";
    $expiry     = "Denne invitation udløber om 7 dage.";
    $footer     = "Med venlig hilsen,<br>$appName";
} else {
    $subject    = "You're invited to $appName!";
    $greeting   = "Hi!";
    $intro      = "$inviterName has invited you to join $appName.";
    $desc       = "Predict the top 3 for each F1 Grand Prix...";
    $buttonText = "Create your account";
    $expiry     = "This invitation expires in 7 days.";
    $footer     = "Best regards,<br>$appName";
}

// After:
$subject    = sprintf(t('email_invite_subject', $lang), $appName);
$greeting   = t('email_invite_greeting', $lang);
$intro      = sprintf(t('email_invite_intro', $lang), $inviterName, $appName);
$desc       = t('email_invite_desc', $lang);
$buttonText = t('email_invite_button', $lang);
$expiry     = t('email_invite_expiry', $lang);
$footer     = sprintf(t('email_footer', $lang), $appName);
```

- [ ] **Step 3: Migrate admin password reset email in admin.php** (~line 238)

```php
// Before:
if ($lang === 'da') {
    $subject    = "Dit kodeord er nulstillet! - $appName";
    $greeting   = "Hej " . $userName . ",";
    $intro      = "Dit kodeord er blevet nulstillet af " . $currentUser['display_name'] . ". Den nye kode er: '" . $newPassword . "'";
    $buttonText = "Gå til appen";
    $expiry     = "Kontakt administrator hvis du har spørgsmål.";
    $regards    = "Venlig hilsen,<br>$appName";
} else {
    $subject    = "Your password has been reset! - $appName";
    $greeting   = "Hi " . $userName . ",";
    $intro      = "Your password has been reset by " . $currentUser['display_name'] . "). The new password is: '" . $newPassword . "'";
    $buttonText = "Go to app";
    $expiry     = "Contact administrator if you have questions.";
    $regards    = "Regards,<br>$appName";
}

// After:
$subject    = sprintf(t('email_admin_reset_subject', $lang), $appName);
$greeting   = sprintf(t('email_admin_reset_greeting', $lang), $userName);
$intro      = sprintf(t('email_admin_reset_intro', $lang), $currentUser['display_name'], $newPassword);
$buttonText = t('email_admin_reset_button', $lang);
$expiry     = t('email_admin_contact', $lang);
$regards    = sprintf(t('email_regards', $lang), $appName);
```

- [ ] **Step 4: Migrate delete-bet notification email in admin.php** (~line 313)

```php
// Before:
if ($lang === 'da') {
    $subject    = "Dit bet er blevet slettet - $appName";
    $greeting   = "Hej " . ($bet['display_name'] ?: $bet['email']) . ",";
    $intro      = "Dit bet på <strong>" . htmlspecialchars($bet['race_name']) . "</strong> er blevet slettet af en administrator.";
    $buttonText = "Gå til appen";
    $expiry     = "Kontakt administrator hvis du har spørgsmål.";
} else {
    $subject    = "Your bet has been deleted - $appName";
    $greeting   = "Hi " . ($bet['display_name'] ?: $bet['email']) . ",";
    $intro      = "Your bet on <strong>" . htmlspecialchars($bet['race_name']) . "</strong> has been deleted by an administrator.";
    $buttonText = "Go to app";
    $expiry     = "Contact administrator if you have questions.";
}

// After:
$userName   = $bet['display_name'] ?: $bet['email'];
$subject    = sprintf(t('email_bet_deleted_subject', $lang), $appName);
$greeting   = sprintf(t('email_bet_deleted_greeting', $lang), $userName);
$intro      = sprintf(t('email_bet_deleted_intro', $lang), htmlspecialchars($bet['race_name']));
$buttonText = t('email_go_to_app', $lang);
$expiry     = t('email_contact_admin', $lang);
```

- [ ] **Step 5: Verify syntax**

```bash
php -l public/includes/smtp.php && php -l public/admin.php
```

Expected: All `No syntax errors detected`

- [ ] **Step 6: End-to-end verification**

1. Open the site, toggle language from DA → EN — confirm nav, footer, and home page strings switch.
2. Visit `/rules.php` in both languages — verify betting window hours, points values, and restriction text render correctly.
3. Login as admin, visit `/admin.php?tab=invites` — verify all invite section labels appear in the correct language.
4. Visit `/admin.php?tab=settings` — verify section headers and field labels switch languages.
5. Trigger a forgot-password flow — verify the page text (not the email) renders in the correct language.
6. Run the E2E test suite to confirm no regressions:

```bash
npx playwright test
```

Expected: All previously passing tests still pass.

- [ ] **Step 7: Commit**

```bash
git add public/includes/smtp.php public/admin.php
git commit -m "refactor: migrate email template strings to t() calls with lang parameter"
```

---

## Self-Review

**Spec coverage:**
- ✅ `public/lang/user.php` — all user-facing strings including existing 54 t() keys
- ✅ `public/lang/admin.php` — all admin page strings and action messages
- ✅ `public/lang/email.php` — all 4 email templates (reset, invite, admin-reset, bet-deleted)
- ✅ `t()` updated to load from files with optional `$lang` param for email use
- ✅ All 20 PHP files with inline ternaries covered across tasks 5–10
- ⚠️ Two invite error messages with embedded HTML+dynamic link left as inline ternaries (explicitly noted in Task 8 Step 5 — this is intentional)

**Placeholder scan:** No TBD/TODO markers. All steps contain actual code.

**Type consistency:** All key names referenced in migration steps are defined in the lang files created in Tasks 1–3. The `sprintf(t('key'), $var)` pattern is used consistently for strings with variables.
