<?php

require_once __DIR__ . '/db.php';


function discordAdminId(): string
{
    return '460750108615770134';
}

function adminDisplayName(): string
{
    return 'Manuel Cassano';
}

function findUserByDiscordId(string $discordId): ?array
{
    $stmt = db()->prepare('SELECT id, discord_id, display_name, is_admin, is_approved, created_at, updated_at FROM user_access WHERE discord_id = :discord_id LIMIT 1');
    $stmt->execute([':discord_id' => trim($discordId)]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function createOrUpdateUserAccess(string $discordId, string $displayName): array
{
    $discordId = trim($discordId);
    if ($discordId === '') {
        throw new InvalidArgumentException('Discord ID darf nicht leer sein.');
    }

    $displayName = trim($displayName);
    $isAdmin = $discordId === discordAdminId() ? 1 : 0;
    if ($isAdmin === 1) {
        $displayName = adminDisplayName();
    }

    $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $sql = <<<'SQL'
INSERT INTO user_access (discord_id, display_name, is_admin, is_approved)
VALUES (:discord_id, :display_name, :is_admin, :is_approved)
ON CONFLICT(discord_id) DO UPDATE SET
    display_name = CASE
        WHEN TRIM(user_access.display_name) = '' THEN excluded.display_name
        ELSE user_access.display_name
    END,
    is_admin = CASE WHEN user_access.discord_id = :admin_id THEN 1 ELSE user_access.is_admin END,
    is_approved = CASE WHEN user_access.discord_id = :admin_id THEN 1 ELSE user_access.is_approved END,
    updated_at = CURRENT_TIMESTAMP
SQL;
    } else {
        $sql = <<<'SQL'
INSERT INTO user_access (discord_id, display_name, is_admin, is_approved)
VALUES (:discord_id, :display_name, :is_admin, :is_approved)
ON DUPLICATE KEY UPDATE
    display_name = IF(TRIM(display_name) = '', VALUES(display_name), display_name),
    is_admin = IF(discord_id = :admin_id, 1, is_admin),
    is_approved = IF(discord_id = :admin_id, 1, is_approved)
SQL;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':discord_id' => $discordId,
        ':display_name' => $displayName,
        ':is_admin' => $isAdmin,
        ':is_approved' => $isAdmin,
        ':admin_id' => discordAdminId(),
    ]);

    $user = findUserByDiscordId($discordId);
    if ($user === null) {
        throw new RuntimeException('Benutzer konnte nicht geladen werden.');
    }

    return $user;
}

function setUserDisplayName(string $discordId, string $displayName): void
{
    $discordId = trim($discordId);
    if ($discordId === '') {
        throw new InvalidArgumentException('Discord ID fehlt.');
    }

    $displayName = trim($displayName);
    if ($displayName === '') {
        throw new InvalidArgumentException('Name darf nicht leer sein.');
    }

    if ($discordId === discordAdminId()) {
        $displayName = adminDisplayName();
    }

    $stmt = db()->prepare('UPDATE user_access SET display_name = :display_name WHERE discord_id = :discord_id');
    $stmt->execute([
        ':display_name' => $displayName,
        ':discord_id' => $discordId,
    ]);
}

function deleteUserAccess(string $discordId): void
{
    $discordId = trim($discordId);
    if ($discordId === '') {
        throw new InvalidArgumentException('Discord ID fehlt.');
    }

    if ($discordId === discordAdminId()) {
        return;
    }

    $stmt = db()->prepare('DELETE FROM user_access WHERE discord_id = :discord_id');
    $stmt->execute([':discord_id' => $discordId]);
}

function allUserAccessEntries(): array
{
    $stmt = db()->query('SELECT id, discord_id, display_name, is_admin, is_approved, created_at, updated_at FROM user_access ORDER BY is_admin DESC, is_approved DESC, created_at ASC');
    return $stmt->fetchAll();
}

function setUserApproval(string $discordId, bool $isApproved): void
{
    if (trim($discordId) === '') {
        throw new InvalidArgumentException('Discord ID fehlt.');
    }

    if (trim($discordId) === discordAdminId()) {
        return;
    }

    $stmt = db()->prepare('UPDATE user_access SET is_approved = :approved WHERE discord_id = :discord_id');
    $stmt->execute([
        ':approved' => $isApproved ? 1 : 0,
        ':discord_id' => trim($discordId),
    ]);
}

