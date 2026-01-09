<?php
/**
 * SendGrid Email Integration for F1 Betting
 * 
 * Konfiguration:
 * Tilføj disse konstanter til config.php:
 *   define('SENDGRID_API_KEY', 'SG.din_api_nøgle_her');
 *   define('SENDGRID_FROM_EMAIL', 'noreply@dit-domæne.dk');
 *   define('SENDGRID_FROM_NAME', 'F1 Betting');
 */

/**
 * Send email via SendGrid API
 * 
 * @param string $to Modtager email
 * @param string $subject Emne
 * @param string $htmlContent HTML indhold
 * @param string $textContent Plain text indhold (valgfrit)
 * @return array ['success' => bool, 'message' => string]
 */
function sendEmailViaSendGrid($to, $subject, $htmlContent, $textContent = null) {
    // Tjek om SendGrid er konfigureret
    if (!defined('SENDGRID_API_KEY') || empty(SENDGRID_API_KEY) || SENDGRID_API_KEY === 'SG.din_api_nøgle_her') {
        return ['success' => false, 'message' => 'SendGrid API key not configured'];
    }
    
    $fromEmail = defined('SENDGRID_FROM_EMAIL') ? SENDGRID_FROM_EMAIL : 'noreply@example.com';
    $fromName = defined('SENDGRID_FROM_NAME') ? SENDGRID_FROM_NAME : 'F1 Betting';
    
    // Byg email payload
    $data = [
        'personalizations' => [
            [
                'to' => [['email' => $to]],
                'subject' => $subject
            ]
        ],
        'from' => [
            'email' => $fromEmail,
            'name' => $fromName
        ],
        'content' => []
    ];
    
    // Tilføj text content hvis angivet
    if ($textContent) {
        $data['content'][] = [
            'type' => 'text/plain',
            'value' => $textContent
        ];
    }
    
    // Tilføj HTML content
    $data['content'][] = [
        'type' => 'text/html',
        'value' => $htmlContent
    ];
    
    // Send via cURL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.sendgrid.com/v3/mail/send',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . SENDGRID_API_KEY,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // SendGrid returnerer 202 for success
    if ($httpCode === 202) {
        return ['success' => true, 'message' => 'Email sent successfully'];
    }
    
    // Fejl
    $errorMsg = $error ?: $response ?: 'Unknown error';
    return ['success' => false, 'message' => "SendGrid error (HTTP $httpCode): $errorMsg"];
}

/**
 * Send password reset email
 * 
 * @param string $email Modtager email
 * @param string $displayName Brugerens navn
 * @param string $resetLink Link til password reset
 * @param string $lang Sprog ('da' eller 'en')
 * @return array ['success' => bool, 'message' => string]
 */
function sendPasswordResetEmail($email, $displayName, $resetLink, $lang = 'da') {
    $name = $displayName ?: $email;
    $appName = defined('SENDGRID_FROM_NAME') ? SENDGRID_FROM_NAME : 'F1 Betting';
    
    if ($lang === 'da') {
        $subject = "Nulstil din adgangskode - $appName";
        $greeting = "Hej $name,";
        $intro = "Du har anmodet om at nulstille din adgangskode til $appName.";
        $buttonText = "Nulstil adgangskode";
        $expiry = "Dette link udløber om 1 time.";
        $ignore = "Hvis du ikke har anmodet om dette, kan du ignorere denne email.";
        $footer = "Med venlig hilsen,<br>$appName";
    } else {
        $subject = "Reset your password - $appName";
        $greeting = "Hi $name,";
        $intro = "You requested to reset your password for $appName.";
        $buttonText = "Reset Password";
        $expiry = "This link expires in 1 hour.";
        $ignore = "If you didn't request this, you can ignore this email.";
        $footer = "Best regards,<br>$appName";
    }
    
    $htmlContent = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #1a1a1a;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" style="max-width: 500px; margin: 0 auto; background: #242424; border-radius: 16px; overflow: hidden;">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 30px 40px; text-align: center; background: linear-gradient(135deg, #e10600 0%, #b30500 100%);">
                            <h1 style="margin: 0; color: white; font-size: 24px; font-weight: 700;">🏎️ $appName</h1>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <p style="margin: 0 0 16px; color: #ffffff; font-size: 18px; font-weight: 600;">$greeting</p>
                            <p style="margin: 0 0 24px; color: #a0a0a0; font-size: 15px; line-height: 1.6;">$intro</p>
                            
                            <table role="presentation" style="width: 100%; margin: 30px 0;">
                                <tr>
                                    <td style="text-align: center;">
                                        <a href="$resetLink" style="display: inline-block; padding: 14px 32px; background: #e10600; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">$buttonText</a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="margin: 24px 0 8px; color: #808080; font-size: 13px;">$expiry</p>
                            <p style="margin: 0 0 24px; color: #606060; font-size: 13px;">$ignore</p>
                            
                            <hr style="border: none; border-top: 1px solid #333; margin: 24px 0;">
                            
                            <p style="margin: 0; color: #606060; font-size: 13px; line-height: 1.5;">$footer</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    $textContent = "$greeting\n\n$intro\n\n$buttonText: $resetLink\n\n$expiry\n\n$ignore";
    
    return sendEmailViaSendGrid($email, $subject, $htmlContent, $textContent);
}

