<?php
class Request {
    private static $instance = null;
    private $method;
    private $uri;
    private $path;
    private $queryParams;
    private $body;
    private $headers;
    private $ip;
    private $userAgent;
    private $startTime;
    private $routeParams;
    private $user;

    private function __construct() {
        $this->initialize();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initialize() {
        $this->startTime = microtime(true);
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        
        // Parse URI and query parameters
        $parsedUri = parse_url($this->uri);
        $this->path = $parsedUri['path'] ?? '/';
        $this->queryParams = $_GET;
        
        // Parse request body
        $this->parseBody();
        
        // Get headers
        $this->headers = $this->getAllHeaders();
        
        Logger::debug("Request initialized", [
            'method' => $this->method,
            'path' => $this->path,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent
        ]);
    }

    private function parseBody() {
        $input = file_get_contents('php://input');

        if (!empty($input)) {
            $this->body = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Logger::warning("Invalid JSON in request body", [
                    'json_error' => json_last_error_msg(),
                    'input_preview' => substr($input, 0, 500)
                ]);
                $this->body = [];
            }
        } else {
            $this->body = $_POST;
        }
    }

    private function getAllHeaders() {
        if (function_exists('apache_request_headers')) {
            return apache_request_headers();
        }
        
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) == 'HTTP_') {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$header] = $value;
            }
        }
        return $headers;
    }

    // Getters
    public function getMethod() { return $this->method; }
    public function getUri() { return $this->uri; }
    public function getPath() { return $this->path; }
    public function getIp() { return $this->ip; }
    public function getUserAgent() { return $this->userAgent; }
    public function getStartTime() { return $this->startTime; }

    public function getQuery($key = null, $default = null) {
        if ($key === null) return $this->queryParams;
        return $this->queryParams[$key] ?? $default;
    }

    public function getHeader($key, $default = null) {
        $key = strtolower($key);
        foreach ($this->headers as $headerKey => $value) {
            if (strtolower($headerKey) === $key) {
                return $value;
            }
        }
        return $default;
    }

    public function getBody($key = null, $default = null) {
        if ($key === null) return $this->body;
        
        if (is_array($this->body)) {
            return $this->body[$key] ?? $default;
        }
        
        return $default;
    }

    public function getBearerToken() {
        $authHeader = $this->getHeader('Authorization');
        if ($authHeader && preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function isJson() {
        return strpos($this->getHeader('Content-Type', ''), 'application/json') !== false;
    }

    public function isPost() { return $this->method === 'POST'; }
    public function isGet() { return $this->method === 'GET'; }
    public function isPut() { return $this->method === 'PUT'; }
    public function isDelete() { return $this->method === 'DELETE'; }

    public function setRouteParams(array $params) {
        $this->routeParams = $params;
    }

    public function getParam(string $key, $default = null) {
        return $this->routeParams[$key] ?? $default;
    }

    public function getExecutionTime() {
        return round((microtime(true) - $this->startTime) * 1000, 2);
    }

    public function setUser($user) {
        $this->user = $user;
    }

    public function getUser() {
        return $this->user;
    }
}
?>