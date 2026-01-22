<?php

final class AuthenticatedClient
{
    private string $cookieFile;

    public function __construct(
        private string $baseUrl,
        string $username,
        string $password
    ) {
        // Ensure we use the WWW version if that's where the session is tied
        if (!str_contains($baseUrl, 'www.')) {
            $baseUrl = str_replace('https://', 'https://www.', $baseUrl);
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        
        // Local cookie storage
        $this->cookieFile = __DIR__ . '/curl_cookies.txt';
        
        // Reset cookie file
        file_put_contents($this->cookieFile, "");
        chmod($this->cookieFile, 0666);

        $this->login($username, $password);
    }

    private function login(string $username, string $password): void
    {
        $loginUrl = $this->baseUrl . '/login.php';
        
        // --- STEP 1: GET TOKEN & SESSION ---
        $ch = curl_init($loginUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $html = curl_exec($ch);

        // Scrape token
        if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $matches)) {
            throw new RuntimeException("Could not find csrf_token on login page.");
        }
        $token = $matches[1];
        
        // Debug output for terminal
        echo "DEBUG: Token found: " . substr($token, 0, 10) . "...\n";

        // --- STEP 2: SUBMIT LOGIN ---
        $postData = [
            'email'      => $username,
            'password'   => $password,
            'csrf_token' => $token,
            'submit'     => 'Log ind'
        ];

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_REFERER, $loginUrl);
        
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        

        // Save for visual check
        file_put_contents(__DIR__ . '/login_debug.html', $response);

        // --- STEP 3: CHECK SUCCESS ---
        $finalUrl = $info['url'];
        $isStillOnLogin = str_contains($finalUrl, 'login.php') && str_contains($response, 'name="password"');

        if ($isStillOnLogin) {
            $size = file_exists($this->cookieFile) ? filesize($this->cookieFile) : 0;
            throw new RuntimeException(
                "Login Rejected.\n" .
                "URL: $finalUrl\n" .
                "Cookie Jar: $size bytes\n" .
                "Check login_debug.html for the site error message."
            );
        }
        
        echo "DEBUG: Login successful! Redirected to: $finalUrl\n";
    }

    public function get(string $path): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => $this->cookieFile, // Read cookies
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        return [$status, $body];
    }

    public function __destruct()
    {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }
}