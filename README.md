# MySql-To-PHP MySQL操作库

## 项目简介

MySql-To-PHP是一个基于PDO的PHP MySQL操作库，提供了简洁、安全、高效的数据库操作接口。用户无需编写任何SQL语句，所有操作通过PHP数据类型完成，极大简化了数据库开发工作。

### 核心价值

- **安全可靠**：基于PDO实现，内置参数化查询，有效lo防止SQL注入
- **简洁易用**：提供面向对象的API，支持链式调用和直观的方法命名
- **高效性能**：优化的查询执行，支持连接池和查询缓存
- **功能全面**：支持CRUD操作、事务管理、表结构操作等
- **兼容广泛**：支持PHP 7.0+和多种MySQL版本

## 安装指南

### 环境要求

- PHP 7.0 或更高版本
- PDO 扩展已启用
- MySQL 5.6 或更高版本

### 安装步骤

1. 下载 `mysql.php` 文件到您的项目目录
2. 在需要使用的PHP文件中引入该文件：
   ```php
   require_once 'mysql.php';
   ```

## 使用方法

### 1. 初始化连接

#### 标准连接方式
```php
// 配置数组方式
$db = new MySQLiPDO([
    'host' => 'localhost',
    'port' => 3306,
    'dbname' => 'test_db',
    'username' => 'root',
    'password' => 'password',
    'charset' => 'utf8mb4',
    'persistent' => false,
    'ssl' => false,
    'timeout' => 30
]);
```

#### 使用现有PDO连接
```php
// 已有的PDO连接方式
$pdo = new PDO('mysql:host=localhost;dbname=test_db', 'root', 'password');
$db = new MySQLiPDO($pdo);
```

#### 单例模式连接
```php
// 使用单例模式获取数据库实例
$db = MySQLiPDO::getInstance([
    'host' => 'localhost',
    'dbname' => 'test_db',
    'username' => 'root',
    'password' => 'password'
]);

// 后续可直接获取同一实例
$db = MySQLiPDO::getInstance();
```

### 2. CRUD操作

#### 查询单条记录
```php
// 查询ID为1的用户
$user = $db->findOne('users', ['id' => 1]);

// 指定查询字段
$user = $db->findOne('users', ['id' => 1], ['id', 'username', 'email']);

// 使用复杂条件查询
$user = $db->findOne('users', [
    'age' => ['>', 18],
    'status' => ['=', 'active']
]);
```

#### 查询多条记录
```php
// 查询所有状态为active的用户
$users = $db->findAll('users', ['status' => 'active']);

// 带选项的查询
$users = $db->findAll('users', 
    ['status' => 'active', 'age' => ['>', 18]], 
    [
        'fields' => ['id', 'username', 'email'],
        'order' => 'created_at DESC',
        'limit' => 10,
        'offset' => 20
    ]
);

// 高级查询 - 带JOIN和GROUP BY
$orders = $db->findAll('orders',
    ['status' => 'paid'],
    [
        'fields' => ['users.username', 'COUNT(orders.id) as order_count'],
        'join' => [
            [
                'type' => 'LEFT',
                'table' => 'users',
                'on' => 'orders.user_id = users.id'
            ]
        ],
        'group' => 'users.username',
        'having' => 'order_count > 5',
        'order' => 'order_count DESC'
    ]
);
```

