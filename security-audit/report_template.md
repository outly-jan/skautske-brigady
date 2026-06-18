# Bezpečnostní audit – skautchlumec.cz
Datum: 2026-06-18
Scope: WordPress portál pro rodiče, role: rodic / spravce_brigad / administrator

---

## Přehled rizik

| # | Oblast | Závažnost | Stav |
|---|--------|-----------|------|
| 1 | xmlrpc.php – veřejně přístupné | 🔴 KRITICKÉ | ⬜ Ověřit |
| 2 | REST API user enumeration /wp-json/wp/v2/users | 🔴 KRITICKÉ | ⬜ Ověřit |
| 3 | Snippet #25 – deaktivován, ale kód zůstává v DB | 🟠 VYSOKÉ | ✅ Deaktivován |
| 4 | wp-login.php?action=lostpassword spam | 🟠 VYSOKÉ | ✅ Zablokován |
| 5 | wp-login.php brute-force bez rate limitingu | 🟠 VYSOKÉ | ⬜ Ověřit |
| 6 | User enumeration /?author=N | 🟠 VYSOKÉ | ⬜ Ověřit |
| 7 | DISALLOW_FILE_EDIT chybí v wp-config.php | 🟠 VYSOKÉ | ⬜ Ověřit |
| 8 | PHP soubory v wp-content/uploads/ (backdoory) | 🟠 VYSOKÉ | ⬜ Ověřit |
| 9 | Aktivní snippety s eval/exec/base64 | 🟠 VYSOKÉ | ⬜ Spustit SQL |
| 10 | Zastaralé pluginy | 🟡 STŘEDNÍ | ⬜ Ověřit |
| 11 | WP_DEBUG aktivní v produkci | 🟡 STŘEDNÍ | ⬜ Ověřit |
| 12 | Neznámé PHP soubory v public_html root | 🟡 STŘEDNÍ | ⬜ Ověřit |
| 13 | wp-cron.php veřejně přístupné | 🟡 STŘEDNÍ | ⬜ Ověřit |
| 14 | Chybějící HTTP security headers | 🟡 STŘEDNÍ | ⬜ Aplikovat |
| 15 | Otevřená registrace uživatelů | 🟡 STŘEDNÍ | ⬜ Ověřit |

---

## Postup auditu

### Krok 1 – audit_script.php

1. Otevřít soubor `audit_script.php` a změnit `AUDIT_KEY` na unikátní heslo
2. Nahrát přes FTP do `public_html/audit_script.php`
3. Otevřít: `https://skautchlumec.cz/audit_script.php?audit_key=VAS_KLIC`
4. **Po auditu SMAZAT** soubor, nebo: `?audit_key=VAS_KLIC&self_delete=1`

### Krok 2 – SQL dotazy v phpMyAdmin

1. Přihlásit se na phpMyAdmin (Webglobe hosting panel)
2. Databáze: `skautchlumec_cz`
3. Záložka SQL → spustit dotazy ze souboru `sql_audit_queries.sql`

### Krok 3 – Nasadit hardened .htaccess

1. Záloha stávajícího .htaccess přes FTP
2. Vložit bloky z `htaccess_hardening.txt` PŘED `# BEGIN WordPress`
3. Test webu

### Krok 4 – Nasadit wp-config.php záplaty

1. Záloha wp-config.php
2. Vložit konstanty z `wp_config_additions.php` PŘED `/* That's all, stop editing! */`
3. Test webu

### Krok 5 – uploads/.htaccess

Nový soubor `public_html/wp-content/uploads/.htaccess`:
```apache
<FilesMatch "\.(php|php3|php4|php5|php7|phtml|pht|shtml|pl|cgi|sh)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>
Options -ExecCGI
```

### Krok 6 – Must-use plugin

Nový soubor `public_html/wp-content/mu-plugins/security-hardening.php`
(kód je v sekci FUNCTIONS.PHP ve `wp_config_additions.php`)

---

## Výsledky (vyplnit po auditu)

### Snippety

| ID | Název | Aktivní | Nebezpečné vzory | Akce |
|----|-------|---------|-----------------|------|
| 25 | doplnit | NE | doplnit | Zvážit DELETE |
| ... | | | | |

### Admini

| ID | Login | Email | Pozn. |
|----|-------|-------|-------|
| | | | |

### Pluginy

| Plugin | Verze | CVE/Riziko |
|--------|-------|-----------|
| Code Snippets | ? | |
| ... | | |

### PHP v uploads/

`(výstup z audit_script.php)`

---

## Prioritizovaný plán oprav

### Ihned (do 24h)
- [ ] Nasadit hardened `.htaccess`
- [ ] Přidat `DISALLOW_FILE_EDIT` + `DISALLOW_FILE_MODS` do wp-config.php
- [ ] Spustit SQL audit a zkontrolovat snippety na eval/base64
- [ ] Ověřit PHP soubory v uploads/

### Do 1 týdne
- [ ] Spustit audit_script.php a zdokumentovat výsledky
- [ ] Aktualizovat všechny pluginy
- [ ] Nasadit must-use security plugin
- [ ] Vytvořit uploads/.htaccess
- [ ] Smazat snippet #25 z DB (pokud kód nepotřebujete)

### Do 1 měsíce
- [ ] Systémový cron místo wp-cron.php
- [ ] WAF/CDN (Cloudflare free tier)
- [ ] Automatické zálohy DB + souborů
- [ ] 2FA pro administrátory

---

## Changelog

| Datum | Akce |
|-------|------|
| 2026-06-18 | Zablokován lostpassword endpoint v .htaccess |
| 2026-06-18 | Deaktivován snippet #25 |
| 2026-06-18 | Audit zahájen |
