<?php
/**
 * SMTP mailer with Resend fallback.
 * Config constants: SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS,
 *                   SMTP_FROM_EMAIL, SMTP_FROM_NAME, RESEND_API_KEY.
 */

class SMTPMailer {
    private $host;
    private $port;
    private $user;
    private $pass;
    private $fromEmail;
    private $fromName;
    private $socket;
    private $debug = false;
    private $lastError = '';
    private $debugLog = [];
    private $lastHtmlBody = '';
    private $lastTextBody = '';

    public function __construct($host, $port, $user, $pass, $fromEmail, $fromName) {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
    }

    public function getLastError() {
        return $this->lastError;
    }
    
    public function getDebugLog() {
        return implode("\n", $this->debugLog);
    }
    
    private function log($message) {
        $this->debugLog[] = date('H:i:s') . " - " . $message;
    }

    public function send($to, $subject, $htmlBody, $textBody = null) {
        $this->lastError = '';
        $this->debugLog = [];
        $this->lastHtmlBody = $htmlBody;
        $this->lastTextBody = $textBody ?: strip_tags($htmlBody);
        
        // Create message with proper headers
        $boundary = md5(uniqid(time()));
        
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "From: {$this->fromName} <{$this->fromEmail}>\r\n";
        $headers .= "Reply-To: {$this->fromEmail}\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        
        // Build message body
        $message = "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= ($textBody ?: strip_tags($htmlBody)) . "\r\n\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $htmlBody . "\r\n\r\n";
        $message .= "--{$boundary}--\r\n";

        $this->log("Attempting SMTP to {$this->host}:{$this->port}");
        
        // Try SMTP first
        if ($this->sendViaSMTP($to, $subject, $message, $headers)) {
            $this->log("SMTP send successful");
            return true;
        }

        $this->log("SMTP failed: {$this->lastError}");
        $this->log("Falling back to Resend API");

        return $this->sendViaResend($to, $subject);
    }