#### 插入数据
```php
// 插入单条记录
$userId = $db->insert('users', [
    'username' => 'testuser',
    'email' => 'test@example.com',
    'password' => password_hash('password123', PASSWORD_DEFAULT),
    'created_at' => date('Y-m-d H:i:s')
]);

// 使用索引数组插入
$userId = $db->insert('users', 
    ['testuser', 'test@example.com', password_hash('password123', PASSWORD_DEFAULT)],
    ['username', 'email', 'password']
);

// 使用对象插入
$userObj = new stdClass();
$userObj->username = 'testuser2';
$userObj->email = 'test2@example.com';
$userId = $db->insert('users', $userObj);

// 批量插入
$insertedCount = $db->batchInsert('users', [
    ['username' => 'user1', 'email' => 'user1@example.com'],
    ['username' => 'user2', 'email' => 'user2@example.com'],
    ['username' => 'user3', 'email' => 'user3@example.com']
]);

// 批量插入带ON DUPLICATE KEY UPDATE
$insertedCount = $db->batchInsert('users', 
    [
        ['id' => 1, 'username' => 'updateduser'],
        ['id' => 2, 'username' => 'updateduser2']
    ],
    [],
    [
        'onDuplicate' => 'username = VALUES(username)'
    ]
);
```

#### 更新数据
```php
// 更新单条记录
$updatedCount = $db->update('users', 
    ['status' => 'inactive'], 
    ['id' => 1]
);

// 更新多条记录
$updatedCount = $db->update('users', 
    ['status' => 'active'], 
    ['group_id' => 2]
);

// 使用复杂条件更新
$updatedCount = $db->update('users', 
    ['last_login' => date('Y-m-d H:i:s')], 
    ['last_login' => ['<', date('Y-m-d H:i:s', strtotime('-30 days'))]]
);
```

#### 删除数据
```php
// 删除单条记录
$deletedCount = $db->delete('users', ['id' => 1]);

// 删除多条记录
$deletedCount = $db->delete('users', ['status' => 'inactive']);

// 使用复杂条件删除
$deletedCount = $db->delete('users', 
    ['created_at' => ['<', date('Y-m-d H:i:s', strtotime('-1 year'))], 'status' => 'inactive']
);
```

### 3. 事务管理

#### 基本事务
```php
try {
    $db->beginTransaction();
    
    // 执行多个数据库操作
    $db->insert('orders', ['user_id' => 1, 'total' => 100]);
    $db->update('users', ['balance' => 900], ['id' => 1]);
    
    $db->commit();
    echo '事务成功';
} catch (Exception $e) {
    $db->rollBack();
    echo '事务失败: ' . $e->getMessage();
}
```

#### 嵌套事务
```php
try {
    $db->beginTransaction();
    
    // 外层事务操作
    $db->insert('orders', ['user_id' => 1, 'total' => 200]);
    
    // 内层事务
    $db->beginTransaction();
    $db->update('users', ['balance' => 800], ['id' => 1]);
    $db->update('products', ['stock' => ['-', 1]], ['id' => 100]);
    
    // 提交内层事务
    $db->commit();
    
    // 提交外层事务
    $db->commit();
    echo '嵌套事务成功';
} catch (Exception $e) {
    $db->rollBack();
    echo '事务失败: ' . $e->getMessage();
}
```

#### 事务状态管理
```php
// 检查是否在事务中
if ($db->inTransaction()) {
    echo '当前正在事务中';
}

// 获取事务深度
$depth = $db->getTransactionDepth();

// 回滚到指定深度
$db->rollBack(1);
```

### 4. 表结构操作

#### 创建表
```php
// 简化格式创建表
$db->createTable('products', [
    'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
    'name' => 'VARCHAR(255) NOT NULL',
    'price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
    'status' => "ENUM('active','inactive') DEFAULT 'active'",
    'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
]);

// 详细格式创建表
$db->createTable('orders', [
    'id' => [
        'name' => 'id',
        'type' => 'INT',
        'auto_increment' => true,
        'primary' => true
    ],
    'user_id' => [
        'name' => 'user_id',
        'type' => 'INT',
        'notnull' => true,
        'comment' => '用户ID'
    ],
    'total' => [
        'name' => 'total',
        'type' => 'DECIMAL',
        'length' => [10, 2],
        'default' => 0,
        'comment' => '订单总金额'
    ]
], [
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',
    'collate' => 'utf8mb4_general_ci'
]);
```

