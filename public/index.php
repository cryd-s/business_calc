<?php
require_once __DIR__ . '/../src/repository.php';

initSchema();

$view = $_GET['view'] ?? 'dashboard';
$action = $_POST['action'] ?? null;

try {
    if ($action === 'ingredient.create') {
        createIngredient($_POST);
        flash('Zutat angelegt.');
        header('Location: ?view=ingredients');
        exit;
    }
    if ($action === 'ingredient.update') {
        updateIngredient((int)$_POST['id'], $_POST);
        flash('Zutat aktualisiert.');
        header('Location: ?view=ingredients');
        exit;
    }
    if ($action === 'ingredient.delete') {
        deleteIngredient((int)$_POST['id']);
        flash('Zutat gelöscht.');
        header('Location: ?view=ingredients');
        exit;
    }

    if ($action === 'product.create') {
        createProduct($_POST);
        flash('Gericht/Getränk angelegt.');
        header('Location: ?view=products');
        exit;
    }
    if ($action === 'product.update') {
        updateProduct((int)$_POST['id'], $_POST);
        flash('Gericht/Getränk aktualisiert.');
        header('Location: ?view=products');
        exit;
    }
    if ($action === 'product.delete') {
        deleteProduct((int)$_POST['id']);
        flash('Gericht/Getränk gelöscht.');
        header('Location: ?view=products');
        exit;
    }

    if ($action === 'recipe.upsert') {
        upsertRecipeItem((int)$_POST['product_id'], (int)$_POST['ingredient_id'], (float)$_POST['qty_per_product']);
        flash('Rezeptposition gespeichert.');
        header('Location: ?view=recipe&product_id=' . (int)$_POST['product_id']);
        exit;
    }
    if ($action === 'recipe.delete') {
        $productId = (int)$_POST['product_id'];
        deleteRecipeItem((int)$_POST['id']);
        flash('Rezeptposition gelöscht.');
        header('Location: ?view=recipe&product_id=' . $productId);
        exit;
    }
} catch (Throwable $e) {
    flash('Fehler: ' . $e->getMessage());
}

