# MySQLiPDO - 基于PDO的MySQL数据库操作类

## 项目简介

MySQLiPDO是一个在PHP中基于PDO的MySQL数据库操作类，提供了完全封装的数据库操作功能。用户无需编写任何SQL语句，所有操作通过PHP数据类型完成，支持单例模式、查询缓存、事务管理、表结构操作等高级功能。

### 核心价值

- **安全可靠**：基于PDO实现，内置参数化查询，有效防止SQL注入
- **简洁易用**：提供面向对象的API，支持单例模式和直观的方法命名
- **高效性能**：优化的查询执行，支持查询缓存
- **功能全面**：支持CRUD操作、事务管理、表结构操作、高级查询等
- **兼容广泛**：支持PHP 7.0+和多种MySQL版本
- **安全加密**：内置AES-256加密解密功能
- **日志记录**：支持详细的日志记录和监控
- **连接监控**：提供连接状态监控功能

## 安装指南

### 环境要求

- PHP 7.0 或更高版本
- PDO 扩展已启用
- MySQL 5.6 或更高版本

### 安装步骤

1. 下载 `mysql.php` 文件到您的项目目录
2. 在需要使用的PHP文件中引入该文件：
   ```php
   require_once 'path/to/mysql.php';
   ```

## 使用方法

### 1. 初始化连接

#### 单例模式（推荐）
```php
// 配置数组方式
$db = MySQLiPDO::getInstance([
    'host' => 'localhost',
    'port' => 3306,
    'dbname' => 'test_db',
    'username' => 'root',
    'password' => 'password',
    'charset' => 'utf8mb4',
    'persistent' => false
]);

// 已有的PDO连接方式
$pdo = new PDO('mysql:host=localhost;dbname=test_db', 'root', 'password');
$db = MySQLiPDO::getInstance($pdo);

// 获取已存在的实例
$db2 = MySQLiPDO::getInstance(); // 返回同一个实例
```

### 2. CRUD操作

#### 查询单条记录

```php
// 查询ID为1的用户
$user = $db->findOne('users', ['id' => 1]);

// 指定查询字段
$user = $db->findOne('users', ['id' => 1], ['id', 'username', 'email']);

// 复杂条件查询
$user = $db->findOne('users', [
    'age' => ['>', 18],
    'status' => ['IN', [1, 2]],
    'name' => ['LIKE', '%John%']
]);
```

#### 查询多条记录

```php
// 查询所有状态为1的用户
$users = $db->findAll('users', ['status' => 1]);

// 带选项的查询
$users = $db->findAll('users', 
    ['status' => 1], 
    [
        'fields' => ['id', 'username'],
        'order' => 'created_at DESC',
        'limit' => 10,
        'offset' => 0
    ]
);

// 复杂条件查询
$users = $db->findAll('users', 
    [
        'age' => ['BETWEEN', [18, 30]],
        'created_at' => ['>', '2023-01-01'],
        'status' => ['!=', 0]
    ],
    [
        'fields' => ['id', 'username', 'age'],
        'order' => 'age DESC, created_at ASC',
        'limit' => 20
    ]
);

// 高级查询（JOIN）
$orders = $db->findAll('orders', 
    ['orders.status' => 1],
    [
        'fields' => ['orders.*', 'users.username'],
        'join' => [
            [
                'type' => 'LEFT',
                'table' => 'users',
                'on' => 'orders.user_id = users.id'
            ]
        ],
        'group' => 'orders.id',
        'having' => 'orders.total > 100'
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

// 批量插入
$insertedCount = $db->batchInsert('users', [
    ['username' => 'user1', 'email' => 'user1@example.com'],
    ['username' => 'user2', 'email' => 'user2@example.com']
]);
```

#### 更新数据

```php
// 更新单条记录
$updatedCount = $db->update('users', 
    ['status' => 0], 
    ['id' => 1]
);

// 更新多条记录
$updatedCount = $db->update('users', 
    ['status' => 1], 
    ['group_id' => 2]
);
```

#### 删除数据

```php
// 删除单条记录
$deletedCount = $db->delete('users', ['id' => 1]);

// 删除多条记录
$deletedCount = $db->delete('users', ['status' => 0]);
```

### 3. 事务管理

```php
try {
    $db->beginTransaction();
    
    // 执行多个数据库操作
    $db->insert('orders', ['user_id' => 1, 'total' => 100]);
    $db->update('users', ['balance' => 900], ['id' => 1]);
    
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    echo '事务失败: ' . $e->getMessage();
}
```

### 4. 表结构操作

#### 创建表

```php
$db->createTable('products', [
    'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
    'name' => 'VARCHAR(255) NOT NULL',
    'price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
    'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
]);

// 更详细的字段定义方式
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
        'notnull' => true
    ],
    'total' => [
        'name' => 'total',
        'type' => 'DECIMAL',
        'length' => [10, 2],
        'default' => 0
    ]
], [
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4'
]);
```

