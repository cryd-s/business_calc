<?php
require_once __DIR__ . '/src/repository.php';

initSchema();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function appBaseUrl(): string
{
    $cfg = appConfig();
    return rtrim((string)($cfg['app']['base_url'] ?? ''), '/');
}

function currentUser(): ?array
{
    return $_SESSION['auth_user'] ?? null;
}

function isLoggedIn(): bool
{
    return is_array(currentUser());
}

function isAdminUser(?array $user): bool
{
    return is_array($user) && (int)($user['is_admin'] ?? 0) === 1;
}

function requireAuth(): void
{
    if (!isLoggedIn()) {
        flash('Bitte zuerst mit Discord anmelden.');
        header('Location: ?view=login');
        exit;
    }
}

function requireAdmin(): void
{
    $user = currentUser();
    if (!isAdminUser($user)) {
        flash('Nur Admins dürfen diese Aktion ausführen.');
        header('Location: ?view=dashboard');
        exit;
    }
}

function discordAuthUrl(): string
{
    $cfg = appConfig()['discord'] ?? [];
    $clientId = trim((string)($cfg['client_id'] ?? ''));
    $redirectUri = trim((string)($cfg['redirect_uri'] ?? ''));

    if ($clientId === '' || $redirectUri === '') {
        return '#';
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['discord_oauth_state'] = $state;

    $params = [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'identify',
        'state' => $state,
        'prompt' => 'none',
    ];

    return 'https://discord.com/api/oauth2/authorize?' . http_build_query($params);
}

function postFormJson(string $url, array $data): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/x-www-form-urlencoded
",
            'content' => http_build_query($data),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    $raw = file_get_contents($url, false, $context);
    if (!is_string($raw)) {
        throw new RuntimeException('Discord API konnte nicht erreicht werden.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Ungültige Discord-Antwort erhalten.');
    }

    return $decoded;
}

function getJson(string $url, string $accessToken): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$accessToken}
",
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    $raw = file_get_contents($url, false, $context);
    if (!is_string($raw)) {
        throw new RuntimeException('Discord Benutzerprofil konnte nicht geladen werden.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Ungültige Antwort vom Discord-Benutzerprofil.');
    }

    return $decoded;
}

function postJson(string $url, array $payload): void
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    $raw = file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException('Discord Webhook konnte nicht erreicht werden.');
    }

    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s(2\d\d)\s/', $statusLine)) {
        throw new RuntimeException('Discord Webhook antwortete mit Fehler: ' . $statusLine);
    }
}

function sendShoppingListWebhook(array $shoppingList, string $companyName, ?array $user): void
{
    $webhookUrl = discordWebhookUrl();
    if ($webhookUrl === '') {
        throw new RuntimeException('Bitte zuerst eine Discord Webhook-URL unter Optionen hinterlegen.');
    }

    $lines = [];
    foreach ($shoppingList['items'] as $row) {
        $qty = number_format((float)$row['qty'], 0, ',', '.');
        $lines[] = sprintf('- %s: %s', (string)$row['name'], $qty);
    }

    if ($lines === []) {
        $lines[] = 'Kein Einkaufsbedarf vorhanden.';
    }

    $completedBy = trim((string)($user['display_name'] ?? 'Unbekannt'));
    $titlePrefix = trim($companyName) !== '' ? $companyName . ' · ' : '';

    $payload = [
        'embeds' => [[
            'title' => $titlePrefix . 'Einkaufsliste abgeschlossen',
            'description' => implode("\n", $lines),
            'color' => 1755602,
            'fields' => [
                [
                    'name' => 'Gesamtkosten',
                    'value' => number_format((float)$shoppingList['total'], 0, ',', '.') . ' $',
                    'inline' => true,
                ],
                [
                    'name' => 'Abgeschlossen von',
                    'value' => $completedBy !== '' ? $completedBy : 'Unbekannt',
                    'inline' => true,
                ],
            ],
            'timestamp' => gmdate('c'),
        ]],
    ];

    postJson($webhookUrl, $payload);
}

$view = $_GET['view'] ?? 'dashboard';
if (isset($_GET['code']) || isset($_GET['error'])) {
    $view = 'oauth-callback';
}
$action = $_POST['action'] ?? null;

