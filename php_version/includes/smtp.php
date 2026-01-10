<?php
/**
 * SMTP Email Class for F1 Betting
 * Works with simply.com and other SMTP providers
 * 
 * Konfiguration i config.php:
 *   define('SMTP_HOST', 'mail.simply.com');
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

    public function send($to, $subject, $htmlBody, $textBody = null) {
        $this->lastError = '';
        
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

        // Try SMTP first
        if ($this->sendViaSMTP($to, $subject, $message, $headers)) {
            return true;
        }

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
                    'allow_self_signed' => true
                ]
            ]);

            $errno = 0;
            $errstr = '';
            
            // Try TLS connection first (port 587)
            if ($this->port == 587) {
                $this->socket = @stream_socket_client(
                    "tcp://{$this->host}:{$this->port}",
                    $errno, $errstr, 30,
                    STREAM_CLIENT_CONNECT,
                    $context
                );
            } else {
                // For port 465, use SSL directly
                $this->socket = @stream_socket_client(
                    "ssl://{$this->host}:{$this->port}",
                    $errno, $errstr, 30,
                    STREAM_CLIENT_CONNECT,
                    $context
                );
            }

            if (!$this->socket) {
                $this->lastError = "Could not connect to SMTP server: $errstr ($errno)";
                return false;
            }

            // Read greeting
            $this->getResponse();

            // Send EHLO
            $this->sendCommand("EHLO " . gethostname());
            
            // Start TLS if port 587
            if ($this->port == 587) {
                $this->sendCommand("STARTTLS");
                if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $this->lastError = "Could not enable TLS";
                    fclose($this->socket);
                    return false;
                }
                // Send EHLO again after TLS
                $this->sendCommand("EHLO " . gethostname());
            }

            // Authenticate
            $this->sendCommand("AUTH LOGIN");
            $this->sendCommand(base64_encode($this->user));
            $response = $this->sendCommand(base64_encode($this->pass));
            
            if (strpos($response, '235') === false && strpos($response, '334') === false) {
                $this->lastError = "SMTP Authentication failed: $response";
                fclose($this->socket);
                return false;
            }

            // Send email
            $this->sendCommand("MAIL FROM:<{$this->fromEmail}>");
            $this->sendCommand("RCPT TO:<{$to}>");
            $this->sendCommand("DATA");

            // Send headers and body
            $data = "To: {$to}\r\n";
            $data .= "Subject: {$subject}\r\n";
            $data .= $headers;
            $data .= "\r\n";
            $data .= $message;
            $data .= "\r\n.";
            
            $response = $this->sendCommand($data);

            // Quit
            $this->sendCommand("QUIT");
            fclose($this->socket);

            return (strpos($response, '250') !== false || strpos($response, '354') !== false);

        } catch (Exception $e) {
            $this->lastError = "SMTP Error: " . $e->getMessage();
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
        stream_set_timeout($this->socket, 10);
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if (substr($line, 3, 1) == ' ') {
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
        return ['success' => true, 'message' => 'Email sent successfully'];
    }

    return ['success' => false, 'message' => $mailer->getLastError()];
}

/**
 * Send password reset email
 */
function sendPasswordResetEmail($email, $displayName, $resetLink, $lang = 'da') {
    $name = $displayName ?: $email;
    $appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
    
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
                        <td style="padding: 30px 40px; text-align: center; background: linear-gradient(135deg, #e10600 0%, #b30500 100%);">
                            <h1 style="margin: 0; color: white; font-size: 24px; font-weight: 700;">🏎️ {$appName}</h1>
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
