<?php
class PasswordService {
    private $userModel;
    private $validator;

    public function __construct($db) {
        $this->userModel = new User($db);
        $this->validator = new Validator();
    }

    /**
     * Validate current password for a user
     */
    public function validateCurrentPassword($userId, $email, $currentPassword) {
        // Get user to verify current password
        $user = $this->userModel->getById($userId);
        if (!$user) {
            throw new InvalidArgumentException("User not found");
        }

        // Verify current password
        $credentialsCheck = $this->userModel->validateCredentials($email, $currentPassword);

        if (!$credentialsCheck['success']) {
            if ($credentialsCheck['error'] === 'INVALID_CREDENTIALS') {
                throw new InvalidArgumentException("Invalid current password");
            } elseif ($credentialsCheck['error'] === 'ACCOUNT_INACTIVE') {
                throw new InvalidArgumentException("Account inactive");
            }
        }

        return true;
    }

    /**
     * Update user password with validation
     */
    public function updatePassword($userId, $newPassword) {
        // Validate new password
        $rules = [
            'new_password' => 'required|min:8|password'
        ];

        $messages = [
            'new_password.required' => 'New password is required.',
            'new_password.min' => 'New password must be at least 8 characters long.',
            'new_password.password' => 'New password must contain at least one uppercase letter, one lowercase letter, and one number.'
        ];

        $this->validator->setData(['new_password' => $newPassword])->rules($rules)->messages($messages);

        if (!$this->validator->validate()) {
            throw new InvalidArgumentException("Password validation failed");
        }

        // Update password
        $success = $this->userModel->updatePassword($userId, $newPassword);

        if (!$success) {
            throw new RuntimeException("Failed to update password");
        }

        Logger::info("Password updated successfully", [
            'user_id' => $userId
        ]);

        return true;
    }

    /**
     * Change password (validate current + update new)
     */
    public function changePassword($userId, $email, $currentPassword, $newPassword) {
        // Validate current password
        $this->validateCurrentPassword($userId, $email, $currentPassword);

        // Update to new password
        return $this->updatePassword($userId, $newPassword);
    }
}
?>