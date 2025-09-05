<?php
/**
 * FoxKit Secure Download System with 101 Download Limit
 * Designed for Cloudflare hosting with advanced security
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// Security configuration
define('MAX_DOWNLOADS', 101);
define('DOWNLOAD_LOG_FILE', __DIR__ . '/foxkit_downloads.json');
define('APK_FILE', __DIR__ . '/FoxKit-v1.1.0.apk');
define('RATE_LIMIT_WINDOW', 300); // 5 minutes
define('MAX_REQUESTS_PER_IP', 3);

class FoxKitSecureDownload {
    
    private $downloadStats;
    private $clientIP;
    private $userAgent;
    
    public function __construct() {
        $this->clientIP = $this->getClientIP();
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->loadDownloadStats();
    }
    
    public function getClientIP() {
        // Get real IP behind Cloudflare
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    private function loadDownloadStats() {
        if (file_exists(DOWNLOAD_LOG_FILE)) {
            $this->downloadStats = json_decode(file_get_contents(DOWNLOAD_LOG_FILE), true);
        } else {
            $this->downloadStats = [
                'total_downloads' => 0,
                'remaining_downloads' => MAX_DOWNLOADS,
                'download_log' => [],
                'rate_limit' => [],
                'started' => date('Y-m-d H:i:s'),
                'blocked_attempts' => 0
            ];
        }
    }
    
    private function saveDownloadStats() {
        file_put_contents(DOWNLOAD_LOG_FILE, json_encode($this->downloadStats, JSON_PRETTY_PRINT));
    }
    
    private function checkRateLimit() {
        $currentTime = time();
        $windowStart = $currentTime - RATE_LIMIT_WINDOW;
        
        // Clean old entries
        $this->downloadStats['rate_limit'] = array_filter(
            $this->downloadStats['rate_limit'],
            function($entry) use ($windowStart) {
                return $entry['timestamp'] > $windowStart;
            }
        );
        
        // Count requests from this IP
        $ipRequests = array_filter(
            $this->downloadStats['rate_limit'],
            function($entry) {
                return $entry['ip'] === $this->clientIP;
            }
        );
        
        if (count($ipRequests) >= MAX_REQUESTS_PER_IP) {
            $this->logSecurityEvent('rate_limit_exceeded');
            return false;
        }
        
        // Add current request
        $this->downloadStats['rate_limit'][] = [
            'ip' => $this->clientIP,
            'timestamp' => $currentTime,
            'user_agent' => substr($this->userAgent, 0, 200)
        ];
        
        return true;
    }
    
    private function isValidRequest() {
        // Check if downloads are exhausted
        if ($this->downloadStats['total_downloads'] >= MAX_DOWNLOADS) {
            $this->logSecurityEvent('downloads_exhausted');
            return false;
        }
        
        // Rate limiting
        if (!$this->checkRateLimit()) {
            return false;
        }
        
        // Basic bot detection
        if (empty($this->userAgent) || strlen($this->userAgent) < 20) {
            $this->logSecurityEvent('suspicious_user_agent');
            return false;
        }
        
        // Check for Android user agent (legitimate users)
        if (!preg_match('/Android|Mobile/', $this->userAgent)) {
            // Allow desktop for testing, but log it
            $this->logSecurityEvent('non_mobile_access', false);
        }
        
        return true;
    }
    
    private function logSecurityEvent($event, $block = true) {
        if ($block) {
            $this->downloadStats['blocked_attempts']++;
        }
        
        error_log(sprintf(
            "[FoxKit Security] %s - IP: %s - Event: %s - UA: %s",
            date('Y-m-d H:i:s'),
            $this->clientIP,
            $event,
            substr($this->userAgent, 0, 100)
        ));
        
        $this->saveDownloadStats();
    }
    
    public function handleDownloadRequest() {
        if (!$this->isValidRequest()) {
            http_response_code(429);
            return [
                'success' => false,
                'error' => 'Access denied',
                'remaining' => max(0, MAX_DOWNLOADS - $this->downloadStats['total_downloads'])
            ];
        }
        
        // Check if APK file exists
        if (!file_exists(APK_FILE)) {
            http_response_code(404);
            return [
                'success' => false,
                'error' => 'File not found'
            ];
        }
        
        // Log successful download
        $this->downloadStats['total_downloads']++;
        $this->downloadStats['remaining_downloads'] = MAX_DOWNLOADS - $this->downloadStats['total_downloads'];
        $this->downloadStats['download_log'][] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $this->clientIP,
            'user_agent' => substr($this->userAgent, 0, 200),
            'download_id' => $this->downloadStats['total_downloads']
        ];
        
        $this->saveDownloadStats();
        
        // Serve the file
        $this->serveAPK();
        
        return [
            'success' => true,
            'download_id' => $this->downloadStats['total_downloads'],
            'remaining' => $this->downloadStats['remaining_downloads']
        ];
    }
    
    private function serveAPK() {
        $fileSize = filesize(APK_FILE);
        
        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="FoxKit-v1.1.0-beta.apk"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Stream the file
        $handle = fopen(APK_FILE, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, 8192);
                ob_flush();
                flush();
            }
            fclose($handle);
        }
        
        exit;
    }
    
    public function getStats() {
        return [
            'total_downloads' => $this->downloadStats['total_downloads'],
            'remaining_downloads' => $this->downloadStats['remaining_downloads'],
            'percentage_used' => round(($this->downloadStats['total_downloads'] / MAX_DOWNLOADS) * 100, 1),
            'started' => $this->downloadStats['started'],
            'blocked_attempts' => $this->downloadStats['blocked_attempts']
        ];
    }
}

// Handle requests
$action = $_GET['action'] ?? 'download';
$downloader = new FoxKitSecureDownload();

switch ($action) {
    case 'download':
        $result = $downloader->handleDownloadRequest();
        if (!$result['success']) {
            echo json_encode($result);
        }
        break;
        
    case 'stats':
        // Only show stats to admin (add your IP here)
        $adminIPs = ['YOUR_ADMIN_IP_HERE', '127.0.0.1'];
        if (in_array($downloader->getClientIP(), $adminIPs)) {
            echo json_encode($downloader->getStats());
        } else {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
        }
        break;
        
    case 'check':
        echo json_encode([
            'downloads_available' => $downloader->getStats()['remaining_downloads'] > 0,
            'remaining' => $downloader->getStats()['remaining_downloads']
        ]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
?>