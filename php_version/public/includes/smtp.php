<?php
/**
 * SMTP Email Class for F1 Betting
 * Works with simply.com and other SMTP providers
 * 
 * Konfiguration i config.php:
 *   define('SMTP_HOST', 'websmtp.simply.com');  // eller 'asmtp.unoeuro.com'
 *   define('SMTP_PORT', 587);
 *   define('SMTP_USER', 'din@email.dk');
 *   define('SMTP_PASS', 'dit_password');
 *   define('SMTP_FROM_EMAIL', 'noreply@dit-domæne.dk');
 *   define('SMTP_FROM_NAME', 'F1 Betting');
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
        $this->log("Falling back to PHP mail()");
        
        // Fallback to PHP mail()
        return $this->sendViaMail($to, $subject, $message, $headers);
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

    private function sendViaMail($to, $subject, $message, $headers) {
        if (@mail($to, $subject, $message, $headers)) {
            return true;
        }
        $this->lastError = "PHP mail() function failed";
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

/**
 * Send email using configured SMTP
 */
function sendEmail($to, $subject, $htmlContent, $textContent = null) {
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
        return ['success' => true, 'message' => 'Email sent successfully via SMTP'];
    }

    return ['success' => false, 'message' => $mailer->getLastError(), 'debug' => $mailer->getDebugLog()];
}

/**
 * Send password reset email
 */
function sendPasswordResetEmail($email, $displayName, $resetLink, $lang = 'da') {
    $name = $displayName ?: $email;
    $appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
    
    // Convert link to use EMAIL_BASE_URL
    $resetLink = convertToEmailUrl($resetLink);
    
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
    
    $htmlContent = getEmailTemplate($greeting, "$intro<br><br>$desc", $buttonText, $inviteLink, $expiry, '', $footer, $appName);
    $textContent = "$greeting\n\n$intro\n\n$desc\n\n$buttonText: $inviteLink\n\n$expiry";
    
    return sendEmail($email, $subject, $htmlContent, $textContent);
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
                                        <a href="{$buttonLink}" style="display: inline-block; padding: 14px 32px; background: #e10600; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">{$buttonText}</a>
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