/**
 * Send invitation email
 * 
 * @param string $email Modtager email
 * @param string $inviteLink Link til registrering
 * @param string $inviterName Navnet på den der inviterer
 * @param string $lang Sprog ('da' eller 'en')
 * @return array ['success' => bool, 'message' => string]
 */
function sendInviteEmail($email, $inviteLink, $inviterName, $lang = 'da') {
    $appName = defined('SENDGRID_FROM_NAME') ? SENDGRID_FROM_NAME : 'F1 Betting';
    
    if ($lang === 'da') {
        $subject = "Du er inviteret til $appName!";
        $greeting = "Hej!";
        $intro = "$inviterName har inviteret dig til at deltage i $appName.";
        $desc = "Forudsig top 3 for hvert F1 Grand Prix og konkurrér mod andre om point og stjerner!";
        $buttonText = "Opret din konto";
        $expiry = "Denne invitation udløber om 7 dage.";
        $footer = "Med venlig hilsen,<br>$appName";
    } else {
        $subject = "You're invited to $appName!";
        $greeting = "Hi!";
        $intro = "$inviterName has invited you to join $appName.";
        $desc = "Predict the top 3 for each F1 Grand Prix and compete against others for points and stars!";
        $buttonText = "Create your account";
        $expiry = "This invitation expires in 7 days.";
        $footer = "Best regards,<br>$appName";
    }
    
    $htmlContent = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #1a1a1a;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" style="max-width: 500px; margin: 0 auto; background: #242424; border-radius: 16px; overflow: hidden;">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 30px 40px; text-align: center; background: linear-gradient(135deg, #e10600 0%, #b30500 100%);">
                            <h1 style="margin: 0; color: white; font-size: 24px; font-weight: 700;">🏎️ $appName</h1>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <p style="margin: 0 0 16px; color: #ffffff; font-size: 18px; font-weight: 600;">$greeting</p>
                            <p style="margin: 0 0 16px; color: #a0a0a0; font-size: 15px; line-height: 1.6;">$intro</p>
                            <p style="margin: 0 0 24px; color: #a0a0a0; font-size: 15px; line-height: 1.6;">$desc</p>
                            
                            <table role="presentation" style="width: 100%; margin: 30px 0;">
                                <tr>
                                    <td style="text-align: center;">
                                        <a href="$inviteLink" style="display: inline-block; padding: 14px 32px; background: #e10600; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">$buttonText</a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="margin: 24px 0 8px; color: #808080; font-size: 13px;">$expiry</p>
                            
                            <hr style="border: none; border-top: 1px solid #333; margin: 24px 0;">
                            
                            <p style="margin: 0; color: #606060; font-size: 13px; line-height: 1.5;">$footer</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    $textContent = "$greeting\n\n$intro\n\n$desc\n\n$buttonText: $inviteLink\n\n$expiry";
    
    return sendEmailViaSendGrid($email, $subject, $htmlContent, $textContent);
}
