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
    $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS ingredients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(120) NOT NULL UNIQUE,
    price_per_unit DECIMAL(10,2) NOT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'Stk',
    stock_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
    min_stock_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(120) NOT NULL UNIQUE,
    type VARCHAR(20) NOT NULL CHECK (type IN ('gericht','getraenk')),
    direct_purchase_price DECIMAL(10,2) NULL,
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
SQL;
    } else {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS ingredients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    price_per_unit DECIMAL(10,2) NOT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'Stk',
    stock_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
    min_stock_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    type ENUM('gericht','getraenk') NOT NULL,
    direct_purchase_price DECIMAL(10,2) NULL,
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
SQL;
    }

    db()->exec($sql);
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
