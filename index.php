<?php
require_once __DIR__ . '/src/repository.php';

initSchema();

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionLifetime = 30 * 24 * 3600; // 30 Tage
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
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

function parseCashAmount(string $value): ?float
{
    $normalized = trim(str_replace(',', '.', $value));
    if ($normalized === '' || !is_numeric($normalized)) {
        return null;
    }

    return (float)$normalized;
}

function sendShoppingListWebhook(array $shoppingList, string $companyName, ?array $user, ?float $cashStart = null, ?float $cashEnd = null): void
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

    $completedBy = 'Unbekannt';
    $userDiscordId = trim((string)($user['discord_id'] ?? ''));
    if ($userDiscordId !== '') {
        $dbUser = findUserByDiscordId($userDiscordId);
        if (is_array($dbUser)) {
            $completedBy = trim((string)($dbUser['display_name'] ?? ''));
        }
    }

    if ($completedBy === '') {
        $completedBy = trim((string)($user['display_name'] ?? 'Unbekannt'));
    }
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

    if ($cashStart !== null || $cashEnd !== null) {
        $payload['embeds'][0]['fields'][] = [
            'name' => 'Kassen-Anfangsbestand',
            'value' => $cashStart !== null ? number_format($cashStart, 2, ',', '.') . ' $' : 'Nicht angegeben',
            'inline' => true,
        ];
        $payload['embeds'][0]['fields'][] = [
            'name' => 'Kassen-Endbestand',
            'value' => $cashEnd !== null ? number_format($cashEnd, 2, ',', '.') . ' $' : 'Nicht angegeben',
            'inline' => true,
        ];
    }

    postJson($webhookUrl, $payload);
}

