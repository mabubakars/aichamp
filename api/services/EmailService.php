<?php
class EmailService {
    private $fromAddress;
    private $fromName;
    private $templatePath;
    private $frontendUrlService;

    public function __construct() {
        $this->fromAddress = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@scholarcompare.com';
        $this->fromName = getenv('MAIL_FROM_NAME') ?: 'ScholarCompare';
        $this->templatePath = getenv('EMAIL_VERIFICATION_TEMPLATE_PATH') ?: './template';
        $this->frontendUrlService = new FrontendUrlService();
    }

    /**
     * Send verification email
     */
    public function sendVerificationEmail($to, $token, $userName, $userEmail = null) {
        $subject = 'Verify Your Email - ScholarCompare';
        $verificationUrl = $this->generateVerificationUrl($token);

        // Load HTML template
        $htmlContent = $this->loadTemplate('verification_email.html', [
            'user_name' => $userName,
            'user_email' => $userEmail ?: $to,
            'verification_url' => $verificationUrl
        ]);

        return $this->sendEmail($to, $subject, $htmlContent);
    }

    /**
     * Send email using PHP mail() function
     */
    private function sendEmail($to, $subject, $htmlContent, $textContent = null) {
        $headers = $this->buildHeaders($htmlContent, $textContent);

        // Log the email attempt
        Logger::info("Sending email", [
            'to' => $to,
            'subject' => $subject,
            'from' => $this->fromAddress
        ]);

        $result = mail($to, $subject, $htmlContent, $headers);

        if ($result) {
            Logger::info("Email sent successfully", ['to' => $to]);
        } else {
            Logger::error("Email sending failed", ['to' => $to]);
        }

        return $result;
    }

    /**
     * Build email headers
     */
    private function buildHeaders($htmlContent, $textContent = null) {
        $headers = [
            'From: ' . $this->fromName . ' <' . $this->fromAddress . '>',
            'Reply-To: ' . $this->fromAddress,
            'X-Mailer: PHP/' . phpversion(),
            'MIME-Version: 1.0'
        ];

        if ($textContent) {
            // Create multipart message
            $boundary = md5(uniqid(time()));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

            $multipartContent = "--{$boundary}\n" .
                               "Content-Type: text/plain; charset=UTF-8\n\n" .
                               $textContent . "\n\n" .
                               "--{$boundary}\n" .
                               "Content-Type: text/html; charset=UTF-8\n\n" .
                               $htmlContent . "\n\n" .
                               "--{$boundary}--";

            // Note: When using multipart, the content needs to be rebuilt
        } else {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }

        return implode("\r\n", $headers);
    }

    /**
     * Load and process email template
     */
    private function loadTemplate($templateName, $variables = []) {
        $templatePath = $this->templatePath . '/' . $templateName;

        if (!file_exists($templatePath)) {
            throw new Exception("Email template not found: {$templateName}");
        }

        $content = file_get_contents($templatePath);

        // Simple variable replacement
        foreach ($variables as $key => $value) {
            $content = str_replace('{{' . $key . '}}', htmlspecialchars($value), $content);
        }

        return $content;
    }

    /**
     * Generate verification URL
     */
    private function generateVerificationUrl($token) {
        // Generate frontend URL for email verification page
        return $this->frontendUrlService->generateEmailVerificationUrl($token);
    }

    /**
     * Send password reset email (for future use)
     */
    public function sendPasswordResetEmail($to, $token, $userName) {
        $subject = 'Reset Your Password - ScholarCompare';
        $resetUrl = $this->generatePasswordResetUrl($token);

        // This would use a different template
        // For now, return false as it's not implemented
        Logger::info("Password reset email requested but not implemented", ['to' => $to]);
        return false;
    }

    /**
     * Generate password reset URL
     */
    private function generatePasswordResetUrl($token) {
        // Generate frontend URL for password reset page
        return $this->frontendUrlService->generatePasswordResetUrl($token);
    }

    /**
     * Get FrontendUrlService instance for direct access to URL generation
     */
    public function getFrontendUrlService() {
        return $this->frontendUrlService;
    }

    /**
     * Generate login URL
     */
    public function generateLoginUrl($params = []) {
        return $this->frontendUrlService->generateLoginUrl($params);
    }

    /**
     * Generate registration URL
     */
    public function generateRegisterUrl($params = []) {
        return $this->frontendUrlService->generateRegisterUrl($params);
    }

    /**
     * Generate profile URL
     */
    public function generateProfileUrl($userId = null, $params = []) {
        return $this->frontendUrlService->generateProfileUrl($userId, $params);
    }

    /**
     * Generate dashboard URL
     */
    public function generateDashboardUrl($params = []) {
        return $this->frontendUrlService->generateDashboardUrl($params);
    }

    /**
     * Generate settings URL
     */
    public function generateSettingsUrl($params = []) {
        return $this->frontendUrlService->generateSettingsUrl($params);
    }

    /**
     * Generate custom frontend URL
     */
    public function generateCustomUrl($path, $params = []) {
        return $this->frontendUrlService->generateCustomUrl($path, $params);
    }

    /**
     * Generate tracked URL with UTM parameters
     */
    public function generateTrackedUrl($path, $params = [], $utmSource = 'api', $utmMedium = 'link') {
        return $this->frontendUrlService->generateTrackedUrl($path, $params, $utmSource, $utmMedium);
    }
}
?>