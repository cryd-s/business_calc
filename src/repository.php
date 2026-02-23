<?php

require_once __DIR__ . '/db.php';

function allIngredients(): array
{
    $stmt = db()->query('SELECT * FROM ingredients ORDER BY name');
    return $stmt->fetchAll();
}

function createIngredient(array $input): void
{
    $stmt = db()->prepare('INSERT INTO ingredients (name, price_per_unit, unit, stock_qty, min_stock_qty) VALUES (:name, :price, :unit, :stock, :min_stock)');
    $stmt->execute([
        ':name' => trim($input['name']),
        ':price' => (float)$input['price_per_unit'],
        ':unit' => trim($input['unit']) ?: 'Stk',
        ':stock' => (float)$input['stock_qty'],
        ':min_stock' => (float)$input['min_stock_qty'],
    ]);
}

function updateIngredient(int $id, array $input): void
{
    $stmt = db()->prepare('UPDATE ingredients SET name = :name, price_per_unit = :price, unit = :unit, stock_qty = :stock, min_stock_qty = :min_stock WHERE id = :id');
    $stmt->execute([
        ':id' => $id,
        ':name' => trim($input['name']),
        ':price' => (float)$input['price_per_unit'],
        ':unit' => trim($input['unit']) ?: 'Stk',
        ':stock' => (float)$input['stock_qty'],
        ':min_stock' => (float)$input['min_stock_qty'],
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

function createProduct(array $input): void
{
    $stmt = db()->prepare('INSERT INTO products (name, type, direct_purchase_price, target_qty, stock_qty) VALUES (:name, :type, :price, :target, :stock)');
    $directPrice = $input['direct_purchase_price'] !== '' ? (float)$input['direct_purchase_price'] : null;
    $stmt->execute([
        ':name' => trim($input['name']),
        ':type' => $input['type'] === 'getraenk' ? 'getraenk' : 'gericht',
        ':price' => $directPrice,
        ':target' => (int)$input['target_qty'],
        ':stock' => (int)$input['stock_qty'],
    ]);
}

function updateProduct(int $id, array $input): void
{
    $stmt = db()->prepare('UPDATE products SET name = :name, type = :type, direct_purchase_price = :price, target_qty = :target, stock_qty = :stock WHERE id = :id');
    $directPrice = $input['direct_purchase_price'] !== '' ? (float)$input['direct_purchase_price'] : null;
    $stmt->execute([
        ':id' => $id,
        ':name' => trim($input['name']),
        ':type' => $input['type'] === 'getraenk' ? 'getraenk' : 'gericht',
        ':price' => $directPrice,
        ':target' => (int)$input['target_qty'],
        ':stock' => (int)$input['stock_qty'],
    ]);
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
    $stmt = db()->prepare('SELECT ri.*, i.name AS ingredient_name, i.unit, i.price_per_unit FROM recipe_items ri JOIN ingredients i ON i.id = ri.ingredient_id WHERE ri.product_id = :id ORDER BY i.name');
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
            'unit' => $ingredient['unit'],
            'price_per_unit' => (float)$ingredient['price_per_unit'],
            'needed_qty' => 0,
            'current_stock' => (float)$ingredient['stock_qty'],
            'min_stock' => (float)$ingredient['min_stock_qty'],
        ];
    }

    $productPurchases = [];

    foreach ($products as $product) {
        $missingProductQty = max(0, (int)$product['target_qty'] - (int)$product['stock_qty']);
        if ($missingProductQty <= 0) {
            continue;
        }

        $recipe = recipeItemsByProduct((int)$product['id']);
        if (count($recipe) === 0) {
            $unitPrice = $product['direct_purchase_price'] !== null ? (float)$product['direct_purchase_price'] : (float)$product['calculated_recipe_price'];
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
        $missing = max(0, $need['needed_qty'] + $need['min_stock'] - $need['current_stock']);
        if ($missing > 0) {
            $ingredientPurchases[] = [
                'name' => $need['name'],
                'qty' => $missing,
                'unit' => $need['unit'],
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
