<?php
//以下是我自己写的php操作mysql的库，具体见github https://github.com/TW-SkyHope/Sql-To-PHP
/**
 * MySQL数据库操作类（基于PDO）- 完全封装版
 * 用户无需编写任何SQL语句，所有操作通过PHP数据类型完成
 * 支持单例模式、连接池、查询缓存等高级功能
 */
class MySQLiPDO {
    private static $instance = null;
    private $pdo;
    private $error;
    private $config;
    private $connectionId;
    private static $cache = [];
    private static $cacheEnabled = false;
    private static $cacheTTL = 3600;
    private $transactionDepth = 0;
    private $savepoints = [];
    private $logLevel = 'error';
    private $logs = [];

    /**
     * 私有构造函数，防止直接实例化
     * @param mixed $connection PDO实例或配置数组
     */
    private function __construct($connection) {
        $this->connectionId = uniqid('conn_', true);
        
        if ($connection instanceof PDO) {
            $this->pdo = $connection;
        } else {
            if (!is_array($connection)) {
                throw new InvalidArgumentException("构造参数必须是PDO实例或配置数组");
            }
            
            $this->config = $this->validateConnectionConfig($connection);
            $dsn = "mysql:host={$this->config['host']};port={$this->config['port']};dbname={$this->config['dbname']};charset={$this->config['charset']}";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];
            
            // 添加持久连接支持
            if (!empty($this->config['persistent'])) {
                $options[PDO::ATTR_PERSISTENT] = true;
            }
            
            try {
                $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], $options);
                $this->log('info', "数据库连接成功 (ID: {$this->connectionId})");
            } catch (PDOException $e) {
                $this->error = "数据库连接失败: " . $e->getMessage();
                $this->log('error', $this->error);
                throw new RuntimeException($this->error);
            }
        }
    }
    
    /**
     * 获取单例实例
     * @param mixed $connection PDO实例或配置数组
     * @return MySQLiPDO
     */
    public static function getInstance($connection = null) {
        if (self::$instance === null) {
            self::$instance = new self($connection);
        }
        return self::$instance;
    }

    /**
     * 验证并标准化连接配置
     * @param array $config 连接配置数组
     * @return array 标准化后的配置数组
     */
    private function validateConnectionConfig($config) {
        return [
            'host' => $config['host'] ?? 'localhost',
            'port' => isset($config['port']) ? (int)$config['port'] : 3306,
            'dbname' => $config['dbname'] ?? '',
            'username' => $config['username'] ?? '',
            'password' => $config['password'] ?? '',
            'charset' => $config['charset'] ?? 'utf8mb4',
            'persistent' => $config['persistent'] ?? false,
            'ssl' => $config['ssl'] ?? false,
            'timeout' => isset($config['timeout']) ? (int)$config['timeout'] : 30
        ];
    }
    
    /**
     * 验证表名合法性
     * @param string $table 表名
     * @return bool
     * @throws InvalidArgumentException
     */
    private function validateTableName($table) {
        // 只允许字母、数字、下划线和$符号
        if (!preg_match('/^[a-zA-Z0-9_\$]+$/', $table)) {
            throw new InvalidArgumentException("无效的表名: {$table}");
        }
        return true;
    }
    
    /**
     * 验证字段名合法性
     * @param string|array $fields 字段名或字段数组
     * @return bool
     * @throws InvalidArgumentException
     */
    private function validateFields($fields) {
        if (is_array($fields)) {
            foreach ($fields as $field) {
                $this->validateFieldName($field);
            }
        } else {
            $this->validateFieldName($fields);
        }
        return true;
    }
    
    /**
     * 验证单个字段名合法性
     * @param string $field 字段名
     * @return bool
     * @throws InvalidArgumentException
     */
    private function validateFieldName($field) {
        // 处理通配符
        if ($field === '*') {
            return true;
        }
        // 处理带表前缀的字段名
        if (strpos($field, '.') !== false) {
            list($tableAlias, $fieldName) = explode('.', $field);
            $this->validateTableName($tableAlias);
            $this->validateFieldName($fieldName);
            return true;
        }
        // 只允许字母、数字、下划线
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
            throw new InvalidArgumentException("无效的字段名: {$field}");
        }
        return true;
    }
    
    /**
     * 生成查询缓存键
     * @param string $sql SQL语句
     * @param array $params 查询参数
     * @return string 唯一缓存键
     */
    private function generateCacheKey($sql, $params) {
        return md5($sql . json_encode($params, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 清除查询缓存
     * @param string|null $table 表名，为null则清除所有缓存
     * @return void
     */
    public function clearCache($table = null) {
        if ($table === null) {
            self::$cache = [];
            $this->log('info', "Cleared all query cache");
        } else {
            $tablePattern = "SELECT.*FROM.*`{$table}`";
            foreach (self::$cache as $key => $cached) {
                if (preg_match("/{$tablePattern}/i", $cached['sql'])) {
                    unset(self::$cache[$key]);
                }
            }
            $this->log('info', "Cleared cache for table: {$table}");
        }
    }
    
    /**
     * 设置缓存状态
     * @param bool $enabled 是否启用缓存
     * @param int $ttl 缓存过期时间（秒）
     * @return void
     */
    public function setCacheEnabled($enabled, $ttl = 3600) {
        self::$cacheEnabled = $enabled;
        self::$cacheTTL = $ttl;
        $this->log('info', "Cache " . ($enabled ? "enabled" : "disabled") . " with TTL: {$ttl}s");
    }

    /**
     * 根据条件查询单条记录
     * @param string $table 表名
     * @param array $conditions 条件数组 ['字段名' => '值' 或复杂条件]
     * @param array $fields 要查询的字段(默认全部)
     * @return array|null 单条记录或null
     */
    public function findOne($table, $conditions = [], $fields = ['*']) {
        $this->validateTableName($table);
        $this->validateFields($fields);
        
        $where = '';
        $params = [];
        
        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $field => $value) {
                $this->validateFieldName($field);
                // 支持简单条件和复杂条件
                if (is_array($value)) {
                    // 复杂条件格式: ['>', 100] 或 ['LIKE', '%keyword%']
                    $operator = $value[0] ?? '=';
                    $actualValue = $value[1] ?? null;
                    $whereParts[] = "`{$field}` {$operator} :{$field}";
                    $params[":{$field}"] = $actualValue;
                } else {
                    // 简单条件格式: '字段名' => '值'
                    $whereParts[] = "`{$field}` = :{$field}";
                    $params[":{$field}"] = $value;
                }
            }
            $where = 'WHERE ' . implode(' AND ', $whereParts);
        }
        
        $fieldList = is_array($fields) ? implode(', ', $fields) : $fields;
        $sql = "SELECT {$fieldList} FROM `{$table}` {$where} LIMIT 1";
        
        // 生成缓存键
        $cacheKey = $this->generateCacheKey($sql, $params);
        
        // 尝试从缓存获取
        if (self::$cacheEnabled && isset(self::$cache[$cacheKey])) {
            $cached = self::$cache[$cacheKey];
            if (time() < $cached['expire']) {
                $this->log('debug', "Cache hit for: {$sql}");
                return $cached['data'] ? $cached['data'][0] : null;
            } else {
                // 缓存过期，删除
                unset(self::$cache[$cacheKey]);
            }
        }
        
        $result = $this->internalQuery($sql, $params);
        
        // 存入缓存
        if (self::$cacheEnabled) {
            self::$cache[$cacheKey] = [
                'data' => $result,
                'expire' => time() + self::$cacheTTL
            ];
            $this->log('debug', "Cache set for: {$sql}");
        }
        
        return $result ? $result[0] : null;
    }

    /**
     * 根据条件查询多条记录
     * @param string $table 表名
     * @param array $conditions 条件数组 ['字段名' => '值' 或复杂条件]
     * @param array $options 选项 ['fields' => [], 'order' => '', 'limit' => '', 'offset' => '', 'join' => '', 'group' => '', 'having' => '']
     * @return array 结果数组
     */
    public function findAll($table, $conditions = [], $options = []) {
        $this->validateTableName($table);
        
        $where = '';
        $params = [];
        
        // 处理查询字段
        $fields = isset($options['fields']) ? $options['fields'] : ['*'];
        $this->validateFields($fields);
        $fieldList = is_array($fields) ? implode(', ', $fields) : $fields;
        
        // 处理WHERE条件
        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $field => $value) {
                $this->validateFieldName($field);
                // 支持复杂条件
                if (is_array($value)) {
                    $operator = $value[0] ?? '=';
                    $actualValue = $value[1] ?? null;
                    $whereParts[] = "`{$field}` {$operator} :{$field}";
                    $params[":{$field}"] = $actualValue;
                } else {
                    $whereParts[] = "`{$field}` = :{$field}";
                    $params[":{$field}"] = $value;
                }
            }
            $where = 'WHERE ' . implode(' AND ', $whereParts);
        }
        
        // 处理JOIN
        $join = '';
        if (isset($options['join'])) {
            if (is_array($options['join'])) {
                foreach ($options['join'] as $joinItem) {
                    $join .= " {$joinItem['type']} JOIN `{$joinItem['table']}` ON {$joinItem['on']}";
                }
            } else {
                $join = " {$options['join']}";
            }
        }
        
        // 处理GROUP BY
        $group = '';
        if (isset($options['group'])) {
            $this->validateFields($options['group']);
            $group = " GROUP BY {$options['group']}";
        }
        
        // 处理HAVING
        $having = '';
        if (isset($options['having'])) {
            $having = " HAVING {$options['having']}";
        }
        
        // 处理ORDER BY
        $order = '';
        if (isset($options['order'])) {
            $order = " ORDER BY {$options['order']}";
        }
        
        // 处理分页
        $limit = '';
        $offset = '';
        if (isset($options['limit'])) {
            $limit = " LIMIT {$options['limit']}";
            if (isset($options['offset'])) {
                $offset = " OFFSET {$options['offset']}";
            }
        }
        
        $sql = "SELECT {$fieldList} FROM `{$table}` {$join} {$where} {$group} {$having} {$order} {$limit} {$offset}";
        
        // 生成缓存键
        $cacheKey = $this->generateCacheKey($sql, $params);
        
        // 尝试从缓存获取
        if (self::$cacheEnabled && isset(self::$cache[$cacheKey])) {
            $cached = self::$cache[$cacheKey];
            if (time() < $cached['expire']) {
                $this->log('debug', "Cache hit for: {$sql}");
                return $cached['data'];
            } else {
                unset(self::$cache[$cacheKey]);
            }
        }
        
        $result = $this->internalQuery($sql, $params);
        
        // 存入缓存
        if (self::$cacheEnabled) {
            self::$cache[$cacheKey] = [
                'sql' => $sql,
                'data' => $result,
                'expire' => time() + self::$cacheTTL
            ];
            $this->log('debug', "Cache set for: {$sql}");
        }
        
        return $result;
    }

    /**
     * 根据条件查询记录数量
     * @param string $table 表名
     * @param array $conditions 条件数组
     * @return int 记录数量
     */
    public function count($table, $conditions = []) {
        $this->validateTableName($table);
        
        $where = '';
        $params = [];
        
        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $field => $value) {
                $this->validateFieldName($field);
                if (is_array($value)) {
                    $operator = $value[0] ?? '=';
                    $actualValue = $value[1] ?? null;
                    $whereParts[] = "`{$field}` {$operator} :{$field}";
                    $params[":{$field}"] = $actualValue;
                } else {
                    $whereParts[] = "`{$field}` = :{$field}";
                    $params[":{$field}"] = $value;
                }
            }
            $where = 'WHERE ' . implode(' AND ', $whereParts);
        }
        
        $sql = "SELECT COUNT(*) AS count FROM `{$table}` {$where}";
        
        // 生成缓存键
        $cacheKey = $this->generateCacheKey($sql, $params);
        
        // 尝试从缓存获取
        if (self::$cacheEnabled && isset(self::$cache[$cacheKey])) {
            $cached = self::$cache[$cacheKey];
            if (time() < $cached['expire']) {
                $this->log('debug', "Cache hit for: {$sql}");
                return $cached['data'] ? (int)$cached['data'][0]['count'] : 0;
            } else {
                unset(self::$cache[$cacheKey]);
            }
        }
        
        $result = $this->internalQuery($sql, $params);
        $count = $result ? (int)$result[0]['count'] : 0;
        
        // 存入缓存
        if (self::$cacheEnabled) {
            self::$cache[$cacheKey] = [
                'sql' => $sql,
                'data' => $result,
                'expire' => time() + self::$cacheTTL
            ];
            $this->log('debug', "Cache set for: {$sql}");
        }
        
        return $count;
    }

    /**
     * 参数消毒处理
     * @param array $params 参数数组
     * @return array 消毒后的参数数组
     */
    private function sanitizeParams($params) {
        return array_map(function($value) {
            if (is_string($value)) {
                // 只进行必要的转义，避免双重转义
                return trim($value);
            }
            return $value;
        }, $params);
    }

    /**
     * 标准化数据格式
     * @param mixed $data 原始数据
     * @param array $columns 字段名数组（用于索引数组）
     * @return array 标准化后的关联数组
     */
    private function normalizeData($data, $columns = []) {
        if (is_object($data)) {
            $data = (array)$data;
        }
        
        // 处理索引数组
        if (isset($data[0])) {
            if (empty($columns)) {
                throw new InvalidArgumentException("索引数组必须提供字段名（columns参数）");
            }
            
            $assocData = [];
            foreach ($columns as $index => $column) {
                if (isset($data[$index])) {
                    $assocData[$column] = $data[$index];
                }
            }
            return $assocData;
        }
        
        // 消毒处理
        return $this->sanitizeParams($data);
    }
    
    /**
     * 插入单条数据
     * @param string $table 表名
     * @param array|object $data 数据(支持关联数组/对象/索引数组)
     * @param array $columns 可选字段名(当$data为索引数组时使用)
     * @return int|false 返回插入ID或false
     */
    public function insert($table, $data, $columns = []) {
        $this->validateTableName($table);
        
        $data = $this->normalizeData($data, $columns);
        if (empty($data)) {
            throw new InvalidArgumentException("插入数据不能为空");
        }
        
        // 验证字段名
        $this->validateFields(array_keys($data));

        $columns = implode('`, `', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO `{$table}` (`{$columns}`) VALUES ({$placeholders})";
        
        try {
            $result = $this->internalExecute($sql, $data);
            // 清除相关缓存
            $this->clearCache($table);
            $this->log('info', "Inserted record into table {$table}");
            return $result ? $this->pdo->lastInsertId() : false;
        } catch (PDOException $e) {
            $this->error = "插入失败: " . $e->getMessage();
            $this->log('error', $this->error);
            return false;
        }
    }

    /**
     * 批量插入数据
     * @param string $table 表名
     * @param array $dataList 数据列表
     * @param array $columns 可选字段名(当$dataList包含索引数组时使用)
     * @param array $options 批量插入选项
     * @return int|false 返回影响行数或false
     */
    public function batchInsert($table, $dataList, $columns = [], $options = []) {
        $this->validateTableName($table);
        
        if (empty($dataList)) return 0;
        
        // 标准化首行数据并确定列顺序
        $firstData = $this->normalizeData($dataList[0], $columns);
        $columnOrder = array_keys($firstData);
        
        // 验证字段名
        $this->validateFields($columnOrder);
        
        $columnStr = implode('`, `', $columnOrder);
        
        $placeholders = [];
        $params = [];
        $placeholderRow = '(' . implode(', ', array_fill(0, count($columnOrder), '?')) . ')';
        
        foreach ($dataList as $row) {
            $rowData = $this->normalizeData($row, $columnOrder);
            $placeholders[] = $placeholderRow;
            // 严格按照列顺序添加参数
            foreach ($columnOrder as $col) {
                $params[] = $rowData[$col] ?? null;
            }
        }
        
        $sql = "INSERT INTO `{$table}` (`{$columnStr}`) VALUES " . implode(', ', $placeholders);
        
        // 处理ON DUPLICATE KEY UPDATE
        if (isset($options['onDuplicate'])) {
            $sql .= " ON DUPLICATE KEY UPDATE {$options['onDuplicate']}";
        }
        
        try {
            $result = $this->internalExecute($sql, $params);
            // 清除相关缓存
            $this->clearCache($table);
            $this->log('info', "Batch inserted {$result} records into table {$table}");
            return $result;
        } catch (PDOException $e) {
            $this->error = "批量插入失败: " . $e->getMessage();
            $this->log('error', $this->error);
            return false;
        }
    }

    /**
     * 更新记录
     * @param string $table 表名
     * @param array|object $data 更新数据
     * @param array $conditions 条件数组 ['字段名' => '值' 或复杂条件]
     * @return int|false 影响行数或false
     */
    public function update($table, $data, $conditions = []) {
        $this->validateTableName($table);
        
        $data = $this->normalizeData($data);
        if (empty($data)) {
            throw new InvalidArgumentException("更新数据不能为空");
        }
        
        $set = [];
        $setParams = [];
        
        // 验证字段名
        foreach (array_keys($data) as $field) {
            $this->validateFieldName($field);
        }
        
        foreach ($data as $key => $value) {
            $paramKey = ':__set_' . $key; // 使用唯一前缀
            $set[] = "`{$key}` = {$paramKey}";
            $setParams[$paramKey] = $value;
        }
        
        $where = '';
        $whereParams = [];
        
        if (!empty($conditions)) {
            $whereParts = [];
            foreach ($conditions as $field => $value) {
                $this->validateFieldName($field);
                if (is_array($value)) {
                    $operator = $value[0] ?? '=';
                    $actualValue = $value[1] ?? null;
                    $whereParts[] = "`{$field}` {$operator} :__where_{$field}";
                    $whereParams[":__where_{$field}"] = $actualValue;
                } else {
                    $whereParts[] = "`{$field}` = :__where_{$field}";
                    $whereParams[":__where_{$field}"] = $value;
                }
            }
            $where = 'WHERE ' . implode(' AND ', $whereParts);
        } else {
            throw new InvalidArgumentException("更新操作必须提供条件");
        }
        
        $sql = "UPDATE `{$table}` SET " . implode(', ', $set) . " {$where}";
        
        try {
            $result = $this->internalExecute($sql, array_merge($setParams, $whereParams));
            // 清除相关缓存
            $this->clearCache($table);
            $this->log('info', "Updated {$result} records in table {$table}");
            return $result;
        } catch (PDOException $e) {
            $this->error = "更新失败: " . $e->getMessage();
            $this->log('error', $this->error);
            return false;
        }
    }

    /**
     * 删除记录
     * @param string $table 表名
     * @param array $conditions 条件数组 ['字段名' => '值' 或复杂条件]
     * @return int|false 影响行数或false
     */
    public function delete($table, $conditions = []) {
        $this->validateTableName($table);
        
        $where = '';
        $params = [];
        
        if (empty($conditions)) {
            throw new InvalidArgumentException("删除操作必须提供条件，防止误删全表数据");
        }
        
        $whereParts = [];
        foreach ($conditions as $field => $value) {
            $this->validateFieldName($field);
            if (is_array($value)) {
                $operator = $value[0] ?? '=';
                $actualValue = $value[1] ?? null;
                $whereParts[] = "`{$field}` {$operator} :{$field}";
                $params[":{$field}"] = $actualValue;
            } else {
                $whereParts[] = "`{$field}` = :{$field}";
                $params[":{$field}"] = $value;
            }
        }
        $where = 'WHERE ' . implode(' AND ', $whereParts);
        
        $sql = "DELETE FROM `{$table}` {$where}";
        
        try {
            $result = $this->internalExecute($sql, $params);
            // 清除相关缓存
            $this->clearCache($table);
            $this->log('info', "Deleted {$result} records from table {$table}");
            return $result;
        } catch (PDOException $e) {
            $this->error = "删除失败: " . $e->getMessage();
            $this->log('error', $this->error);
            return false;
        }
    }

    /**
     * 开始事务
     * 支持嵌套事务（基于savepoint）
     * @return bool 是否成功
     */
    public function beginTransaction() {
        if ($this->transactionDepth === 0) {
            $result = $this->pdo->beginTransaction();
            $this->transactionDepth = 1;
            $this->log('info', "Transaction started");
        } else {
            $savepointName = "savepoint_{$this->transactionDepth}";
            $this->pdo->exec("SAVEPOINT {$savepointName}");
            $this->savepoints[] = $savepointName;
            $this->transactionDepth++;
            $this->log('debug', "Savepoint created: {$savepointName}");
            $result = true;
        }
        return $result;
    }
    
    /**
     * 提交事务
     * @return bool 是否成功
     */
    public function commit() {
        if ($this->transactionDepth === 0) {
            throw new RuntimeException("No transaction started");
        }
        
        if ($this->transactionDepth === 1) {
            $result = $this->pdo->commit();
            $this->transactionDepth = 0;
            $this->savepoints = [];
            $this->log('info', "Transaction committed");
        } else {
            $this->transactionDepth--;
            array_pop($this->savepoints);
            $this->log('debug', "Savepoint released, transaction depth: {$this->transactionDepth}");
            $result = true;
        }
        return $result;
    }
    
    /**
     * 回滚事务
     * @param int|null $toDepth 回滚到指定深度，null表示回滚全部
     * @return bool 是否成功
     */
    public function rollBack($toDepth = null) {
        if ($this->transactionDepth === 0) {
            throw new RuntimeException("No transaction started");
        }
        
        if ($toDepth === null || $toDepth <= 0) {
            $result = $this->pdo->rollBack();
            $this->transactionDepth = 0;
            $this->savepoints = [];
            $this->log('info', "Transaction rolled back completely");
        } else if ($toDepth < $this->transactionDepth) {
            $savepointName = "savepoint_{$toDepth}";
            $result = $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepointName}");
            $this->transactionDepth = $toDepth;
            $this->savepoints = array_slice($this->savepoints, 0, $toDepth - 1);
            $this->log('debug', "Transaction rolled back to savepoint: {$savepointName}");
        } else {
            $result = true;
        }
        return $result;
    }
    
    /**
     * 获取事务深度
     * @return int 事务深度
     */
    public function getTransactionDepth() {
        return $this->transactionDepth;
    }
    
    /**
     * 是否在事务中
     * @return bool
     */
    public function inTransaction() {
        return $this->transactionDepth > 0;
    }

    /**
     * 获取表结构
     * @param string $table 表名
     * @return array 表结构数组
     */
    public function getTableStructure($table) {
        $this->validateTableName($table);
        
        $sql = "DESCRIBE `{$table}`";
        return $this->internalQuery($sql);
    }
    
    /**
     * 获取数据库版本
     * @return string 数据库版本
     */
    public function getVersion() {
        return $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    }
    
    /**
     * 获取连接状态
     * @return array 连接状态信息
     */
    public function getConnectionStatus() {
        return [
            'connection_id' => $this->connectionId,
            'in_transaction' => $this->inTransaction(),
            'transaction_depth' => $this->transactionDepth,
            'server_version' => $this->getVersion(),
            'cache_enabled' => self::$cacheEnabled,
            'cache_size' => count(self::$cache)
        ];
    }

    /**
     * 创建表
     * @param string $table 表名
     * @param array $fields 字段定义(支持多种格式)
     * @param array $options 表选项(ENGINE/CHARSET等)
     * @return bool 是否成功
     */
    public function createTable($table, $fields, $options = []) {
        $this->validateTableName($table);
        
        $fieldDefinitions = [];
        $primaryKeys = [];
        
        foreach ($fields as $name => $definition) {
            // 跳过PRIMARY KEY定义，稍后处理
            if ($name === 'PRIMARY KEY') {
                continue;
            }
            
            // 简化格式: '字段名' => '类型 属性'
            if (is_string($definition)) {
                $fieldDefinitions[] = "`{$name}` {$definition}";
                // 检查字符串定义中是否包含PRIMARY KEY
                if (strpos(strtoupper($definition), 'PRIMARY KEY') !== false) {
                    $primaryKeys[] = $name;
                }
            } 
            // 标准格式: ['name'=>'字段', 'type'=>'类型', ...]
            else if (is_array($definition)) {
                $fieldDefinitions[] = $this->buildColumnDefinition($definition);
                // 检查数组定义中是否有primary键
                if (!empty($definition['primary'])) {
                    $primaryKeys[] = $definition['name'] ?? $name;
                }
            }
        }
        
        // 处理显式的PRIMARY KEY定义
        if (isset($fields['PRIMARY KEY'])) {
            $pkDef = $fields['PRIMARY KEY'];
            if (is_string($pkDef)) {
                // 格式: 'PRIMARY KEY' => '(col1, col2)'
                $primaryKeys = [trim($pkDef, '()')];
            } elseif (is_array($pkDef)) {
                // 格式: 'PRIMARY KEY' => ['col1', 'col2']
                $primaryKeys = $pkDef;
            }
        }
        
        $engine = $options['engine'] ?? 'InnoDB';
        $charset = $options['charset'] ?? 'utf8mb4';
        $collate = $options['collate'] ?? 'utf8mb4_general_ci';
        
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (" . 
               implode(', ', $fieldDefinitions);
        
        // 添加主键定义
        if (!empty($primaryKeys)) {
            $pkColumns = is_array($primaryKeys) ? 
                        '`' . implode('`, `', $primaryKeys) . '`' : 
                        $primaryKeys;
            $sql .= ", PRIMARY KEY ({$pkColumns})";
        }
        
        $sql .= ") ENGINE={$engine} DEFAULT CHARSET={$charset} COLLATE={$collate}";
        
        try {
            $result = $this->internalExecute($sql) !== false;
            if ($result) {
                $this->log('info', "Created table: {$table}");
            }
            return $result;
        } catch (PDOException $e) {
            $this->error = "创建表失败: " . $e->getMessage();
            $this->log('error', $this->error, ['table' => $table, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 构建列定义
     * @param array $field 字段定义数组
     * @return string 列定义SQL语句
     */
    private function buildColumnDefinition($field) {
        if (!isset($field['name']) && !isset($field['type'])) {
            throw new InvalidArgumentException("字段定义必须包含name和type属性");
        }
        
        $name = $field['name'] ?? '';
        $type = $field['type'] ?? '';
        
        $definition = "`{$name}` {$type}";

        // 处理长度定义
        if (isset($field['length'])) {
            $length = is_array($field['length']) ? 
                     implode(',', $field['length']) : 
                     $field['length'];
            $definition .= "({$length})";
        }

        // 处理无符号
        if (!empty($field['unsigned'])) {
            $definition .= ' UNSIGNED';
        }

        // 处理非空约束
        $nullHandled = false;
        if (isset($field['notnull'])) {
            $definition .= $field['notnull'] ? ' NOT NULL' : ' NULL';
            $nullHandled = true;
        }

        // 处理默认值
        if (array_key_exists('default', $field)) {
            if ($field['default'] === null) {
                $definition .= ' DEFAULT NULL';
            } elseif (strtoupper($field['default']) === 'CURRENT_TIMESTAMP') {
                $definition .= ' DEFAULT CURRENT_TIMESTAMP';
            } else {
                $defaultValue = $this->formatDefaultValue($field);
                $definition .= " DEFAULT {$defaultValue}";
            }
        } elseif (!$nullHandled) {
            $definition .= ' NULL';
        }

        // 处理自增
        if (!empty($field['auto_increment'])) {
            $definition .= ' AUTO_INCREMENT';
        }

        // 处理主键 - 只标记，不在字段级别添加PRIMARY KEY，统一在最后添加
        if (!empty($field['primary'])) {
            // 这里不做处理，主键会在createTable方法中统一处理
        }

        // 处理注释
        if (isset($field['comment'])) {
            $definition .= " COMMENT '" . str_replace("'", "''", $field['comment']) . "'";
        }

        return $definition;
    }

    /**
     * 格式化默认值
     * @param array $field 字段定义数组
     * @return string 格式化后的默认值
     */
    private function formatDefaultValue($field) {
        $type = strtoupper($field['type']);
        $default = $field['default'];
        
        // 数字类型且不是字符串类字段
        if (is_numeric($default) && !in_array($type, ['CHAR','VARCHAR','TEXT','ENUM','SET'])) {
            return $default;
        }
        
        // 布尔值转换为数字
        if (is_bool($default)) {
            return $default ? 1 : 0;
        }
        
        // 其他情况加引号
        return "'" . str_replace("'", "''", $default) . "'";
    }

    /**
     * 修改表结构
     * @param string $table 表名
     * @param array $alter 修改操作数组
     * @return bool 是否成功
     */
    public function alterTable($table, $alter) {
        $this->validateTableName($table);
        
        $sql = "ALTER TABLE `{$table}` " . implode(', ', array_map(function($action) {
            if (!isset($action['type']) || !isset($action['field'])) {
                throw new InvalidArgumentException("ALTER操作必须包含type和field属性");
            }
            
            $definition = "{$action['type']} `{$action['field']}`";
            if (isset($action['definition'])) {
                $definition .= " {$action['definition']}";
            }
            if (isset($action['after'])) {
                $definition .= " AFTER `{$action['after']}`";
            }
            return $definition;
        }, $alter));
        
        try {
            $result = $this->internalExecute($sql) !== false;
            if ($result) {
                $this->log('info', "Altered table: {$table}");
            }
            return $result;
        } catch (PDOException $e) {
            $this->error = "修改表结构失败: " . $e->getMessage();
            $this->log('error', $this->error, ['table' => $table, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 获取错误信息
     * @return string 错误信息
     */
    public function getError() {
        return $this->error;
    }

    /**
     * 记录日志
     * @param string $level 日志级别: debug, info, warn, error
     * @param string $message 日志消息
     * @param array $context 上下文信息
     * @return void
     */
    private function log($level, $message, $context = []) {
        $levels = ['debug', 'info', 'warn', 'error'];
        $levelIndex = array_search(strtolower($level), $levels);
        $currentLevelIndex = array_search(strtolower($this->logLevel), $levels);
        
        if ($levelIndex >= $currentLevelIndex) {
            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'level' => strtoupper($level),
                'message' => $message,
                'context' => $context,
                'connection_id' => $this->connectionId
            ];
            $this->logs[] = $logEntry;
            
            // 可以扩展为写入文件或其他日志系统
            if ($level === 'error') {
                error_log(json_encode($logEntry, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    
    /**
     * 设置日志级别
     * @param string $level 日志级别: debug, info, warn, error
     * @return void
     */
    public function setLogLevel($level) {
        $this->logLevel = strtolower($level);
        $this->log('info', "Log level set to: {$level}");
    }
    
    /**
     * 获取日志
     * @param string|null $level 过滤特定级别，null表示所有
     * @return array 日志条目数组
     */
    public function getLogs($level = null) {
        if ($level === null) {
            return $this->logs;
        }
        return array_filter($this->logs, function($log) use ($level) {
            return strtolower($log['level']) === strtolower($level);
        });
    }
    
    /**
     * 清空日志
     * @return void
     */
    public function clearLogs() {
        $this->logs = [];
        $this->log('debug', "Logs cleared");
    }
    
    /**
     * AES-256加密数据
     * @param string $data 要加密的数据
     * @param string $key 加密密钥
     * @return string 加密后的数据
     */
    public function encrypt($data, $key) {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * AES-256解密数据
     * @param string $encrypted 加密的数据
     * @param string $key 解密密钥
     * @return string 解密后的数据
     */
    public function decrypt($encrypted, $key) {
        $data = base64_decode($encrypted);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLength);
        $encryptedData = substr($data, $ivLength);
        return openssl_decrypt($encryptedData, 'aes-256-cbc', $key, 0, $iv);
    }
    
    /**
     * 执行SQL语句（用于不需要返回结果的语句）
     * @param string $sql SQL语句
     * @return bool 是否成功
     */
    public function exec($sql) {
        try {
            $result = $this->pdo->exec($sql);
            $this->log('info', "Executed SQL: {$sql}");
            return $result !== false;
        } catch (PDOException $e) {
            $this->error = "执行失败: " . $e->getMessage();
            $this->log('error', $this->error, ['sql' => $sql, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /********************* 内部方法 *********************/
    /**
     * 内部查询方法
     * @param string $sql SQL语句
     * @param array $params 查询参数
     * @return array|false 查询结果或false
     */
    protected function internalQuery($sql, $params = []) {
        $startTime = microtime(true);
        $this->log('debug', "Executing query", ['sql' => $sql, 'params' => $params]);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetchAll();
            
            $executionTime = microtime(true) - $startTime;
            $this->log('debug', "Query executed successfully", [
                'sql' => $sql,
                'rows' => count($result),
                'time' => round($executionTime, 4) . 's'
            ]);
            
            return $result;
        } catch (PDOException $e) {
            $this->error = "查询失败: " . $e->getMessage();
            $this->log('error', $this->error, [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return false;
        }
    }

    /**
     * 内部执行方法
     * @param string $sql SQL语句
     * @param array $params 查询参数
     * @return int|false 影响行数或false
     */
    protected function internalExecute($sql, $params = []) {
        $startTime = microtime(true);
        $this->log('debug', "Executing statement", ['sql' => $sql, 'params' => $params]);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rowCount = $stmt->rowCount();
            
            $executionTime = microtime(true) - $startTime;
            $this->log('debug', "Statement executed successfully", [
                'sql' => $sql,
                'rows_affected' => $rowCount,
                'time' => round($executionTime, 4) . 's'
            ]);
            
            return $rowCount;
        } catch (PDOException $e) {
            $this->error = "执行失败: " . $e->getMessage();
            $this->log('error', $this->error, [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return false;
        }
    }
}
