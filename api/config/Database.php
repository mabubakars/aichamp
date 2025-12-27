<?php
require_once 'Environment.php';
require_once __DIR__ . '/../core/Logger.php';

class Database {
    private static $instance = null;
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    private $currentStatement = null;
    private $currentQuery = '';
    private $currentParams = [];

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        Environment::load();
        $this->loadConfig();
        if (!defined('TESTING') || !TESTING) {
            $this->getConnection();
        }
    }

    private function loadConfig() {
        $this->host = Environment::get('DB_HOST', '127.0.0.1');
        $this->db_name = Environment::get('DB_NAME', 'ai_chat_platform');
        $this->username = Environment::get('DB_USER', 'or4wb23d_Raffaele');
        $this->password = Environment::get('DB_PASS', 'Raffa.1991');

        // Validate required configuration
        if (empty($this->host) || empty($this->db_name) || empty($this->username)) {
            Logger::critical("Database configuration is incomplete");
            throw new RuntimeException("Database configuration is incomplete");
        }

        Logger::debug("Database configuration loaded", [
            'host' => $this->host,
            'database' => $this->db_name,
            'username' => $this->username
        ]);
    }

    public function getConnection() {
        $this->conn = null;
        $startTime = microtime(true);
        
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            
            Logger::info("Attempting database connection", [
                'dsn' => $dsn,
                'username' => $this->username
            ]);
            
            $this->conn = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Database connection established successfully", [
                'duration_ms' => $duration
            ]);

            return $this->conn;
            
        } catch(PDOException $exception) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Database connection failed", [
                'error' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'duration_ms' => $duration
            ]);
            
            if (Environment::isDevelopment()) {
                throw new Exception("Database connection failed: " . $exception->getMessage());
            } else {
                throw new Exception("Database connection failed");
            }
        }
    }

    /**
     * SEQUENTIAL FETCHING METHODS
     */

    /**
     * Prepare a query for sequential fetching
     */
    public function prepareQuery($sql, $params = []) {
        $startTime = microtime(true);
        
        try {
            // Clean up any previous statement
            $this->closeCursor();
            
            Logger::debug("Preparing query for sequential fetching", [
                'query' => $sql,
                'params' => $params
            ]);
            
            $this->currentStatement = $this->conn->prepare($sql);
            $this->currentQuery = $sql;
            $this->currentParams = $params;
            
            $result = $this->currentStatement->execute($params);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($result) {
                Logger::info("Query prepared successfully for sequential fetching", [
                    'duration_ms' => $duration
                ]);
                return true;
            }
            
            Logger::error("Failed to prepare query for sequential fetching", [
                'duration_ms' => $duration
            ]);
            return false;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Query preparation failed", [
                'query' => $sql,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Fetch next row from prepared statement
     */
    public function fetchNext() {
        if (!$this->currentStatement) {
            Logger::warning("No prepared statement available for fetching");
            return null;
        }
        
        try {
            $row = $this->currentStatement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
            
        } catch (Exception $e) {
            Logger::error("Failed to fetch next row", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Fetch multiple rows at once
     */
    public function fetchNextBatch($batchSize = 100) {
        if (!$this->currentStatement) {
            Logger::warning("No prepared statement available for batch fetching");
            return [];
        }
        
        try {
            $batch = [];
            $count = 0;
            
            while ($count < $batchSize && ($row = $this->currentStatement->fetch(PDO::FETCH_ASSOC))) {
                $batch[] = $row;
                $count++;
            }
            
            Logger::debug("Fetched batch of rows", [
                'batch_size' => count($batch),
                'requested_size' => $batchSize
            ]);
            
            return $batch;
            
        } catch (Exception $e) {
            Logger::error("Failed to fetch batch", [
                'error' => $e->getMessage(),
                'batch_size' => $batchSize
            ]);
            return [];
        }
    }

    /**
     * Check if more rows are available
     */
    public function hasMoreRows() {
        if (!$this->currentStatement) {
            return false;
        }
        
        // Peek at the next row without advancing the pointer
        try {
            $row = $this->currentStatement->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_ABS, 0);
            return $row !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get the number of rows in the current result set
     */
    public function getRowCount() {
        if (!$this->currentStatement) {
            return 0;
        }
        
        return $this->currentStatement->rowCount();
    }

    /**
     * Reset the pointer to the beginning of the result set
     */
    public function resetPointer() {
        if (!$this->currentStatement) {
            return false;
        }
        
        try {
            // Close and re-execute to reset
            $this->currentStatement->closeCursor();
            return $this->currentStatement->execute($this->currentParams);
        } catch (Exception $e) {
            Logger::error("Failed to reset pointer", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Close the current cursor and free resources
     */
    public function closeCursor() {
        if ($this->currentStatement) {
            try {
                $this->currentStatement->closeCursor();
                Logger::debug("Cursor closed successfully");
            } catch (Exception $e) {
                Logger::warning("Failed to close cursor", [
                    'error' => $e->getMessage()
                ]);
            }
            $this->currentStatement = null;
            $this->currentQuery = '';
            $this->currentParams = [];
        }
    }

    /**
     * Get information about current sequential operation
     */
    public function getSequentialInfo() {
        return [
            'has_statement' => $this->currentStatement !== null,
            'current_query' => $this->currentQuery,
            'param_count' => count($this->currentParams),
            'row_count' => $this->getRowCount(),
            'has_more_rows' => $this->hasMoreRows()
        ];
    }

    private function prepareBooleanValues(&$data) {
        foreach ($data as $key => &$value) {
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }
        }
    }

    /**
     * CRUD Operations - Simplified Interface
     */

    /**
     * Generate a UUID v4
     */
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Create a new record
     */
    public function create($table, $data) {
        $startTime = microtime(true);

        try {
            // Generate UUID if id not provided
            if (!isset($data['id']) || empty($data['id'])) {
                $data['id'] = $this->generateUuid();
            }

            // Convert boolean values to MySQL-compatible integers
            $this->prepareBooleanValues($data);

            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));

            $query = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";

            Logger::debug("Creating record", [
                'table' => $table,
                'query' => $query,
                'data' => $data
            ]);

            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute($data);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($result) {
                Logger::info("Record created successfully", [
                    'table' => $table,
                    'id' => $data['id'],
                    'duration_ms' => $duration
                ]);
                return $data['id'];
            }

            Logger::error("Failed to create record", [
                'table' => $table,
                'duration_ms' => $duration
            ]);
            return false;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Create operation failed", [
                'table' => $table,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }


    /**
     * Read single record by ID
     */
    public function read($table, $id, $columns = '*') {
        return $this->readOne($table, ['id' => $id], $columns);
    }

    /**
     * Read single record with conditions
     */
    public function readOne($table, $conditions = [], $columns = '*', $orderBy = '') {
        $startTime = microtime(true);

        try {
            $whereClause = '';
            $params = [];

            if (!empty($conditions)) {
                $whereParts = [];
                foreach ($conditions as $column => $value) {
                    $whereParts[] = "{$column} = :{$column}";
                    $params[":{$column}"] = $value;
                }
                $whereClause = 'WHERE ' . implode(' AND ', $whereParts);
            }

            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : '';
            $query = "SELECT {$columns} FROM {$table} {$whereClause} {$orderClause} LIMIT 1";
            
            Logger::debug("Reading single record", [
                'table' => $table,
                'query' => $query,
                'conditions' => $conditions
            ]);
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch();
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Read single record completed", [
                'table' => $table,
                'found' => !empty($result),
                'duration_ms' => $duration
            ]);
            
            return $result ?: null;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Read one operation failed", [
                'table' => $table,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Read multiple records with conditions
     */
    public function readMany($table, $conditions = [], $columns = '*', $orderBy = '', $limit = null, $offset = 0) {
        $startTime = microtime(true);
        
        try {
            $whereClause = '';
            $params = [];
            
            if (!empty($conditions)) {
                $whereParts = [];
                foreach ($conditions as $column => $value) {
                    $whereParts[] = "{$column} = :{$column}";
                    $params[":{$column}"] = $value;
                }
                $whereClause = 'WHERE ' . implode(' AND ', $whereParts);
            }
            
            $orderClause = $orderBy ? "ORDER BY {$orderBy}" : '';
            $limitClause = $limit ? "LIMIT {$offset}, {$limit}" : '';
            
            $query = "SELECT {$columns} FROM {$table} {$whereClause} {$orderClause} {$limitClause}";
            
            Logger::debug("Reading multiple records", [
                'table' => $table,
                'query' => $query,
                'conditions' => $conditions
            ]);
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $results = $stmt->fetchAll();
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Read many records completed", [
                'table' => $table,
                'count' => count($results),
                'duration_ms' => $duration
            ]);
            
            return $results;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Read many operation failed", [
                'table' => $table,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Update records
     */
    public function update($table, $data, $conditions) {
        $startTime = microtime(true);

        try {
            // Convert boolean values to MySQL-compatible integers
            $this->prepareBooleanValues($data);

            $setParts = [];
            $whereParts = [];
            $params = [];

            // Build SET clause
            foreach ($data as $column => $value) {
                $setParts[] = "{$column} = :set_{$column}";
                $params[":set_{$column}"] = $value;
            }
            
            // Build WHERE clause
            foreach ($conditions as $column => $value) {
                $whereParts[] = "{$column} = :where_{$column}";
                $params[":where_{$column}"] = $value;
            }
            
            $setClause = implode(', ', $setParts);
            $whereClause = 'WHERE ' . implode(' AND ', $whereParts);
            
            $query = "UPDATE {$table} SET {$setClause} {$whereClause}";
            
            Logger::debug("Updating records", [
                'table' => $table,
                'query' => $query,
                'data' => $data,
                'conditions' => $conditions
            ]);
            
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute($params);
            $affectedRows = $stmt->rowCount();
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($result) {
                Logger::info("Update operation completed", [
                    'table' => $table,
                    'affected_rows' => $affectedRows,
                    'duration_ms' => $duration
                ]);
                return $affectedRows;
            }
            
            Logger::error("Update operation failed", [
                'table' => $table,
                'duration_ms' => $duration
            ]);
            return false;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Update operation failed", [
                'table' => $table,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Delete records
     */
    public function delete($table, $conditions) {
        $startTime = microtime(true);
        
        try {
            $whereParts = [];
            $params = [];
            
            foreach ($conditions as $column => $value) {
                $whereParts[] = "{$column} = :{$column}";
                $params[":{$column}"] = $value;
            }
            
            $whereClause = 'WHERE ' . implode(' AND ', $whereParts);
            $query = "DELETE FROM {$table} {$whereClause}";
            
            Logger::debug("Deleting records", [
                'table' => $table,
                'query' => $query,
                'conditions' => $conditions
            ]);
            
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute($params);
            $affectedRows = $stmt->rowCount();
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($result) {
                Logger::info("Delete operation completed", [
                    'table' => $table,
                    'affected_rows' => $affectedRows,
                    'duration_ms' => $duration
                ]);
                return $affectedRows;
            }
            
            Logger::error("Delete operation failed", [
                'table' => $table,
                'duration_ms' => $duration
            ]);
            return false;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Delete operation failed", [
                'table' => $table,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Count records with conditions
     */
    public function count($table, $conditions = []) {
        $startTime = microtime(true);
        
        try {
            $whereClause = '';
            $params = [];
            
            if (!empty($conditions)) {
                $whereParts = [];
                foreach ($conditions as $column => $value) {
                    $whereParts[] = "{$column} = :{$column}";
                    $params[":{$column}"] = $value;
                }
                $whereClause = 'WHERE ' . implode(' AND ', $whereParts);
            }
            
            $query = "SELECT COUNT(*) as total FROM {$table} {$whereClause}";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch();
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::debug("Count operation completed", [
                'table' => $table,
                'count' => $result['total'],
                'duration_ms' => $duration
            ]);
            
            return (int)$result['total'];
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Count operation failed", [
                'table' => $table,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Execute custom query
     */
    public function query($sql, $params = []) {
        $startTime = microtime(true);
        
        try {
            Logger::debug("Executing custom query", [
                'query' => $sql,
                'params' => $params
            ]);
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            // Determine if it's a SELECT query
            if (stripos(trim($sql), 'SELECT') === 0) {
                $result = $stmt->fetchAll();
            } else {
                $result = $stmt->rowCount();
            }
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::info("Custom query executed", [
                'duration_ms' => $duration,
                'result_type' => gettype($result)
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error("Custom query failed", [
                'query' => $sql,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ]);
            throw $e;
        }
    }

    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->conn->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit() {
        return $this->conn->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->conn->rollBack();
    }

    /**
     * Test connection
     */
    public function testConnection() {
        try {
            $conn = $this->getConnection();
            $startTime = microtime(true);
            $stmt = $conn->query("SELECT 1 as test");
            $result = $stmt->fetch();
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Logger::debug("Database connection test", [
                'success' => !empty($result),
                'duration_ms' => $duration
            ]);
            
            return !empty($result);
        } catch (Exception $e) {
            Logger::error("Database connection test failed", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Destructor to clean up resources
     */
    public function __destruct() {
        $this->closeCursor();
    }
}
?>