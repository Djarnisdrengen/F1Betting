<?php
return [
    'da' => [
        // Betting window open notification
        'email_betting_open_subject'       => 'Betting åbent: %s - %s',
        'email_betting_open_greeting'      => 'Hej %s!',
        'email_betting_open_intro'         => 'Betting er nu åbent for <strong>%s</strong> (%s)!',
        'email_betting_open_pool'          => 'Den aktuelle pulje er <strong>%s kr</strong>.<br>',
        'email_betting_open_details'       => 'Løbet starter: <strong>%s kl. %s</strong><br>Du har %s timer til at placere dit bet.',
        'email_betting_open_button'        => 'Placer dit bet nu',
        'email_betting_open_footer'        => 'Held og lykke!<br>%s',
        'email_betting_open_pool_text'     => 'Pulje: %s kr',
        'email_betting_open_starts_text'   => 'Løbet starter: %s kl. %s',

        // Pool reminder (non-competing users and pending invites)
        'email_pool_reminder_subject'      => 'Du går måske glip af %s kr! – %s',
        'email_pool_reminder_greeting'     => 'Hej %s!',
        'email_pool_reminder_intro'        => 'Betting er nu åbent for <strong>%s</strong> (%s).',
        'email_pool_reminder_body'         => 'Den aktuelle pulje er <strong>%s kr</strong> — og du er ikke med endnu!<br><br>Løbet starter: %s kl. %s',
        'email_pool_reminder_button'       => 'Se hvad du går glip af',
        'email_pool_reminder_body_text'    => "Den aktuelle pulje er %s kr\nLøbet starter: %s kl. %s",

        // Betting closing soon notification
        'email_betting_closing_subject'    => '⏰ Sidste chance: %s - %s',
        'email_betting_closing_greeting'   => 'Hej %s!',
        'email_betting_closing_intro'      => 'Betting lukker snart for <strong>%s</strong>!',
        'email_betting_closing_details'    => 'Du har kun <strong>ca. 2 timer</strong> tilbage til at placere dit bet.<br>Løbet starter: %s kl. %s',
        'email_betting_closing_button'     => 'Placer dit bet NU',
        'email_betting_closing_footer'     => 'Skynd dig!<br>%s',
        'email_betting_closing_time_text'  => 'Du har kun ca. 2 timer tilbage!',
        'email_betting_closing_starts_text'=> 'Løbet starter: %s kl. %s',

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
        // Betting window open notification
        'email_betting_open_subject'       => 'Betting open: %s - %s',
        'email_betting_open_greeting'      => 'Hi %s!',
        'email_betting_open_intro'         => 'Betting is now open for <strong>%s</strong> (%s)!',
        'email_betting_open_pool'          => 'The current pool is <strong>%s kr</strong>.<br>',
        'email_betting_open_details'       => 'Race starts: <strong>%s at %s</strong><br>You have %s hours to place your bet.',
        'email_betting_open_button'        => 'Place your bet now',
        'email_betting_open_footer'        => 'Good luck!<br>%s',
        'email_betting_open_pool_text'     => 'Pool: %s kr',
        'email_betting_open_starts_text'   => 'Race starts: %s at %s',

        // Pool reminder (non-competing users and pending invites)
        'email_pool_reminder_subject'      => "You're missing out on possible %s kr! – %s",
        'email_pool_reminder_greeting'     => 'Hi %s!',
        'email_pool_reminder_intro'        => 'Betting is now open for <strong>%s</strong> (%s).',
        'email_pool_reminder_body'         => "The current pool is <strong>%s kr</strong> — and you're not in it yet!<br><br>Race starts: %s at %s",
        'email_pool_reminder_button'       => "See what you're missing",
        'email_pool_reminder_body_text'    => "The current pool is %s kr\nRace starts: %s at %s",

        // Betting closing soon notification
        'email_betting_closing_subject'    => '⏰ Last chance: %s - %s',
        'email_betting_closing_greeting'   => 'Hi %s!',
        'email_betting_closing_intro'      => 'Betting is closing soon for <strong>%s</strong>!',
        'email_betting_closing_details'    => 'You have only <strong>approx. 2 hours</strong> left to place your bet.<br>Race starts: %s at %s',
        'email_betting_closing_button'     => 'Place your bet NOW',
        'email_betting_closing_footer'     => 'Hurry!<br>%s',
        'email_betting_closing_time_text'  => 'You only have about 2 hours left!',
        'email_betting_closing_starts_text'=> 'Race starts: %s at %s',

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
