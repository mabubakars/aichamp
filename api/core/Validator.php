<?php
class Validator {
    private $data;
    private $errors = [];
    private $rules = [];
    private $customMessages = [];

    public function __construct($data = []) {
        $this->data = $data;
        $this->setDefaultMessages();
    }

    /**
     * Set data to validate
     */
    public function setData($data) {
        $this->data = $data;
        $this->errors = [];
        return $this;
    }

    /**
     * Define validation rules
     */
    public function rules($rules) {
        $this->rules = $rules;
        return $this;
    }

    /**
     * Set custom error messages
     */
    public function messages($messages) {
        $this->customMessages = array_merge($this->customMessages, $messages);
        return $this;
    }

    /**
     * Validate the data against rules
     */
    public function validate() {
        $this->errors = [];

        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $fieldExists = $this->fieldExists($field);
            $isSometimes = in_array('sometimes', $rules);
            
            // If field doesn't exist and rule is 'sometimes', skip validation
            if (!$fieldExists && $isSometimes) {
                continue;
            }
            
            foreach ($rules as $rule) {
                // Skip 'sometimes' rule itself
                if ($rule === 'sometimes') {
                    continue;
                }
                
                $this->applyRule($field, $rule);
                
                // Stop validating this field if it has errors and is required
                if (isset($this->errors[$field]) && in_array('required', $rules)) {
                    break;
                }
            }
        }

