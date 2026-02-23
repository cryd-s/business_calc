<?php

function appConfig(): array
{
    static $config;

    if ($config !== null) {
        return $config;
    }

    $configFile = __DIR__ . '/../config/config.php';
    if (!file_exists($configFile)) {
        throw new RuntimeException('Bitte config/config.php aus config/config.example.php erstellen.');
    }

    $config = require $configFile;
    return $config;
}

function db(): PDO
{
    static $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = appConfig()['db'];
    if (!empty($cfg['dsn'])) {
        $dsn = $cfg['dsn'];
        $user = $cfg['user'] ?? null;
        $pass = $cfg['pass'] ?? null;
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['dbname'],
            $cfg['charset']
        );
        $user = $cfg['user'];
        $pass = $cfg['pass'];
    }

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function initSchema(): void
{
    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS ingredients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(120) NOT NULL UNIQUE,
    price_per_unit DECIMAL(10,2) NOT NULL,
    stock_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(120) NOT NULL UNIQUE,
    direct_purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_direct_purchase INTEGER NOT NULL DEFAULT 0,
    target_qty INT NOT NULL DEFAULT 0,
    stock_qty INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS recipe_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    ingredient_id INTEGER NOT NULL,
    qty_per_product DECIMAL(10,2) NOT NULL,
    UNIQUE (product_id, ingredient_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value TEXT NOT NULL DEFAULT ''
);
SQL;
    } else {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS ingredients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    price_per_unit DECIMAL(10,2) NOT NULL,
    stock_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    direct_purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_direct_purchase TINYINT(1) NOT NULL DEFAULT 0,
    target_qty INT NOT NULL DEFAULT 0,
    stock_qty INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS recipe_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    ingredient_id INT NOT NULL,
    qty_per_product DECIMAL(10,2) NOT NULL,
    UNIQUE KEY uniq_recipe (product_id, ingredient_id),
    CONSTRAINT fk_recipe_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_recipe_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value TEXT NOT NULL
);
SQL;
    }

    $pdo->exec($sql);

    ensureProductSchema($pdo, $driver);
    ensureIngredientSchema($pdo, $driver);
    ensureAppSettingsSchema($pdo, $driver);
    ensureUserAccessSchema($pdo, $driver);
    ensureShoppingHistorySchema($pdo, $driver);
    ensureAuditLogSchema($pdo, $driver);
}

function ensureIngredientSchema(PDO $pdo, string $driver): void
{
    $columns = tableColumns($pdo, 'ingredients', $driver);

    if (!isset($columns['stock_qty'])) {
        $pdo->exec('ALTER TABLE ingredients ADD COLUMN stock_qty DECIMAL(10,2) NOT NULL DEFAULT 0');
    }
}

function ensureProductSchema(PDO $pdo, string $driver): void
{
    $columns = tableColumns($pdo, 'products', $driver);

    if (!isset($columns['direct_purchase_price'])) {
        $pdo->exec('ALTER TABLE products ADD COLUMN direct_purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0');
    }
    if (!isset($columns['is_direct_purchase'])) {
        $type = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)';
        $pdo->exec("ALTER TABLE products ADD COLUMN is_direct_purchase {$type} NOT NULL DEFAULT 0");
    }
    if (!isset($columns['target_qty'])) {
        $pdo->exec('ALTER TABLE products ADD COLUMN target_qty INT NOT NULL DEFAULT 0');
    }
    if (!isset($columns['stock_qty'])) {
        $pdo->exec('ALTER TABLE products ADD COLUMN stock_qty INT NOT NULL DEFAULT 0');
    }
}



function ensureAppSettingsSchema(PDO $pdo, string $driver): void
{
    if ($driver === 'sqlite') {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value TEXT NOT NULL DEFAULT ''
);
SQL);
        return;
    }

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value TEXT NOT NULL
);
SQL);
}