#### 修改表结构
```php
// 添加字段
$db->alterTable('users', [
    [
        'type' => 'ADD',
        'field' => 'age',
        'definition' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'after' => 'email'
    ]
]);

// 修改字段
$db->alterTable('users', [
    [
        'type' => 'MODIFY',
        'field' => 'email',
        'definition' => 'VARCHAR(255) NOT NULL UNIQUE'
    ]
]);

// 删除字段
$db->alterTable('users', [
    [
        'type' => 'DROP',
        'field' => 'old_field'
    ]
]);
```

#### 获取表结构
```php
$tableStructure = $db->getTableStructure('users');
print_r($tableStructure);
```

### 5. 高级功能

#### 查询记录数量
```php
// 获取用户总数
$totalUsers = $db->count('users');

// 获取状态为active的用户数量
$activeUsers = $db->count('users', ['status' => 'active']);

// 使用复杂条件计数
$oldUsers = $db->count('users', ['created_at' => ['<', date('Y-m-d H:i:s', strtotime('-1 year'))]]);
```

#### 查询缓存
```php
// 启用查询缓存，设置缓存时间为300秒
$db->setCacheEnabled(true, 300);

// 第一次查询（无缓存，执行SQL）
$users1 = $db->findAll('users', ['status' => 'active']);

// 第二次查询（命中缓存，不执行SQL）
$users2 = $db->findAll('users', ['status' => 'active']);

// 清除特定表的缓存
$db->clearCache('users');

// 清除所有缓存
$db->clearCache();

// 禁用缓存
$db->setCacheEnabled(false);
```

#### 数据加密
```php
// 加密敏感数据
$encrypted = $db->encrypt('敏感数据', 'encryption_key');

// 解密数据
$decrypted = $db->decrypt($encrypted, 'encryption_key');

// 示例：存储加密数据
$db->insert('users', [
    'username' => 'testuser',
    'email' => 'test@example.com',
    'credit_card' => $db->encrypt('1234-5678-9012-3456', 'encryption_key')
]);

// 示例：读取并解密数据
$user = $db->findOne('users', ['username' => 'testuser']);
$creditCard = $db->decrypt($user['credit_card'], 'encryption_key');
```

#### 直接执行SQL
```php
// 执行简单SQL
$db->exec("CREATE INDEX idx_username ON users(username)");

// 执行查询并获取结果
$result = $db->internalQuery("SELECT * FROM users WHERE status = ?", ['active']);
print_r($result);
```

### 6. 连接与状态管理

#### 获取连接状态
```php
// 获取连接状态信息
$status = $db->getConnectionStatus();
print_r($status);
/*
返回示例：
[
    'connection_id' => 'conn_1234567890',
    'in_transaction' => false,
    'transaction_depth' => 0,
    'server_version' => '5.7.33',
    'cache_enabled' => true,
    'cache_size' => 10
]
*/
```

#### 获取MySQL版本
```php
$version = $db->getVersion();
echo "MySQL版本: {$version}";
```

### 7. 日志管理

#### 设置日志级别
```php
// 设置日志级别（debug, info, warn, error）
$db->setLogLevel('debug');
$db->setLogLevel('error');
```

#### 获取日志
```php
// 获取所有日志
$logs = $db->getLogs();

// 获取特定级别日志
$errorLogs = $db->getLogs('error');
$debugLogs = $db->getLogs('debug');

// 打印日志
print_r($logs);
```

#### 清空日志
```php
$db->clearLogs();
```

## 参数说明

### 构造函数参数

| 参数名 | 类型 | 描述 | 默认值 |
|--------|------|------|--------|
| connection | array/PDO | 数据库连接配置或PDO实例 | - |
| connection.host | string | 数据库主机地址 | localhost |
| connection.port | int | 数据库端口 | 3306 |
| connection.dbname | string | 数据库名称 | - |
| connection.username | string | 数据库用户名 | - |
| connection.password | string | 数据库密码 | - |
| connection.charset | string | 数据库字符集 | utf8mb4 |
| connection.persistent | bool | 是否使用持久连接 | false |
| connection.ssl | bool | 是否使用SSL连接 | false |
| connection.timeout | int | 连接超时时间（秒） | 30 |