try {
    if ($action === 'auth.logout') {
        unset($_SESSION['auth_user']);
        flash('Du wurdest abgemeldet.');
        header('Location: ?view=login');
        exit;
    }

    if ($action === 'admin.user.approve') {
        requireAdmin();
        setUserApproval((string)($_POST['discord_id'] ?? ''), true);
        flash('Mitarbeiter freigeschaltet.');
        header('Location: ?view=admin&admin_tab=employees');
        exit;
    }

    if ($action === 'admin.user.revoke') {
        requireAdmin();
        setUserApproval((string)($_POST['discord_id'] ?? ''), false);
        flash('Freigabe entfernt.');
        header('Location: ?view=admin&admin_tab=employees');
        exit;
    }

    if ($action === 'admin.user.make_admin') {
        requireAdmin();
        setUserAdmin((string)($_POST['discord_id'] ?? ''), true);
        flash('Admin-Berechtigung erteilt.');
        header('Location: ?view=admin&admin_tab=employees');
        exit;
    }

    if ($action === 'admin.user.remove_admin') {
        requireAdmin();
        setUserAdmin((string)($_POST['discord_id'] ?? ''), false);
        flash('Admin-Berechtigung entfernt.');
        header('Location: ?view=admin&admin_tab=employees');
        exit;
    }

    if ($action !== null) {
        requireAuth();

        $adminOnlyActions = [
            'ingredient.create',
            'ingredient.update',
            'ingredient.stock.update',
            'ingredient.delete',
            'product.create',
            'product.update',
            'product.stock.update',
            'product.delete',
            'recipe.upsert',
            'recipe.assign',
            'recipe.delete',
        ];
        if (in_array($action, $adminOnlyActions, true)) {
            requireAdmin();
        }

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
        if ($action === 'ingredient.stock.update') {
            updateIngredientStock((int)$_POST['id'], (float)($_POST['stock_qty'] ?? 0));
            flash('Lagerbestand der Zutat aktualisiert.');
            header('Location: ?view=inventory');
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
        if ($action === 'product.stock.update') {
            updateProductStock((int)$_POST['id'], (int)($_POST['stock_qty'] ?? 0));
            flash('Lagerbestand des Gerichts aktualisiert.');
            header('Location: ?view=inventory');
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
        if ($action === 'options.company.update') {
            requireAdmin();
            updateCompanyName((string)($_POST['company_name'] ?? ''));
            flash('Unternehmensname gespeichert.');
            header('Location: ?view=admin&admin_tab=options');
            exit;
        }
        if ($action === 'options.webhook.update') {
            requireAdmin();
            updateDiscordWebhookUrl((string)($_POST['discord_webhook_url'] ?? ''));
            flash('Discord Webhook gespeichert.');
            header('Location: ?view=admin&admin_tab=options');
            exit;
        }
        if ($action === 'shopping.complete') {
            $list = shoppingList();
            sendShoppingListWebhook($list, companyName(), currentUser());
            flash('Einkaufsliste abgeschlossen und an Discord gesendet. Lagerbestände wurden nicht verändert.');
            header('Location: ?view=shopping');
            exit;
        }
    }

    if ($view === 'oauth-callback') {
        $oauthError = trim((string)($_GET['error'] ?? ''));
        if ($oauthError !== '') {
            throw new RuntimeException('Discord Login wurde abgebrochen oder verweigert (' . $oauthError . ').');
        }

        $cfg = appConfig()['discord'] ?? [];
        $clientId = trim((string)($cfg['client_id'] ?? ''));
        $clientSecret = trim((string)($cfg['client_secret'] ?? ''));
        $redirectUri = trim((string)($cfg['redirect_uri'] ?? ''));

        if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
            throw new RuntimeException('Discord OAuth ist nicht vollständig konfiguriert.');
        }

        $state = (string)($_GET['state'] ?? '');
        $expectedState = (string)($_SESSION['discord_oauth_state'] ?? '');
        unset($_SESSION['discord_oauth_state']);
        if ($state === '' || !hash_equals($expectedState, $state)) {
            throw new RuntimeException('Ungültiger OAuth-Status. Bitte erneut einloggen.');
        }

        $code = (string)($_GET['code'] ?? '');
        if ($code === '') {
            throw new RuntimeException('Discord hat keinen Code geliefert.');
        }

        $tokenData = postFormJson('https://discord.com/api/oauth2/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        $accessToken = (string)($tokenData['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Discord Access-Token konnte nicht gelesen werden.');
        }

        $discordUser = getJson('https://discord.com/api/users/@me', $accessToken);
        $discordId = trim((string)($discordUser['id'] ?? ''));
        $displayName = trim((string)($discordUser['global_name'] ?? $discordUser['username'] ?? ''));
        if ($discordId === '') {
            throw new RuntimeException('Discord ID fehlt in der Antwort.');
        }

        $user = createOrUpdateUserAccess($discordId, $displayName);
        if ((int)$user['is_approved'] !== 1 && !isAdminUser($user)) {
            unset($_SESSION['auth_user']);
            flash('Du bist noch nicht freigeschaltet. Bitte Admin kontaktieren.');
            header('Location: ?view=login');
            exit;
        }

        $_SESSION['auth_user'] = [
            'discord_id' => $user['discord_id'],
            'display_name' => $user['display_name'],
            'is_admin' => (int)$user['is_admin'],
            'is_approved' => (int)$user['is_approved'],
        ];

        flash('Erfolgreich mit Discord eingeloggt.');
        header('Location: ?view=dashboard');
        exit;
    }
} catch (Throwable $e) {
    flash('Fehler: ' . $e->getMessage());
}

$user = currentUser();
$loggedIn = isLoggedIn();
if (!$loggedIn && !in_array($view, ['login', 'oauth-callback'], true)) {
    $view = 'login';
}

$adminOnlyViews = ['ingredients', 'products', 'recipe'];
if ($loggedIn && !isAdminUser($user) && in_array($view, $adminOnlyViews, true)) {
    flash('Zutaten und Gerichte sind nur für Admins sichtbar.');
    $view = 'dashboard';
}

$ingredients = $loggedIn ? allIngredients() : [];
$products = $loggedIn ? allProducts() : [];
$inventoryIngredients = $loggedIn ? allIngredientsByStockOrder() : [];
$inventoryProducts = $loggedIn ? allProductsByStockOrder() : [];
$inventoryItems = [];
foreach ($inventoryProducts as $product) {
    $inventoryItems[] = [
        'type' => 'product',
        'type_label' => 'Gericht',
        'id' => (int)$product['id'],
        'name' => $product['name'],
        'stock_qty' => (float)$product['stock_qty'],
    ];
}
foreach ($inventoryIngredients as $ingredient) {
    $inventoryItems[] = [
        'type' => 'ingredient',
        'type_label' => 'Zutat',
        'id' => (int)$ingredient['id'],
        'name' => $ingredient['name'],
        'stock_qty' => (float)$ingredient['stock_qty'],
    ];
}
usort($inventoryItems, static function (array $a, array $b): int {
    return strcasecmp($a['name'], $b['name']);
});
$shoppingList = $loggedIn ? shoppingList() : ['items' => [], 'total' => 0];
$companyName = companyName();
$discordWebhookUrl = $loggedIn && isAdminUser($user) ? discordWebhookUrl() : '';
$productForRecipe = $loggedIn && isset($_GET['product_id']) ? productById((int)$_GET['product_id']) : null;
$recipeItems = $productForRecipe ? recipeItemsByProduct((int)$productForRecipe['id']) : [];
$adminUsers = ($loggedIn && isAdminUser($user)) ? allUserAccessEntries() : [];
$discordLoginUrl = discordAuthUrl();
$message = flash();

$adminTab = (string)($_GET['admin_tab'] ?? 'employees');
$allowedAdminTabs = ['options', 'employees', 'products', 'ingredients'];
if (!in_array($adminTab, $allowedAdminTabs, true)) {
    $adminTab = 'employees';
}

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
        html, body {
            min-height: 100%;
        }
        body {
            margin: 0;
            padding: 24px;
            min-height: 100vh;
            font-family: Inter, Segoe UI, Arial, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 10% 0%, rgba(30, 217, 210, 0.2), transparent 35%),
                radial-gradient(circle at 70% -10%, rgba(60, 105, 255, 0.18), transparent 30%),
                var(--bg);
            background-repeat: no-repeat;
            background-size: cover;
            background-attachment: fixed;
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
        .admin-nav-wrapper {
            margin-top: 10px;
            display: flex;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 10px;
        }
        .admin-nav-wrapper details {
            width: 100%;
        }
        .admin-nav-wrapper summary {
            list-style: none;
            cursor: pointer;
            width: fit-content;
        }
        .admin-nav-wrapper summary::-webkit-details-marker {
            display: none;
        }
        .admin-nav-label {
            color: var(--muted);
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-size: 0.82rem;
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
        .content-grid.shopping-view {
            grid-template-columns: 1fr;
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
        .autosave-form {
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: stretch;
        }
        .autosave-note {
            min-height: 1.1em;
            font-size: 0.8rem;
            color: var(--accent);
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
        <h1><?= htmlspecialchars(trim(($companyName !== '' ? $companyName . ' ' : '') . 'Business Verwaltung & Einkaufsliste')) ?></h1>
        <?php if ($loggedIn): ?>
            <div style="display:flex; align-items:center; gap:10px;">
                <small>Angemeldet als <?= htmlspecialchars((string)($user['display_name'] ?? $user['discord_id'] ?? '')) ?></small>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="auth.logout">
                    <button type="submit">Logout</button>
                </form>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($loggedIn): ?>
    <nav class="pill-nav">
        <a class="<?= $view === 'dashboard' ? 'active' : '' ?>" href="?view=dashboard">Dashboard</a>
        <a class="<?= $view === 'inventory' ? 'active' : '' ?>" href="?view=inventory">Lager</a>
        <a class="<?= $view === 'shopping' ? 'active' : '' ?>" href="?view=shopping">Einkaufsliste</a>
    </nav>
    <?php endif; ?>

    <?php if ($loggedIn && isAdminUser($user)): ?>
    <div class="admin-nav-wrapper">
        <details>
            <summary><button type="button">Admin-Menü</button></summary>
            <nav class="pill-nav" style="margin-top:8px;">
                <a class="<?= ($view === 'ingredients') || ($view === 'admin' && $adminTab === 'ingredients') ? 'active' : '' ?>" href="?view=admin&admin_tab=ingredients">Zutaten</a>
                <a class="<?= ($view === 'products') || ($view === 'admin' && $adminTab === 'products') ? 'active' : '' ?>" href="?view=admin&admin_tab=products">Rezepte</a>
                <a class="<?= ($view === 'options') || ($view === 'admin' && $adminTab === 'options') ? 'active' : '' ?>" href="?view=admin&admin_tab=options">Optionen</a>
                <a class="<?= $view === 'admin' && $adminTab === 'employees' ? 'active' : '' ?>" href="?view=admin&admin_tab=employees">Mitarbeiter</a>
            </nav>
        </details>
    </div>
    <?php endif; ?>

<?php if ($message): ?>
    <p class="flash"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<div class="content-grid <?= in_array($view, ['shopping', 'login'], true) ? 'shopping-view' : '' ?>">
    <div>

<?php if ($view === 'login'): ?>
<section>
    <h2>Discord Login</h2>
    <p>Bitte melde dich mit Discord an. Deine Discord-ID dient als eindeutiger Identifier. Neue Nutzer müssen einmalig von einem Admin freigeschaltet werden.</p>
    <?php if ($discordLoginUrl === '#'): ?>
        <p>Discord OAuth ist noch nicht konfiguriert. Ergänze bitte die Werte in <code>config/config.php</code>.</p>
    <?php else: ?>
        <a href="<?= htmlspecialchars($discordLoginUrl) ?>"><button type="button">Mit Discord einloggen</button></a>
    <?php endif; ?>
</section>

<?php elseif ($view === 'admin' && isAdminUser($user) && $adminTab === 'employees'): ?>
<section>
    <h2>Admin: Mitarbeiter verwalten</h2>
    <p>Hier siehst du alle Nutzer, die sich via Discord angemeldet haben.</p>
    <table>
        <thead><tr><th>Discord ID</th><th>Name</th><th>Freigabe</th><th>Admin</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($adminUsers as $entry): ?>
            <tr>
                <td><?= htmlspecialchars($entry['discord_id']) ?></td>
                <td><?= htmlspecialchars($entry['display_name']) ?></td>
                <td><?= (int)$entry['is_approved'] === 1 ? 'Freigeschaltet' : 'Gesperrt' ?></td>
                <td><?= (int)$entry['is_admin'] === 1 ? 'Ja' : 'Nein' ?></td>
                <td>
                    <?php if ($entry['discord_id'] !== discordAdminId()): ?>
                        <?php if ((int)$entry['is_approved'] === 1): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action" value="admin.user.revoke">
                                <input type="hidden" name="discord_id" value="<?= htmlspecialchars($entry['discord_id']) ?>">
                                <button class="danger" type="submit">Sperren</button>
                            </form>
                        <?php else: ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action" value="admin.user.approve">
                                <input type="hidden" name="discord_id" value="<?= htmlspecialchars($entry['discord_id']) ?>">
                                <button type="submit">Freischalten</button>
                            </form>
                        <?php endif; ?>
                        <?php if ((int)$entry['is_admin'] === 1): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action" value="admin.user.remove_admin">
                                <input type="hidden" name="discord_id" value="<?= htmlspecialchars($entry['discord_id']) ?>">
                                <button class="danger" type="submit">Admin entziehen</button>
                            </form>
                        <?php else: ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action" value="admin.user.make_admin">
                                <input type="hidden" name="discord_id" value="<?= htmlspecialchars($entry['discord_id']) ?>">
                                <button type="submit">Admin freischalten</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php elseif ($view === 'ingredients' || ($view === 'admin' && isAdminUser($user) && $adminTab === 'ingredients')): ?>
<section>
    <h2>Zutaten</h2>
    <form method="post" class="grid">
        <input type="hidden" name="action" value="ingredient.create">
        <div><label>Name<input required name="name"></label></div>
        <div><label>Preis pro Stück<input required step="1" type="number" name="price_per_unit"></label></div>
        <div><label>Lagerbestand<input step="1" type="number" name="stock_qty" value="0"></label></div>
        <div><button type="submit">Anlegen</button></div>
    </form>

    <table>
        <thead><tr><th>Name</th><th>Preis pro Stück</th><th>Lagerbestand</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($ingredients as $ingredient): ?>
            <?php $formId = 'ingredient-' . (int)$ingredient['id']; ?>
            <tr>
                <td>
                    <form method="post" class="autosave-form" id="<?= $formId ?>">
                    <input type="hidden" name="action" value="ingredient.update">
                    <input type="hidden" name="id" value="<?= (int)$ingredient['id'] ?>">
                        <input form="<?= $formId ?>" name="name" value="<?= htmlspecialchars($ingredient['name']) ?>">
                        <small class="autosave-note" aria-live="polite"></small>
                    </form>
                </td>
                <td><input form="<?= $formId ?>" type="number" step="1" name="price_per_unit" value="<?= htmlspecialchars((string)$ingredient['price_per_unit']) ?>"></td>
                <td><input form="<?= $formId ?>" type="number" step="1" name="stock_qty" value="<?= htmlspecialchars((string)$ingredient['stock_qty']) ?>"></td>
                <td>
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

<?php elseif ($view === 'products' || ($view === 'admin' && isAdminUser($user) && $adminTab === 'products')): ?>
<section>
    <h2>Direktvermarktung</h2>
    <p>Direktes Gericht: Preis wird manuell gepflegt.</p>
    <form method="post" class="grid" style="grid-template-columns: 2fr 1fr 1fr 1fr;">
        <input type="hidden" name="action" value="product.create">
        <input type="hidden" name="product_type" value="direct">
        <div><label>Name<input required name="name"></label></div>
        <div><label>Preis pro Stück<input required step="1" type="number" name="direct_purchase_price" value="0"></label></div>
        <div><label>Zielbestand<input type="number" name="target_qty" value="0"></label></div>
        <div><button type="submit">Anlegen</button></div>
    </form>

    <table>
        <thead><tr><th>Name</th><th>Ziel</th><th>Preis pro Stück</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($products as $product): ?>
            <?php if ((int)$product['is_direct_purchase'] !== 1) { continue; } ?>
            <?php $formId = 'product-direct-' . (int)$product['id']; ?>
            <tr>
                <td>
                    <form method="post" class="autosave-form" id="<?= $formId ?>">
                        <input type="hidden" name="action" value="product.update">
                        <input type="hidden" name="id" value="<?= (int)$product['id'] ?>">
                        <input type="hidden" name="product_type" value="direct">
                        <input form="<?= $formId ?>" name="name" value="<?= htmlspecialchars($product['name']) ?>">
                        <small class="autosave-note" aria-live="polite"></small>
                    </form>
                </td>
                <td><input form="<?= $formId ?>" type="number" name="target_qty" value="<?= (int)$product['target_qty'] ?>"></td>
                <td><input form="<?= $formId ?>" type="number" step="1" name="direct_purchase_price" value="<?= htmlspecialchars((string)$product['direct_purchase_price']) ?>"></td>
                <td>
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
    <h2>Rezepte</h2>
    <p>Kombination mehrerer Zutaten: Preis wird automatisch aus den Zutaten berechnet.</p>
    <form method="post" class="grid" style="grid-template-columns: 2fr 1fr 1fr;">
        <input type="hidden" name="action" value="product.create">
        <input type="hidden" name="product_type" value="recipe">
        <div><label>Name<input required name="name"></label></div>
        <div><label>Zielbestand<input type="number" name="target_qty" value="0"></label></div>
        <div><button type="submit">Anlegen</button></div>
    </form>

    <table>
        <thead><tr><th>Name</th><th>Ziel</th><th>Automatischer Preis</th><th>Rezept</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($products as $product): ?>
            <?php if ((int)$product['is_direct_purchase'] !== 0) { continue; } ?>
            <?php $formId = 'product-recipe-' . (int)$product['id']; ?>
            <tr>
                <td>
                    <form method="post" class="autosave-form" id="<?= $formId ?>">
                        <input type="hidden" name="action" value="product.update">
                        <input type="hidden" name="id" value="<?= (int)$product['id'] ?>">
                        <input type="hidden" name="product_type" value="recipe">
                        <input type="hidden" name="direct_purchase_price" value="0">
                        <input form="<?= $formId ?>" name="name" value="<?= htmlspecialchars($product['name']) ?>">
                        <small class="autosave-note" aria-live="polite"></small>
                    </form>
                </td>
                <td><input form="<?= $formId ?>" type="number" name="target_qty" value="<?= (int)$product['target_qty'] ?>"></td>
                <td><?= number_format((float)$product['calculated_recipe_price'], 0, ',', '.') ?> $</td>
                <td><a href="?view=recipe&product_id=<?= (int)$product['id'] ?>">Bearbeiten (<?= (int)$product['ingredient_count'] ?>)</a></td>
                <td>
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

<?php elseif ($view === 'inventory'): ?>
<section>
    <h2>Lager</h2>
    <p>Hier siehst du Gerichte und Zutaten gemeinsam. Du kannst die Reihenfolge per Drag &amp; Drop sortieren und nur die aktuelle Lagermenge pflegen.</p>
    <table>
        <thead><tr><th>Typ</th><th>Name</th><th>Aktueller Lagerbestand</th></tr></thead>
        <tbody id="inventory-items" class="inventory-list" data-sortable-key="inventory-order-v1">
        <?php foreach ($inventoryItems as $item): ?>
            <tr draggable="true" data-item-type="<?= htmlspecialchars($item['type']) ?>" data-item-id="<?= (int)$item['id'] ?>" data-sort-value="<?= htmlspecialchars($item['type'] . ':' . (string)$item['id']) ?>">
                <td><?= htmlspecialchars($item['type_label']) ?></td>
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td>
                    <form method="post" class="autosave-form">
                        <input type="hidden" name="action" value="<?= $item['type'] === 'product' ? 'product.stock.update' : 'ingredient.stock.update' ?>">
                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                        <input type="number" step="1" name="stock_qty" value="<?= $item['type'] === 'ingredient' ? htmlspecialchars((string)$item['stock_qty']) : (int)$item['stock_qty'] ?>">
                        <small class="autosave-note" aria-live="polite"></small>
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
        <div><label>Stück pro Gericht<input required type="number" step="1" name="qty_per_product"></label></div>
        <div><button>Speichern</button></div>
    </form>

    <table>
        <thead><tr><th>Zutat</th><th>Stück</th><th>Kosten/Gericht</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($recipeItems as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['ingredient_name']) ?></td>
                <td><?= number_format((float)$item['qty_per_product'], 0, ',', '.') ?></td>
                <td><?= number_format((float)$item['qty_per_product'] * (float)$item['price_per_unit'], 0, ',', '.') ?> $</td>
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

<?php elseif (($view === 'options' && isAdminUser($user)) || ($view === 'admin' && isAdminUser($user) && $adminTab === 'options')): ?>
<section>
    <h2>Optionen</h2>
    <form method="post" class="grid" style="grid-template-columns: 3fr 1fr;">
        <input type="hidden" name="action" value="options.company.update">
        <div><label>Unternehmensname<input name="company_name" value="<?= htmlspecialchars($companyName) ?>" placeholder="z. B. Muster GmbH"></label></div>
        <div><button type="submit">Speichern</button></div>
    </form>

    <form method="post" class="grid" style="grid-template-columns: 3fr 1fr; margin-top: 12px;">
        <input type="hidden" name="action" value="options.webhook.update">
        <div><label>Discord Webhook URL<input name="discord_webhook_url" value="<?= htmlspecialchars($discordWebhookUrl) ?>" placeholder="https://discord.com/api/webhooks/..." autocomplete="off"></label></div>
        <div><button type="submit">Webhook speichern</button></div>
    </form>
</section>
<?php elseif ($view === 'shopping'): ?>
<section>
    <h2>Einkaufsliste (automatisch)</h2>
    <p>Alle benötigten Einkäufe in einer Liste. Die Reihenfolge kannst du per Drag &amp; Drop anpassen.</p>
    <table>
        <thead><tr><th>Artikel</th><th>Menge</th></tr></thead>
        <tbody class="shopping-list" data-sortable-key="shopping-order-v1">
        <?php foreach ($shoppingList['items'] as $row): ?>
            <tr draggable="true" data-sort-value="<?= htmlspecialchars($row['type'] . ':' . $row['name']) ?>">
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><?= number_format((float)$row['qty'], 0, ',', '.') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (count($shoppingList['items']) === 0): ?>
            <tr><td colspan="2">Kein Einkaufsbedarf.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <p><strong>Gesamtkosten Einkauf: <?= number_format((float)$shoppingList['total'], 0, ',', '.') ?> $</strong></p>
    <form method="post" style="margin-top: 12px;">
        <input type="hidden" name="action" value="shopping.complete">
        <button type="submit">Einkaufsliste abschließen</button>
    </form>
</section>
<?php else: ?>
<section>
    <h2>Dashboard</h2>
    <?php if (isAdminUser($user)): ?>
        <p>Lege zuerst Zutaten und Gerichte an. Hinterlege Ziel- und Ist-Bestand.</p>
        <ul>
            <li>Anzahl Zutaten: <?= count($ingredients) ?></li>
            <li>Anzahl Gerichte: <?= count($products) ?></li>
        </ul>
    <?php else: ?>
        <p>Willkommen! Nutze Lager und Einkaufsliste für den Tagesbetrieb.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
    </div>

<?php if ($loggedIn && $view !== 'shopping' && $view !== 'login'): ?>
    <div>
        <section>
            <h2>Live Überblick</h2>
            <ul>
                <?php if (isAdminUser($user)): ?>
                    <li>Anzahl Zutaten: <?= count($ingredients) ?></li>
                    <li>Anzahl Gerichte: <?= count($products) ?></li>
                <?php endif; ?>
                <li>Gesamter Bedarf: <?= number_format((float)$shoppingList['total'], 0, ',', '.') ?> $</li>
            </ul>
        </section>
    </div>
<?php endif; ?>
</div>
</div>
<script>
    (function () {
        const autoSaveForms = document.querySelectorAll('form.autosave-form');

        autoSaveForms.forEach((form) => {
            let timeoutId = null;
            const fields = Array.from(form.elements).filter((field) => {
                if (!(field instanceof HTMLElement)) {
                    return false;
                }

                if (field.tagName === 'BUTTON') {
                    return false;
                }

                return !(field instanceof HTMLInputElement && field.type === 'hidden');
            });
            const note = form.querySelector('.autosave-note');

            const showNote = (text, isError = false) => {
                if (!note) {
                    return;
                }
                note.textContent = text;
                note.style.color = isError ? 'var(--danger)' : 'var(--accent)';
            };

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const formData = new FormData(form);

                showNote('Speichert …');

                try {
                    const response = await window.fetch(window.location.href, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                    });

                    if (!response.ok) {
                        throw new Error('Speichern fehlgeschlagen');
                    }

                    showNote('Gespeichert');
                } catch (_error) {
                    showNote('Fehler beim Speichern', true);
                }
            });

            fields.forEach((field) => {
                field.addEventListener('input', () => {
                    window.clearTimeout(timeoutId);
                    timeoutId = window.setTimeout(() => {
                        form.requestSubmit();
                    }, 500);
                });

                field.addEventListener('change', () => {
                    window.clearTimeout(timeoutId);
                    form.requestSubmit();
                });
            });
        });

        const sortableContainers = document.querySelectorAll('[data-sortable-key]');
        let draggedRow = null;

        const saveOrder = (container) => {
            const storageKey = container.dataset.sortableKey;
            if (!storageKey) {
                return;
            }

            const values = Array.from(container.querySelectorAll('tr[draggable="true"]'))
                .map((row) => row.dataset.sortValue || row.textContent?.trim() || '')
                .filter((value) => value !== '');

            if (values.length > 0) {
                window.localStorage.setItem(storageKey, JSON.stringify(values));
            }
        };

        const restoreOrder = (container) => {
            const storageKey = container.dataset.sortableKey;
            if (!storageKey) {
                return;
            }

            const raw = window.localStorage.getItem(storageKey);
            if (!raw) {
                return;
            }

            let savedValues = [];
            try {
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) {
                    savedValues = parsed;
                }
            } catch (_error) {
                window.localStorage.removeItem(storageKey);
                return;
            }

            if (savedValues.length === 0) {
                return;
            }

            const rowsByValue = new Map();
            Array.from(container.querySelectorAll('tr[draggable="true"]')).forEach((row) => {
                const value = row.dataset.sortValue || row.textContent?.trim() || '';
                if (value !== '') {
                    rowsByValue.set(value, row);
                }
            });

            savedValues.forEach((value) => {
                const row = rowsByValue.get(value);
                if (!row) {
                    return;
                }
                container.appendChild(row);
                rowsByValue.delete(value);
            });

            rowsByValue.forEach((row) => {
                container.appendChild(row);
            });
        };

        sortableContainers.forEach((container) => {
            restoreOrder(container);

            container.addEventListener('dragstart', (event) => {
                const row = event.target.closest('tr[draggable="true"]');
                if (!row) {
                    return;
                }
                draggedRow = row;
                row.style.opacity = '0.5';
            });

            container.addEventListener('dragend', () => {
                if (draggedRow) {
                    draggedRow.style.opacity = '1';
                }
                saveOrder(container);
                draggedRow = null;
            });

            container.addEventListener('dragover', (event) => {
                event.preventDefault();
                const targetRow = event.target.closest('tr[draggable="true"]');
                if (!draggedRow || !targetRow || targetRow === draggedRow) {
                    return;
                }

                const rows = Array.from(container.querySelectorAll('tr[draggable="true"]'));
                const draggedIndex = rows.indexOf(draggedRow);
                const targetIndex = rows.indexOf(targetRow);

                if (draggedIndex < targetIndex) {
                    targetRow.after(draggedRow);
                } else {
                    targetRow.before(draggedRow);
                }
            });
        });
    })();
</script>
</body>
</html>
