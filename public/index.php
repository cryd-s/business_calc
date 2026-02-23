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
        flash('Gericht angelegt.');
        header('Location: ?view=products');
        exit;
    }
    if ($action === 'product.update') {
        updateProduct((int)$_POST['id'], $_POST);
        flash('Gericht aktualisiert.');
        header('Location: ?view=products');
        exit;
    }
    if ($action === 'product.delete') {
        deleteProduct((int)$_POST['id']);
        flash('Gericht gelöscht.');
        header('Location: ?view=products');
        exit;
    }

    if ($action === 'recipe.upsert') {
        upsertRecipeItem((int)$_POST['product_id'], (int)$_POST['ingredient_id'], (float)$_POST['qty_per_product']);
        flash('Rezeptposition gespeichert.');
        header('Location: ?view=recipe&product_id=' . (int)$_POST['product_id']);
        exit;
    }
    if ($action === 'recipe.assign') {
        assignRecipeItems((int)$_POST['product_id'], $_POST['ingredient_qty'] ?? []);
        flash('Zutaten für das Gericht zugewiesen.');
        header('Location: ?view=products');
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
        :root {
            --bg: #04070f;
            --panel: rgba(10, 16, 33, 0.88);
            --panel-border: rgba(109, 130, 185, 0.35);
            --text: #e8eefb;
            --muted: #8f9fc5;
            --accent: #1ed9d2;
            --accent-2: #3c69ff;
            --danger: #ec5750;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px;
            font-family: Inter, Segoe UI, Arial, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 10% 0%, rgba(30, 217, 210, 0.2), transparent 35%),
                radial-gradient(circle at 70% -10%, rgba(60, 105, 255, 0.18), transparent 30%),
                var(--bg);
        }
        h1 { margin: 0; font-size: 1.6rem; }
        h2, h3 { color: #f2f6ff; margin-top: 0; }
        p, li, label { color: var(--muted); }
        .app-shell { max-width: 1500px; margin: 0 auto; }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 14px 18px;
            border-radius: 16px;
            background: linear-gradient(120deg, rgba(11, 22, 46, 0.95), rgba(5, 10, 22, 0.95));
            border: 1px solid var(--panel-border);
            box-shadow: 0 0 0 1px rgba(9, 25, 53, 0.45), 0 16px 30px rgba(0, 0, 0, 0.45);
        }
        .pill-nav {
            margin-top: 16px;
            display: inline-flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 8px;
            border-radius: 999px;
            background: rgba(8, 14, 29, 0.8);
            border: 1px solid var(--panel-border);
        }
        nav a {
            text-decoration: none;
            color: var(--text);
            padding: 10px 14px;
            border-radius: 999px;
            transition: 0.2s ease;
        }
        nav a:hover,
        nav a.active {
            color: #041015;
            background: linear-gradient(135deg, var(--accent), #53e9b0);
            box-shadow: 0 0 15px rgba(30, 217, 210, 0.45);
        }
        .content-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 18px;
            margin-top: 18px;
        }
        section {
            background: linear-gradient(140deg, rgba(7, 12, 25, 0.94), rgba(5, 8, 19, 0.94));
            border: 1px solid var(--panel-border);
            border-radius: 14px;
            padding: 16px;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.35);
            margin-bottom: 16px;
        }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td {
            padding: 10px 8px;
            border-bottom: 1px solid rgba(151, 169, 214, 0.22);
            text-align: left;
            color: #d6def2;
        }
        input, select {
            padding: 10px;
            width: 100%;
            background: rgba(10, 16, 33, 0.95);
            color: var(--text);
            border: 1px solid rgba(131, 149, 193, 0.35);
            border-radius: 10px;
        }
        .grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; align-items: end; }
        button {
            padding: 10px 14px;
            border: 1px solid rgba(78, 209, 226, 0.5);
            border-radius: 999px;
            background: linear-gradient(135deg, #0f223f, #0e1730);
            color: var(--text);
            cursor: pointer;
        }
        button:hover { border-color: var(--accent); box-shadow: 0 0 15px rgba(30, 217, 210, 0.3); }
        .danger { border-color: rgba(236, 87, 80, 0.7); color: #ffd6d4; }
        .flash {
            padding: 12px;
            border-radius: 10px;
            border: 1px solid rgba(30, 217, 210, 0.4);
            background: rgba(17, 35, 49, 0.8);
            margin-top: 14px;
        }
        a { color: #87e5ff; }
        @media (max-width: 1100px) {
            .content-grid { grid-template-columns: 1fr; }
            .grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <header class="top-bar">
        <h1>Business Verwaltung & Einkaufsliste</h1>
        <small>Modernes Dark UI</small>
    </header>

    <nav class="pill-nav">
        <a class="<?= $view === 'dashboard' ? 'active' : '' ?>" href="?view=dashboard">Dashboard</a>
        <a class="<?= $view === 'ingredients' ? 'active' : '' ?>" href="?view=ingredients">Zutaten</a>
        <a class="<?= $view === 'products' ? 'active' : '' ?>" href="?view=products">Gerichte</a>
        <a class="<?= $view === 'shopping' ? 'active' : '' ?>" href="?view=shopping">Einkaufsliste</a>
    </nav>

<?php if ($message): ?>
    <p class="flash"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<div class="content-grid">
    <div>

<?php if ($view === 'ingredients'): ?>
<section>
    <h2>Zutaten</h2>
    <form method="post" class="grid">
        <input type="hidden" name="action" value="ingredient.create">
        <div><label>Name<input required name="name"></label></div>
        <div><label>Preis pro Stück<input required step="0.01" type="number" name="price_per_unit"></label></div>
        <div><button type="submit">Anlegen</button></div>
    </form>

    <table>
        <thead><tr><th>Name</th><th>Preis pro Stück</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($ingredients as $ingredient): ?>
            <tr>
                <form method="post">
                    <input type="hidden" name="action" value="ingredient.update">
                    <input type="hidden" name="id" value="<?= (int)$ingredient['id'] ?>">
                    <td><input name="name" value="<?= htmlspecialchars($ingredient['name']) ?>"></td>
                    <td><input type="number" step="0.01" name="price_per_unit" value="<?= htmlspecialchars((string)$ingredient['price_per_unit']) ?>"></td>
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
    <h2>Gerichte</h2>
    <form method="post" class="grid">
        <input type="hidden" name="action" value="product.create">
        <div><label>Name<input required name="name"></label></div>
        <div><label>Typ
            <select name="product_type">
                <option value="recipe">Kombination mehrerer Zutaten</option>
                <option value="direct">Direktes Gericht</option>
            </select>
        </label></div>
        <div><label>Preis pro Stück (nur bei direktem Gericht)<input step="0.01" type="number" name="direct_purchase_price" value="0"></label></div>
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
                        <select name="product_type">
                            <option value="recipe" <?= (int)$product['is_direct_purchase'] === 0 ? 'selected' : '' ?>>Kombi-Zutaten</option>
                            <option value="direct" <?= (int)$product['is_direct_purchase'] === 1 ? 'selected' : '' ?>>Direktes Gericht</option>
                        </select>
                    </td>
                    <td><input type="number" name="target_qty" value="<?= (int)$product['target_qty'] ?>"></td>
                    <td><input type="number" name="stock_qty" value="<?= (int)$product['stock_qty'] ?>"></td>
                    <td><input type="number" step="0.01" name="direct_purchase_price" value="<?= htmlspecialchars((string)$product['direct_purchase_price']) ?>" placeholder="nur direkt"></td>
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

<section>
    <h3>Zutaten einer Kombination zuweisen</h3>
    <p>Wähle ein Kombi-Gericht aus und trage die benötigte Stückzahl je Zutat ein. Leere/Feld 0 wird ignoriert.</p>
    <form method="post">
        <input type="hidden" name="action" value="recipe.assign">
        <div class="grid" style="grid-template-columns: 2fr 1fr; margin-bottom: 10px;">
            <div><label>Gericht (Kombination)
                <select name="product_id" required>
                    <?php foreach ($products as $product): ?>
                        <?php if ((int)$product['is_direct_purchase'] === 0): ?>
                            <option value="<?= (int)$product['id'] ?>"><?= htmlspecialchars($product['name']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label></div>
            <div><button type="submit">Zuweisen</button></div>
        </div>

        <table>
            <thead><tr><th>Zutat</th><th>Stück pro Gericht</th></tr></thead>
            <tbody>
            <?php foreach ($ingredients as $ingredient): ?>
                <tr>
                    <td><?= htmlspecialchars($ingredient['name']) ?></td>
                    <td>
                        <input type="number" min="0" step="0.01" name="ingredient_qty[<?= (int)$ingredient['id'] ?>]" value="0">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </form>
</section>

<?php elseif ($view === 'recipe' && $productForRecipe): ?>
<section>
    <h2>Rezept für <?= htmlspecialchars($productForRecipe['name']) ?></h2>
    <p>Hier legst du fest, wie viele Stück einer Zutat für ein Gericht benötigt werden (Typ: Kombination mehrerer Zutaten).</p>
    <form method="post" class="grid" style="grid-template-columns: 3fr 2fr 1fr;">
        <input type="hidden" name="action" value="recipe.upsert">
        <input type="hidden" name="product_id" value="<?= (int)$productForRecipe['id'] ?>">
        <div><label>Zutat
            <select name="ingredient_id">
                <?php foreach ($ingredients as $ingredient): ?>
                    <option value="<?= (int)$ingredient['id'] ?>"><?= htmlspecialchars($ingredient['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label></div>
        <div><label>Stück pro Gericht<input required type="number" step="0.01" name="qty_per_product"></label></div>
        <div><button>Speichern</button></div>
    </form>

    <table>
        <thead><tr><th>Zutat</th><th>Stück</th><th>Kosten/Gericht</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($recipeItems as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['ingredient_name']) ?></td>
                <td><?= number_format((float)$item['qty_per_product'], 2, ',', '.') ?></td>
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
    <p>Lege zuerst Zutaten und Gerichte an. Hinterlege Ziel- und Ist-Bestand.</p>
    <ul>
        <li>Anzahl Zutaten: <?= count($ingredients) ?></li>
        <li>Anzahl Gerichte: <?= count($products) ?></li>
        <li>Aktueller Einkaufsbedarf: <?= number_format((float)$shoppingList['total'], 2, ',', '.') ?> €</li>
    </ul>
</section>
<?php endif; ?>
    </div>

    <div>
        <section>
            <h2>Live Überblick</h2>
            <ul>
                <li>Anzahl Zutaten: <?= count($ingredients) ?></li>
                <li>Anzahl Gerichte: <?= count($products) ?></li>
                <li>Gesamter Bedarf: <?= number_format((float)$shoppingList['total'], 2, ',', '.') ?> €</li>
            </ul>
        </section>

<?php if ($view === 'shopping' || $view === 'dashboard'): ?>
<section>
    <h2>Einkaufsliste (automatisch)</h2>
    <h3>Fehlende Zutaten</h3>
    <table>
        <thead><tr><th>Zutat</th><th>Stück</th><th>Einheit</th><th>Preis / Stück</th><th>Summe</th></tr></thead>
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

    <h3>Direkt einzukaufende Gerichte</h3>
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
    </div>
</div>
</div>
</body>
</html>