function setUserAdmin(string $discordId, bool $isAdmin): void
{
    if (trim($discordId) === '') {
        throw new InvalidArgumentException('Discord ID fehlt.');
    }

    if (trim($discordId) === discordAdminId()) {
        return;
    }

    $stmt = db()->prepare('UPDATE user_access SET is_admin = :is_admin WHERE discord_id = :discord_id');
    $stmt->execute([
        ':is_admin' => $isAdmin ? 1 : 0,
        ':discord_id' => trim($discordId),
    ]);
}


function actorLabelFromUser(?array $actor): array
{
    $discordId = trim((string)($actor['discord_id'] ?? ''));
    $displayName = trim((string)($actor['display_name'] ?? ''));

    if ($discordId !== '') {
        $user = findUserByDiscordId($discordId);
        if (is_array($user)) {
            $dbName = trim((string)($user['display_name'] ?? ''));
            if ($dbName !== '') {
                $displayName = $dbName;
            }
        }
    }

    if ($displayName === '') {
        $displayName = 'Unbekannt';
    }

    return [
        'discord_id' => $discordId,
        'display_name' => $displayName,
    ];
}

function writeAuditLog(?array $actor, string $actionKey, string $targetValue = '', string $details = ''): void
{
    $actorLabel = actorLabelFromUser($actor);

    $stmt = db()->prepare(
        'INSERT INTO audit_log (actor_discord_id, actor_name, action_key, target_value, details)
         VALUES (:actor_discord_id, :actor_name, :action_key, :target_value, :details)'
    );
    $stmt->execute([
        ':actor_discord_id' => $actorLabel['discord_id'],
        ':actor_name' => $actorLabel['display_name'],
        ':action_key' => trim($actionKey),
        ':target_value' => trim($targetValue),
        ':details' => trim($details),
    ]);
}

