<?php
/**
 * F1Intelligence - PHP Client for RAG API
 * 
 * Makes HTTP requests to the F1 Intelligence RAG API hosted on Vercel.
 * No Node.js or special dependencies required - works on simply.com.
 * 
 * Usage:
 *   require_once __DIR__ . '/F1Intelligence.php';
 *   $intel = new F1Intelligence('https://your-app.vercel.app');
 *   $result = $intel->query("How does Verstappen perform at Monaco?");
 *   echo $result['answer'];
 */

class F1Intelligence {
    private string $apiUrl;
    private int $timeout;
    private bool $debug;
    
    /**
     * Initialize the F1 Intelligence client
     * 
     * @param string $apiUrl Base URL of the RAG API (e.g., https://your-app.vercel.app)
     * @param int $timeout Request timeout in seconds
     * @param bool $debug Enable debug logging via error_log()
     */
    public function __construct(
        string $apiUrl,
        int $timeout = 30,
        bool $debug = false
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->timeout = $timeout;
        $this->debug = $debug;
    }
    
    /**
     * Query the F1 Intelligence RAG system
     * 
     * @param string $question The user's question about F1 racing
     * @return array{answer: string, sources: array}|null Returns answer and sources, or null on error
     */
    public function query(string $question): ?array {
        if (empty($question)) {
            $this->log('Error: Empty question provided');
            return null;
        }
        
        $this->log("Querying: $question");
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl . '/api/intelligence',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['question' => $question]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);
        
        if ($curlError) {
            $this->log("cURL error: $curlError");
            return null;
        }
        
        if ($httpCode !== 200) {
            $this->log("API error: HTTP $httpCode");
            $this->log("Response: $response");
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['answer'])) {
            $this->log("Invalid response format");
            return null;
        }
        
        $this->log("Success: Answer received (" . strlen($data['answer']) . " chars)");
        
        return $data;
    }
    
    /**
     * Check if the API is available and responding
     * 
     * @return bool True if API is reachable
     */
    public function healthCheck(): bool {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl . '/api/intelligence',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_NOBODY => true,
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);
        
        // 405 means endpoint exists but only accepts POST - this is OK
        $isHealthy = in_array($httpCode, [200, 405]);
        $this->log("Health check: " . ($isHealthy ? 'OK' : 'FAILED'));
        
        return $isHealthy;
    }
    
    /**
     * Get the configured API URL
     */
    public function getApiUrl(): string {
        return $this->apiUrl;
    }
    
    /**
     * Log debug messages via error_log()
     */
    private function log(string $message): void {
        if ($this->debug) {
            error_log("[F1Intelligence] $message");
        }
    }
}
