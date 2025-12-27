<?php
class AuthMiddleware {
    public static function authenticate($request = null, $response = null) {
        if ($request === null) {
            $request = Request::getInstance();
        }
        if ($response === null) {
            $response = Response::getInstance();
        }

        $token = $request->getBearerToken();

        if (!$token) {
            $response->unauthorized("Access denied. Token required.")->send();
            return false;
        }

        $decoded = JWT::decode($token);
        if (!$decoded) {
            $response->unauthorized("Invalid or expired token.")->send();
            return false;
        }

        // Set authenticated user in request
        $request->setUser($decoded);
        return true;
    }
}
?>