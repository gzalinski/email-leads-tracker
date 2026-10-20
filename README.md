# Location Lead Tracker

Plugin WordPress pentru formularele de contact ale locațiilor. Emailurile se trimit prin [Postmark](https://postmarkapp.com), fiecare lead se înregistrează în baza de date, trimiterile eșuate se reîncearcă automat, iar datele personale ale vizitatorilor se șterg după o perioadă configurabilă.

Arhitectura completă: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Cerințe

- WordPress 6.0+, PHP 7.4+ (cu extensia `sodium`, inclusă implicit), MySQL 5.7+ / MariaDB 10.3+
- Site unic (Multisite nu este suportat)
- Cont Postmark cu domeniul expeditorului verificat (DKIM și Return-Path)
- Recomandat: cron real de server la fiecare minut

## Instalare

1. Copiază folderul în `wp-content/plugins/` și activează pluginul.
2. **Setări → Location Lead Tracker → Postmark**: introdu Server API Token și apasă „Trimite email de test”.
3. **General**: emailul și numele expeditorului (de pe domeniul verificat în Postmark).
4. **Formular**: textul de consimțământ GDPR, cu link către pagina de confidențialitate.
5. **Alerte**: adresa pentru alerte și „Trimite alertă de test”.
6. Pe fiecare locație, completează câmpul meta `email` (funcționează și cu un câmp ACF numit `email`).
7. Adaugă shortcode-ul în pagină sau în template.

### Cron de server (recomandat)

În `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

Pe server, la fiecare minut:

```
* * * * * wp cron event run --due-now --path=/cale/către/site >/dev/null 2>&1
```

Fără cron real pluginul funcționează, dar reîncercările și alertele depind de trafic.

### Mutarea site-ului (staging → producție)

Tokenurile sunt criptate cu o cheie derivată din salturile WordPress. Dacă mediile au salturi diferite, definește aceeași cheie pe toate:

```php
define( 'LLT_ENCRYPTION_KEY', 'o-valoare-aleatoare-de-cel-putin-32-de-caractere' );
```

## Utilizare

```
[location_contact object_id="245"]
[location_contact]
```

- `object_id` — postarea locației; dacă lipsește, se folosește postarea curentă.
- Destinatarul se citește din post meta `email` a postării și **nu apare în HTML**.
- Postarea trebuie să fie publicată și să aibă un `email` valid; altfel editorii văd un mesaj explicativ, iar vizitatorii nu văd formularul.

Template-urile se pot suprascrie din temă, în `location-lead-tracker/form.php` și `location-lead-tracker/email.php`.

## Cum funcționează

1. Vizitatorul trimite formularul. Pluginul verifică contextul semnat, honeypot-ul, timpul minim, limita pe IP și, opțional, Cloudflare Turnstile.
2. Leadul și mesajul se salvează, iar vizitatorul primește imediat confirmarea.
3. Emailul pleacă prin Postmark imediat după răspuns (PHP-FPM / LiteSpeed) sau în cel mult un minut (cron).
4. Erorile temporare se reîncearcă după 1, 5, 15, 60 și 360 de minute (configurabil). La un timeout ambiguu, pluginul verifică în Postmark dacă mesajul a plecat înainte să-l retrimită.
5. La eșecuri, administratorul primește un email (fără datele vizitatorilor).
6. Datele personale se șterg după perioada configurată; rămâne doar evidența minimă a leadului.

## Confirmarea livrării (v2)

1. **Setări → Location Lead Tracker → Webhook**: apasă „Generează credențialele” și copiază URL-ul complet (parola se afișează o singură dată).
2. În Postmark: Server → Message Stream `outbound` → Webhooks → Add webhook. Lipește URL-ul, bifează **Delivery** și **Bounce**, apasă **Verify**.
3. Bifează „Activează webhookul” și salvează.

Din acel moment:

- leadurile primesc starea `delivered`, `bounced` sau `deferred`;
- datele vizitatorului se șterg imediat după livrarea confirmată, sau după „Zile fără confirmare” (tabul Retenție);
- o reconciliere orară întreabă Postmark despre leadurile fără confirmare, pentru cazul în care un webhook s-a pierdut;
- dacă webhookul pare oprit, administratorul primește o alertă.

Dacă **Verify** din Postmark întoarce 401 deși credențialele sunt corecte, serverul elimină headerul `Authorization`. Pe Apache adaugă în `.htaccess`:

```
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

## Leaduri eșuate în admin (v3)

**Setări → Location Lead Tracker → Leaduri eșuate**, doar pentru administratori (`manage_options`). Numărul de leaduri care necesită atenție apare lângă numele pluginului în meniu.

- Vizualizări: Eșuate, Blocate (în coadă de peste o oră), Respinse (v2), Ignorate.
- Filtre: cod de eroare, interval de date, căutare după locație.
- Selecție pe pagină sau „toate leadurile care corespund filtrului”.
- Acțiuni: Retrimite, Ignoră, Restaurează, Șterge conținutul. Retrimiterea pune leadurile în coadă; emailurile pleacă în următorul minut.
- „Vezi mesajul” încarcă datele vizitatorului doar la cerere.

Leadurile al căror conținut a fost deja șters de retenție nu mai pot fi retrimise; lista le marchează ca atare.

## WP-CLI

```
wp llt status
wp llt leads list [--status=failed] [--since=2026-10-01] [--format=table|csv|json]
wp llt leads show <id> [--with-content]
wp llt retry <id>... | --failed
wp llt send-test <email>
wp llt queue run
wp llt cleanup run [--dry-run]
wp llt content delete <id>...
wp llt stats [--since=…] [--until=…] [--by=object]
wp llt reconcile run
```

## Testare

Testele rulează fără WordPress (funcțiile WordPress sunt simulate, baza de date este SQLite în memorie):

```
php tests/run.php
php tests/check-calls.php
```

Înainte de producție, verifică pe un staging cu WordPress real lista din `docs/ARCHITECTURE.md` §22.4.

Notă: unele pluginuri de securitate blochează accesul vizitatorilor la `wp-admin/admin-post.php`. Formularul îl folosește pentru trimitere, deci trebuie permis.

## Versiuni

- **1.2.0 (v3)** — tabul „Leaduri eșuate”: vizualizări, filtre, selecție după filtru, retrimitere și ignorare în masă, „Vezi mesajul”, contor în meniu.
- **1.1.0 (v2)** — webhook Postmark (Delivery, Bounce) cu Basic Auth, stările `delivered` / `bounced` / `deferred`, ștergerea datelor la livrare, reconciliere orară, alerte pentru bounce și webhook oprit.
- **1.0.0 (v1)** — trimitere prin Postmark, coadă cu retry, verificare anti-duplicat, pagină de setări cu secrete criptate, alerte, WP-CLI, GDPR, Turnstile, detectarea IP-ului real, retenție.
