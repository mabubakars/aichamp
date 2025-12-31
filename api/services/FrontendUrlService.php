<?php
class FrontendUrlService {
    private $baseUrl;
    private $routes;

    public function __construct() {
        $this->baseUrl = getenv('APP_URL') ?? 'http://localhost';

        // Define configurable routes
        $this->routes = [
            'verify_email' => getenv('FRONTEND_VERIFY_EMAIL_PATH') ?: '/verify-email',
            'reset_password' => getenv('FRONTEND_RESET_PASSWORD_PATH') ?: '/reset-password',
            'login' => getenv('FRONTEND_LOGIN_PATH') ?: '/login',
            'register' => getenv('FRONTEND_REGISTER_PATH') ?: '/register',
            'profile' => getenv('FRONTEND_PROFILE_PATH') ?: '/profile',
            'dashboard' => getenv('FRONTEND_DASHBOARD_PATH') ?: '/dashboard',
            'settings' => getenv('FRONTEND_SETTINGS_PATH') ?: '/settings',
        ];

        // Log initialization
        Logger::debug("FrontendUrlService initialized", [
            'base_url' => $this->baseUrl,
            'routes_configured' => count($this->routes)
        ]);
    }

    /**
     * Generate email verification URL
     */
    public function generateEmailVerificationUrl($token, $params = []) {
        $path = $this->routes['verify_email'];
        $queryParams = array_merge(['token' => $token], $params);
        return $this->buildUrl($path, $queryParams);
    }

    /**
     * Generate password reset URL
     */
    public function generatePasswordResetUrl($token, $params = []) {
        $path = $this->routes['reset_password'];
        $queryParams = array_merge(['token' => $token], $params);
        return $this->buildUrl($path, $queryParams);
    }

    /**
     * Generate login URL
     */
    public function generateLoginUrl($params = []) {
        $path = $this->routes['login'];
        return $this->buildUrl($path, $params);
    }

    /**
     * Generate registration URL
     */
    public function generateRegisterUrl($params = []) {
        $path = $this->routes['register'];
        return $this->buildUrl($path, $params);
    }

    /**
     * Generate profile URL
     */
    public function generateProfileUrl($userId = null, $params = []) {
        $path = $this->routes['profile'];
        if ($userId) {
            $path .= '/' . $userId;
        }
        return $this->buildUrl($path, $params);
    }

    /**
     * Generate dashboard URL
     */
    public function generateDashboardUrl($params = []) {
        $path = $this->routes['dashboard'];
        return $this->buildUrl($path, $params);
    }

    /**
     * Generate settings URL
     */
    public function generateSettingsUrl($params = []) {
        $path = $this->routes['settings'];
        return $this->buildUrl($path, $params);
    }

    /**
     * Generate custom URL with path and parameters
     */
    public function generateCustomUrl($path, $params = []) {
        return $this->buildUrl($path, $params);
    }

    /**
     * Get the base frontend URL
     */
    public function getBaseUrl() {
        return $this->baseUrl;
    }

    /**
     * Set a custom route path
     */
    public function setRoute($routeName, $path) {
        $this->routes[$routeName] = $path;
        Logger::debug("Custom route set", [
            'route' => $routeName,
            'path' => $path
        ]);
    }

    /**
     * Get all configured routes
     */
    public function getRoutes() {
        return $this->routes;
    }

    /**
     * Build URL with path and query parameters
     */
    private function buildUrl($path, $params = []) {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        if (!empty($params)) {
            $queryString = http_build_query($params);
            $url .= '?' . $queryString;
        }

        return $url;
    }

    /**
     * Validate URL format
     */
    public function validateUrl($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Generate absolute URL from relative path
     */
    public function makeAbsolute($relativePath) {
        if (filter_var($relativePath, FILTER_VALIDATE_URL)) {
            return $relativePath; // Already absolute
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($relativePath, '/');
    }

    /**
     * Generate API endpoint URL (for internal API calls)
     */
    public function generateApiUrl($endpoint, $params = []) {
        $apiBase = getenv('APP_URL') ?? 'http://localhost';
        $url = rtrim($apiBase, '/') . '/api/' . ltrim($endpoint, '/');

        if (!empty($params)) {
            $queryString = http_build_query($params);
            $url .= '?' . $queryString;
        }

        return $url;
    }

    /**
     * Generate URL with UTM parameters for tracking
     */
    public function generateTrackedUrl($path, $params = [], $utmSource = 'email', $utmMedium = 'link') {
        $trackingParams = [
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => getenv('APP_NAME') ?: 'scholarcompare'
        ];

        $allParams = array_merge($params, $trackingParams);
        return $this->buildUrl($path, $allParams);
    }
}
?>