<?php
return [
    'da' => [
        // MFA email OTP
        'email_otp_subject'                => 'Din loginkode: %s',
        'email_otp_greeting'               => 'Hej %s!',
        'email_otp_intro'                  => 'Brug denne engangskode for at fuldføre dit login:',
        'email_otp_body'                   => 'Din kode er <strong>%s</strong>. Den udløber om 10 minutter.',
        'email_otp_expiry'                 => 'Koden udløber om 10 minutter.',
        'email_otp_ignore'                 => 'Hvis du ikke forsøgte at logge ind, kan du ignorere denne e-mail.',
        // Betting window open notification
        'email_betting_open_subject'       => 'Betting åbent: %s',
        'email_betting_open_greeting'      => 'Hej %s!',
        'email_betting_open_intro'         => 'Betting er nu åbent for <strong>%s</strong> (%s)!',
        'email_betting_open_pool'          => 'Den aktuelle pulje er <strong>%s kr</strong>.<br>',
        'email_betting_open_details'       => 'Løbet starter: <strong>%s kl. %s</strong><br>Du har %s timer til at placere dit bet.',
        'email_betting_open_button'        => 'Placer dit bet nu',
        'email_betting_open_footer'        => 'Held og lykke!<br>%s',
        'email_betting_open_pool_text'     => 'Pulje: %s kr',
        'email_betting_open_starts_text'   => 'Løbet starter: %s kl. %s',

        // Pool reminder — registered user not in competition
        'email_pool_noncompeting_subject'    => 'Du går glip af %s kr! – Deltag i konkurrencen',
        'email_pool_noncompeting_greeting'   => 'Hej %s!',
        'email_pool_noncompeting_intro'      => 'Betting er nu åbent for <strong>%s</strong> (%s).',
        'email_pool_noncompeting_body'       => 'Den aktuelle pulje er <strong>%s kr</strong> — men du er ikke tilmeldt konkurrencen endnu.<br><br>Kontakt en administrator for at komme med, og placer dit bet inden løbet starter.<br>Løbet starter: %s kl. %s',
        'email_pool_noncompeting_button'     => 'Se resultattavlen',
        'email_pool_noncompeting_body_text'  => "Den aktuelle pulje er %s kr — men du er ikke tilmeldt konkurrencen endnu.\nKontakt en administrator for at komme med.\nLøbet starter: %s kl. %s",

        // Pool reminder — pending invite
        'email_pool_invite_subject'          => 'Du går glip af %s kr! – Du har en afventende invitation',
        'email_pool_invite_greeting'         => 'Hej!',
        'email_pool_invite_intro'            => 'Betting er nu åbent for <strong>%s</strong> (%s).',
        'email_pool_invite_body'             => 'Den aktuelle pulje er <strong>%s kr</strong> — og der er stadig plads til dig!<br><br>Du har en afventende invitation. Opret din konto og placer dit bet inden løbet starter.<br>Løbet starter: %s kl. %s',
        'email_pool_invite_button'           => 'Registrér dig nu',
        'email_pool_invite_body_text'        => "Den aktuelle pulje er %s kr — og der er stadig plads til dig!\nDu har en afventende invitation.\nLøbet starter: %s kl. %s",

        // Betting closing soon notification
        'email_betting_closing_subject'    => '⏰ Sidste chance: %s',
        'email_betting_closing_greeting'   => 'Hej %s!',
        'email_betting_closing_intro'      => 'Betting lukker snart for <strong>%s</strong>!',
        'email_betting_closing_details'    => 'Du har kun <strong>ca. 2 timer</strong> tilbage til at placere dit bet.<br>Løbet starter: %s kl. %s',
        'email_betting_closing_button'     => 'Placer dit bet NU',
        'email_betting_closing_footer'     => 'Skynd dig!<br>%s',
        'email_betting_closing_time_text'  => 'Du har kun ca. 2 timer tilbage!',
        'email_betting_closing_starts_text'=> 'Løbet starter: %s kl. %s',

        // Password reset email (self-service via forgot_password.php)
        'email_reset_subject'       => 'Nulstil din adgangskode',
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
        'email_admin_reset_subject' => 'Dit kodeord er nulstillet!',
        'email_admin_reset_greeting' => 'Hej %s,',
        'email_admin_reset_intro'   => 'Dit kodeord er blevet nulstillet af %s. Den nye kode er: \'%s\'',
        'email_admin_reset_button'  => 'Gå til appen',
        'email_admin_contact'       => 'Kontakt administrator hvis du har spørgsmål.',

        // Admin removed two-step sign-in (support/lockout recovery)
        'email_mfa_removed_subject' => 'Totrins-login er fjernet fra din konto',
        'email_mfa_removed_greeting' => 'Hej %s,',
        'email_mfa_removed_intro'   => 'Administratoren %s har fjernet totrins-login (passkeys, autentificator-app, e-mail-koder og gendannelseskoder) fra din konto. Du logger nu ind med din adgangskode alene. Hvis du ikke selv har bedt om dette, så kontakt administratoren med det samme.',

        // Bet deleted notification email
        'email_bet_deleted_subject' => 'Dit bet er blevet slettet',
        'email_bet_deleted_greeting' => 'Hej %s,',
        'email_bet_deleted_intro'   => 'Dit bet på <strong>%s</strong> er blevet slettet af en administrator.',
        'email_go_to_app'           => 'Gå til appen',
        'email_contact_admin'       => 'Kontakt administrator hvis du har spørgsmål.',
        'email_regards'             => 'Venlig hilsen,<br>%s',

        // Bet confirmation email (placed / updated)
        'email_bet_confirm_subject_placed'  => 'Bet bekræftet: %s',
        'email_bet_confirm_subject_updated' => 'Bet opdateret: %s',
        'email_bet_confirm_greeting'        => 'Hej %s,',
        'email_bet_confirm_intro_placed'    => 'Dit bet på <strong>%s</strong> er registreret.',
        'email_bet_confirm_intro_updated'   => 'Dit bet på <strong>%s</strong> er opdateret.',
        'email_bet_confirm_picks'           => 'Dine valg:',
        'email_bet_confirm_meta'            => 'Registreret på %s: %s',

        // Magic link email (Challenges)
        'email_magic_subject'               => 'Din Challenges login-link',
        'email_magic_greeting'              => 'Hej,',
        'email_magic_intro'                 => 'Klik på linket nedenfor for at logge ind på Challenges.',
        'email_magic_button'                => 'Log ind på Challenges',
        'email_magic_expiry'                => 'Dette link udløber om 30 minutter.',
        'email_magic_ignore'                => 'Hvis du ikke anmodede om dette, kan du ignorere denne email.',

        // Duel result email
        'email_duel_result_subject'         => 'Duellen er afsluttet: %s',
        'email_duel_result_greeting'        => 'Hej %s,',
        'email_duel_result_won'             => 'Du vandt duellen mod %s! +15 CP',
        'email_duel_result_lost'            => 'Du tabte duellen mod %s. +5 CP',
        'email_duel_result_tie'             => 'Duellen mod %s endte uafgjort. +10 CP',
        'email_duel_result_void'            => 'Duellen mod %s blev annulleret (ufuldstendig).',
    ],
    'en' => [
        // MFA email OTP
        'email_otp_subject'                => 'Your login code: %s',
        'email_otp_greeting'               => 'Hi %s!',
        'email_otp_intro'                  => 'Use this one-time code to finish signing in:',
        'email_otp_body'                   => 'Your code is <strong>%s</strong>. It expires in 10 minutes.',
        'email_otp_expiry'                 => 'The code expires in 10 minutes.',
        'email_otp_ignore'                 => 'If you did not try to sign in, you can ignore this email.',
        // Betting window open notification
        'email_betting_open_subject'       => 'Betting open: %s',
        'email_betting_open_greeting'      => 'Hi %s!',
        'email_betting_open_intro'         => 'Betting is now open for <strong>%s</strong> (%s)!',
        'email_betting_open_pool'          => 'The current pool is <strong>%s kr</strong>.<br>',
        'email_betting_open_details'       => 'Race starts: <strong>%s at %s</strong><br>You have %s hours to place your bet.',
        'email_betting_open_button'        => 'Place your bet now',
        'email_betting_open_footer'        => 'Good luck!<br>%s',
        'email_betting_open_pool_text'     => 'Pool: %s kr',
        'email_betting_open_starts_text'   => 'Race starts: %s at %s',

        // Pool reminder — registered user not in competition
        'email_pool_noncompeting_subject'    => "You're missing out on %s kr! – Opt in to competition",
        'email_pool_noncompeting_greeting'   => 'Hi %s!',
        'email_pool_noncompeting_intro'      => 'Betting is now open for <strong>%s</strong> (%s).',
        'email_pool_noncompeting_body'       => "The current pool is <strong>%s kr</strong> — but you're not in the competition yet.<br><br>Contact an admin to opt in, then place your bet before the race starts.<br>Race starts: %s at %s",
        'email_pool_noncompeting_button'     => 'View leaderboard',
        'email_pool_noncompeting_body_text'  => "The current pool is %s kr — but you're not in the competition yet.\nContact an admin to opt in.\nRace starts: %s at %s",

        // Pool reminder — pending invite
        'email_pool_invite_subject'          => "You're missing out on %s kr! – You have a pending invitation",
        'email_pool_invite_greeting'         => 'Hi!',
        'email_pool_invite_intro'            => 'Betting is now open for <strong>%s</strong> (%s).',
        'email_pool_invite_body'             => "The current pool is <strong>%s kr</strong> — and there's still a spot for you!<br><br>You have a pending invitation. Create your account and place your bet before the race starts.<br>Race starts: %s at %s",
        'email_pool_invite_button'           => 'Register now',
        'email_pool_invite_body_text'        => "The current pool is %s kr — and there's still a spot for you!\nYou have a pending invitation.\nRace starts: %s at %s",

        // Betting closing soon notification
        'email_betting_closing_subject'    => '⏰ Last chance: %s',
        'email_betting_closing_greeting'   => 'Hi %s!',
        'email_betting_closing_intro'      => 'Betting is closing soon for <strong>%s</strong>!',
        'email_betting_closing_details'    => 'You have only <strong>approx. 2 hours</strong> left to place your bet.<br>Race starts: %s at %s',
        'email_betting_closing_button'     => 'Place your bet NOW',
        'email_betting_closing_footer'     => 'Hurry!<br>%s',
        'email_betting_closing_time_text'  => 'You only have about 2 hours left!',
        'email_betting_closing_starts_text'=> 'Race starts: %s at %s',

        // Password reset email (self-service)
        'email_reset_subject'       => 'Reset your password',
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
        'email_admin_reset_subject' => 'Your password has been reset!',
        'email_admin_reset_greeting' => 'Hi %s,',
        'email_admin_reset_intro'   => "Your password has been reset by %s. The new password is: '%s'",
        'email_admin_reset_button'  => 'Go to app',
        'email_admin_contact'       => 'Contact administrator if you have questions.',

        // Admin removed two-step sign-in (support/lockout recovery)
        'email_mfa_removed_subject' => 'Two-step sign-in was removed from your account',
        'email_mfa_removed_greeting' => 'Hi %s,',
        'email_mfa_removed_intro'   => 'The administrator %s has removed two-step sign-in (passkeys, authenticator app, email codes and recovery codes) from your account. You now sign in with your password alone. If you did not request this, contact the administrator immediately.',

        // Bet deleted notification email
        'email_bet_deleted_subject' => 'Your bet has been deleted',
        'email_bet_deleted_greeting' => 'Hi %s,',
        'email_bet_deleted_intro'   => 'Your bet on <strong>%s</strong> has been deleted by an administrator.',
        'email_go_to_app'           => 'Go to app',
        'email_contact_admin'       => 'Contact administrator if you have questions.',
        'email_regards'             => 'Best regards,<br>%s',

        // Bet confirmation email (placed / updated)
        'email_bet_confirm_subject_placed'  => 'Bet confirmed: %s',
        'email_bet_confirm_subject_updated' => 'Bet updated: %s',
        'email_bet_confirm_greeting'        => 'Hi %s,',
        'email_bet_confirm_intro_placed'    => 'Your bet on <strong>%s</strong> has been placed.',
        'email_bet_confirm_intro_updated'   => 'Your bet on <strong>%s</strong> has been updated.',
        'email_bet_confirm_picks'           => 'Your picks:',
        'email_bet_confirm_meta'            => 'Recorded on %s: %s',

        // Magic link email (Challenges)
        'email_magic_subject'               => 'Your Challenges login link',
        'email_magic_greeting'              => 'Hi,',
        'email_magic_intro'                 => 'Click the link below to sign in to Challenges.',
        'email_magic_button'                => 'Sign in to Challenges',
        'email_magic_expiry'                => 'This link expires in 30 minutes.',
        'email_magic_ignore'                => 'If you did not request this, you can ignore this email.',

        // Duel result email
        'email_duel_result_subject'         => 'Duel complete: %s',
        'email_duel_result_greeting'        => 'Hi %s,',
        'email_duel_result_won'             => 'You won the duel against %s! +15 CP',
        'email_duel_result_lost'            => 'You lost the duel against %s. +5 CP',
        'email_duel_result_tie'             => 'Your duel against %s ended in a tie. +10 CP',
        'email_duel_result_void'            => 'Your duel against %s was voided (incomplete).',
    ],
];
