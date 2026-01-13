<?php
class User {
    private $db;
    private $table_name = "users";

    public $id;
    public $email;
    public $password_hash;
    public $first_name;
    public $last_name;
    public $phone;
    public $avatar_url;
    public $email_verified;
    public $is_active;
    public $is_admin;
    public $last_login_at;
    public $stripe_customer_id;
    public $created_at;
    public $updated_at;

    public function __construct($db) {
        $this->db = $db;
        Logger::debug("User model initialized");
    }

    /**
     * Create a new user
     */
    public function create($userData) {
        $startTime = microtime(true);
        
        try {
            // Transform password to password_hash if needed
            if (isset($userData['password']) && !isset($userData['password_hash'])) {
                $userData['password_hash'] = $userData['password'];
                unset($userData['password']);
            }

            // Validate required fields
            if (!$this->validateRequiredFields($userData)) {
                throw new InvalidArgumentException("Missing required fields");
            }

            // Validate email format
            if (!$this->validateEmail($userData['email'])) {
                throw new InvalidArgumentException("Invalid email format");
            }

            // Validate password strength
            if (!$this->validatePassword($userData['password_hash'])) {
                throw new InvalidArgumentException("Password must be at least 8 characters");
            }

            // Validate names
            if (!$this->validateName($userData['first_name']) || !$this->validateName($userData['last_name'])) {
                throw new InvalidArgumentException("Invalid name format");
            }

            // Check if email already exists
            if ($this->emailExists($userData['email'])) {
                throw new InvalidArgumentException("Email already exists");
            }

            // Generate UUID for the user
            $uuid = $this->generateUUID();
            
            // Prepare data for insertion with MySQL-compatible boolean values
            $insertData = [
                'id' => $uuid, // Explicitly set the UUID
                'email' => $this->sanitizeEmail($userData['email']),
                'password_hash' => password_hash($userData['password_hash'], PASSWORD_DEFAULT, ['cost' => 12]),
                'first_name' => $this->sanitizeString($userData['first_name']),
                'last_name' => $this->sanitizeString($userData['last_name']),
                'phone' => isset($userData['phone']) ? $this->sanitizePhone($userData['phone']) : null,
                'avatar_url' => isset($userData['avatar_url']) ? $this->sanitizeUrl($userData['avatar_url']) : null,
                'email_verified' => isset($userData['email_verified']) ? (int)$userData['email_verified'] : 0, // Use 0 instead of false for MySQL
                'is_active' => isset($userData['is_active']) ? (int)$userData['is_active'] : 1,      // Use 1 instead of true for MySQL
                'is_admin' => 0        // Default to non-admin
            ];

            Logger::debug("Creating user with data", ['data' => $insertData]);

            // Create user using DB CRUD method
            $this->id = $this->db->create($this->table_name, $insertData);
            
            if ($this->id || $uuid) {
                // If create returns false but we have UUID, try to fetch the user by email
                if (!$this->id && $uuid) {
                    $this->id = $uuid;
                }
                
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                
                Logger::info("User created successfully", [
                    'user_id' => $this->id,
                    'email' => $userData['email'],
                    'first_name' => $userData['first_name'],
                    'last_name' => $userData['last_name'],
                    'duration_ms' => $duration
                ]);
                
                // Return the created user data
                return $this->getById($this->id);
            }
            
            // If we reach here, try to get the user by email as fallback
            $user = $this->getByEmail($userData['email']);
            if ($user) {
                $this->id = $user['id'];
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                
                Logger::info("User created successfully (fallback)", [
                    'user_id' => $this->id,
                    'email' => $userData['email'],
                    'duration_ms' => $duration
                ]);
                
                return $user;
            }
            
            throw new RuntimeException("Failed to create user and could not retrieve created user");
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("User creation failed", [
                'error' => $e->getMessage(),
                'email' => $userData['email'] ?? 'unknown',
                'duration_ms' => $duration
            ]);
            throw $e;
        }
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

    /**
     * Modern sanitization methods for PHP 8.1+
     */
    
    private function sanitizeEmail($email) {
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
    
    private function sanitizeString($string) {
        // Remove any HTML tags and encode special characters
        $string = strip_tags($string);
        $string = htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove excessive whitespace
        $string = trim($string);
        $string = preg_replace('/\s+/', ' ', $string);
        
        return $string;
    }
    
    private function sanitizePhone($phone) {
        // Remove all non-numeric characters except + sign
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        return $phone;
    }
    
    private function sanitizeUrl($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }

    /**
     * Get user by ID
     */
    public function getById($userId, $includeSensitive = false) {
        $startTime = microtime(true);

        try {
            $columns = $includeSensitive ? '*' : 'id, email, first_name, last_name, phone, avatar_url, email_verified, is_active, is_admin, last_login_at, stripe_customer_id, created_at, updated_at';
            
            $user = $this->db->readOne($this->table_name, ['id' => $userId], $columns);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($user) {
                Logger::debug("User retrieved by ID", [
                    'user_id' => $userId,
                    'duration_ms' => $duration
                ]);
                return $user;
            }
            
            Logger::debug("User not found by ID", [
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            return null;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get user by ID", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get user by email
     */
    public function getByEmail($email, $includeSensitive = false) {
        $startTime = microtime(true);

        try {
            $columns = $includeSensitive ? '*' : 'id, email, first_name, last_name, phone, avatar_url, email_verified, is_active, is_admin, last_login_at, stripe_customer_id, created_at, updated_at';
            
            $user = $this->db->readOne($this->table_name, ['email' => $email], $columns);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($user) {
                Logger::debug("User retrieved by email", [
                    'email' => $email,
                    'duration_ms' => $duration
                ]);
                return $user;
            }
            
            Logger::debug("User not found by email", [
                'email' => $email,
                'duration_ms' => $duration
            ]);
            return null;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get user by email", [
                'error' => $e->getMessage(),
                'email' => $email,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Check if email exists
     */
    public function emailExists($email) {
        $startTime = microtime(true);
        
        try {
            $user = $this->db->readOne($this->table_name, ['email' => $email], 'id');
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $exists = !empty($user);
            
            Logger::debug("Email existence check", [
                'email' => $email,
                'exists' => $exists,
                'duration_ms' => $duration
            ]);
            
            return $exists;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Email existence check failed", [
                'error' => $e->getMessage(),
                'email' => $email,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update user profile
     */
    public function updateProfile($userId, $updateData) {
        $startTime = microtime(true);
        
        try {
            // Allowed fields for update
            $allowedFields = ['first_name', 'last_name', 'phone', 'avatar_url', 'stripe_customer_id'];
            $filteredData = [];
            
            foreach ($allowedFields as $field) {
                if (isset($updateData[$field])) {
                    $filteredData[$field] = $updateData[$field];
                }
            }
            
            if (empty($filteredData)) {
                throw new InvalidArgumentException("No valid fields to update");
            }
            
            // Add updated timestamp
            $filteredData['updated_at'] = date('Y-m-d H:i:s');
            
            // Sanitize data using modern methods
            if (isset($filteredData['first_name'])) {
                $filteredData['first_name'] = $this->sanitizeString($filteredData['first_name']);
            }
            if (isset($filteredData['last_name'])) {
                $filteredData['last_name'] = $this->sanitizeString($filteredData['last_name']);
            }
            if (isset($filteredData['phone'])) {
                $filteredData['phone'] = $this->sanitizePhone($filteredData['phone']);
            }
            if (isset($filteredData['avatar_url'])) {
                $filteredData['avatar_url'] = $this->sanitizeUrl($filteredData['avatar_url']);
            }
            
            // Update using DB CRUD method
            $affectedRows = $this->db->update($this->table_name, $filteredData, ['id' => $userId]);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($affectedRows > 0) {
                Logger::info("User profile updated successfully", [
                    'user_id' => $userId,
                    'updated_fields' => array_keys($filteredData),
                    'duration_ms' => $duration
                ]);
                
                return $this->getById($userId);
            }
            
            Logger::warning("No changes made to user profile", [
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            return $this->getById($userId);
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("User profile update failed", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update user password
     */
    public function updatePassword($userId, $newPassword) {
        $startTime = microtime(true);
        
        try {
            if (!$this->validatePassword($newPassword)) {
                throw new InvalidArgumentException("Password must be at least 8 characters");
            }
            
            $updateData = [
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT, ['cost' => 12]),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $userId]);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($affectedRows > 0) {
                Logger::info("User password updated successfully", [
                    'user_id' => $userId,
                    'duration_ms' => $duration
                ]);
                return true;
            }
            
            Logger::warning("Password update affected no rows", [
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            return false;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("User password update failed", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Verify user email
     */
    public function verifyEmail($userId) {
        $startTime = microtime(true);
        
        try {
            $updateData = [
                'email_verified' => true,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $userId]);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($affectedRows > 0) {
                Logger::info("User email verified successfully", [
                    'user_id' => $userId,
                    'duration_ms' => $duration
                ]);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("User email verification failed", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update last login timestamp
     */
    public function updateLastLogin($userId) {
        $startTime = microtime(true);
        
        try {
            $updateData = [
                'last_login_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $userId]);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($affectedRows > 0) {
                Logger::debug("Last login timestamp updated", [
                    'user_id' => $userId,
                    'duration_ms' => $duration
                ]);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to update last login", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Deactivate user account
     */
    public function deactivate($userId) {
        $startTime = microtime(true);
        
        try {
            $updateData = [
                'is_active' => false,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $userId]);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($affectedRows > 0) {
                Logger::info("User account deactivated", [
                    'user_id' => $userId,
                    'duration_ms' => $duration
                ]);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to deactivate user account", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Activate user account
     */
    public function activate($userId) {
        $startTime = microtime(true);

        try {
            $updateData = [
                'is_active' => true,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $userId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("User account activated", [
                    'user_id' => $userId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to activate user account", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Promote user to admin
     */
    public function promoteToAdmin($userId) {
        $startTime = microtime(true);

        try {
            $updateData = [
                'is_admin' => true,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $userId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("User promoted to admin", [
                    'user_id' => $userId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to promote user to admin", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Demote user from admin
     */
    public function demoteFromAdmin($userId) {
        $startTime = microtime(true);

        try {
            $updateData = [
                'is_admin' => false,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $userId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("User demoted from admin", [
                    'user_id' => $userId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to demote user from admin", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get all users with pagination
     */
    public function getAll($page = 1, $limit = 50, $filters = []) {
        $startTime = microtime(true);
        
        try {
            $offset = ($page - 1) * $limit;
            $conditions = [];
            
            // Apply filters
            if (isset($filters['is_active'])) {
                $conditions['is_active'] = (bool)$filters['is_active'];
            }
            
            if (isset($filters['email_verified'])) {
                $conditions['email_verified'] = (bool)$filters['email_verified'];
            }
            
            $users = $this->db->readMany(
                $this->table_name,
                $conditions,
                'id, email, first_name, last_name, phone, avatar_url, email_verified, is_active, is_admin, last_login_at, stripe_customer_id, created_at, updated_at',
                'created_at DESC',
                $limit,
                $offset
            );
            
            // Get total count for pagination
            $total = $this->db->count($this->table_name, $conditions);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Logger::info("Retrieved users list", [
                'page' => $page,
                'limit' => $limit,
                'total_users' => $total,
                'returned_count' => count($users),
                'duration_ms' => $duration
            ]);
            
            return [
                'users' => $users,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get users list", [
                'error' => $e->getMessage(),
                'page' => $page,
                'limit' => $limit,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Search users by name or email
     */
    public function search($query, $page = 1, $limit = 50) {
        $startTime = microtime(true);
        
        try {
            $offset = ($page - 1) * $limit;
            $searchTerm = "%{$query}%";
            
            $sql = "SELECT id, email, first_name, last_name, phone, avatar_url, email_verified, is_active, is_admin, last_login_at, stripe_customer_id, created_at, updated_at
                    FROM {$this->table_name}
                    WHERE (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)
                    AND is_active = true
                    ORDER BY created_at DESC
                    LIMIT ?, ?";
            
            $params = [$searchTerm, $searchTerm, $searchTerm, $offset, $limit];
            
            $users = $this->db->query($sql, $params);
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total 
                         FROM {$this->table_name} 
                         WHERE (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?) 
                         AND is_active = true";
            
            $countResult = $this->db->query($countSql, [$searchTerm, $searchTerm, $searchTerm]);
            $total = $countResult[0]['total'] ?? 0;
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Logger::info("User search completed", [
                'query' => $query,
                'page' => $page,
                'limit' => $limit,
                'total_results' => $total,
                'returned_count' => count($users),
                'duration_ms' => $duration
            ]);
            
            return [
                'users' => $users,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("User search failed", [
                'error' => $e->getMessage(),
                'query' => $query,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Validate user credentials
     */
    public function validateCredentials($email, $password) {
        $startTime = microtime(true);
        
        try {
            // Use readOne method from Database class to get user with sensitive data
            $user = $this->db->readOne($this->table_name, ['email' => $email], '*');
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Check if account is active
                if (!$user['is_active']) {
                    Logger::warning("Login attempt for inactive account", ['email' => $email]);
                    return ['success' => false, 'error' => 'ACCOUNT_INACTIVE'];
                }
                
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                Logger::info("Credentials validated successfully", [
                    'user_id' => $user['id'],
                    'email' => $email,
                    'duration_ms' => $duration
                ]);
                
                return ['success' => true, 'user' => $user];
            }
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::warning("Invalid credentials provided", [
                'email' => $email,
                'duration_ms' => $duration
            ]);
            
            return ['success' => false, 'error' => 'INVALID_CREDENTIALS'];
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Credentials validation failed", [
                'error' => $e->getMessage(),
                'email' => $email,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Validation methods
     */
    private function validateRequiredFields($data) {
        $required = ['email', 'password_hash', 'first_name', 'last_name'];
        $missing = [];
        
        foreach ($required as $field) {
            // Check for password field as alternative to password_hash
            if ($field === 'password_hash' && empty($data['password_hash']) && !empty($data['password'])) {
                continue; // password field exists, so password_hash requirement is satisfied
            }
            
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            Logger::warning("Missing required fields for user creation", ['fields' => $missing]);
            return false;
        }
        
        return true;
    }

    private function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validatePassword($password) {
        return strlen($password) >= 8;
    }

    private function validateName($name) {
        return preg_match('/^[a-zA-Z\s\-\.]{2,100}$/', $name) === 1;
    }

    /**
     * Update user Stripe customer ID
     */
    public function updateStripeCustomerId($userId, $stripeCustomerId) {
        $startTime = microtime(true);

        try {
            $updateData = [
                'stripe_customer_id' => $stripeCustomerId,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $affectedRows = $this->db->update($this->table_name, $updateData, ['id' => $userId]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($affectedRows > 0) {
                Logger::info("Stripe customer ID updated successfully", [
                    'user_id' => $userId,
                    'stripe_customer_id' => $stripeCustomerId,
                    'duration_ms' => $duration
                ]);
                return true;
            }

            Logger::warning("Stripe customer ID update affected no rows", [
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Stripe customer ID update failed", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Get user by Stripe customer ID
     */
    public function getByStripeCustomerId($stripeCustomerId, $includeSensitive = false) {
        $startTime = microtime(true);

        try {
            $columns = $includeSensitive ? '*' : 'id, email, first_name, last_name, phone, avatar_url, email_verified, is_active, is_admin, last_login_at, stripe_customer_id, created_at, updated_at';

            $user = $this->db->readOne($this->table_name, ['stripe_customer_id' => $stripeCustomerId], $columns);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($user) {
                Logger::debug("User retrieved by Stripe customer ID", [
                    'stripe_customer_id' => $stripeCustomerId,
                    'duration_ms' => $duration
                ]);
                return $user;
            }

            Logger::debug("User not found by Stripe customer ID", [
                'stripe_customer_id' => $stripeCustomerId,
                'duration_ms' => $duration
            ]);
            return null;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Failed to get user by Stripe customer ID", [
                'error' => $e->getMessage(),
                'stripe_customer_id' => $stripeCustomerId,
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Create users table
     */
    public static function createTable($db) {
        $startTime = microtime(true);
        
        try {
            $query = "CREATE TABLE IF NOT EXISTS users (
                id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                phone VARCHAR(20),
                avatar_url TEXT,
                email_verified BOOLEAN DEFAULT FALSE,
                is_active BOOLEAN DEFAULT TRUE,
                is_admin BOOLEAN DEFAULT FALSE,
                last_login_at TIMESTAMP NULL,
                stripe_customer_id VARCHAR(255) UNIQUE NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_users_is_admin (is_admin),
                INDEX idx_users_email (email),
                INDEX idx_users_created_at (created_at),
                INDEX idx_users_stripe_customer_id (stripe_customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            Logger::debug("Creating users table if not exists");

            $result = $db->query($query);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($result !== false) {
                Logger::info("Users table created/verified successfully", [
                    'duration_ms' => $duration
                ]);
                return true;
            }
            
            Logger::warning("Users table creation may have failed", [
                'duration_ms' => $duration
            ]);
            return false;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Users table creation failed", [
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }
}
?>