    private function sendViaSMTP($to, $subject, $message, $headers) {
        try {
            // Connect to SMTP server
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                ]
            ]);

            $errno = 0;
            $errstr = '';
            
            $this->log("Connecting to {$this->host}:{$this->port}");
            
            // For port 465, use SSL directly. For 587/25, use TCP then upgrade to TLS
            if ($this->port == 465) {
                $this->socket = @stream_socket_client(
                    "ssl://{$this->host}:{$this->port}",
                    $errno, $errstr, 30,
                    STREAM_CLIENT_CONNECT,
                    $context
                );
            } else {
                $this->socket = @stream_socket_client(
                    "tcp://{$this->host}:{$this->port}",
                    $errno, $errstr, 30,
                    STREAM_CLIENT_CONNECT,
                    $context
                );
            }

            if (!$this->socket) {
                $this->lastError = "Could not connect to SMTP server: $errstr ($errno)";
                $this->log($this->lastError);
                return false;
            }

            $this->log("Connected successfully");

            // Read greeting
            $greeting = $this->getResponse();
            $this->log("Server greeting: " . trim($greeting));
            
            if (strpos($greeting, '220') === false) {
                $this->lastError = "Invalid server greeting: $greeting";
                fclose($this->socket);
                return false;
            }

            // Send EHLO
            $ehloHost = gethostname() ?: 'localhost';
            $response = $this->sendCommand("EHLO {$ehloHost}");
            $this->log("EHLO response: " . trim($response));
            
            // Start TLS if port 587 or 25 (not needed for 465 which is already SSL)
            if ($this->port == 587 || $this->port == 25) {
                $response = $this->sendCommand("STARTTLS");
                $this->log("STARTTLS response: " . trim($response));
                
                if (strpos($response, '220') === false) {
                    $this->lastError = "STARTTLS failed: $response";
                    fclose($this->socket);
                    return false;
                }
                
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                
                if (!@stream_socket_enable_crypto($this->socket, true, $cryptoMethod)) {
                    $this->lastError = "Could not enable TLS encryption";
                    $this->log($this->lastError);
                    fclose($this->socket);
                    return false;
                }
                
                $this->log("TLS enabled successfully");
                
                // Send EHLO again after TLS
                $response = $this->sendCommand("EHLO {$ehloHost}");
                $this->log("EHLO after TLS: " . trim($response));
            }

            // Authenticate
            $response = $this->sendCommand("AUTH LOGIN");
            $this->log("AUTH LOGIN response: " . trim($response));
            
            if (strpos($response, '334') === false) {
                $this->lastError = "AUTH LOGIN not accepted: $response";
                fclose($this->socket);
                return false;
            }
            
            $response = $this->sendCommand(base64_encode($this->user));
            $this->log("Username response: " . trim($response));
            
            if (strpos($response, '334') === false) {
                $this->lastError = "Username not accepted: $response";
                fclose($this->socket);
                return false;
            }
            
            $response = $this->sendCommand(base64_encode($this->pass));
            $this->log("Password response: " . trim($response));
            
            if (strpos($response, '235') === false) {
                $this->lastError = "SMTP Authentication failed: $response";
                fclose($this->socket);
                return false;
            }
            
            $this->log("Authentication successful");

            // Send email
            $response = $this->sendCommand("MAIL FROM:<{$this->fromEmail}>");
            $this->log("MAIL FROM response: " . trim($response));
            
            if (strpos($response, '250') === false) {
                $this->lastError = "MAIL FROM rejected: $response";
                fclose($this->socket);
                return false;
            }
            
            $response = $this->sendCommand("RCPT TO:<{$to}>");
            $this->log("RCPT TO response: " . trim($response));
            
            if (strpos($response, '250') === false) {
                $this->lastError = "RCPT TO rejected: $response";
                fclose($this->socket);
                return false;
            }
            
            $response = $this->sendCommand("DATA");
            $this->log("DATA response: " . trim($response));
            
            if (strpos($response, '354') === false) {
                $this->lastError = "DATA command rejected: $response";
                fclose($this->socket);
                return false;
            }

            // Send headers and body
            $data = "To: {$to}\r\n";
            $data .= "Subject: {$subject}\r\n";
            $data .= $headers;
            $data .= "\r\n";
            $data .= $message;
            $data .= "\r\n.";
            
            $response = $this->sendCommand($data);
            $this->log("Message send response: " . trim($response));

            // Quit
            $this->sendCommand("QUIT");
            fclose($this->socket);

            if (strpos($response, '250') !== false) {
                return true;
            }
            
            $this->lastError = "Message not accepted: $response";
            return false;

        } catch (Exception $e) {
            $this->lastError = "SMTP Error: " . $e->getMessage();
            $this->log($this->lastError);
            if ($this->socket) {
                fclose($this->socket);
            }
            return false;
        }
    }

    private function sendCommand($command) {
        fwrite($this->socket, $command . "\r\n");
        return $this->getResponse();
    }

    private function getResponse() {
        $response = '';
        stream_set_timeout($this->socket, 30);
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            // Check if this is the last line (4th char is space, not hyphen)
            if (isset($line[3]) && $line[3] == ' ') {
                break;
            }
        }
        return $response;
    }

    private function sendViaResend($to, $subject) {
        if (!defined('RESEND_API_KEY') || empty(RESEND_API_KEY)) {
            $this->lastError = 'Resend fallback not configured — RESEND_API_KEY is missing';
            $this->log($this->lastError);
            return false;
        }

        $payload = json_encode([
            'from'    => "{$this->fromName} <{$this->fromEmail}>",
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $this->lastHtmlBody,
            'text'    => $this->lastTextBody,
        ]);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . RESEND_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->lastError = "Resend curl error: {$curlError}";
            $this->log($this->lastError);
            return false;
        }

        if ($httpCode === 200 || $httpCode === 201) {
            $this->log("Resend fallback successful (HTTP {$httpCode})");
            return true;
        }

        $this->lastError = "Resend API error HTTP {$httpCode}: {$response}";
        $this->log($this->lastError);
        return false;
    }
}

/**
 * Convert SITE_URL to EMAIL_BASE_URL in links
 */
function convertToEmailUrl($link) {
    $emailBaseUrl = defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : '';
    $siteUrl = defined('SITE_URL') ? SITE_URL : '';
    
    if (!empty($siteUrl) && !empty($emailBaseUrl) && $siteUrl !== $emailBaseUrl && strpos($link, $siteUrl) === 0) {
        return str_replace($siteUrl, $emailBaseUrl, $link);
    }
    return $link;
}

// On an interception-capable environment (SMTP_INTERCEPT) real delivery is the DEFAULT.
// Interception is opt-in: it is active only while this flag file is present. E2E turns it
// on for the duration of a run (global-setup) and off at the end (global-teardown); the admin
// panel toggles it for manual capture. Toggled via test-seed smtp_intercept_on/off actions.
function smtpInterceptFlagPath(): string {
    return sys_get_temp_dir() . '/f1betting_smtp_intercept';
}

// True when this environment is capturing emails instead of delivering them
// (i.e. SMTP_INTERCEPT is on AND the intercept flag is present).
function emailIntercepted(): bool {
    return defined('SMTP_INTERCEPT') && SMTP_INTERCEPT && file_exists(smtpInterceptFlagPath());
}

/**
 * Send email using configured SMTP
 */