$ingredients = allIngredients();
$products = allProducts();
$shoppingList = shoppingList();
$productForRecipe = isset($_GET['product_id']) ? productById((int)$_GET['product_id']) : null;
$recipeItems = $productForRecipe ? recipeItemsByProduct((int)$productForRecipe['id']) : [];
$message = flash();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Business Einkaufsliste</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; background: #f7f8fa; color: #202533; }
        nav a { margin-right: 14px; text-decoration: none; color: #1557ff; font-weight: bold; }
        section { background: #fff; border-radius: 10px; padding: 16px; margin-top: 16px; box-shadow: 0 3px 12px rgba(0,0,0,0.06); }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 8px; border-bottom: 1px solid #e4e8f0; text-align: left; }
        input, select { padding: 6px; width: 100%; box-sizing: border-box; }
        .grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; align-items: end; }
        button { padding: 8px 10px; border: none; border-radius: 7px; background: #1557ff; color: #fff; cursor: pointer; }
        .danger { background: #c53b37; }
        .flash { padding: 10px; border-radius: 8px; background: #eef4ff; margin-top: 12px; }
    </style>
</head>
<body>
<h1>Business Verwaltung & Einkaufsliste</h1>
<nav>
    <a href="?view=dashboard">Dashboard</a>
    <a href="?view=ingredients">Zutaten</a>
    <a href="?view=products">Gerichte/Getränke</a>
    <a href="?view=shopping">Einkaufsliste</a>
</nav>

<?php if ($message): ?>
    <p class="flash"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<?php if ($view === 'ingredients'): ?>
<section>
    <h2>Zutaten</h2>
    <form method="post" class="grid">
        <input type="hidden" name="action" value="ingredient.create">
        <div><label>Name<input required name="name"></label></div>
        <div><label>Preis / Einheit<input required step="0.01" type="number" name="price_per_unit"></label></div>
        <div><label>Einheit<input name="unit" placeholder="kg, l, Stk"></label></div>
        <div><label>Lagerbestand<input step="0.01" type="number" name="stock_qty" value="0"></label></div>
        <div><label>Mindestbestand<input step="0.01" type="number" name="min_stock_qty" value="0"></label></div>
        <div><button type="submit">Anlegen</button></div>
    </form>

    <table>
        <thead><tr><th>Name</th><th>Preis</th><th>Einheit</th><th>Lager</th><th>Min.</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($ingredients as $ingredient): ?>
            <tr>
                <form method="post">
                    <input type="hidden" name="action" value="ingredient.update">
                    <input type="hidden" name="id" value="<?= (int)$ingredient['id'] ?>">
                    <td><input name="name" value="<?= htmlspecialchars($ingredient['name']) ?>"></td>
                    <td><input type="number" step="0.01" name="price_per_unit" value="<?= htmlspecialchars((string)$ingredient['price_per_unit']) ?>"></td>
                    <td><input name="unit" value="<?= htmlspecialchars($ingredient['unit']) ?>"></td>
                    <td><input type="number" step="0.01" name="stock_qty" value="<?= htmlspecialchars((string)$ingredient['stock_qty']) ?>"></td>
                    <td><input type="number" step="0.01" name="min_stock_qty" value="<?= htmlspecialchars((string)$ingredient['min_stock_qty']) ?>"></td>
                    <td>
                        <button>Speichern</button>
                </form>
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="ingredient.delete">
                    <input type="hidden" name="id" value="<?= (int)$ingredient['id'] ?>">
                    <button class="danger" onclick="return confirm('Wirklich löschen?')">Löschen</button>
                </form>
                    </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php elseif ($view === 'products'): ?>
<section>
    <h2>Gerichte & Getränke</h2>
    <form method="post" class="grid">
        <input type="hidden" name="action" value="product.create">
        <div><label>Name<input required name="name"></label></div>
        <div><label>Typ<select name="type"><option value="gericht">Gericht</option><option value="getraenk">Getränk</option></select></label></div>
        <div><label>Direkt-Einkaufspreis<input step="0.01" type="number" name="direct_purchase_price" placeholder="Optional"></label></div>
        <div><label>Zielbestand<input type="number" name="target_qty" value="0"></label></div>
        <div><label>Ist-Bestand<input type="number" name="stock_qty" value="0"></label></div>
        <div><button type="submit">Anlegen</button></div>
    </form>

    <table>
        <thead><tr><th>Name</th><th>Typ</th><th>Ziel</th><th>Ist</th><th>Preis pro Stück</th><th>Rezept</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($products as $product): ?>
            <tr>
                <form method="post">
                    <input type="hidden" name="action" value="product.update">
                    <input type="hidden" name="id" value="<?= (int)$product['id'] ?>">
                    <td><input name="name" value="<?= htmlspecialchars($product['name']) ?>"></td>
                    <td>
                        <select name="type">
                            <option value="gericht" <?= $product['type'] === 'gericht' ? 'selected' : '' ?>>Gericht</option>
                            <option value="getraenk" <?= $product['type'] === 'getraenk' ? 'selected' : '' ?>>Getränk</option>
                        </select>
                    </td>
                    <td><input type="number" name="target_qty" value="<?= (int)$product['target_qty'] ?>"></td>
                    <td><input type="number" name="stock_qty" value="<?= (int)$product['stock_qty'] ?>"></td>
                    <td><input type="number" step="0.01" name="direct_purchase_price" value="<?= htmlspecialchars((string)$product['direct_purchase_price']) ?>" placeholder="optional"></td>
                    <td>
                        <a href="?view=recipe&product_id=<?= (int)$product['id'] ?>">Bearbeiten (<?= (int)$product['ingredient_count'] ?>)</a><br>
                        kalkuliert: <?= number_format((float)$product['calculated_recipe_price'], 2, ',', '.') ?> €
                    </td>
                    <td>
                        <button>Speichern</button>
                </form>
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="product.delete">
                    <input type="hidden" name="id" value="<?= (int)$product['id'] ?>">
                    <button class="danger" onclick="return confirm('Wirklich löschen?')">Löschen</button>
                </form>
                    </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php elseif ($view === 'recipe' && $productForRecipe): ?>
<section>
    <h2>Rezept für <?= htmlspecialchars($productForRecipe['name']) ?></h2>
    <p>Leeres Rezept = direkt einkaufbares Produkt (z. B. Getränke/Fertigwaren).</p>
    <form method="post" class="grid" style="grid-template-columns: 3fr 2fr 1fr;">
        <input type="hidden" name="action" value="recipe.upsert">
        <input type="hidden" name="product_id" value="<?= (int)$productForRecipe['id'] ?>">
        <div><label>Zutat
            <select name="ingredient_id">
                <?php foreach ($ingredients as $ingredient): ?>
                    <option value="<?= (int)$ingredient['id'] ?>"><?= htmlspecialchars($ingredient['name']) ?> (<?= htmlspecialchars($ingredient['unit']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label></div>
        <div><label>Menge pro Produkt<input required type="number" step="0.01" name="qty_per_product"></label></div>
        <div><button>Speichern</button></div>
    </form>

    <table>
        <thead><tr><th>Zutat</th><th>Menge</th><th>Einheit</th><th>Kosten/Produkt</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($recipeItems as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['ingredient_name']) ?></td>
                <td><?= number_format((float)$item['qty_per_product'], 2, ',', '.') ?></td>
                <td><?= htmlspecialchars($item['unit']) ?></td>
                <td><?= number_format((float)$item['qty_per_product'] * (float)$item['price_per_unit'], 2, ',', '.') ?> €</td>
                <td>
                    <form method="post">
                        <input type="hidden" name="action" value="recipe.delete">
                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                        <input type="hidden" name="product_id" value="<?= (int)$productForRecipe['id'] ?>">
                        <button class="danger">Entfernen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php else: ?>
<section>
    <h2>Dashboard</h2>
    <p>Lege zuerst Zutaten und Gerichte/Getränke an. Hinterlege Ziel- und Ist-Bestand.</p>
    <ul>
        <li>Anzahl Zutaten: <?= count($ingredients) ?></li>
        <li>Anzahl Gerichte/Getränke: <?= count($products) ?></li>
        <li>Aktueller Einkaufsbedarf: <?= number_format((float)$shoppingList['total'], 2, ',', '.') ?> €</li>
    </ul>
</section>
<?php endif; ?>

<?php if ($view === 'shopping' || $view === 'dashboard'): ?>
<section>
    <h2>Einkaufsliste (automatisch)</h2>
    <h3>Fehlende Zutaten</h3>
    <table>
        <thead><tr><th>Zutat</th><th>Menge</th><th>Einheit</th><th>Preis / Einheit</th><th>Summe</th></tr></thead>
        <tbody>
        <?php foreach ($shoppingList['ingredient_purchases'] as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= number_format((float)$row['qty'], 2, ',', '.') ?></td>
                <td><?= htmlspecialchars($row['unit']) ?></td>
                <td><?= number_format((float)$row['unit_price'], 2, ',', '.') ?> €</td>
                <td><?= number_format((float)$row['sum'], 2, ',', '.') ?> €</td>
            </tr>
        <?php endforeach; ?>
        <?php if (count($shoppingList['ingredient_purchases']) === 0): ?>
            <tr><td colspan="5">Keine fehlenden Zutaten.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3>Direkt einzukaufende Gerichte/Getränke</h3>
    <table>
        <thead><tr><th>Artikel</th><th>Menge</th><th>Preis / Stück</th><th>Summe</th></tr></thead>
        <tbody>
        <?php foreach ($shoppingList['product_purchases'] as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= (int)$row['qty'] ?></td>
                <td><?= number_format((float)$row['unit_price'], 2, ',', '.') ?> €</td>
                <td><?= number_format((float)$row['sum'], 2, ',', '.') ?> €</td>
            </tr>
        <?php endforeach; ?>
        <?php if (count($shoppingList['product_purchases']) === 0): ?>
            <tr><td colspan="4">Keine direkten Artikel nötig.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <p><strong>Gesamtkosten Einkauf: <?= number_format((float)$shoppingList['total'], 2, ',', '.') ?> €</strong></p>
</section>
<?php endif; ?>
</body>
</html>
