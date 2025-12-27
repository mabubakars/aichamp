<?php
class ErrorHandler {
    private static $registered = false;

    public static function register() {
        if (self::$registered) {
            return;
        }

        Environment::load();
        Logger::initialize();
        
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
        
        self::$registered = true;
    }

    public static function handleError($level, $message, $file = '', $line = 0) {
        if (error_reporting() & $level) {
            $errorTypes = [
                E_ERROR => 'E_ERROR',
                E_WARNING => 'E_WARNING',
                E_PARSE => 'E_PARSE',
                E_NOTICE => 'E_NOTICE',
                E_CORE_ERROR => 'E_CORE_ERROR',
                E_CORE_WARNING => 'E_CORE_WARNING',
                E_COMPILE_ERROR => 'E_COMPILE_ERROR',
                E_COMPILE_WARNING => 'E_COMPILE_WARNING',
                E_USER_ERROR => 'E_USER_ERROR',
                E_USER_WARNING => 'E_USER_WARNING',
                E_USER_NOTICE => 'E_USER_NOTICE',
                E_STRICT => 'E_STRICT',
                E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
                E_DEPRECATED => 'E_DEPRECATED',
                E_USER_DEPRECATED => 'E_USER_DEPRECATED'
            ];

            $errorType = $errorTypes[$level] ?? 'E_UNKNOWN';
            
            Logger::error("PHP Error: {$errorType} - {$message}", [
                'file' => $file,
                'line' => $line,
                'level' => $level
            ]);

            throw new ErrorException($message, 0, $level, $file, $line);
        }
    }

    public static function handleException($exception) {
        $code = $exception->getCode();
        if ($code < 100 || $code > 599) {
            $code = 500;
        }
        http_response_code($code);
        
        // Log the exception with full details
        Logger::critical("Uncaught Exception: " . $exception->getMessage(), [
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
            'trace' => $exception->getTraceAsString(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ]);

        // Prepare response based on environment
        $response = [
            "message" => "An error occurred",
            "error_code" => "INTERNAL_ERROR",
            "request_id" => self::generateRequestId()
        ];

        if (Environment::isDevelopment()) {
            $response = [
                "message" => $exception->getMessage(),
                "type" => get_class($exception),
                "file" => $exception->getFile(),
                "line" => $exception->getLine(),
                "trace" => Environment::get('APP_DEBUG') === 'true' ? $exception->getTrace() : null,
                "request_id" => self::generateRequestId()
            ];
        }

        if (!defined('TESTING') || !TESTING) {
            header('Content-Type: application/json');
            echo json_encode($response);
        } else {
            // In testing, log to stderr instead
            file_put_contents('php://stderr', json_encode($response) . PHP_EOL);
        }
    }

    public static function handleShutdown() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $exception = new ErrorException(
                $error['message'], 0, $error['type'], $error['file'], $error['line']
            );
            self::handleException($exception);
        }
    }

    private static function generateRequestId() {
        return bin2hex(random_bytes(8));
    }
}
?>