function ensureUserAccessSchema(PDO $pdo, string $driver): void
{
    if ($driver === 'sqlite') {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS user_access (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    discord_id VARCHAR(32) NOT NULL UNIQUE,
    display_name VARCHAR(120) NOT NULL DEFAULT '',
    is_admin INTEGER NOT NULL DEFAULT 0,
    is_approved INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
SQL);
    } else {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS user_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discord_id VARCHAR(32) NOT NULL UNIQUE,
    display_name VARCHAR(120) NOT NULL DEFAULT '',
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    is_approved TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
SQL);
    }

    $columns = tableColumns($pdo, 'user_access', $driver);
    if (!isset($columns['is_admin'])) {
        $type = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)';
        $pdo->exec("ALTER TABLE user_access ADD COLUMN is_admin {$type} NOT NULL DEFAULT 0");
    }

    $stmt = $pdo->prepare('UPDATE user_access SET is_admin = 1, is_approved = 1, display_name = :display_name WHERE discord_id = :discord_id');
    $stmt->execute([
        ':discord_id' => discordAdminId(),
        ':display_name' => adminDisplayName(),
    ]);
}

function ensureShoppingHistorySchema(PDO $pdo, string $driver): void
{
    if ($driver === 'sqlite') {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS shopping_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    completed_by_discord_id VARCHAR(32) DEFAULT '',
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS shopping_history_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    shopping_history_id INTEGER NOT NULL,
    item_type VARCHAR(40) NOT NULL,
    item_name VARCHAR(120) NOT NULL,
    qty DECIMAL(10,2) NOT NULL,
    unit VARCHAR(20) NOT NULL DEFAULT 'Stk',
    total_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (shopping_history_id) REFERENCES shopping_history(id) ON DELETE CASCADE
);
SQL);
    } else {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS shopping_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    completed_by_discord_id VARCHAR(32) NOT NULL DEFAULT '',
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS shopping_history_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shopping_history_id INT NOT NULL,
    item_type VARCHAR(40) NOT NULL,
    item_name VARCHAR(120) NOT NULL,
    qty DECIMAL(10,2) NOT NULL,
    unit VARCHAR(20) NOT NULL DEFAULT 'Stk',
    total_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_shopping_history_item_history FOREIGN KEY (shopping_history_id) REFERENCES shopping_history(id) ON DELETE CASCADE
);
SQL);
    }

    $historyColumns = tableColumns($pdo, 'shopping_history', $driver);
    if (!isset($historyColumns['completed_by_discord_id'])) {
        $pdo->exec("ALTER TABLE shopping_history ADD COLUMN completed_by_discord_id VARCHAR(32) NOT NULL DEFAULT ''");
    }
}

function ensureAuditLogSchema(PDO $pdo, string $driver): void
{
    if ($driver === 'sqlite') {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_discord_id VARCHAR(32) NOT NULL DEFAULT '',
    actor_name VARCHAR(120) NOT NULL DEFAULT '',
    action_key VARCHAR(120) NOT NULL,
    target_value VARCHAR(190) NOT NULL DEFAULT '',
    details TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
SQL);
    } else {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_discord_id VARCHAR(32) NOT NULL DEFAULT '',
    actor_name VARCHAR(120) NOT NULL DEFAULT '',
    action_key VARCHAR(120) NOT NULL,
    target_value VARCHAR(190) NOT NULL DEFAULT '',
    details TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
SQL);
    }
}

function tableColumns(PDO $pdo, string $table, string $driver): array
{
    if ($driver === 'sqlite') {
        $stmt = $pdo->query(sprintf('PRAGMA table_info(%s)', $table));
        $rows = $stmt->fetchAll();
        $columns = [];
        foreach ($rows as $row) {
            $columns[(string)$row['name']] = true;
        }
        return $columns;
    }

    $stmt = $pdo->prepare(sprintf('SHOW COLUMNS FROM %s', $table));
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $columns = [];
    foreach ($rows as $row) {
        $columns[(string)$row['Field']] = true;
    }

    return $columns;
}

function flash(?string $message = null): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if ($message !== null) {
        $_SESSION['flash_message'] = $message;
        return null;
    }

    $msg = $_SESSION['flash_message'] ?? null;
    unset($_SESSION['flash_message']);
    return $msg;
}