### 查询条件参数

| 参数名 | 类型 | 描述 |
|--------|------|------|
| table | string | 表名 |
| conditions | array | 查询条件，支持简单条件（`['field' => 'value']`）和复杂条件（`['field' => ['>', 100]]`） |
| fields | array | 查询字段列表 |
| options | array | 查询选项，包含以下子选项： |
| options.fields | array | 查询字段列表 |
| options.order | string | 排序字段和方向，格式：`field ASC` 或 `field1 DESC, field2 ASC` |
| options.limit | int | 查询结果数量限制 |
| options.offset | int | 查询结果偏移量 |
| options.join | array | JOIN查询配置，格式：`[['type' => 'LEFT', 'table' => 'table_name', 'on' => 'join_condition']]` |
| options.group | string | GROUP BY字段 |
| options.having | string | HAVING条件 |

### 插入/更新参数

| 参数名 | 类型 | 描述 |
|--------|------|------|
| table | string | 表名 |
| data | array/object | 插入或更新的数据 |
| columns | array | 当data为索引数组时，指定字段名 |
| conditions | array | 更新条件 |
| options | array | 批量操作选项 |
| options.onDuplicate | string | 批量插入时的ON DUPLICATE KEY UPDATE语句 |

### 缓存参数

| 参数名 | 类型 | 描述 | 默认值 |
|--------|------|------|--------|
| enabled | bool | 是否启用缓存 | false |
| ttl | int | 缓存过期时间（秒） | 3600 |

### 日志参数

| 参数名 | 类型 | 描述 | 默认值 |
|--------|------|------|--------|
| level | string | 日志级别（debug, info, warn, error） | error |

## 注意事项

### 1. 安全性

- **SQL注入防护**：库内置了参数化查询，防止SQL注入，但仍需注意：
  - 不要直接拼接SQL语句，所有查询都通过方法调用完成
  - 避免将用户输入直接作为表名或字段名使用
  - 对用户输入进行严格验证和过滤

- **敏感数据处理**：
  - 使用`encrypt()`和`decrypt()`方法加密存储敏感数据（如密码、信用卡号等）
  - 密码应使用PHP内置的`password_hash()`和`password_verify()`函数处理
  - 避免在日志中记录敏感信息

- **权限管理**：
  - 为数据库用户分配最小必要权限
  - 定期更新数据库密码
  - 限制数据库访问IP

### 2. 性能优化

- **查询优化**：
  - 只查询需要的字段，避免使用`SELECT *`
  - 为频繁查询的字段添加索引
  - 合理使用`LIMIT`和`OFFSET`进行分页

- **缓存策略**：
  - 对频繁查询且变化不频繁的数据启用查询缓存
  - 根据数据更新频率调整缓存过期时间
  - 缓存键应包含所有影响查询结果的参数

- **批量操作**：
  - 使用`batchInsert()`和`batchUpdate()`方法处理大量数据
  - 合理控制单次批量操作的数据量

- **连接管理**：
  - 使用单例模式减少连接创建开销
  - 考虑使用连接池（如果实际实现）
  - 及时关闭不再需要的连接


### 3. 兼容性

- **PHP版本**：
  - 支持PHP 7.0及以上版本
  - 建议使用PHP 7.4+以获得更好的性能和安全性

- **MySQL版本**：
  - 支持MySQL 5.6及以上版本
  - 支持MariaDB 10.0及以上版本
  - 不同MySQL版本可能有语法差异，注意测试

- **字符集**：
  - 建议使用`utf8mb4`字符集支持完整的Unicode
  - 确保应用和数据库字符集一致

## 项目维护

若您正在使用我的项目对我的项目有新的需求或发现bug请向于本项目内报告，一般3-7天内会给出答复，后期可能会视作品小星星der数量增加更多功能！

## 作者的话：php懒人库
