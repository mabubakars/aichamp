<?php
class EmailVerificationService {
    private $db;
    private $userModel;
    private $emailService;
    private $table_name = 'email_verifications';

    public function __construct($db) {
        $this->db = $db;
        $this->userModel = new User($db);
        $this->emailService = new EmailService();
    }

    /**
     * Request email verification for a user
     */
    public function requestVerification($userId) {
        $user = $this->userModel->getById($userId);

        if (!$user) {
            throw new InvalidArgumentException("User not found");
        }

        if ($user['email_verified']) {
            throw new InvalidArgumentException("Email already verified");
        }

        try {
            // Generate secure token
            $verificationToken = $this->generateSecureToken();
            $tokenHash = $this->hashToken($verificationToken);

            // Calculate expiration (24 hours from now)
            $expiryHours = getenv('EMAIL_VERIFICATION_TOKEN_EXPIRY') ?: 24;
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryHours} hours"));

            // Invalidate any existing active tokens for this user
            $this->invalidateExistingTokens($userId);

            // Store token in database
            $tokenData = [
                'id' => $this->generateUUID(),
                'user_id' => $userId,
                'email' => $user['email'],
                'token_hash' => $tokenHash,
                'token_type' => 'verification',
                'expires_at' => $expiresAt
            ];

            $this->db->create($this->table_name, $tokenData);

            // Send verification email
            $emailSent = $this->emailService->sendVerificationEmail(
                $user['email'],
                $verificationToken,
                $user['first_name'] . ' ' . $user['last_name'],
                $user['email']
            );

            if (!$emailSent) {
                Logger::warning("Email sending failed but token stored", [
                    'user_id' => $userId,
                    'email' => $user['email']
                ]);
                // Don't throw error, token is stored and user can retry
            }

            Logger::info("Email verification requested", [
                'user_id' => $user['id'],
                'email' => $user['email']
            ]);

            return [
                'success' => true,
                'message' => 'Verification email sent successfully',
                'email_sent' => $emailSent
            ];

        } catch (Exception $e) {
            Logger::error("Email verification request failed", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Verify user email with token
     */
    public function verifyEmail($token) {
        try {
            $tokenHash = $this->hashToken($token);

            // Find active token
            $tokenRecord = $this->db->readOne($this->table_name, [
                'token_hash' => $tokenHash,
                'used_at' => null
            ]);

            if (!$tokenRecord) {
                throw new InvalidArgumentException("Invalid or expired verification token");
            }

            // Check if token has expired
            if (strtotime($tokenRecord['expires_at']) < time()) {
                throw new InvalidArgumentException("Verification token has expired");
            }

            // Mark token as used
            $this->db->update($this->table_name, [
                'used_at' => date('Y-m-d H:i:s')
            ], ['id' => $tokenRecord['id']]);

            // Update user email verification status
            $success = $this->userModel->verifyEmail($tokenRecord['user_id']);

            if (!$success) {
                throw new RuntimeException("Failed to update user verification status");
            }

            $updatedUser = $this->userModel->getById($tokenRecord['user_id']);

            Logger::info("Email verified successfully", [
                'user_id' => $tokenRecord['user_id'],
                'email' => $updatedUser['email']
            ]);

            return $updatedUser;

        } catch (Exception $e) {
            Logger::error("Email verification failed", [
                'error' => $e->getMessage(),
                'token_provided' => !empty($token)
            ]);
            throw $e;
        }
    }

    /**
     * Check if user email is verified
     */
    public function isEmailVerified($userId) {
        $user = $this->userModel->getById($userId);
        return $user ? (bool)$user['email_verified'] : false;
    }

    /**
     * Clean up expired tokens
     */
    public function cleanupExpiredTokens() {
        try {
            $expiredCount = $this->db->delete($this->table_name, [
                'expires_at <' => date('Y-m-d H:i:s'),
                'used_at' => null
            ]);

            // Also clean up old used tokens (older than 30 days)
            $oldUsedCount = $this->db->delete($this->table_name, [
                'used_at <' => date('Y-m-d H:i:s', strtotime('-30 days')),
                'used_at !=' => null
            ]);

            Logger::info("Token cleanup completed", [
                'expired_tokens_removed' => $expiredCount,
                'old_used_tokens_removed' => $oldUsedCount
            ]);

            return $expiredCount + $oldUsedCount;

        } catch (Exception $e) {
            Logger::error("Token cleanup failed", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Invalidate existing active tokens for a user
     */
    private function invalidateExistingTokens($userId) {
        try {
            $this->db->update($this->table_name, [
                'used_at' => date('Y-m-d H:i:s')
            ], [
                'user_id' => $userId,
                'token_type' => 'verification',
                'used_at' => null
            ]);
        } catch (Exception $e) {
            Logger::warning("Failed to invalidate existing tokens", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Generate secure random token
     */
    private function generateSecureToken() {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash token for storage
     */
    private function hashToken($token) {
        return hash('sha256', $token);
    }

    /**
     * Generate UUID v4
     */
    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
?>