function sendEmail($to, $subject, $htmlContent, $textContent = null) {
    // In test mode, write to JSONL file instead of sending real email.
    if (emailIntercepted()) {
        $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '';
        $fromName  = defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : '';
        $entry = json_encode([
            'to'        => $to,
            'from'      => [['address' => $fromEmail, 'name' => $fromName]],
            'subject'   => $subject,
            'html'      => $htmlContent,
            'text'      => $textContent ?: strip_tags($htmlContent),
            'timestamp' => time(),
        ]) . "\n";
        $file = defined('EMAIL_INTERCEPT_FILE') ? EMAIL_INTERCEPT_FILE : (sys_get_temp_dir() . '/f1betting_test_emails.jsonl');
        file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
        if (defined('MAIL_LOG_FILE')) {
            logToFile(MAIL_LOG_FILE, '[INTERCEPTED] to=' . $to . ' subject="' . $subject . '"');
        }
        return ['success' => true, 'message' => 'intercepted'];
    }

    // Check if SMTP is configured
    if (!defined('SMTP_HOST') || empty(SMTP_HOST)) {
        return ['success' => false, 'message' => 'SMTP not configured'];
    }

    $mailer = new SMTPMailer(
        SMTP_HOST,
        defined('SMTP_PORT') ? SMTP_PORT : 587,
        SMTP_USER,
        SMTP_PASS,
        defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : SMTP_USER,
        defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting'
    );

    if ($mailer->send($to, $subject, $htmlContent, $textContent)) {
        if (defined('MAIL_LOG_FILE')) {
            logToFile(MAIL_LOG_FILE, '[SUCCESS] to=' . $to . ' subject="' . $subject . '"');
        }
        return ['success' => true, 'message' => 'Email sent successfully via SMTP'];
    }

    $error = $mailer->getLastError();
    if (defined('MAIL_LOG_FILE')) {
        logToFile(MAIL_LOG_FILE, '[FAIL] to=' . $to . ' subject="' . $subject . '" error="' . $error . '"');
    }
    return ['success' => false, 'message' => $error, 'debug' => $mailer->getDebugLog()];
}

/**
 * Send password reset email
 */
function sendPasswordResetEmail($email, $displayName, $resetLink, $lang = 'da') {
    $name = $displayName ?: $email;
    $appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
    
    // Convert link to use EMAIL_BASE_URL
    $resetLink = convertToEmailUrl($resetLink);
    
    $subject    = t('email_reset_subject', $lang);
    $greeting   = sprintf(t('email_reset_greeting', $lang), $name);
    $intro      = sprintf(t('email_reset_intro', $lang), $appName);
    $buttonText = t('email_reset_button', $lang);
    $expiry     = t('email_reset_expiry', $lang);
    $ignore     = t('email_reset_ignore', $lang);
    $footer     = sprintf(t('email_footer', $lang), $appName);
    
    $htmlContent = getEmailTemplate($greeting, $intro, $buttonText, $resetLink, $expiry, $ignore, $footer, $appName);
    $textContent = "$greeting\n\n$intro\n\n$buttonText: $resetLink\n\n$expiry\n\n$ignore";
    
    return sendEmail($email, $subject, $htmlContent, $textContent);
}

/**
 * Send invitation email
 */
function sendInviteEmail($email, $inviteLink, $inviterName, $lang = 'da') {
    $appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
    
    // Convert link to use EMAIL_BASE_URL
    $inviteLink = convertToEmailUrl($inviteLink);
    
    $subject    = sprintf(t('email_invite_subject', $lang), $appName);
    $greeting   = t('email_invite_greeting', $lang);
    $intro      = sprintf(t('email_invite_intro', $lang), $inviterName, $appName);
    $desc       = t('email_invite_desc', $lang);
    $buttonText = t('email_invite_button', $lang);
    $expiry     = t('email_invite_expiry', $lang);
    $footer     = sprintf(t('email_footer', $lang), $appName);
    
    $htmlContent = getEmailTemplate($greeting, "$intro<br><br>$desc", $buttonText, $inviteLink, $expiry, '', $footer, $appName);
    $textContent = "$greeting\n\n$intro\n\n$desc\n\n$buttonText: $inviteLink\n\n$expiry";
    
    return sendEmail($email, $subject, $htmlContent, $textContent);
}

/**
 * Build bet confirmation email content (bet placed or updated).
 * $driverNames = driver names in podium order [P1, P2, P3].
 * Returns ['subject' => ..., 'html' => ..., 'text' => ...] so the email
 * preview endpoint can render it without duplicating the layout.
 */
