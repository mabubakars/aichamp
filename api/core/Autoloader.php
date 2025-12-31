<?php
class Autoloader {
    private static $initialized = false;
    private static $loadedClasses = [];
    private static $directories = [
        'controllers/',
        'models/',
        'middleware/',
        'services/',
        'utils/',
        'core/',
        'config/'
    ];

    public static function register() {
        if (self::$initialized) {
            return;
        }

        spl_autoload_register([self::class, 'loadClass']);
        self::$initialized = true;
        
        // Pre-load critical classes to prevent circular dependencies
        self::preloadCriticalClasses();
    }

    private static function preloadCriticalClasses() {
        $criticalClasses = [
            'Environment' => 'config/Environment.php',
            'Logger' => 'core/Logger.php',
            'Request' => 'core/Request.php',
            'Response' => 'core/Response.php',
            'JWT' => 'utils/JWT.php',
            'Database' => 'config/Database.php',
            'ErrorHandler' => 'core/ErrorHandler.php',
            'User' => 'models/User.php'
        ];

        foreach ($criticalClasses as $className => $filePath) {
            $fullPath = __DIR__ . '/../' . $filePath;
            if (file_exists($fullPath) && !class_exists($className, false)) {
                require_once $fullPath;
                self::$loadedClasses[$className] = $fullPath;
                
                if (Environment::isDevelopment()) {
                    Logger::debug("Pre-loaded critical class: {$className}");
                }
            }
        }
    }

    public static function loadClass($className) {
        // Skip if already loaded
        if (isset(self::$loadedClasses[$className]) || class_exists($className, false)) {
            return true;
        }

        foreach (self::$directories as $directory) {
            $file = __DIR__ . '/../' . $directory . $className . '.php';
            if (file_exists($file)) {
                require_once $file;
                self::$loadedClasses[$className] = $file;
                
                // Log in development only
                if (Environment::isDevelopment() && !in_array($className, ['Logger', 'Environment', 'Request', 'Response'])) {
                    Logger::debug("Autoloaded class: {$className}", ['file' => $file]);
                }
                return true;
            }
        }

        // Log missing classes in development
        if (Environment::isDevelopment()) {
            Logger::warning("Class not found: {$className}", [
                'searched_directories' => self::$directories
            ]);
        }

        return false;
    }

    public static function getLoadedClasses() {
        return array_keys(self::$loadedClasses);
    }

    public static function forceLoad($className) {
        return self::loadClass($className);
    }

    public static function isLoaded($className) {
        return isset(self::$loadedClasses[$className]) || class_exists($className, false);
    }
}
?>