<?php
class Environment {
    private static $loaded = false;
    private static $variables = [];

    public static function load($filePath = null) {
        if (self::$loaded) {
            return;
        }

        if ($filePath === null) {
            $filePath = __DIR__ . '/../.env';
        }

        // Load from .env file if it exists
        if (file_exists($filePath)) {
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) {
                    continue; // Skip comments
                }
                
                list($name, $value) = self::parseLine($line);
                if ($name !== null) {
                    self::$variables[$name] = $value;
                    // Also set in actual environment if not already set
                    if (getenv($name) === false) {
                        putenv("$name=$value");
                    }
                }
            }
        }

        self::$loaded = true;
    }

    public static function get($key, $default = null) {
        // First check PHP environment
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        // Then check our loaded variables
        if (isset(self::$variables[$key])) {
            return self::$variables[$key];
        }

        return $default;
    }

    private static function parseLine($line) {
        $line = trim($line);
        if (empty($line)) {
            return [null, null];
        }

        // Check if line contains =
        $equalsPos = strpos($line, '=');
        if ($equalsPos === false) {
            return [null, null];
        }

        $name = trim(substr($line, 0, $equalsPos));
        $value = trim(substr($line, $equalsPos + 1));

        // Remove quotes if present
        if (preg_match('/^"([\s\S]*)"$/', $value, $matches)) {
            $value = $matches[1];
        } elseif (preg_match('/^\'([\s\S]*)\'$/', $value, $matches)) {
            $value = $matches[1];
        }

        // Handle comments in value
        $commentPos = strpos($value, ' #');
        if ($commentPos !== false) {
            $value = trim(substr($value, 0, $commentPos));
        }

        return [$name, $value];
    }

    public static function isProduction() {
        return self::get('APP_ENV') === 'production';
    }

    public static function isDevelopment() {
        $env = self::get('APP_ENV');
        return $env === 'development' || $env === null || $env === '';
    }

    public static function getAll() {
        return self::$variables;
    }
    public static function getAllowedOrigins() {
        $origins = self::get('ALLOWED_ORIGINS', 'http://localhost');
        return array_map('trim', explode(',', $origins));
    }
}
?>