        return empty($this->errors);
    }

    /**
     * Check if field exists in data
     */
    private function fieldExists($field) {
        $value = $this->getValue($field);
        return $value !== null;
    }

    /**
     * Apply a single validation rule
     */
    private function applyRule($field, $rule) {
        $value = $this->getValue($field);
        $params = [];

        // Check if rule has parameters
        if (strpos($rule, ':') !== false) {
            list($rule, $paramString) = explode(':', $rule, 2);
            $params = explode(',', $paramString);
        }

        $methodName = 'validate' . ucfirst($rule);
        
        if (method_exists($this, $methodName)) {
            if (!$this->$methodName($field, $value, $params)) {
                $this->addError($field, $rule, $params);
            }
        }
    }

    /**
     * Get field value with dot notation support
     */
    private function getValue($field) {
        // Support dot notation for nested arrays
        if (strpos($field, '.') !== false) {
            $keys = explode('.', $field);
            $value = $this->data;
            
            foreach ($keys as $key) {
                if (isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    return null;
                }
            }
            
            return $value;
        }
        
        return isset($this->data[$field]) ? $this->data[$field] : null;
    }

    /**
     * Add validation error
     */
    private function addError($field, $rule, $params = []) {
        $messageKey = "{$field}.{$rule}";
        
        if (isset($this->customMessages[$messageKey])) {
            $message = $this->customMessages[$messageKey];
        } elseif (isset($this->customMessages[$rule])) {
            $message = $this->customMessages[$rule];
        } else {
            $message = $this->getDefaultMessage($rule);
        }

        // Replace placeholders in message - FIXED PHP 8.1 DEPRECATION
        $message = str_replace(':field', $this->formatFieldName($field), $message);
        
        $fieldValue = $this->getValue($field);
        if ($fieldValue !== null) {
            $message = str_replace(':value', (string)$fieldValue, $message);
        }
        
        foreach ($params as $index => $param) {
            $message = str_replace(":param{$index}", (string)$param, $message);
        }

        $this->errors[$field][] = $message;
    }

    /**
     * Format field name for error messages
     */
    private function formatFieldName($field) {
        return ucfirst(str_replace(['_', '.'], ' ', $field));
    }

    /**
     * Get validation errors
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Get first error message
     */
    public function getFirstError() {
        if (empty($this->errors)) {
            return null;
        }
        
        $firstField = array_key_first($this->errors);
        return $this->errors[$firstField][0] ?? null;
    }

    /**
     * Check if field has errors
     */
    public function hasError($field) {
        return isset($this->errors[$field]);
    }

    /**
     * Add custom error message for a field
     */
    public function addCustomError($field, $message) {
        $this->errors[$field][] = $message;
    }

    /**
     * Get errors for specific field
     */
    public function getFieldErrors($field) {
        return $this->errors[$field] ?? [];
    }

    /**
     * Set default error messages
     */
    private function setDefaultMessages() {
        $this->customMessages = [
            // Required
            'required' => 'The :field field is required.',
            
            // String
            'string' => 'The :field must be a string.',
            'min' => 'The :field must be at least :param0 characters.',
            'max' => 'The :field may not be greater than :param0 characters.',
            'length' => 'The :field must be exactly :param0 characters.',
            
            // Numeric
            'numeric' => 'The :field must be a number.',
            'integer' => 'The :field must be an integer.',
            'float' => 'The :field must be a float.',
            'min_value' => 'The :field must be at least :param0.',
            'max_value' => 'The :field may not be greater than :param0.',
            
            // Email
            'email' => 'The :field must be a valid email address.',
            
            // Password
            'password' => 'The :field must be at least 8 characters and contain uppercase, lowercase, and numbers.',
            
            // Phone
            'phone' => 'The :field must be a valid phone number.',
            
            // URL
            'url' => 'The :field must be a valid URL.',
            
            // Boolean
            'boolean' => 'The :field field must be true or false.',
            
            // Array
            'array' => 'The :field must be an array.',
            'in' => 'The selected :field is invalid.',
            
            // Date
            'date' => 'The :field is not a valid date.',
            'date_format' => 'The :field does not match the format :param0.',
            
            // File
            'file' => 'The :field must be a file.',
            'image' => 'The :field must be an image.',
            'mimes' => 'The :field must be a file of type: :param0.',
            
            // Custom
            'unique' => 'The :field has already been taken.',
            'exists' => 'The selected :field is invalid.',
            'confirmed' => 'The :field confirmation does not match.',
            'regex' => 'The :field format is invalid.'
        ];
    }

    /**
     * Get default message for rule
     */
    private function getDefaultMessage($rule) {
        return $this->customMessages[$rule] ?? "Validation failed for :field";
    }

    /**
     * VALIDATION RULES IMPLEMENTATION
     */

    // Required field
    private function validateRequired($field, $value, $params) {
        if (is_string($value)) {
            $value = trim($value);
        }
        return !empty($value) || $value === 0 || $value === '0';
    }

    // String validation
    private function validateString($field, $value, $params) {
        // If value is null and field is not required, skip validation
        if ($value === null) return true;
        return is_string($value);
    }

    // Minimum length
    private function validateMin($field, $value, $params) {
        if ($value === null) return true;
        if (!is_string($value) && !is_numeric($value)) return false;
        $min = (int)($params[0] ?? 0);
        return strlen((string)$value) >= $min;
    }

    // Maximum length
    private function validateMax($field, $value, $params) {
        if ($value === null) return true;
        if (!is_string($value) && !is_numeric($value)) return false;
        $max = (int)($params[0] ?? 0);
        return strlen((string)$value) <= $max;
    }

    // Exact length
    private function validateLength($field, $value, $params) {
        if ($value === null) return true;
        if (!is_string($value) && !is_numeric($value)) return false;
        $length = (int)($params[0] ?? 0);
        return strlen((string)$value) === $length;
    }

    // Numeric validation
    private function validateNumeric($field, $value, $params) {
        if ($value === null) return true;
        return is_numeric($value);
    }

    private function validateInteger($field, $value, $params) {
        if ($value === null) return true;
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    private function validateFloat($field, $value, $params) {
        if ($value === null) return true;
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }

    // Minimum value
    private function validateMin_value($field, $value, $params) {
        if ($value === null) return true;
        if (!is_numeric($value)) return false;
        $min = (float)($params[0] ?? 0);
        return $value >= $min;
    }

    // Maximum value
    private function validateMax_value($field, $value, $params) {
        if ($value === null) return true;
        if (!is_numeric($value)) return false;
        $max = (float)($params[0] ?? 0);
        return $value <= $max;
    }

    // Email validation
    private function validateEmail($field, $value, $params) {
        if ($value === null) return true;
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    // Password strength
    private function validatePassword($field, $value, $params) {
        if ($value === null) return true;
        if (!is_string($value)) return false;
        return strlen($value) >= 8 && 
               preg_match('/[A-Z]/', $value) && 
               preg_match('/[a-z]/', $value) && 
               preg_match('/[0-9]/', $value);
    }

    // Phone validation
    private function validatePhone($field, $value, $params) {
        if ($value === null) return true;
        if (!is_string($value)) return false;
        $cleaned = preg_replace('/[^0-9+]/', '', $value);
        return strlen($cleaned) >= 10 && strlen($cleaned) <= 15;
    }

    // URL validation
    private function validateUrl($field, $value, $params) {
        if ($value === null) return true;
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    // Boolean validation
    private function validateBoolean($field, $value, $params) {
        if ($value === null) return true;
        return in_array($value, [true, false, 1, 0, '1', '0'], true);
    }

    // Array validation
    private function validateArray($field, $value, $params) {
        if ($value === null) return true;
        return is_array($value);
    }

    // In array validation
    private function validateIn($field, $value, $params) {
        if ($value === null) return true;
        return in_array($value, $params);
    }

    // Date validation
    private function validateDate($field, $value, $params) {
        if ($value === null) return true;
        return strtotime($value) !== false;
    }

    private function validateDate_format($field, $value, $params) {
        if ($value === null) return true;
        $format = $params[0] ?? 'Y-m-d';
        $date = DateTime::createFromFormat($format, $value);
        return $date && $date->format($format) === $value;
    }

    // File validation (basic)
    private function validateFile($field, $value, $params) {
        return isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK;
    }

    private function validateImage($field, $value, $params) {
        if (!$this->validateFile($field, $value, $params)) return false;
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $fileType = mime_content_type($_FILES[$field]['tmp_name']);
        
        return in_array($fileType, $allowedTypes);
    }

    private function validateMimes($field, $value, $params) {
        if (!$this->validateFile($field, $value, $params)) return false;
        
        $allowedExtensions = $params;
        $fileName = $_FILES[$field]['name'];
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        return in_array($extension, $allowedExtensions);
    }

    // Database unique validation
    private function validateUnique($field, $value, $params) {
        if ($value === null) return true;
        if (empty($params)) return false;
        
        $table = $params[0];
        $column = $params[1] ?? $field;
        $ignoreId = $params[2] ?? null;
        
        // This would require database connection - for now return true
        // In real implementation, you'd check against database
        return true;
    }

    // Database exists validation
    private function validateExists($field, $value, $params) {
        if ($value === null) return true;
        if (empty($params)) return false;
        
        $table = $params[0];
        $column = $params[1] ?? $field;
        
        // This would require database connection - for now return true
        // In real implementation, you'd check against database
        return true;
    }

    // Field confirmation
    private function validateConfirmed($field, $value, $params) {
        if ($value === null) return true;
        $confirmationField = $field . '_confirmation';
        return isset($this->data[$confirmationField]) && $value === $this->data[$confirmationField];
    }

    // Regex validation
    private function validateRegex($field, $value, $params) {
        if ($value === null) return true;
        if (empty($params)) return false;
        $pattern = $params[0];
        return preg_match($pattern, $value) === 1;
    }

    /**
     * Static method for quick validation
     */
    public static function make($data, $rules, $messages = []) {
        $validator = new self($data);
        return $validator->rules($rules)->messages($messages)->validate();
    }

    /**
     * Get validated data (only fields that passed validation)
     */
    public function getValidated() {
        $validated = [];
        
        foreach (array_keys($this->rules) as $field) {
            if (!isset($this->errors[$field])) {
                $value = $this->getValue($field);
                if ($value !== null) {
                    $validated[$field] = $value;
                }
            }
        }
        
        return $validated;
    }

    /**
     * Check if validation passed
     */
    public function passes() {
        return empty($this->errors);
    }

    /**
     * Check if validation failed
     */
    public function fails() {
        return !empty($this->errors);
    }
}
?>