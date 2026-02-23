<?php

require_once __DIR__ . '/db.php';

function allIngredients(): array
{
    $stmt = db()->query('SELECT id, name, price_per_unit, stock_qty, created_at FROM ingredients ORDER BY name');
    return $stmt->fetchAll();
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
            'needed_qty' => 0,
        ];
    }

    $productPurchases = [];

    foreach ($products as $product) {
        $missingProductQty = max(0, (int)$product['target_qty'] - (int)$product['stock_qty']);
        if ($missingProductQty <= 0) {
            continue;
        }

        $recipe = recipeItemsByProduct((int)$product['id']);
        if ((int)$product['is_direct_purchase'] === 1) {
            $unitPrice = (float)$product['direct_purchase_price'];
            $productPurchases[] = [
                'name' => $product['name'],
                'qty' => $missingProductQty,
                'unit_price' => $unitPrice,
                'sum' => $unitPrice * $missingProductQty,
            ];
            continue;
        }

        foreach ($recipe as $item) {
            $id = (int)$item['ingredient_id'];
            $ingredientNeeds[$id]['needed_qty'] += (float)$item['qty_per_product'] * $missingProductQty;
        }
    }

    $ingredientPurchases = [];
    foreach ($ingredientNeeds as $need) {
        $missing = max(0, $need['needed_qty']);
        if ($missing > 0) {
            $ingredientPurchases[] = [
                'name' => $need['name'],
                'qty' => $missing,
                'unit' => 'Stk',
                'unit_price' => $need['price_per_unit'],
                'sum' => $missing * $need['price_per_unit'],
            ];
        }
    }

    $total = array_sum(array_column($ingredientPurchases, 'sum')) + array_sum(array_column($productPurchases, 'sum'));

    return [
        'ingredient_purchases' => $ingredientPurchases,
        'product_purchases' => $productPurchases,
        'total' => $total,
    ];
}