function sendSpecialPurchaseWebhook(array $purchase, string $companyName, ?array $user, ?float $cashStart = null, ?float $cashEnd = null): void
{
    $webhookUrl = discordWebhookUrl();
    if ($webhookUrl === '') {
        throw new RuntimeException('Bitte zuerst eine Discord Webhook-URL unter Optionen hinterlegen.');
    }

    $lines = [];
    foreach ($purchase['items'] as $row) {
        $qty = number_format((float)$row['qty'], 0, ',', '.');
        $lines[] = sprintf('- %s (%s): %s', (string)$row['name'], (string)$row['type'], $qty);
    }

    if ($lines === []) {
        $lines[] = 'Kein Sondereinkauf ausgewählt.';
    }

    $completedBy = 'Unbekannt';
    $userDiscordId = trim((string)($user['discord_id'] ?? ''));
    if ($userDiscordId !== '') {
        $dbUser = findUserByDiscordId($userDiscordId);
        if (is_array($dbUser)) {
            $completedBy = trim((string)($dbUser['display_name'] ?? ''));
        }
    }

    if ($completedBy === '') {
        $completedBy = trim((string)($user['display_name'] ?? 'Unbekannt'));
    }
    $titlePrefix = trim($companyName) !== '' ? $companyName . ' · ' : '';

    $payload = [
        'embeds' => [[
            'title' => $titlePrefix . 'Sondereinkauf abgeschlossen',
            'description' => implode("\n", $lines),
            'color' => 5793266,
            'fields' => [
                [
                    'name' => 'Gesamtkosten',
                    'value' => number_format((float)$purchase['total'], 0, ',', '.') . ' $',
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

    if ($cashStart !== null || $cashEnd !== null) {
        $payload['embeds'][0]['fields'][] = [
            'name' => 'Kassen-Anfangsbestand',
            'value' => $cashStart !== null ? number_format($cashStart, 2, ',', '.') . ' $' : 'Nicht angegeben',
            'inline' => true,
        ];
        $payload['embeds'][0]['fields'][] = [
            'name' => 'Kassen-Endbestand',
            'value' => $cashEnd !== null ? number_format($cashEnd, 2, ',', '.') . ' $' : 'Nicht angegeben',
            'inline' => true,
        ];
    }

    postJson($webhookUrl, $payload);
}


function sendInventoryWebhook(array $inventoryItems, string $companyName, ?array $user): void
{
    $webhookUrl = inventoryDiscordWebhookUrl();
    if ($webhookUrl === '') {
        throw new RuntimeException('Bitte zuerst eine Discord Webhook-URL für Lagerbestand unter Optionen hinterlegen.');
    }

    usort($inventoryItems, static function (array $a, array $b): int {
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    $lines = [];
    foreach ($inventoryItems as $item) {
        $typeLabel = ((string)($item['type'] ?? '')) === 'product' ? 'Gericht' : 'Zutat';
        $qtyValue = (float)($item['stock_qty'] ?? 0);
        $qty = number_format($qtyValue, 0, ',', '.');
        $lines[] = sprintf('- [%s] %s: %s', $typeLabel, (string)($item['name'] ?? ''), $qty);
    }

    if ($lines === []) {
        $lines[] = 'Kein Lagerbestand vorhanden.';
    }

    $sentBy = trim((string)($user['display_name'] ?? ''));
    $userDiscordId = trim((string)($user['discord_id'] ?? ''));
    if ($userDiscordId !== '') {
        $dbUser = findUserByDiscordId($userDiscordId);
        if (is_array($dbUser) && trim((string)($dbUser['display_name'] ?? '')) !== '') {
            $sentBy = trim((string)$dbUser['display_name']);
        }
    }
    if ($sentBy === '') {
        $sentBy = 'Unbekannt';
    }

    $titlePrefix = trim($companyName) !== '' ? $companyName . ' · ' : '';

    $payload = [
        'embeds' => [[
            'title' => $titlePrefix . 'Aktueller Lagerbestand',
            'description' => implode("\n", $lines),
            'color' => 3447003,
            'fields' => [[
                'name' => 'Gesendet von',
                'value' => $sentBy,
                'inline' => true,
            ]],
            'timestamp' => gmdate('c'),
        ]],
    ];

    postJson($webhookUrl, $payload);
}

function renderAdminNav(string $activeView): string
{
    $links = [
        'employees'   => 'Mitarbeiter',
        'ingredients' => 'Zutaten',
        'products'    => 'Rezepte',
        'options'     => 'Optionen',
        'auditlog'    => 'Audit-Log',
    ];
    $html = '<nav class="pill-nav" style="margin-top:0; margin-bottom:12px;">';
    foreach ($links as $viewKey => $label) {
        $active = $viewKey === $activeView ? ' class="active"' : '';
        $html .= sprintf('<a%s href="?view=%s">%s</a>', $active, htmlspecialchars($viewKey), htmlspecialchars($label));
    }
    $html .= '</nav>';
    return $html;
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
        $targetDiscordId = (string)($_POST['discord_id'] ?? '');
        setUserApproval($targetDiscordId, true);
        writeAuditLog(currentUser(), 'admin.user.approve', $targetDiscordId, 'Mitarbeiter freigeschaltet');
        flash('Mitarbeiter freigeschaltet.');
        header('Location: ?view=employees');
        exit;
    }

    if ($action === 'admin.user.revoke') {
        requireAdmin();
        $targetDiscordId = (string)($_POST['discord_id'] ?? '');
        setUserApproval($targetDiscordId, false);
        writeAuditLog(currentUser(), 'admin.user.revoke', $targetDiscordId, 'Freigabe entfernt');
        flash('Freigabe entfernt.');
        header('Location: ?view=employees');
        exit;
    }

    if ($action === 'admin.user.make_admin') {
        requireAdmin();
        $targetDiscordId = (string)($_POST['discord_id'] ?? '');
        setUserAdmin($targetDiscordId, true);
        writeAuditLog(currentUser(), 'admin.user.make_admin', $targetDiscordId, 'Admin-Berechtigung erteilt');
        flash('Admin-Berechtigung erteilt.');
        header('Location: ?view=employees');
        exit;
    }

    if ($action === 'admin.user.remove_admin') {
        requireAdmin();
        $targetDiscordId = (string)($_POST['discord_id'] ?? '');
        setUserAdmin($targetDiscordId, false);
        writeAuditLog(currentUser(), 'admin.user.remove_admin', $targetDiscordId, 'Admin-Berechtigung entfernt');
        flash('Admin-Berechtigung entfernt.');
        header('Location: ?view=employees');
        exit;
    }

    if ($action === 'admin.user.delete') {
        requireAdmin();
        $targetDiscordId = (string)($_POST['discord_id'] ?? '');
        deleteUserAccess($targetDiscordId);
        writeAuditLog(currentUser(), 'admin.user.delete', $targetDiscordId, 'Mitarbeiter gelöscht');
        flash('Mitarbeiter gelöscht.');
        header('Location: ?view=employees');
        exit;
    }

    if ($action === 'admin.user.rename') {
        requireAdmin();
        $targetDiscordId = (string)($_POST['discord_id'] ?? '');
        $newDisplayName = (string)($_POST['display_name'] ?? '');
        setUserDisplayName($targetDiscordId, $newDisplayName);
        writeAuditLog(currentUser(), 'admin.user.rename', $targetDiscordId, 'Neuer Name: ' . trim($newDisplayName));
        flash('Mitarbeitername aktualisiert.');
        header('Location: ?view=employees');
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
            $ingredientName = trim((string)($_POST['name'] ?? ''));
            $ingredientPrice = (float)($_POST['price_per_unit'] ?? 0);
            writeAuditLog(currentUser(), 'ingredient.create', $ingredientName, 'Preis pro Einheit: ' . number_format($ingredientPrice, 2, ',', '.'));
            flash('Zutat angelegt.');
            header('Location: ?view=ingredients');
            exit;
        }
        if ($action === 'ingredient.update') {
            $ingredientId = (int)$_POST['id'];
            $before = ingredientById($ingredientId);
            updateIngredient($ingredientId, $_POST);
            $after = ingredientById($ingredientId);
            if (is_array($after)) {
                $beforePrice = is_array($before) ? (float)($before['price_per_unit'] ?? 0) : null;
                $afterPrice = (float)($after['price_per_unit'] ?? 0);
                $details = [];
                if ($beforePrice === null || abs($beforePrice - $afterPrice) > 0.00001) {
                    $details[] = 'Preis: ' . ($beforePrice === null ? 'neu' : number_format($beforePrice, 2, ',', '.')) . ' → ' . number_format($afterPrice, 2, ',', '.');
                }
                if (is_array($before) && trim((string)($before['name'] ?? '')) !== trim((string)($after['name'] ?? ''))) {
                    $details[] = 'Name geändert';
                }
                writeAuditLog(currentUser(), 'ingredient.update', (string)($after['name'] ?? ''), $details !== [] ? implode(' | ', $details) : 'Zutat aktualisiert');
            }
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
            $ingredientId = (int)$_POST['id'];
            $ingredient = ingredientById($ingredientId);
            deleteIngredient($ingredientId);
            writeAuditLog(currentUser(), 'ingredient.delete', (string)($ingredient['name'] ?? ''), 'Zutat gelöscht');
            flash('Zutat gelöscht.');
            header('Location: ?view=ingredients');
            exit;
        }

        if ($action === 'product.create') {
            createProduct($_POST);
            $productName = trim((string)($_POST['name'] ?? ''));
            writeAuditLog(currentUser(), 'product.create', $productName, 'Gericht angelegt');
            flash('Gericht angelegt.');
            header('Location: ?view=products');
            exit;
        }
        if ($action === 'product.update') {
            $productId = (int)$_POST['id'];
            updateProduct($productId, $_POST);
            $product = productById($productId);
            writeAuditLog(currentUser(), 'product.update', (string)($product['name'] ?? ''), 'Gericht aktualisiert');
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
            $productId = (int)$_POST['id'];
            $product = productById($productId);
            deleteProduct($productId);
            writeAuditLog(currentUser(), 'product.delete', (string)($product['name'] ?? ''), 'Gericht gelöscht');
            flash('Gericht gelöscht.');
            header('Location: ?view=products');
            exit;
        }

        if ($action === 'recipe.upsert') {
            $productId = (int)$_POST['product_id'];
            $ingredientId = (int)$_POST['ingredient_id'];
            $before = recipeItemByProductAndIngredient($productId, $ingredientId);
            upsertRecipeItem($productId, $ingredientId, (float)$_POST['qty_per_product']);
            $after = recipeItemByProductAndIngredient($productId, $ingredientId);
            $actionKey = is_array($before) ? 'recipe.update' : 'recipe.create';
            if (is_array($after)) {
                $beforeQty = is_array($before) ? (float)($before['qty_per_product'] ?? 0) : null;
                $afterQty = (float)($after['qty_per_product'] ?? 0);
                $details = 'Gericht: ' . (string)($after['product_name'] ?? '') . ' | Menge: ' . ($beforeQty === null ? 'neu' : number_format($beforeQty, 3, ',', '.')) . ' → ' . number_format($afterQty, 3, ',', '.');
                writeAuditLog(currentUser(), $actionKey, (string)($after['ingredient_name'] ?? ''), $details);
            }
            flash('Rezeptposition gespeichert.');
            header('Location: ?view=recipe&product_id=' . (int)$_POST['product_id']);
            exit;
        }
        if ($action === 'recipe.assign') {
            $productId = (int)$_POST['product_id'];
            $product = productById($productId);
            assignRecipeItems($productId, $_POST['ingredient_qty'] ?? []);
            writeAuditLog(currentUser(), 'recipe.assign', (string)($product['name'] ?? ''), 'Zutaten für Gericht zugewiesen/aktualisiert');
            flash('Zutaten für das Gericht zugewiesen.');
            header('Location: ?view=products');
            exit;
        }
        if ($action === 'recipe.delete') {
            $productId = (int)$_POST['product_id'];
            $recipeItem = recipeItemById((int)$_POST['id']);
            deleteRecipeItem((int)$_POST['id']);
            if (is_array($recipeItem)) {
                $details = 'Gericht: ' . (string)($recipeItem['product_name'] ?? '') . ' | Menge: ' . number_format((float)($recipeItem['qty_per_product'] ?? 0), 3, ',', '.');
                writeAuditLog(currentUser(), 'recipe.delete', (string)($recipeItem['ingredient_name'] ?? ''), $details);
            }
            flash('Rezeptposition gelöscht.');
            header('Location: ?view=recipe&product_id=' . $productId);
            exit;
        }
        if ($action === 'options.company.update') {
            requireAdmin();
            $newCompanyName = (string)($_POST['company_name'] ?? '');
            updateCompanyName($newCompanyName);
            writeAuditLog(currentUser(), 'options.company.update', '', 'Unternehmensname gesetzt auf: ' . trim($newCompanyName));
            flash('Unternehmensname gespeichert.');
            header('Location: ?view=options');
            exit;
        }
        if ($action === 'options.webhook.update') {
            requireAdmin();
            $newWebhookUrl = (string)($_POST['discord_webhook_url'] ?? '');
            updateDiscordWebhookUrl($newWebhookUrl);
            $urlInfo = trim($newWebhookUrl) === '' ? 'leer' : 'gesetzt';
            writeAuditLog(currentUser(), 'options.webhook.update', '', 'Discord Webhook ' . $urlInfo);
            flash('Discord Webhook gespeichert.');
            header('Location: ?view=options');
            exit;
        }
        if ($action === 'options.inventory_webhook.update') {
            requireAdmin();
            $newWebhookUrl = (string)($_POST['inventory_discord_webhook_url'] ?? '');
            updateInventoryDiscordWebhookUrl($newWebhookUrl);
            $urlInfo = trim($newWebhookUrl) === '' ? 'leer' : 'gesetzt';
            writeAuditLog(currentUser(), 'options.inventory_webhook.update', '', 'Lagerbestand Discord Webhook ' . $urlInfo);
            flash('Discord Webhook für Lagerbestand gespeichert.');
            header('Location: ?view=options');
            exit;
        }
        if ($action === 'options.shopping_cash_check.toggle') {
            requireAdmin();
            $enabled = (string)($_POST['shopping_cash_check_enabled'] ?? '0') === '1';
            updateShoppingCashCheckEnabled($enabled);
            writeAuditLog(currentUser(), 'options.shopping_cash_check.toggle', '', $enabled ? 'Kassenprüfung aktiviert' : 'Kassenprüfung deaktiviert');
            flash($enabled ? 'Kassenprüfung aktiviert.' : 'Kassenprüfung deaktiviert.');
            header('Location: ?view=options');
            exit;
        }
        if ($action === 'shopping.complete') {
            $list = shoppingList();
            $cashStart = parseCashAmount((string)($_POST['cash_start'] ?? ''));
            $cashEnd = parseCashAmount((string)($_POST['cash_end'] ?? ''));
            sendShoppingListWebhook($list, companyName(), currentUser(), $cashStart, $cashEnd);
            $completedByDiscordId = trim((string)(currentUser()['discord_id'] ?? ''));
            completeShoppingListAndUpdateInventory($completedByDiscordId);
            flash('Einkaufsliste abgeschlossen, an Discord gesendet und Lagerbestände aktualisiert.');
            header('Location: ?view=shopping');
            exit;
        }
        if ($action === 'special-purchase.complete') {
            $cashStart = parseCashAmount((string)($_POST['cash_start'] ?? ''));
            $cashEnd = parseCashAmount((string)($_POST['cash_end'] ?? ''));
            $completedByDiscordId = trim((string)(currentUser()['discord_id'] ?? ''));
            $purchase = completeSpecialPurchase($_POST['special_qty'] ?? [], $completedByDiscordId);
            sendSpecialPurchaseWebhook($purchase, companyName(), currentUser(), $cashStart, $cashEnd);
            flash('Sondereinkauf abgeschlossen, an Discord gesendet und Statistik aktualisiert.');
            header('Location: ?view=special-purchase');
            exit;
        }
        if ($action === 'inventory.webhook.send') {
            requireAdmin();
            $inventoryRows = [];
            foreach (allProductsByStockOrder() as $product) {
                $inventoryRows[] = [
                    'type' => 'product',
                    'name' => (string)$product['name'],
                    'stock_qty' => (float)$product['stock_qty'],
                ];
            }
            foreach (allIngredientsByStockOrder() as $ingredient) {
                $inventoryRows[] = [
                    'type' => 'ingredient',
                    'name' => (string)$ingredient['name'],
                    'stock_qty' => (float)$ingredient['stock_qty'],
                ];
            }
            sendInventoryWebhook($inventoryRows, companyName(), currentUser());
            writeAuditLog(currentUser(), 'inventory.webhook.send', '', 'Aktueller Lagerbestand an Discord gesendet');
            flash('Aktueller Lagerbestand an Discord gesendet.');
            header('Location: ?view=inventory');
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

        $avatarHash = trim((string)($discordUser['avatar'] ?? ''));
        if ($avatarHash !== '') {
            $avatarExt = str_starts_with($avatarHash, 'a_') ? 'gif' : 'webp';
            $avatarUrl = "https://cdn.discordapp.com/avatars/{$discordId}/{$avatarHash}.{$avatarExt}?size=64";
        } else {
            $defaultIndex = (int)((int)$discordId >> 22) % 6;
            $avatarUrl = "https://cdn.discordapp.com/embed/avatars/{$defaultIndex}.png";
        }

        $user = createOrUpdateUserAccess($discordId, $displayName, $avatarUrl);
        if ((int)$user['is_approved'] !== 1 && !isAdminUser($user)) {
            unset($_SESSION['auth_user']);
            flash('Du bist noch nicht freigeschaltet. Bitte Admin kontaktieren.');
            header('Location: ?view=login');
            exit;
        }

        $_SESSION['auth_user'] = [
            'discord_id'   => $user['discord_id'],
            'display_name' => $user['display_name'],
            'avatar_url'   => $user['avatar_url'] ?? $avatarUrl,
            'is_admin'     => (int)$user['is_admin'],
            'is_approved'  => (int)$user['is_approved'],
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

$adminOnlyViews = ['ingredients', 'products', 'recipe', 'options', 'employees', 'auditlog'];
if ($loggedIn && !isAdminUser($user) && in_array($view, $adminOnlyViews, true)) {
    flash('Zutaten und Gerichte sind nur für Admins sichtbar.');
    $view = 'dashboard';
}

$isAdmin = $loggedIn && isAdminUser($user);

$needsIngredients    = $loggedIn && in_array($view, ['ingredients', 'products', 'recipe', 'dashboard'], true);
$needsProducts       = $loggedIn && in_array($view, ['products', 'recipe', 'dashboard'], true);
$needsInventory      = $loggedIn && $view === 'inventory';
$needsShoppingList   = $loggedIn && in_array($view, ['shopping', 'dashboard'], true);
$needsSpecialCatalog = $loggedIn && $view === 'special-purchase';
$needsStats          = $loggedIn && $view === 'stats';
$needsAdminUsers     = $isAdmin && $view === 'employees';
$needsAuditLog       = $isAdmin && $view === 'auditlog';
$needsRecipe         = $isAdmin && $view === 'recipe';
$needsOptions        = $isAdmin && $view === 'options';

$ingredients = $needsIngredients ? allIngredients() : [];
$products    = $needsProducts    ? allProducts()     : [];

$inventoryItems = [];
if ($needsInventory) {
    $inventoryProducts   = allProductsByStockOrder();
    $inventoryIngredients = allIngredientsByStockOrder();
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
}

$shoppingList          = $needsShoppingList   ? shoppingList()          : ['items' => [], 'total' => 0];
$specialPurchaseCatalog = $needsSpecialCatalog ? specialPurchaseCatalog() : [];
$shoppingStats         = $needsStats          ? shoppingStats()         : [];
$companyName           = companyName();
$shoppingCashCheckEnabled = $loggedIn ? isShoppingCashCheckEnabled() : false;
$discordWebhookUrl          = $needsOptions ? discordWebhookUrl()          : '';
$inventoryDiscordWebhookUrl = $needsOptions ? inventoryDiscordWebhookUrl() : '';
$productForRecipe = $needsRecipe && isset($_GET['product_id']) ? productById((int)$_GET['product_id']) : null;
$recipeItems      = $productForRecipe ? recipeItemsByProduct((int)$productForRecipe['id']) : [];
$adminUsers       = $needsAdminUsers ? allUserAccessEntries() : [];
$auditLogEntries  = $needsAuditLog   ? auditLogEntries(250)  : [];
$discordLoginUrl = discordAuthUrl();
$message = flash();
$isAdminWorkspaceView = $loggedIn && isAdminUser($user) && in_array($view, ['employees', 'recipe', 'options', 'auditlog'], true);
$singleColumnViews = ['login', 'employees', 'recipe', 'options', 'auditlog'];


?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Business Einkaufsliste</title>
    <style>
        :root {
            --bg: #05080f;
            --surface: rgba(255, 255, 255, 0.032);
            --surface-hover: rgba(255, 255, 255, 0.055);
            --border: rgba(255, 255, 255, 0.075);
            --border-accent: rgba(91, 138, 245, 0.38);
            --text: #e5ecff;
            --text-muted: #5d718f;
            --text-dim: #344260;
            --accent: #5b8af5;
            --accent-2: #1ed9d2;
            --accent-glow: rgba(91, 138, 245, 0.22);
            --teal-glow: rgba(30, 217, 210, 0.18);
            --danger: #ff5252;
            --danger-dim: rgba(255, 82, 82, 0.1);
            --r-sm: 8px;
            --r-md: 12px;
            --r-lg: 16px;
            --r-xl: 22px;
            --r-pill: 9999px;
            --ease: 0.17s ease;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { min-height: 100%; }

        body {
            padding: 20px;
            min-height: 100vh;
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            font-size: 14px;
            line-height: 1.55;
            color: var(--text);
            background:
                radial-gradient(ellipse 90% 55% at 12% -8%,  rgba(91, 138, 245, 0.14) 0%, transparent 55%),
                radial-gradient(ellipse 65% 45% at 88%  5%,  rgba(30, 217, 210, 0.09) 0%, transparent 52%),
                radial-gradient(ellipse 110% 70% at 50% 108%, rgba(91, 138, 245, 0.06) 0%, transparent 60%),
                var(--bg);
            background-attachment: fixed;
        }

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.09); border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.17); }

        /* ── Typography ── */
        h1 {
            font-size: 1.3rem;
            font-weight: 700;
            letter-spacing: -0.025em;
            background: linear-gradient(128deg, var(--text) 35%, var(--accent-2) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        h2 {
            font-size: 1.02rem;
            font-weight: 600;
            color: var(--text);
            letter-spacing: -0.01em;
            margin-bottom: 14px;
        }
        h3 { font-size: 0.93rem; font-weight: 600; color: var(--text); margin-bottom: 10px; }
        p  { color: var(--text-muted); font-size: 0.86rem; margin-bottom: 10px; }
        li { color: var(--text-muted); font-size: 0.87rem; padding: 3px 0; }
        li strong { color: var(--text); }
        ul { padding-left: 18px; }
        small { font-size: 0.79rem; color: var(--text-muted); }
        code {
            background: rgba(255,255,255,0.07);
            padding: 2px 7px;
            border-radius: 5px;
            font-size: 0.84em;
            color: var(--accent-2);
            font-family: 'JetBrains Mono', 'Consolas', monospace;
        }
        a { color: var(--accent); text-decoration: none; transition: color var(--ease); }
        a:hover { color: var(--accent-2); }

        label {
            display: block;
            font-size: 0.81rem;
            font-weight: 500;
            color: var(--text-muted);
            letter-spacing: 0.01em;
        }
        label input, label select { margin-top: 6px; }

        /* ── App shell ── */
        .app-shell { max-width: 1400px; margin: 0 auto; }
        .app-shell.app-shell-wide { max-width: 100%; }

        /* ── Top bar ── */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 14px 22px;
            border-radius: var(--r-xl);
            background: var(--surface);
            border: 1px solid var(--border);
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,0.05),
                0 12px 36px rgba(0,0,0,0.45);
            backdrop-filter: blur(28px);
            -webkit-backdrop-filter: blur(28px);
        }

        /* ── Navigation ── */
        .pill-nav {
            margin-top: 14px;
            display: inline-flex;
            flex-wrap: wrap;
            gap: 3px;
            padding: 5px;
            border-radius: var(--r-pill);
            background: rgba(0,0,0,0.48);
            border: 1px solid var(--border);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }
        nav a {
            text-decoration: none;
            color: var(--text-muted);
            padding: 7px 15px;
            border-radius: var(--r-pill);
            font-size: 0.83rem;
            font-weight: 500;
            transition: all var(--ease);
            white-space: nowrap;
        }
        nav a:hover  { color: var(--text); background: rgba(255,255,255,0.07); }
        nav a.active {
            color: #041018;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            box-shadow: 0 0 20px var(--accent-glow);
            font-weight: 600;
        }

        /* ── Content grid ── */
        .content-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 16px;
            margin-top: 16px;
        }
        .content-grid.shopping-view { grid-template-columns: 1fr; }

        /* ── Panels ── */
        section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--r-lg);
            padding: 20px 22px;
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,0.04),
                0 8px 32px rgba(0,0,0,0.38);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            margin-bottom: 14px;
        }

        /* ── Tables ── */
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        thead tr { border-bottom: 1px solid var(--border); }
        th {
            padding: 8px 11px;
            text-align: left;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-dim);
        }
        td {
            padding: 11px;
            border-bottom: 1px solid rgba(255,255,255,0.038);
            color: var(--text);
            font-size: 0.87rem;
            vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr { transition: background var(--ease); }
        tbody tr:hover td { background: var(--surface-hover); }

        /* ── Inputs ── */
        input:not([type="checkbox"]):not([type="hidden"]),
        select {
            padding: 9px 13px;
            width: 100%;
            background: rgba(0,0,0,0.38);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: var(--r-sm);
            font-size: 0.86rem;
            font-family: inherit;
            transition: border-color var(--ease), box-shadow var(--ease), background var(--ease);
            outline: none;
            -webkit-appearance: none;
        }
        input:not([type="checkbox"]):not([type="hidden"]):hover,
        select:hover {
            border-color: rgba(255,255,255,0.13);
            background: rgba(0,0,0,0.44);
        }
        input:not([type="checkbox"]):not([type="hidden"]):focus,
        select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
            background: rgba(0,0,0,0.44);
        }
        input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--accent);
            cursor: pointer;
            flex-shrink: 0;
        }
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button { opacity: 0.28; }
        select option { background: #0d1525; color: var(--text); }

        /* ── Form grid ── */
        .grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; align-items: end; }

        /* ── Buttons ── */
        button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 18px;
            border: 1px solid var(--border-accent);
            border-radius: var(--r-pill);
            background: linear-gradient(135deg, rgba(91,138,245,0.13), rgba(30,217,210,0.07));
            color: var(--text);
            cursor: pointer;
            font-size: 0.83rem;
            font-weight: 500;
            font-family: inherit;
            transition: all var(--ease);
            white-space: nowrap;
            line-height: 1;
        }
        button:hover {
            border-color: var(--accent);
            background: linear-gradient(135deg, rgba(91,138,245,0.28), rgba(30,217,210,0.14));
            box-shadow: 0 0 22px var(--accent-glow);
            transform: translateY(-1px);
            color: #fff;
        }
        button:active { transform: translateY(0); }

        .danger {
            border-color: rgba(255,82,82,0.32);
            background: var(--danger-dim);
            color: #ffaaaa;
        }
        .danger:hover {
            border-color: var(--danger);
            background: rgba(255,82,82,0.2);
            box-shadow: 0 0 22px rgba(255,82,82,0.2);
            color: #ffd5d5;
        }

        /* ── Flash ── */
        .flash {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 13px 18px;
            border-radius: var(--r-md);
            border: 1px solid var(--border-accent);
            background: rgba(91,138,245,0.07);
            margin-top: 14px;
            font-size: 0.87rem;
            color: var(--text);
        }
        .flash::before {
            content: '';
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 9px var(--accent);
            flex-shrink: 0;
        }

        /* ── Autosave ── */
        .autosave-form {
            display: flex;
            flex-direction: column;
            gap: 7px;
            align-items: stretch;
        }
        .autosave-note {
            min-height: 1em;
            font-size: 0.75rem;
            color: var(--accent-2);
            letter-spacing: 0.025em;
            padding-left: 2px;
        }

        /* ── User badge ── */
        .user-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 5px 5px 5px 5px;
            border-radius: var(--r-pill);
            background: rgba(0,0,0,0.3);
            border: 1px solid var(--border);
        }
        .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: 2px solid var(--border-accent);
            object-fit: cover;
            flex-shrink: 0;
        }
        .user-avatar--fallback {
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            color: #041018;
            font-weight: 700;
            font-size: 0.9rem;
        }
        .user-badge__info {
            display: flex;
            flex-direction: column;
            gap: 1px;
            margin-right: 2px;
        }
        .user-badge__name {
            font-size: 0.84rem;
            font-weight: 500;
            color: var(--text);
            line-height: 1.2;
        }
        .user-badge__role {
            font-size: 0.68rem;
            font-weight: 600;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            color: var(--accent-2);
        }
        .btn-icon {
            padding: 6px 10px;
            font-size: 0.95rem;
            line-height: 1;
            border-radius: 50%;
            min-width: 32px;
            height: 32px;
        }

        /* ── Employees avatar ── */
        .tbl-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 1px solid var(--border-accent);
            object-fit: cover;
            vertical-align: middle;
            margin-right: 6px;
        }
        .tbl-avatar--fallback {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            color: #041018;
            font-weight: 700;
            font-size: 0.75rem;
            vertical-align: middle;
            margin-right: 6px;
        }

        /* ── Responsive ── */
        @media (max-width: 1100px) {
            .content-grid { grid-template-columns: 1fr; }
            .grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 600px) {
            body { padding: 12px; }
            .top-bar { padding: 12px 16px; flex-wrap: wrap; }
            section { padding: 16px; }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="app-shell<?= $isAdminWorkspaceView ? ' app-shell-wide' : '' ?>">
    <header class="top-bar">
        <h1><?= htmlspecialchars(trim($companyName)) ?></h1>
        <?php if ($loggedIn): ?>
            <div class="user-badge">
                <?php $avatarUrl = (string)($user['avatar_url'] ?? ''); ?>
                <?php if ($avatarUrl !== ''): ?>
                    <img class="user-avatar" src="<?= htmlspecialchars($avatarUrl) ?>" alt="" title="<?= htmlspecialchars((string)($user['display_name'] ?? '')) ?>">
                <?php else: ?>
                    <div class="user-avatar user-avatar--fallback" title="<?= htmlspecialchars((string)($user['display_name'] ?? '')) ?>"><?= htmlspecialchars(mb_substr((string)($user['display_name'] ?? '?'), 0, 1)) ?></div>
                <?php endif; ?>
                <?php if (isAdminUser($user)): ?>
                    <a href="?view=employees"><button type="button" class="btn-icon" title="Admin-Bereich">⚙</button></a>
                <?php endif; ?>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="auth.logout">
                    <button type="button" class="btn-icon" title="Abmelden" onclick="this.closest('form').submit()">↩</button>
                </form>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($loggedIn): ?>
    <nav class="pill-nav">
        <a class="<?= $view === 'dashboard' ? 'active' : '' ?>" href="?view=dashboard">Dashboard</a>
        <a class="<?= $view === 'inventory' ? 'active' : '' ?>" href="?view=inventory">Lager</a>
        <a class="<?= $view === 'shopping' ? 'active' : '' ?>" href="?view=shopping">Einkaufsliste</a>
        <a class="<?= $view === 'special-purchase' ? 'active' : '' ?>" href="?view=special-purchase">Sondereinkauf</a>
        <a class="<?= $view === 'stats' ? 'active' : '' ?>" href="?view=stats">Statistik</a>
    </nav>
    <?php endif; ?>


<?php if ($message): ?>
    <p class="flash"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<div class="content-grid <?= in_array($view, $singleColumnViews, true) ? 'shopping-view' : '' ?>">
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

<?php elseif ($view === 'employees' && isAdminUser($user)): ?>
<section>
    <?= renderAdminNav($view) ?>
    <table>
        <thead><tr><th>Discord ID</th><th>Name</th><th>Freigabe</th><th>Admin</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($adminUsers as $entry): ?>
            <tr>
                <td><?= htmlspecialchars($entry['discord_id']) ?></td>
                <td>
                    <?php $entryAvatar = (string)($entry['avatar_url'] ?? ''); ?>
                    <?php if ($entryAvatar !== ''): ?>
                        <img class="tbl-avatar" src="<?= htmlspecialchars($entryAvatar) ?>" alt="">
                    <?php else: ?>
                        <span class="tbl-avatar--fallback"><?= htmlspecialchars(mb_substr((string)($entry['display_name'] ?? '?'), 0, 1)) ?></span>
                    <?php endif; ?>
                    <?= htmlspecialchars($entry['display_name']) ?>
                </td>
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
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="admin.user.delete">
                            <input type="hidden" name="discord_id" value="<?= htmlspecialchars($entry['discord_id']) ?>">
                            <button class="danger" type="submit" onclick="return confirm('Mitarbeiter wirklich löschen?')">Löschen</button>
                        </form>
                        <form method="post" style="display:inline-flex; gap:6px; align-items:center; margin-top:6px;">
                            <input type="hidden" name="action" value="admin.user.rename">
                            <input type="hidden" name="discord_id" value="<?= htmlspecialchars($entry['discord_id']) ?>">
                            <input type="text" name="display_name" value="<?= htmlspecialchars($entry['display_name']) ?>" placeholder="Name für Nachweis" style="width:180px;">
                            <button type="submit">Namen speichern</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php elseif ($view === 'ingredients' && isAdminUser($user)): ?>
<section>
    <?= renderAdminNav($view) ?>
    <h2>Zutaten</h2>
    <form method="post" class="grid">
        <input type="hidden" name="action" value="ingredient.create">
        <div><label>Name<input required name="name"></label></div>
        <div><label>Preis pro Stück<input required step="1" type="number" name="price_per_unit"></label></div>
        <div><button type="submit">Anlegen</button></div>
    </form>

    <table>
        <thead><tr><th>Name</th><th>Preis pro Stück</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($ingredients as $ingredient): ?>
            <?php $formId = 'ingredient-' . (int)$ingredient['id']; ?>
            <tr>
                <td>
                    <form method="post" class="autosave-form" id="<?= $formId ?>">
                    <input type="hidden" name="action" value="ingredient.update">
                    <input type="hidden" name="id" value="<?= (int)$ingredient['id'] ?>">
                    <input type="hidden" name="stock_qty" value="<?= (int)$ingredient['stock_qty'] ?>">
                        <input form="<?= $formId ?>" name="name" value="<?= htmlspecialchars($ingredient['name']) ?>">
                        <small class="autosave-note" aria-live="polite"></small>
                    </form>
                </td>
                <td><input form="<?= $formId ?>" type="number" step="1" name="price_per_unit" value="<?= htmlspecialchars((string)$ingredient['price_per_unit']) ?>"></td>
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

<?php elseif ($view === 'products' && isAdminUser($user)): ?>
<section>
    <?= renderAdminNav($view) ?>
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
    <?php if (isAdminUser($user)): ?>
    <form method="post" style="margin-top: 12px; margin-bottom: 12px;">
        <input type="hidden" name="action" value="inventory.webhook.send">
        <button type="submit">Lagerbestand an Discord senden</button>
    </form>
    <?php endif; ?>
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
                        <input type="number" step="1" name="stock_qty" value="<?= (int)$item['stock_qty'] ?>">
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

<?php elseif ($view === 'options' && isAdminUser($user)): ?>
<section>
    <?= renderAdminNav($view) ?>
    <h2>Optionen</h2>
    <form method="post" class="autosave-form" style="margin-bottom: 14px;">
        <input type="hidden" name="action" value="options.company.update">
        <label>Unternehmensname<input name="company_name" value="<?= htmlspecialchars($companyName) ?>" placeholder="z. B. Muster GmbH"></label>
        <small class="autosave-note" aria-live="polite"></small>
    </form>

    <form method="post" class="autosave-form" style="margin-bottom: 14px;">
        <input type="hidden" name="action" value="options.webhook.update">
        <label>Discord Webhook URL (Einkäufe)<input name="discord_webhook_url" value="<?= htmlspecialchars($discordWebhookUrl) ?>" placeholder="https://discord.com/api/webhooks/..." autocomplete="off"></label>
        <small class="autosave-note" aria-live="polite"></small>
    </form>

    <form method="post" class="autosave-form" style="margin-bottom: 14px;">
        <input type="hidden" name="action" value="options.inventory_webhook.update">
        <label>Discord Webhook URL (Lagerbestand)<input name="inventory_discord_webhook_url" value="<?= htmlspecialchars($inventoryDiscordWebhookUrl) ?>" placeholder="https://discord.com/api/webhooks/..." autocomplete="off"></label>
        <small class="autosave-note" aria-live="polite"></small>
    </form>

    <form method="post" class="autosave-form">
        <input type="hidden" name="action" value="options.shopping_cash_check.toggle">
        <label style="display:flex; align-items:center; gap:8px;">
            <input type="hidden" name="shopping_cash_check_enabled" value="0">
            <input type="checkbox" name="shopping_cash_check_enabled" value="1" <?= $shoppingCashCheckEnabled ? 'checked' : '' ?>>
            Kassenprüfung neben Einkaufsliste anzeigen
        </label>
        <small class="autosave-note" aria-live="polite"></small>
    </form>
</section>

<?php elseif ($view === 'auditlog' && isAdminUser($user)): ?>
<section>
    <?= renderAdminNav($view) ?>
    <h2>Audit-Log</h2>
    <p>Hier siehst du, wer was wann im Admin-Bereich geändert hat.</p>
    <table>
        <thead><tr><th>Zeitpunkt</th><th>Benutzer</th><th>Discord ID</th><th>Aktion</th><th>Ziel</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($auditLogEntries as $entry): ?>
            <tr>
                <td><?= htmlspecialchars((string)$entry['created_at']) ?></td>
                <td><?= htmlspecialchars((string)$entry['actor_name']) ?></td>
                <td><?= htmlspecialchars((string)$entry['actor_discord_id']) ?></td>
                <td><?= htmlspecialchars((string)$entry['action_key']) ?></td>
                <td><?= htmlspecialchars((string)$entry['target_value']) ?></td>
                <td><?= htmlspecialchars((string)$entry['details']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (count($auditLogEntries) === 0): ?>
            <tr><td colspan="6">Noch keine Änderungen protokolliert.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php elseif ($view === 'shopping'): ?>
<?php
    $cashStartInput = trim((string)($_POST['cash_start'] ?? ''));
    $cashEndInput = trim((string)($_POST['cash_end'] ?? ''));
?>
<div class="grid" style="grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap:16px; align-items:start;">
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
        <form method="post" id="shopping-complete-form" style="margin-top: 12px;">
            <input type="hidden" name="action" value="shopping.complete">
            <button type="submit">Einkaufsliste abschließen</button>
        </form>
    </section>

    <?php if ($shoppingCashCheckEnabled): ?>
    <section>
        <h3>Kassenprüfung</h3>
        <div class="grid" style="grid-template-columns: 1fr; gap: 10px; margin-bottom: 10px;">
            <label>Kassen-Anfangsbestand
                <input form="shopping-complete-form" type="number" name="cash_start" step="0.01" min="0" value="<?= htmlspecialchars($cashStartInput) ?>" placeholder="0,00">
            </label>
            <label>Kassen-Endbestand
                <input form="shopping-complete-form" type="number" name="cash_end" step="0.01" min="0" value="<?= htmlspecialchars($cashEndInput) ?>" placeholder="0,00">
            </label>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php elseif ($view === 'special-purchase'): ?>
<?php
    $specialCashStartInput = trim((string)($_POST['cash_start'] ?? ''));
    $specialCashEndInput = trim((string)($_POST['cash_end'] ?? ''));
?>
<div class="grid" style="grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap:16px; align-items:start;">
    <section>
        <h2>Sondereinkauf</h2>
        <p>Hier kannst du manuell Einkäufe buchen. Diese werden im Lager, in der Statistik und per Discord-Webhook berücksichtigt.</p>
        <form method="post" id="special-purchase-complete-form" style="margin-top: 12px;">
            <input type="hidden" name="action" value="special-purchase.complete">
            <table>
                <thead><tr><th>Artikel</th><th>Typ</th><th>Preis pro Stück</th><th>Menge</th></tr></thead>
                <tbody>
                <?php foreach ($specialPurchaseCatalog as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$item['item_name']) ?></td>
                        <td><?= htmlspecialchars((string)$item['item_type']) ?></td>
                        <td><?= number_format((float)$item['unit_price'], 0, ',', '.') ?> $</td>
                        <td>
                            <input
                                type="number"
                                name="special_qty[<?= htmlspecialchars((string)$item['key']) ?>]"
                                min="0"
                                step="1"
                                value="0"
                            >
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($specialPurchaseCatalog) === 0): ?>
                    <tr><td colspan="4">Keine Artikel vorhanden. Bitte zuerst Zutaten oder Gerichte anlegen.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <button type="submit" style="margin-top: 12px;">Sondereinkauf abschließen</button>
        </form>
    </section>

    <?php if ($shoppingCashCheckEnabled): ?>
    <section>
        <h3>Kassenprüfung</h3>
        <div class="grid" style="grid-template-columns: 1fr; gap: 10px; margin-bottom: 10px;">
            <label>Kassen-Anfangsbestand
                <input form="special-purchase-complete-form" type="number" name="cash_start" step="0.01" min="0" value="<?= htmlspecialchars($specialCashStartInput) ?>" placeholder="0,00">
            </label>
            <label>Kassen-Endbestand
                <input form="special-purchase-complete-form" type="number" name="cash_end" step="0.01" min="0" value="<?= htmlspecialchars($specialCashEndInput) ?>" placeholder="0,00">
            </label>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php elseif ($view === 'stats'): ?>
<section>
    <h2>Statistik</h2>
    <p>Auswertung der abgeschlossenen Einkaufslisten: meistgekaufte Artikel oben, inklusive geschätztem Tagesverbrauch.</p>
    <table>
        <thead><tr><th>Artikel</th><th>Typ</th><th>Gesamt gekauft</th><th>Tagesverbrauch</th><th>Einkäufe</th><th>Gesamtkosten</th></tr></thead>
        <tbody>
        <?php foreach ($shoppingStats as $row): ?>
            <tr>
                <td><?= htmlspecialchars((string)$row['item_name']) ?></td>
                <td><?= htmlspecialchars((string)$row['item_type']) ?></td>
                <td><?= number_format((float)$row['total_qty'], 0, ',', '.') ?> Stk</td>
                <td><?= number_format((float)$row['avg_per_day'], 2, ',', '.') ?> / Tag</td>
                <td><?= (int)$row['shopping_runs'] ?></td>
                <td><?= number_format((float)$row['total_cost'], 0, ',', '.') ?> $</td>
            </tr>
        <?php endforeach; ?>
        <?php if (count($shoppingStats) === 0): ?>
            <tr><td colspan="6">Noch keine abgeschlossenen Einkaufslisten vorhanden.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
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

<?php if ($loggedIn && $view === 'dashboard'): ?>
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
