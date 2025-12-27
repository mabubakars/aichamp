<?php
require_once __DIR__ . '/../config/Environment.php';
require_once __DIR__ . '/../core/Logger.php';

class JWT {
    private static $algorithm = 'HS256';
    private static $initialized = false;
    private static $secretKey = null;
    private static $instance = null;

    // Private constructor to prevent multiple instances
    private function __construct() {}

    private static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::initialize();
        }
        return self::$instance;
    }

    private static function initialize() {
        if (self::$initialized) {
            return;
        }

        Environment::load();
        self::$secretKey = Environment::get('JWT_SECRET');
        
        if (empty(self::$secretKey)) {
            throw new RuntimeException("JWT secret key not configured");
        }
        
        if (strlen(self::$secretKey) < 32) {
            throw new RuntimeException("JWT secret key too short. Minimum 32 characters required.");
        }
        
        self::$initialized = true;
        
        Logger::debug("JWT class initialized", [
            'secret_length' => strlen(self::$secretKey),
            'class_file' => __FILE__
        ]);
    }

    public static function debugClassLocation() {
        $reflector = new ReflectionClass('JWT');
        return [
            'file' => $reflector->getFileName(),
            'methods' => get_class_methods('JWT')
        ];
    }

    public static function encode($payload) {
        return self::getInstance()->encodePayload($payload);
    }

    public static function encodePayload($payload) {
        self::initialize();
        
        // Create a clean copy of the payload to prevent modification
        $cleanPayload = [];
        foreach ($payload as $key => $value) {
            $cleanPayload[$key] = $value;
        }
        
        $header = [
            'typ' => 'JWT',
            'alg' => self::$algorithm
        ];

        // Set expiration time - ensure it's consistent
        $currentTime = time();
        $expirationTime = $currentTime + (60 * 60 * 24); // 24 hours
        
        // Add standard claims
        $cleanPayload['iss'] = Environment::get('APP_URL', 'http://localhost');
        $cleanPayload['iat'] = $currentTime;
        $cleanPayload['exp'] = $expirationTime;
        $cleanPayload['jti'] = bin2hex(random_bytes(16));

        $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES);
        $payloadJson = json_encode($cleanPayload, JSON_UNESCAPED_SLASHES);

        // Log the exact payload being encoded
        Logger::debug("JWT encoding payload", [
            'payload' => $cleanPayload,
            'payload_json' => $payloadJson,
            'current_time' => $currentTime,
            'expiration_time' => $expirationTime
        ]);

        $base64UrlHeader = self::base64UrlEncode($headerJson);
        $base64UrlPayload = self::base64UrlEncode($payloadJson);

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::$secretKey, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

        Logger::info("JWT token created successfully", [
            'user_id' => $cleanPayload['user_id'] ?? 'unknown',
            'email' => $cleanPayload['email'] ?? 'unknown',
            'issued_at' => $cleanPayload['iat'],
            'expires_at' => $cleanPayload['exp'],
            'time_remaining' => $cleanPayload['exp'] - $currentTime . ' seconds',
            'token_length' => strlen($jwt)
        ]);

        return $jwt;
    }

    public static function decode($jwt) {
        self::initialize();
        
        try {
            // Validate token structure first
            if (!self::validateStructure($jwt)) {
                throw new Exception("Invalid token structure");
            }

            $tokenParts = explode('.', $jwt);
            if (count($tokenParts) !== 3) {
                throw new Exception("Invalid token parts count: " . count($tokenParts));
            }

            list($base64UrlHeader, $base64UrlPayload, $signatureProvided) = $tokenParts;

            // Verify signature FIRST
            $verificationSignature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::$secretKey, true);
            $base64UrlSignature = self::base64UrlEncode($verificationSignature);

            if (!hash_equals($base64UrlSignature, $signatureProvided)) {
                throw new Exception("Invalid token signature");
            }

            // Decode header
            $headerJson = self::base64UrlDecode($base64UrlHeader);
            $header = json_decode($headerJson, true);
            
            if (!$header || !isset($header['alg']) || $header['alg'] !== 'HS256') {
                throw new Exception("Invalid token algorithm");
            }

            // Decode payload
            $payloadJson = self::base64UrlDecode($base64UrlPayload);
            $payload = json_decode($payloadJson, true);
            
            if (!$payload) {
                throw new Exception("Invalid token payload");
            }

            $currentTime = time();
            
            // Log the exact payload being decoded
            Logger::debug("JWT decoding payload", [
                'payload' => $payload,
                'payload_json' => $payloadJson,
                'current_time' => $currentTime,
                'header' => $header
            ]);

            // Validate expiration
            if (!isset($payload['exp'])) {
                throw new Exception("Token missing expiration claim");
            }

            if ($payload['exp'] < $currentTime) {
                $timeExpired = $currentTime - $payload['exp'];
                Logger::warning("JWT token expired", [
                    'user_id' => $payload['user_id'] ?? 'unknown',
                    'email' => $payload['email'] ?? 'unknown',
                    'token_exp' => $payload['exp'],
                    'current_time' => $currentTime,
                    'expired_since_seconds' => $timeExpired,
                    'token_issued_at' => $payload['iat'] ?? 'unknown'
                ]);
                throw new Exception("Token expired");
            }

            // Validate issuer
            $expectedIssuer = Environment::get('APP_URL', 'http://localhost');
            if (isset($payload['iss']) && $payload['iss'] !== $expectedIssuer) {
                throw new Exception("Invalid token issuer");
            }

            Logger::info("JWT token validated successfully", [
                'user_id' => $payload['user_id'] ?? 'unknown',
                'email' => $payload['email'] ?? 'unknown',
                'expires_in_seconds' => $payload['exp'] - $currentTime
            ]);

            return $payload;

        } catch (Exception $e) {
            Logger::error("JWT token validation failed", [
                'error' => $e->getMessage(),
                'token_preview' => substr($jwt, 0, 30) . '...'
            ]);
            return false;
        }
    }

    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }

    public static function validateStructure($jwt) {
        $parts = explode('.', $jwt);
        return count($parts) === 3;
    }

    public static function inspect($jwt) {
        try {
            $tokenParts = explode('.', $jwt);
            if (count($tokenParts) !== 3) {
                return ['error' => 'Invalid token structure'];
            }

            $headerJson = self::base64UrlDecode($tokenParts[0]);
            $payloadJson = self::base64UrlDecode($tokenParts[1]);
            
            $header = json_decode($headerJson, true);
            $payload = json_decode($payloadJson, true);
            
            return [
                'header' => $header,
                'payload' => $payload,
                'valid_until' => isset($payload['exp']) ? date('Y-m-d H:i:s', $payload['exp']) : 'unknown',
                'seconds_remaining' => isset($payload['exp']) ? $payload['exp'] - time() : 'unknown',
                'token_created_at' => isset($payload['iat']) ? date('Y-m-d H:i:s', $payload['iat']) : 'unknown'
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // Debug method to check which JWT class is being used
    public static function debugInfo() {
        return [
            'class_file' => __FILE__,
            'secret_configured' => !empty(self::$secretKey),
            'initialized' => self::$initialized,
            'memory_usage' => memory_get_usage(true)
        ];
    }
}
?>