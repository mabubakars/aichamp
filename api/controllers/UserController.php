<?php
class UserController extends BaseController {
    private $passwordService;
    private $emailVerificationService;

    public function __construct($db) {
        parent::__construct($db);
        $this->passwordService = new PasswordService($db);
        $this->emailVerificationService = new EmailVerificationService($db);
    }

    public function getProfile() {
        $user = $this->getAuthenticatedUser();

        $user = $this->user->getById($user['user_id']);

        if (!$user) {
            return $this->error("User not found.", 404, 'USER_NOT_FOUND');
        }

        return $this->success([
            "user" => $user
        ], "Profile retrieved successfully.");
    }
    public function updateProfile() {
        $user = $this->getAuthenticatedUser();
        $data = $this->getJsonInput();

        try {
            $updatedUser = $this->user->updateProfile($user['user_id'], $data);

            return $this->success([
                "user" => $updatedUser
            ], "Profile updated successfully.");

        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400, 'VALIDATION_ERROR');
        } catch (Exception $e) {
            Logger::error("Profile update failed in controller", [
                'user_id' => $user['user_id'],
                'error' => $e->getMessage()
            ]);
            return $this->error("Failed to update profile.", 500, 'UPDATE_FAILED');
        }
    }

    public function updatePassword() {
        $user = $this->getAuthenticatedUser();
            $data = $this->getJsonInput();

            $this->validateRequiredFields($data, ['current_password', 'new_password']);

            return $this->handleServiceCall(function() use ($user, $data) {
                $this->passwordService->changePassword(
                    $user['user_id'],
                    $user['email'],
                    $data['current_password'],
                    $data['new_password']
                );
                return [];
            }, "Password updated successfully.", 'PASSWORD_UPDATE_FAILED');

    }

    public function verifyEmail() {
        $user = $this->getAuthenticatedUser();
            $data = $this->getJsonInput();

            $this->validateRequiredFields($data, ['verification_token']);

            return $this->handleServiceCall(function() use ($user) {
                $user = $this->emailVerificationService->verifyEmail($user['user_id']);
                return ["user" => $user];
            }, "Email verified successfully.", 'EMAIL_VERIFICATION_FAILED');

    }

    public function requestEmailVerification() {
        $user = $this->getAuthenticatedUser();
            return $this->handleServiceCall(function() use ($user) {
                $result = $this->emailVerificationService->requestVerification($user['user_id']);
                return [
                    "message" => $result['message'],
                    "verification_token" => $result['token'] // Remove this in production
                ];
            }, "Verification email sent successfully.", 'VERIFICATION_REQUEST_FAILED');

    }

    public function deactivateAccount() {
        $user = $this->getAuthenticatedUser();
            $data = $this->getJsonInput();

            $this->validateRequiredFields($data, ['password']);

            // Verify password before deactivation
            $this->passwordService->validateCurrentPassword($user['user_id'], $user['email'], $data['password']);

            return $this->handleServiceCall(function() use ($user) {
                $success = $this->user->deactivate($user['user_id']);

                if ($success) {
                    Logger::info("Account deactivated successfully", [
                        'user_id' => $user['user_id'],
                        'email' => $user['email']
                    ]);
                } else {
                    throw new RuntimeException("Failed to deactivate account");
                }

                return [];
            }, "Account deactivated successfully.", 'DEACTIVATION_FAILED');

    }

    public function uploadAvatar() {
        $user = $this->getAuthenticatedUser();

        // Check if file was uploaded
        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            return $this->error("No avatar file uploaded or upload error.", 400, 'UPLOAD_ERROR');
        }

        $uploadedFile = $_FILES['avatar'];

        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $fileType = mime_content_type($uploadedFile['tmp_name']);

        if (!in_array($fileType, $allowedTypes)) {
            return $this->error("Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed.", 400, 'INVALID_FILE_TYPE');
        }

        // Validate file size (max 5MB)
        if ($uploadedFile['size'] > 5 * 1024 * 1024) {
            return $this->error("File too large. Maximum size is 5MB.", 400, 'FILE_TOO_LARGE');
        }

        try {
            // In a real implementation, you would:
            // 1. Upload to cloud storage (S3, Cloudinary, etc.)
            // 2. Generate unique filename
            // 3. Resize/crop if needed

            // For now, we'll create a placeholder URL
            $avatarUrl = $this->handleAvatarUpload($uploadedFile);

            // Update user profile with avatar URL
            $updatedUser = $this->user->updateProfile($user['user_id'], [
                'avatar_url' => $avatarUrl
            ]);

            Logger::info("Avatar uploaded successfully", [
                'user_id' => $user['user_id'],
                'avatar_url' => $avatarUrl
            ]);

            return $this->success([
                "user" => $updatedUser
            ], "Avatar uploaded successfully.");

        } catch (Exception $e) {
            Logger::error("Avatar upload failed", [
                'user_id' => $user['user_id'],
                'error' => $e->getMessage()
            ]);
            return $this->error("Failed to upload avatar.", 500, 'UPLOAD_FAILED');
        }
    }

    /**
     * Admin endpoints (protected by admin middleware)
     */
    
    public function getAllUsers() {
        // Only allow admin users to access this endpoint
        $user = $this->getAuthenticatedUser();

        if (!$this->isAdminUser($user)) {
            return $this->error("Access denied. Admin privileges required.", 403, 'ACCESS_DENIED');
        }

        $page = (int)($this->request->getQuery('page', 1));
        $limit = (int)($this->request->getQuery('limit', 50));
        $filters = [];

        // Apply filters if provided
        if ($this->request->getQuery('is_active') !== null) {
            $filters['is_active'] = (bool)$this->request->getQuery('is_active');
        }

        if ($this->request->getQuery('email_verified') !== null) {
            $filters['email_verified'] = (bool)$this->request->getQuery('email_verified');
        }

        try {
            $result = $this->user->getAll($page, $limit, $filters);

            return $this->success($result, "Users retrieved successfully.");

        } catch (Exception $e) {
            Logger::error("Get all users failed in controller", [
                'error' => $e->getMessage()
            ]);
            return $this->error("Failed to retrieve users.", 500, 'RETRIEVAL_FAILED');
        }
    }

    // ... (other admin methods remain the same as previous implementation)

    /**
     * Helper methods
     */
    
    private function isValidVerificationToken($userId, $token) {
        // Placeholder implementation
        // In real implementation, you would check against a stored token in database
        // with proper expiration check
        return !empty($token) && strlen($token) === 64;
    }
    
    private function handleAvatarUpload($uploadedFile) {
        // Placeholder implementation
        // In real implementation, you would:
        // 1. Upload to cloud storage
        // 2. Generate CDN URL
        // 3. Return the public URL
        
        $fileExtension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
        $fileName = 'avatar_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $fileExtension;
        
        // For now, return a placeholder URL
        return 'https://example.com/avatars/' . $fileName;
    }
    
    private function isAdminUser($userUser) {
        // Check if user has admin privileges
        $user = $this->user->getById($userUser['user_id']);

        if (!$user) {
            Logger::warning("User not found during admin check", [
                'user_id' => $userUser['user_id']
            ]);
            return false;
        }

        $isAdmin = (bool)$user['is_admin'];

        Logger::debug("Admin check performed", [
            'user_id' => $userUser['user_id'],
            'is_admin' => $isAdmin
        ]);

        return $isAdmin;
    }
}
?>