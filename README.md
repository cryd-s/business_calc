# Business Calc

## Discord Login + Admin-Freigabe

Die App unterstützt jetzt Login über Discord OAuth2. Die **Discord-ID** ist der eindeutige Benutzer-Identifier.

### Fester Admin
Folgende Discord-ID ist immer Admin und wird automatisch freigeschaltet:

- `460750108615770134`

## Einrichtung

1. `config/config.example.php` nach `config/config.php` kopieren.
2. DB-Zugangsdaten setzen.
3. Discord-Konfiguration eintragen:
   - `discord.client_id`
   - `discord.client_secret`
   - `discord.redirect_uri`
4. In deinem Discord Developer Portal unter **OAuth2 → Redirects** exakt dieselbe Redirect-URI eintragen.

### Beispiel Redirect URI

`https://deine-domain.tld/?view=oauth-callback`

## Ablauf

1. Benutzer klickt auf **Mit Discord einloggen**.
2. Nach Rückkehr aus Discord wird die Discord-ID gespeichert.
3. Nur freigeschaltete Nutzer (oder der feste Admin) dürfen die App nutzen.
4. Admins sehen den Menüpunkt **Admin** und können Mitarbeiter freischalten/sperren.