#### 修改表结构

```php
$db->alterTable('users', [
    [
        'type' => 'ADD',
        'field' => 'age',
        'definition' => 'INT UNSIGNED NOT NULL DEFAULT 0'
    ],
    [
        'type' => 'MODIFY',
        'field' => 'email',
        'definition' => 'VARCHAR(255) NOT NULL UNIQUE'
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

// 获取状态为1的用户数量
$activeUsers = $db->count('users', ['status' => 1]);
```

### 5. 高级功能

#### 查询缓存

```php
// 启用查询缓存，设置TTL为3600秒
$db->setCacheEnabled(true, 3600);

// 第一次查询（会缓存结果）
$users1 = $db->findAll('users', ['status' => 1]);

// 第二次查询（会命中缓存，提高性能）
$users2 = $db->findAll('users', ['status' => 1]);

// 清除特定表的缓存
$db->clearCache('users');

// 清除所有缓存
$db->clearCache();

// 禁用缓存
$db->setCacheEnabled(false);
```

#### AES加密功能

```php
// 加密敏感数据
$sensitiveData = "敏感信息";
$encryptionKey = "your_encryption_key";
$encryptedData = $db->encrypt($sensitiveData, $encryptionKey);

// 解密数据
$decryptedData = $db->decrypt($encryptedData, $encryptionKey);
```

#### 日志记录

```php
// 设置日志级别
$db->setLogLevel('debug'); // debug, info, warn, error

// 执行一些数据库操作
$db->findAll('users');

// 获取日志
$logs = $db->getLogs(); // 获取所有日志
$errorLogs = $db->getLogs('error'); // 获取错误日志

// 清空日志
$db->clearLogs();
```

#### 连接状态监控

```php
// 获取连接状态
$status = $db->getConnectionStatus();
print_r($status);
/*
返回示例:
Array (
    [connection_id] => conn_60a5e1a3b1c2d
    [in_transaction] => false
    [transaction_depth] => 0
    [server_version] => 8.0.28
    [cache_enabled] => true
    [cache_size] => 5
)
*/
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
| conditions | array | 查询条件，关联数组格式，支持复杂条件 |
| fields | array | 查询字段列表 |
| options | array | 查询选项（fields, order, limit, offset, join, group, having） |

### 复杂条件格式

| 条件类型 | 示例 | 描述 |
|----------|------|------|
| 等于 | `['id' => 1]` | 字段等于指定值 |
| 大于 | `['age' => ['>', 18]]` | 字段大于指定值 |
| 小于 | `['price' => ['<', 100]]` | 字段小于指定值 |
| 不等于 | `['status' => ['!=', 0]]` | 字段不等于指定值 |
| 范围 | `['age' => ['BETWEEN', [18, 30]]]` | 字段在指定范围内 |
| IN | `['status' => ['IN', [1, 2, 3]]]` | 字段在指定数组中 |
| LIKE | `['name' => ['LIKE', '%John%']]` | 字段匹配指定模式 |
| IS NULL | `['email' => ['IS', null]]` | 字段为NULL |

## 注意事项

1. **安全性**：
   - 库内置了参数化查询，防止SQL注入
   - 建议使用内置的AES加密功能处理敏感数据
   - 定期更新数据库密码和访问权限
   - 加密密钥应妥善保管，避免泄露
   - 日志中可能包含敏感信息，生产环境建议配置适当的日志级别

2. **性能优化**：
   - 对于频繁查询，建议使用查询缓存
   - 批量操作时，建议使用batchInsert方法
   - 合理设置索引，优化查询性能
   - 定期清理过期缓存，避免内存占用过高
   - 复杂查询时，注意监控查询性能

3. **错误处理**：
   - 所有方法在失败时返回false
   - 可通过getError()方法获取详细错误信息
   - 建议使用try-catch块捕获异常
   - 定期查看错误日志，及时发现和解决问题

4. **兼容性**：
   - 支持PHP 7.0及以上版本
   - 支持MySQL 5.6及以上版本
   - 支持MariaDB 10.0及以上版本
   - 部分高级功能可能需要特定MySQL版本支持

5. **最佳实践**：
   - 使用单例模式获取数据库实例
   - 避免在循环中执行数据库操作
   - 合理使用事务，确保数据一致性
   - 定期备份数据库，防止数据丢失
   - 根据实际需求调整缓存策略
   - 生产环境建议关闭debug日志

6. **单例模式注意事项**：
   - 单例模式下，所有操作共享同一个连接
   - 不适合在高并发环境下使用
   - 长时间运行的脚本应注意连接超时问题
   - 多个数据库操作应考虑使用事务

## 项目维护

若您正在使用我的项目对我的项目有新的需求或发现bug请向于本项目内报告，一般3-7天内会给出答复，后期可能会视作品小星星der数量增加更多功能！

## 作者的话：php懒人库