function buildBetConfirmationEmail($displayName, $email, $raceName, array $driverNames, $isUpdate = false, $lang = 'da') {
    $appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
    $domain  = parse_url(defined('SITE_URL') ? SITE_URL : '', PHP_URL_HOST) ?: '';
    $when    = date('d M Y') . ' - ' . date('H:i') . ' CET';

    $name       = $displayName ?: $email;
    $variant    = $isUpdate ? 'updated' : 'placed';
    $subject    = sprintf(t("email_bet_confirm_subject_{$variant}", $lang), $raceName);
    $greeting   = sprintf(t('email_bet_confirm_greeting', $lang), $name);
    $intro      = sprintf(t("email_bet_confirm_intro_{$variant}", $lang), htmlspecialchars($raceName));
    $picksLabel = t('email_bet_confirm_picks', $lang);
    $meta       = sprintf(t('email_bet_confirm_meta', $lang), $domain, $when);

    $picksHtml = $picksLabel;
    $picksText = $picksLabel;
    foreach (array_values($driverNames) as $i => $driverName) {
        $pos        = 'P' . ($i + 1);
        $picksHtml .= "<br>{$pos}: <strong>" . htmlspecialchars($driverName) . '</strong>';
        $picksText .= "\n{$pos}: {$driverName}";
    }

    $buttonText   = t('email_go_to_app', $lang);
    $emailBaseUrl = defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : (defined('SITE_URL') ? SITE_URL : '');
    $regards      = sprintf(t('email_regards', $lang), $appName);

    $html = getEmailTemplate($greeting, "$intro<br><br>$picksHtml", $buttonText, $emailBaseUrl, $meta, '', $regards, $appName);
    $text = $greeting . "\n\n" . strip_tags($intro) . "\n\n" . $picksText . "\n\n" . $meta;

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/**
 * Send bet confirmation email (bet placed or updated)
 */
function sendBetConfirmationEmail($email, $displayName, $raceName, array $driverNames, $isUpdate = false, $lang = 'da') {
    $mail = buildBetConfirmationEmail($displayName, $email, $raceName, $driverNames, $isUpdate, $lang);
    return sendEmail($email, $mail['subject'], $mail['html'], $mail['text']);
}

/**
 * Get HTML email template
 */
function getEmailTemplate($greeting, $intro, $buttonText, $buttonLink, $expiry, $ignore, $footer, $appName) {
    // Use EMAIL_BASE_URL for email assets and links (falls back to SITE_URL)
    $emailBaseUrl = defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : (defined('SITE_URL') ? SITE_URL : '');
    $logoUrl = $emailBaseUrl . '/assets/logo_header_dark.png';
    
    // Convert buttonLink to use EMAIL_BASE_URL if it uses SITE_URL
    $siteUrl = defined('SITE_URL') ? SITE_URL : '';
    if (!empty($siteUrl) && !empty($emailBaseUrl) && $siteUrl !== $emailBaseUrl && strpos($buttonLink, $siteUrl) === 0) {
        $buttonLink = str_replace($siteUrl, $emailBaseUrl, $buttonLink);
    }

    // Action area: a CTA link when a buttonLink is given, otherwise a read-only code box
    // (used by one-time codes like the login OTP, which have nothing to click).
    if ($buttonLink === '') {
        $actionBlock = <<<HTML
<div style="display: inline-block; padding: 16px 28px; background: #1a1a1a; border: 1px solid #333; border-radius: 8px; color: #ffffff; font-size: 30px; font-weight: 700; letter-spacing: 8px; font-family: 'Courier New', monospace;">{$buttonText}</div>
HTML;
    } else {
        $actionBlock = <<<HTML
<a href="{$buttonLink}" style="display: inline-block; padding: 14px 32px; background: #e10600; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">{$buttonText}</a>
HTML;
    }

    return <<<HTML
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
                    <tr>
                        <td style="padding: 30px 40px; text-align: center; background: #1a1a1a; border-bottom: 3px solid #e10600;">
                            <img src="{$logoUrl}" alt="{$appName}" style="height: 40px; width: auto; margin-bottom: 10px;">
                            <h1 style="margin: 0; color: white; font-size: 24px; font-weight: 700;">{$appName}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 40px;">
                            <p style="margin: 0 0 16px; color: #ffffff; font-size: 18px; font-weight: 600;">{$greeting}</p>
                            <p style="margin: 0 0 24px; color: #a0a0a0; font-size: 15px; line-height: 1.6;">{$intro}</p>
                            
                            <table role="presentation" style="width: 100%; margin: 30px 0;">
                                <tr>
                                    <td style="text-align: center;">
                                        {$actionBlock}
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="margin: 24px 0 8px; color: #808080; font-size: 13px;">{$expiry}</p>
                            <p style="margin: 0 0 24px; color: #606060; font-size: 13px;">{$ignore}</p>
                            
                            <hr style="border: none; border-top: 1px solid #333; margin: 24px 0;">
                            
                            <p style="margin: 0; color: #606060; font-size: 13px; line-height: 1.5;">{$footer}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}