function auditLogEntries(int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $stmt = db()->prepare(
        'SELECT id, actor_discord_id, actor_name, action_key, target_value, details, created_at
         FROM audit_log
         ORDER BY id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function allIngredients(): array
{
    $stmt = db()->query('SELECT id, name, price_per_unit, stock_qty, created_at FROM ingredients ORDER BY name');
    return $stmt->fetchAll();
}

function ingredientById(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, name, price_per_unit, stock_qty, created_at FROM ingredients WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function allIngredientsByStockOrder(): array
{
    $stmt = db()->query('SELECT id, name, price_per_unit, stock_qty, created_at FROM ingredients ORDER BY stock_qty DESC, name');
    return $stmt->fetchAll();
}

function createIngredient(array $input): void
{
    $stmt = db()->prepare('INSERT INTO ingredients (name, price_per_unit, stock_qty) VALUES (:name, :price, :stock_qty)');
    $stmt->execute([
        ':name' => trim($input['name']),
        ':price' => (float)$input['price_per_unit'],
        ':stock_qty' => (float)($input['stock_qty'] ?? 0),
    ]);
}

function updateIngredient(int $id, array $input): void
{
    $stmt = db()->prepare('UPDATE ingredients SET name = :name, price_per_unit = :price, stock_qty = :stock_qty WHERE id = :id');
    $stmt->execute([
        ':id' => $id,
        ':name' => trim($input['name']),
        ':price' => (float)$input['price_per_unit'],
        ':stock_qty' => (float)($input['stock_qty'] ?? 0),
    ]);
}

function updateIngredientStock(int $id, float $stockQty): void
{
    $stmt = db()->prepare('UPDATE ingredients SET stock_qty = :stock_qty WHERE id = :id');
    $stmt->execute([
        ':id' => $id,
        ':stock_qty' => $stockQty,
    ]);
}

function deleteIngredient(int $id): void
{
    $stmt = db()->prepare('DELETE FROM ingredients WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function allProducts(): array
{
    $sql = <<<'SQL'
SELECT p.*,
       COUNT(ri.id) AS ingredient_count,
       COALESCE(SUM(ri.qty_per_product * i.price_per_unit), 0) AS calculated_recipe_price
FROM products p
LEFT JOIN recipe_items ri ON ri.product_id = p.id
LEFT JOIN ingredients i ON i.id = ri.ingredient_id
GROUP BY p.id
ORDER BY p.name
SQL;
    return db()->query($sql)->fetchAll();
}

function allProductsByStockOrder(): array
{
    $sql = <<<'SQL'
SELECT p.*,
       COUNT(ri.id) AS ingredient_count,
       COALESCE(SUM(ri.qty_per_product * i.price_per_unit), 0) AS calculated_recipe_price
FROM products p
LEFT JOIN recipe_items ri ON ri.product_id = p.id
LEFT JOIN ingredients i ON i.id = ri.ingredient_id
GROUP BY p.id
ORDER BY p.stock_qty DESC, p.name
SQL;
    return db()->query($sql)->fetchAll();
}

function updateProductStock(int $id, int $stockQty): void
{
    if ($stockQty < 0) {
        throw new InvalidArgumentException('Lagerbestand darf nicht negativ sein.');
    }

    $stmt = db()->prepare('UPDATE products SET stock_qty = :stock_qty WHERE id = :id');
    $stmt->execute([
        ':id' => $id,
        ':stock_qty' => $stockQty,
    ]);
}

function createProduct(array $input): void
{
    $stmt = db()->prepare('INSERT INTO products (name, direct_purchase_price, is_direct_purchase, target_qty, stock_qty) VALUES (:name, :price, :is_direct_purchase, :target, :stock)');
    $isDirectPurchase = resolveProductType($input);
    $directPrice = $isDirectPurchase === 1 ? (float)($input['direct_purchase_price'] ?? 0) : 0;
    $stmt->execute([
        ':name' => trim($input['name']),
        ':price' => $directPrice,
        ':is_direct_purchase' => $isDirectPurchase,
        ':target' => (int)($input['target_qty'] ?? 0),
        ':stock' => (int)($input['stock_qty'] ?? 0),
    ]);
}

function updateProduct(int $id, array $input): void
{
    $stmt = db()->prepare('UPDATE products SET name = :name, direct_purchase_price = :price, is_direct_purchase = :is_direct_purchase, target_qty = :target, stock_qty = :stock WHERE id = :id');
    $isDirectPurchase = resolveProductType($input);
    $directPrice = $isDirectPurchase === 1 ? (float)($input['direct_purchase_price'] ?? 0) : 0;
    $stmt->execute([
        ':id' => $id,
        ':name' => trim($input['name']),
        ':price' => $directPrice,
        ':is_direct_purchase' => $isDirectPurchase,
        ':target' => (int)($input['target_qty'] ?? 0),
        ':stock' => (int)($input['stock_qty'] ?? 0),
    ]);
}

function resolveProductType(array $input): int
{
    if (isset($input['product_type'])) {
        return $input['product_type'] === 'direct' ? 1 : 0;
    }

    return !empty($input['is_direct_purchase']) ? 1 : 0;
}

function deleteProduct(int $id): void
{
    $stmt = db()->prepare('DELETE FROM products WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function productById(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM products WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function recipeItemsByProduct(int $productId): array
{
    $stmt = db()->prepare('SELECT ri.*, i.name AS ingredient_name, i.price_per_unit FROM recipe_items ri JOIN ingredients i ON i.id = ri.ingredient_id WHERE ri.product_id = :id ORDER BY i.name');
    $stmt->execute([':id' => $productId]);
    return $stmt->fetchAll();
}

function recipeItemByProductAndIngredient(int $productId, int $ingredientId): ?array
{
    $stmt = db()->prepare('SELECT ri.*, i.name AS ingredient_name, p.name AS product_name FROM recipe_items ri JOIN ingredients i ON i.id = ri.ingredient_id JOIN products p ON p.id = ri.product_id WHERE ri.product_id = :product_id AND ri.ingredient_id = :ingredient_id LIMIT 1');
    $stmt->execute([
        ':product_id' => $productId,
        ':ingredient_id' => $ingredientId,
    ]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function recipeItemById(int $id): ?array
{
    $stmt = db()->prepare('SELECT ri.*, i.name AS ingredient_name, p.name AS product_name FROM recipe_items ri JOIN ingredients i ON i.id = ri.ingredient_id JOIN products p ON p.id = ri.product_id WHERE ri.id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function upsertRecipeItem(int $productId, int $ingredientId, float $qty): void
{
    $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $sql = 'INSERT INTO recipe_items (product_id, ingredient_id, qty_per_product) VALUES (:p, :i, :q) ON CONFLICT(product_id, ingredient_id) DO UPDATE SET qty_per_product = excluded.qty_per_product';
    } else {
        $sql = 'INSERT INTO recipe_items (product_id, ingredient_id, qty_per_product) VALUES (:p, :i, :q) ON DUPLICATE KEY UPDATE qty_per_product = VALUES(qty_per_product)';
    }

    $stmt = db()->prepare($sql);
    $stmt->execute([':p' => $productId, ':i' => $ingredientId, ':q' => $qty]);
}

function assignRecipeItems(int $productId, array $ingredientQtyMap): void
{
    $validRows = [];
    foreach ($ingredientQtyMap as $ingredientId => $qty) {
        $ingredientId = (int)$ingredientId;
        $qty = (float)$qty;
        if ($ingredientId <= 0 || $qty <= 0) {
            continue;
        }

        $validRows[$ingredientId] = $qty;
    }

    if ($validRows === []) {
        return;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($validRows as $ingredientId => $qty) {
            upsertRecipeItem($productId, $ingredientId, $qty);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function deleteRecipeItem(int $id): void
{
    $stmt = db()->prepare('DELETE FROM recipe_items WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function shoppingList(): array
{
    $products = allProducts();
    $ingredients = allIngredients();

    $ingredientNeeds = [];
    foreach ($ingredients as $ingredient) {
        $ingredientNeeds[(int)$ingredient['id']] = [
            'id' => (int)$ingredient['id'],
            'name' => $ingredient['name'],
            'price_per_unit' => (float)$ingredient['price_per_unit'],
            'stock_qty' => (float)$ingredient['stock_qty'],
            'needed_qty' => 0,
        ];
    }

    $shoppingItems = [];

    foreach ($products as $product) {
        $missingProductQty = max(0, (int)$product['target_qty'] - (int)$product['stock_qty']);
        if ($missingProductQty <= 0) {
            continue;
        }

        $recipe = recipeItemsByProduct((int)$product['id']);
        if ((int)$product['is_direct_purchase'] === 1) {
            $unitPrice = (float)$product['direct_purchase_price'];
            $shoppingItems[] = [
                'type' => 'Gericht',
                'name' => $product['name'],
                'qty' => $missingProductQty,
                'unit' => 'Stk',
                'sum' => $unitPrice * $missingProductQty,
            ];
            continue;
        }

        foreach ($recipe as $item) {
            $id = (int)$item['ingredient_id'];
            $ingredientNeeds[$id]['needed_qty'] += (float)$item['qty_per_product'] * $missingProductQty;
        }
    }

    foreach ($ingredientNeeds as $need) {
        $missing = max(0, $need['needed_qty'] - $need['stock_qty']);
        if ($missing > 0) {
            $shoppingItems[] = [
                'type' => 'Zutat',
                'name' => $need['name'],
                'qty' => $missing,
                'unit' => 'Stk',
                'sum' => $missing * $need['price_per_unit'],
            ];
        }
    }

    usort($shoppingItems, static function (array $a, array $b): int {
        return strcasecmp($a['name'], $b['name']);
    });

    $total = array_sum(array_column($shoppingItems, 'sum'));

    return [
        'items' => $shoppingItems,
        'total' => $total,
    ];
}

function specialPurchaseCatalog(): array
{
    $catalog = [];

    foreach (allIngredients() as $ingredient) {
        $catalog[] = [
            'key' => 'ingredient:' . (int)$ingredient['id'],
            'item_type' => 'Zutat',
            'item_name' => (string)$ingredient['name'],
            'item_id' => (int)$ingredient['id'],
            'unit_price' => (float)$ingredient['price_per_unit'],
            'target_table' => 'ingredients',
        ];
    }

    foreach (allProducts() as $product) {
        $unitPrice = (int)$product['is_direct_purchase'] === 1
            ? (float)$product['direct_purchase_price']
            : (float)$product['calculated_recipe_price'];
        $catalog[] = [
            'key' => 'product:' . (int)$product['id'],
            'item_type' => 'Gericht',
            'item_name' => (string)$product['name'],
            'item_id' => (int)$product['id'],
            'unit_price' => $unitPrice,
            'target_table' => 'products',
        ];
    }

    usort($catalog, static function (array $a, array $b): int {
        $typeCompare = strcasecmp((string)$a['item_type'], (string)$b['item_type']);
        if ($typeCompare !== 0) {
            return $typeCompare;
        }

        return strcasecmp((string)$a['item_name'], (string)$b['item_name']);
    });

    return $catalog;
}

function completeSpecialPurchase(array $selectedItems, string $completedByDiscordId = ''): array
{
    $catalog = specialPurchaseCatalog();
    $catalogByKey = [];
    foreach ($catalog as $item) {
        $catalogByKey[(string)$item['key']] = $item;
    }

    $purchaseItems = [];
    foreach ($selectedItems as $key => $qtyRaw) {
        $key = trim((string)$key);
        if ($key === '' || !isset($catalogByKey[$key])) {
            continue;
        }

        $qty = (float)$qtyRaw;
        if ($qty <= 0) {
            continue;
        }

        $catalogItem = $catalogByKey[$key];
        $purchaseItems[] = [
            'item_type' => (string)$catalogItem['item_type'],
            'item_name' => (string)$catalogItem['item_name'],
            'qty' => $qty,
            'unit' => 'Stk',
            'sum' => $qty * (float)$catalogItem['unit_price'],
            'item_id' => (int)$catalogItem['item_id'],
            'target_table' => (string)$catalogItem['target_table'],
        ];
    }

    if ($purchaseItems === []) {
        throw new InvalidArgumentException('Bitte mindestens einen Artikel mit Menge größer als 0 auswählen.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $actorDiscordId = trim($completedByDiscordId);
        $historyStmt = $pdo->prepare('INSERT INTO shopping_history (completed_by_discord_id) VALUES (:completed_by_discord_id)');
        $historyStmt->execute([':completed_by_discord_id' => $actorDiscordId]);
        $shoppingHistoryId = (int)$pdo->lastInsertId();

        $historyItemStmt = $pdo->prepare('INSERT INTO shopping_history_items (shopping_history_id, item_type, item_name, qty, unit, total_cost) VALUES (:shopping_history_id, :item_type, :item_name, :qty, :unit, :total_cost)');

        foreach ($purchaseItems as $item) {
            $historyItemStmt->execute([
                ':shopping_history_id' => $shoppingHistoryId,
                ':item_type' => $item['item_type'],
                ':item_name' => $item['item_name'],
                ':qty' => $item['qty'],
                ':unit' => $item['unit'],
                ':total_cost' => $item['sum'],
            ]);

            $targetTable = $item['target_table'] === 'ingredients' ? 'ingredients' : 'products';
            $qty = $targetTable === 'products' ? (int)round((float)$item['qty']) : (float)$item['qty'];
            $stmt = $pdo->prepare("UPDATE {$targetTable} SET stock_qty = stock_qty + :qty WHERE id = :id");
            $stmt->execute([
                ':qty' => $qty,
                ':id' => (int)$item['item_id'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    return [
        'items' => array_map(static function (array $item): array {
            return [
                'type' => $item['item_type'],
                'name' => $item['item_name'],
                'qty' => $item['qty'],
                'unit' => $item['unit'],
                'sum' => $item['sum'],
            ];
        }, $purchaseItems),
        'total' => array_sum(array_column($purchaseItems, 'sum')),
    ];
}

function completeShoppingListAndUpdateInventory(string $completedByDiscordId = ''): void
{
    $shoppingListSnapshot = shoppingList();
    $products = allProducts();
    $ingredients = allIngredients();

    $ingredientMissingQty = [];
    foreach ($ingredients as $ingredient) {
        $ingredientMissingQty[(int)$ingredient['id']] = 0.0;
    }

    $directProductMissingQty = [];

    foreach ($products as $product) {
        $productId = (int)$product['id'];
        $missingProductQty = max(0, (int)$product['target_qty'] - (int)$product['stock_qty']);
        if ($missingProductQty <= 0) {
            continue;
        }

        if ((int)$product['is_direct_purchase'] === 1) {
            $directProductMissingQty[$productId] = $missingProductQty;
            continue;
        }

        $recipe = recipeItemsByProduct($productId);
        foreach ($recipe as $item) {
            $ingredientId = (int)$item['ingredient_id'];
            $ingredientMissingQty[$ingredientId] += (float)$item['qty_per_product'] * $missingProductQty;
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $actorDiscordId = trim($completedByDiscordId);
        $historyStmt = $pdo->prepare('INSERT INTO shopping_history (completed_by_discord_id) VALUES (:completed_by_discord_id)');
        $historyStmt->execute([':completed_by_discord_id' => $actorDiscordId]);
        $shoppingHistoryId = (int)$pdo->lastInsertId();

        $historyItemStmt = $pdo->prepare('INSERT INTO shopping_history_items (shopping_history_id, item_type, item_name, qty, unit, total_cost) VALUES (:shopping_history_id, :item_type, :item_name, :qty, :unit, :total_cost)');
        foreach ($shoppingListSnapshot['items'] as $shoppingItem) {
            $historyItemStmt->execute([
                ':shopping_history_id' => $shoppingHistoryId,
                ':item_type' => (string)($shoppingItem['type'] ?? ''),
                ':item_name' => (string)($shoppingItem['name'] ?? ''),
                ':qty' => (float)($shoppingItem['qty'] ?? 0),
                ':unit' => (string)($shoppingItem['unit'] ?? 'Stk'),
                ':total_cost' => (float)($shoppingItem['sum'] ?? 0),
            ]);
        }

        foreach ($directProductMissingQty as $productId => $missingQty) {
            $stmt = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + :qty WHERE id = :id');
            $stmt->execute([
                ':id' => (int)$productId,
                ':qty' => (int)$missingQty,
            ]);
        }

        foreach ($ingredients as $ingredient) {
            $ingredientId = (int)$ingredient['id'];
            $neededQty = $ingredientMissingQty[$ingredientId] ?? 0;
            if ($neededQty <= 0) {
                continue;
            }

            $currentStock = (float)$ingredient['stock_qty'];
            $missingQty = max(0, $neededQty - $currentStock);
            if ($missingQty <= 0) {
                continue;
            }

            $stmt = $pdo->prepare('UPDATE ingredients SET stock_qty = stock_qty + :qty WHERE id = :id');
            $stmt->execute([
                ':id' => $ingredientId,
                ':qty' => $missingQty,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function shoppingStats(): array
{
    $sql = <<<'SQL'
SELECT item_name,
       item_type,
       SUM(qty) AS total_qty,
       COUNT(DISTINCT shopping_history_id) AS shopping_runs,
       CAST(julianday('now') - julianday(MIN(sh.completed_at)) AS INTEGER) + 1 AS days_span,
       SUM(total_cost) AS total_cost
FROM shopping_history_items shi
JOIN shopping_history sh ON sh.id = shi.shopping_history_id
GROUP BY item_name, item_type
ORDER BY total_qty DESC, item_name ASC
SQL;

    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql = <<<'SQL'
SELECT item_name,
       item_type,
       SUM(qty) AS total_qty,
       COUNT(DISTINCT shopping_history_id) AS shopping_runs,
       DATEDIFF(CURDATE(), DATE(MIN(sh.completed_at))) + 1 AS days_span,
       SUM(total_cost) AS total_cost
FROM shopping_history_items shi
JOIN shopping_history sh ON sh.id = shi.shopping_history_id
GROUP BY item_name, item_type
ORDER BY total_qty DESC, item_name ASC
SQL;
    }

    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $daysSpan = max(1, (int)($row['days_span'] ?? 1));
        $row['avg_per_day'] = (float)$row['total_qty'] / $daysSpan;
    }
    unset($row);

    return $rows;
}

function companyName(): string
{
    $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => 'company_name']);
    $value = $stmt->fetchColumn();

    return $value === false ? '' : trim((string)$value);
}

function updateCompanyName(string $name): void
{
    $name = trim($name);
    $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $sql = 'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value';
    } else {
        $sql = 'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';
    }

    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':key' => 'company_name',
        ':value' => $name,
    ]);
}

function discordWebhookUrl(): string
{
    $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => 'discord_webhook_url']);
    $value = $stmt->fetchColumn();

    return $value === false ? '' : trim((string)$value);
}

function updateDiscordWebhookUrl(string $url): void
{
    $url = trim($url);
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Webhook-URL ist ungültig.');
    }

    $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $sql = 'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value';
    } else {
        $sql = 'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';
    }

    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':key' => 'discord_webhook_url',
        ':value' => $url,
    ]);
}
