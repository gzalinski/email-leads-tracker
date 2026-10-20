# Location Lead Tracker — Arhitectură

Document de arhitectură pentru pluginul WordPress „Location Lead Tracker”.
Versiune finală: **doar Postmark**, trimitere asincronă, destinatar preluat din post meta `email`, alerte pentru administrator, WP-CLI, captcha opțional, consimțământ GDPR, detectarea IP-ului real, listă de leaduri eșuate în admin. Livrarea este împărțită în **v1**, **v2** și **v3**.

---

## 1. Decizii

| # | Decizie |
| --- | --- |
| 1 | Nu există `manual_review`. Eșecurile se semnalează prin **email către administrator**, iar intervenția se face prin **WP-CLI** (v1) și din **lista de leaduri eșuate** din pagina pluginului (v3). |
| 2 | Trimiterea este **asincronă**: formularul salvează leadul și răspunde imediat, emailul se trimite imediat după. |
| 3 | Furnizor unic: **Postmark** (API pentru trimitere, webhook pentru livrare reală). Fără SMTP generic și fără alți furnizori. |
| 4 | Emailul destinatarului **nu apare în HTML**. Se citește pe server din post meta cu cheia fixă **`email`** a postării. Cheia nu este configurabilă și nu există restricție după tipul postării. |
| 5 | Formularul public **nu folosește nonce** (incompatibil cu cache-ul de pagină). Protecție: context semnat, honeypot, time-trap, rate limiting și **captcha opțional** (Cloudflare Turnstile). |
| 6 | Checkbox de consimțământ GDPR cu text editabil din setări; integrare cu uneltele WordPress de export/ștergere a datelor personale. |
| 7 | **v1** = trimitere, retry, retenție, setări, alerte, CLI, GDPR, captcha, detectarea IP-ului. **v2** = webhook de livrare Postmark + reconciliere. **v3** = listă de leaduri eșuate în admin, cu selecție și retrimitere în masă, doar pentru administratori. Rămânem doar pe Postmark. |
| 8 | Rate limiting-ul folosește **IP-ul real** al vizitatorului, detectat automat pe orice tip de hosting (direct, Cloudflare, proxy). |

---

## 2. Etape de livrare

### v1 — Trimitere fiabilă

- Shortcode + formular (destinatar din post meta, nimic sensibil în HTML).
- Salvare lead + conținut, apoi trimitere asincronă prin Postmark API.
- Retry automat cu backoff pentru erori temporare.
- Verificare anti-duplicat pentru timeout-uri ambigue (căutare în Postmark).
- Retenție: conținutul se șterge la un număr de ore după acceptare.
- Pagina de setări.
- Alerte email pentru administrator.
- Comenzi WP-CLI.
- Captcha opțional (Turnstile), checkbox GDPR, export/ștergere date personale.

**Rezultat v1**: fiecare lead este salvat, trimis și, la eșec, semnalat. Se știe câte leaduri au fost **acceptate** de Postmark.

### v2 — Confirmarea livrării

- Endpoint webhook pentru evenimentele Postmark **Delivery** și **Bounce**.
- `delivery_status`: `delivered`, `bounced`, `deferred`.
- Conținutul se șterge **imediat după livrarea confirmată**.
- Reconciliere orară prin Messages API pentru evenimentele de webhook ratate.
- Alertă la bounce și alertă când webhookul pare oprit.
- Statistici CLI: leaduri livrate / respinse.

**Rezultat v2**: se știe câte emailuri au fost **livrate** efectiv.

### v3 — Leaduri eșuate în admin

- Tab **„Leaduri eșuate”** în pagina pluginului, vizibil doar pentru administratori.
- Listă cu filtre, paginare și selecție (inclusiv „selectează toate”).
- Acțiuni în masă: **Retrimite**, **Ignoră**, **Șterge conținutul**.
- Detaliile mesajului se pot vedea la cerere, pentru contact manual cu vizitatorul.
- Număr de leaduri eșuate afișat în meniul admin.

**Rezultat v3**: eșecurile se rezolvă din wp-admin, fără WP-CLI. Comenzile CLI rămân disponibile.

Schema bazei de date include coloanele pentru v2 și v3 încă din v1, ca să nu fie nevoie de migrare.

---

## 3. Structura fișierelor

Structura implementată (repo `gzalinski/email-leads-tracker`):

```
location-lead-tracker.php              # Bootstrap: header, autoload PSR-4, activare/dezactivare
uninstall.php                          # Ștergere date (dacă e bifat în setări)
includes/
├── Plugin.php                         # Înregistrează toate componentele
├── Installer.php                      # dbDelta, versiune schemă, evenimente cron
├── State.php                          # Starea internă (opțiunea llt_state)
├── Template.php                       # Randare template-uri, override din temă
├── Util.php                           # Date UTC, mascare emailuri, ID-uri
├── Settings/
│   ├── Settings.php                   # Citire setări + valori implicite
│   ├── SettingsFields.php             # Taburi și câmpuri (extensibile prin filtrul llt_settings_tabs)
│   ├── SettingsPage.php               # Pagina Setări → Location Lead Tracker
│   ├── SettingsSanitizer.php          # Validare la salvare (doar tabul curent)
│   ├── SecretStore.php                # Criptare libsodium
│   ├── TestActions.php                # Email de test, alertă de test, diagnostic IP
│   ├── StatusTab.php                  # Tabul Stare
│   └── AdminNotice.php                # Mesaje după acțiuni
├── Shortcode/
│   ├── ContactShortcode.php           # [location_contact]
│   ├── RecipientResolver.php          # object_id → post meta „email”
│   └── ContextSigner.php              # HMAC pentru object_id + momentul randării
├── Form/
│   ├── SubmissionHandler.php          # admin-post.php?action=llt_submit
│   ├── Validator.php                  # Câmpuri + consimțământ
│   ├── RateLimiter.php                # Limită pe IP (hash HMAC)
│   ├── Messages.php                   # Mesajele pentru vizitator
│   └── Captcha/TurnstileVerifier.php  # Verificare server-side
├── Http/
│   ├── ClientIp.php                   # IP-ul real (direct / Cloudflare / proxy)
│   ├── ProxyRanges.php                # Lista Cloudflare, actualizată săptămânal
│   └── Cidr.php                       # Apartenența IP ↔ rețea (IPv4/IPv6)
├── Lead/
│   ├── LeadService.php                # Creare lead + conținut (tranzacție)
│   ├── LeadStatus.php                 # Stări + regula de monotonie
│   └── LeadActions.php                # Retrimite / Ignoră / Restaurează / Șterge conținut (CLI și admin)
├── Postmark/
│   ├── PostmarkClient.php             # POST /email, căutare după metadata, detalii mesaj, bounce
│   ├── EmailBuilder.php               # Payload-ul emailului
│   ├── SendResult.php                 # accepted / temporary / ambiguous / permanent / config
│   └── ErrorClassifier.php            # HTTP + ErrorCode + erori cURL → categorie
├── Queue/
│   ├── SendWorker.php                 # Trimitere imediată și din coadă
│   ├── BackoffPolicy.php              # Intervale + jitter + Retry-After
│   └── Lock.php                       # Lock pe rând și lock global de worker
├── Alerts/
│   ├── AdminNotifier.php              # Alerte prin wp_mail(), limitare, revenire, bounce, webhook oprit
│   └── HealthCheck.php                # Verificare orară
├── Retention/RetentionService.php      # Ștergerea conținutului
├── Privacy/                           # Export, ștergere, text pentru politica de confidențialitate
├── Database/                          # Schema, LeadRepository, MessageRepository
├── Cli/Commands.php                   # wp llt ...
├── Webhook/                           # v2
│   ├── WebhookController.php          # POST /wp-json/llt/v1/postmark
│   ├── WebhookAuth.php                # Basic Auth + filtru IP
│   ├── EventProcessor.php             # Delivery / Bounce → lead
│   ├── Reconciler.php                 # Reconciliere orară prin Messages API
│   └── WebhookSettings.php            # Tabul Webhook, câmpurile v2, rânduri în Stare/CLI
└── Admin/                             # v3
    ├── FailedLeadsTab.php             # Tabul „Leaduri eșuate”
    ├── FailedLeadsTable.php           # WP_List_Table
    ├── FailedLeadsQuery.php           # Vizualizări, filtre, „toate după filtru”
    ├── BulkActionHandler.php          # Acțiuni pe rând și în masă
    ├── LeadDetailsAjax.php            # „Vezi mesajul”
    └── MenuBadge.php                  # Contor în meniu
templates/   form.php, email.php
assets/      form.js, form.css, admin.js, admin.css
tests/       run.php, check-calls.php, cases/ (fără WordPress: stub-uri + SQLite)
```

Namespace: `LocationLeadTracker\`. Autoload PSR-4.

---

## 4. Diagrama componentelor

```mermaid
flowchart LR
    V[Vizitator] -->|POST| SH[SubmissionHandler]
    SC[ContactShortcode] -->|formular + object_id semnat| V
    SH --> LS[LeadService]
    LS --> DB[(Tabele plugin)]
    LS -.->|după răspuns| SW[SendWorker]
    CRON[Cron server] --> SW
    CRON --> RS[RetentionService]
    CRON --> HC[HealthCheck]
    CRON -->|v2| REC[Reconciler]
    SW --> PC[PostmarkClient]
    PC --> PM[(Postmark API)]
    SW --> AN[AdminNotifier]
    HC --> AN
    AN -->|wp_mail| ADM[Administrator]
    PM -->|v2: Delivery / Bounce| WC[WebhookController]
    WC --> EP[EventProcessor]
    REC --> PC
    REC --> EP
    EP --> DB
    CLI[WP-CLI] --> LA[LeadActions]
    ADMIN[Admin v3] --> LA
    LA --> DB
    RS --> DB
```

---

## 5. Shortcode și destinatar

### 5.1. Utilizare

```
[location_contact object_id="245"]
[location_contact]                    ← folosește postarea curentă
```

- `object_id` este opțional; dacă lipsește, se folosește `get_the_ID()`.
- Atributul `email` **nu mai există**.

### 5.2. Rezolvarea destinatarului (`RecipientResolver`)

```php
$post  = get_post( $object_id );
$email = trim( (string) get_post_meta( $object_id, 'email', true ) );   // cheie fixă
```

Condiții de validitate:

1. `object_id` este un întreg pozitiv (dacă lipsește și `get_the_ID()` nu întoarce o postare, de exemplu într-un widget în afara loop-ului, condiția eșuează).
2. Postarea există și are status `publish`.
3. `is_email( $email )` este adevărat.

Cheia meta este fixă (`email`) și nu apare în setări. Nu există restricție după tipul postării: orice postare publicată cu un câmp `email` valid poate fi destinatar. Funcționează și dacă câmpul a fost creat cu ACF, pentru că ACF salvează valoarea tot în post meta, sub aceeași cheie.

Condițiile se verifică **de două ori**: la randare și la submit (postarea poate fi depublicată sau emailul șters între timp).

- **La randare**, dacă o condiție eșuează: editorii (`edit_posts`) văd un mesaj explicativ, de exemplu „Postarea #312 nu are un câmp `email` valid”; vizitatorii nu văd formularul.
- **La submit**, dacă o condiție eșuează: leadul nu se creează, iar vizitatorul primește „Formularul nu este disponibil momentan. Te rugăm să încerci mai târziu.” Evenimentul se loghează fără date personale.

Notă: deoarece nu există restricție de tip, `object_id` din shortcode trebuie să indice postarea corectă. Un ID greșit care indică o altă postare cu câmp `email` valid va trimite mesajele acolo. Vizitatorii nu pot schimba ID-ul (este semnat, §5.3); riscul ține doar de configurarea shortcode-ului de către editori.

### 5.3. Ce ajunge în HTML

| Câmp ascuns | Conținut |
| --- | --- |
| `llt_ctx` | `object_id` + momentul randării, codificat base64url |
| `llt_sig` | `HMAC-SHA256(llt_ctx, secret)` |
| `llt_hp` | Honeypot (gol) |
| widget Turnstile | doar dacă este activat |

**Emailul destinatarului nu apare nicăieri.** La submit se recitește din post meta.

Semnătura împiedică un vizitator să schimbe `object_id` pentru a trimite mesaje către altă postare. Momentul randării este tot în contextul semnat, deci un bot nu îl poate falsifica pentru a trece de time-trap. Secretul HMAC este derivat din `wp_salt( 'auth' )` și nu este expus.

Contextul nu expiră: pe o pagină cache-uită momentul randării poate fi vechi de zile, ceea ce trece oricum de time-trap (care cere doar un minimum).

### 5.4. Snapshot

La crearea leadului, emailul rezolvat se salvează în `recipient_email`. Dacă meta se schimbă ulterior, retry-urile merg tot la adresa de la momentul submitului.

---

## 6. Formularul

| Câmp | Obligatoriu |
| --- | --- |
| Full Name | Da |
| Email | Da |
| Phone Number | Nu |
| Message | Da |
| Checkbox consimțământ GDPR | Da |
| Turnstile | Da, dacă este activat |

### 6.1. Consimțământ GDPR

- Textul de lângă checkbox vine din setarea **„Text consimțământ”** (textarea).
- HTML permis: `<a href target rel>`, `<strong>`, `<em>`, `<br>` (filtrat cu `wp_kses`).
- La afișare, linkurile primesc automat `rel="noopener"` dacă au `target="_blank"`.
- Submit fără checkbox bifat → eroare de validare.
- Se salvează `consent_at` în lead, ca dovadă a consimțământului, fără date personale.

### 6.2. Anti-spam (fără nonce)

| Măsură | Detalii |
| --- | --- |
| Context semnat | Protejează `object_id`; compatibil cu cache-ul (nu expiră) |
| Honeypot | Câmp ascuns care trebuie să rămână gol |
| Time-trap | Minimum N secunde între randare (din contextul semnat) și submit (setare, implicit 3) |
| Rate limiting | Max N submit-uri / IP / oră (setare, implicit 5); cheie `hash(IP + salt)`; IP-ul real detectat conform §6.5 |
| Captcha | Opțional, Cloudflare Turnstile |

### 6.3. Captcha: Cloudflare Turnstile

Ales pentru că:

- funcționează cu pagini cache-uite;
- de cele mai multe ori este invizibil pentru vizitator;
- este gratuit și colectează mai puține date decât reCAPTCHA.

Implementare:

- Scriptul Turnstile se încarcă **doar** pe paginile care conțin shortcode-ul.
- La submit, tokenul se verifică server-side la `https://challenges.cloudflare.com/turnstile/v0/siteverify`, cu cheia secretă din setări și IP-ul real al vizitatorului (`remoteip`, din `ClientIp`, §6.5).
- Dacă Cloudflare nu răspunde: setare **„Dacă verificarea nu este disponibilă”** → *respinge* (implicit) sau *acceptă*.

Turnstile încarcă resurse de la Cloudflare, deci trebuie menționat în politica de confidențialitate (§11.3).

### 6.4. Răspunsul pentru vizitator

Trimiterea este asincronă, deci mesajul nu mai pretinde că emailul a fost trimis:

> „Mulțumim! Solicitarea ta a fost înregistrată.”

Textul se poate modifica din setări.

### 6.5. Detectarea IP-ului real al vizitatorului (`ClientIp`)

**Problema.** Rate limiting-ul numără mesajele pe adresă IP. Dacă site-ul stă în spatele Cloudflare, al unui load balancer sau al unui proxy nginx, cererea ajunge la WordPress de la proxy, nu direct de la vizitator. PHP vede atunci IP-ul proxy-ului, același pentru toți vizitatorii. Rezultatul: toți vizitatorii împart aceeași limită și, după 5 mesaje pe oră de la oricine, formularul se blochează pentru toată lumea.

Proxy-ul transmite IP-ul real într-un header (`CF-Connecting-IP` la Cloudflare, `X-Forwarded-For` la majoritatea celorlalte). Acele headere pot fi însă falsificate de oricine trimite cereri direct la server. Deci pluginul le citește **doar** dacă cererea vine efectiv de la un proxy cunoscut.

**Algoritm (modul „Automat”, implicit, funcționează pe orice site):**

1. `$remote = $_SERVER['REMOTE_ADDR']` — adresa care s-a conectat efectiv la server.
2. Dacă `$remote` aparține rețelelor Cloudflare → IP real = `CF-Connecting-IP`.
3. Altfel, dacă `$remote` este o adresă privată sau locală (`127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `::1`, `fc00::/7`) sau aparține listei de proxy-uri suplimentare din setări → proxy pe același server sau în rețeaua internă (nginx, Docker, load balancer). IP real = prima adresă din `X-Forwarded-For` parcursă **de la dreapta la stânga** care nu este ea însăși un proxy cunoscut; dacă headerul lipsește, `X-Real-IP`.
4. Altfel → conexiune directă; IP real = `$remote`. Headerele sunt ignorate.
5. Orice valoare se validează cu `filter_var( $ip, FILTER_VALIDATE_IP )`; o valoare invalidă duce la folosirea lui `$remote`.

Astfel:

| Configurație site | Ce se întâmplă |
| --- | --- |
| Fără proxy | Pasul 4, IP direct |
| Cloudflare | Pasul 2, IP real din `CF-Connecting-IP` |
| nginx / Varnish / Docker în fața PHP | Pasul 3, IP real din `X-Forwarded-For` |
| Cloudflare + nginx local | Pasul 3, apoi parcurgerea lanțului ajunge la IP-ul Cloudflare, apoi la vizitator |
| Atacator care trimite `CF-Connecting-IP` fals direct la server | Pasul 4, headerul este ignorat |
| Alt CDN / load balancer cu IP public (Sucuri, AWS ALB etc.) | Administratorul adaugă rețelele lui în „Proxy-uri suplimentare”; altfel se comportă ca la pasul 4 |

**Lista Cloudflare (`ProxyRanges`):**

- Pluginul conține o copie a listei, ca să funcționeze din prima zi.
- Un cron săptămânal o actualizează de la `https://www.cloudflare.com/ips-v4/` și `https://www.cloudflare.com/ips-v6/`. Dacă descărcarea eșuează, rămâne lista existentă.
- Lista nu se schimbă des (ultima modificare publicată: septembrie 2023), deci riscul de a fi depășită este mic.

**Setări** (tabul Anti-spam):

| Câmp | Valori | Implicit |
| --- | --- | --- |
| Sursa IP | Automat / Doar conexiunea directă / Doar Cloudflare | Automat |
| Proxy-uri suplimentare | CIDR, câte unul pe linie | gol |
| Diagnostic | doar citire, ex.: „IP-ul tău detectat: 85.x.x.x — prin Cloudflare” | — |

Lista Cloudflare descărcată se păstrează în opțiunea `llt_proxy_ranges` (§14.3).

Diagnosticul îi permite administratorului să vadă imediat dacă detectarea funcționează: dacă IP-ul afișat nu este al lui, configurația are nevoie de un proxy suplimentar.

Aceeași clasă este folosită și pentru filtrul opțional de IP al webhookului (§13.1).

---

## 7. Fluxul de submit și trimitere

```mermaid
sequenceDiagram
    participant V as Vizitator
    participant SH as SubmissionHandler
    participant DB as Baza de date
    participant SW as SendWorker
    participant PM as Postmark

    V->>SH: POST
    SH->>SH: HMAC context, honeypot, time-trap, rate limit, Turnstile, validare câmpuri, consimțământ
    SH->>SH: rezolvă destinatarul din post meta (§5.2)
    SH->>DB: TRANZACȚIE: INSERT lead (pending, next_retry_at = acum) + INSERT mesaj
    SH-->>V: „Solicitarea ta a fost înregistrată.”
    Note over SH,SW: după trimiterea răspunsului
    SH->>SW: process(lead_id)
    SW->>DB: claim (lock)
    SW->>PM: POST /email
    PM-->>SW: rezultat
    SW->>DB: actualizare stare
```

### 7.1. Trimiterea imediată după răspuns

1. Răspunsul către vizitator se trimite complet.
2. Dacă există `fastcgi_finish_request()` (PHP-FPM, cel mai des întâlnit), conexiunea se închide și același proces trimite emailul imediat.
3. Altfel, se programează `wp_schedule_single_event( time(), 'llt_send_lead', [ $id ] )`, iar cronul îl preia în cel mult un minut.
4. În orice caz, workerul periodic preia orice lead `pending` scadent. Nimic nu rămâne netrimis dacă pasul 2 sau 3 eșuează.

Lock-ul (§9.3) garantează că un lead nu se trimite de două ori, chiar dacă pașii 2 și 4 rulează simultan.

Formularul se trimite prin AJAX (`form.js`) către `admin-post.php?action=llt_submit` și primește răspuns JSON. Fără JavaScript, același endpoint face redirect înapoi la pagină cu mesajul de confirmare sau de eroare.

### 7.2. Cererea Postmark

`POST https://api.postmarkapp.com/email`, header `X-Postmark-Server-Token`.

| Câmp | Valoare |
| --- | --- |
| `From` | `"{nume expeditor}" <{email expeditor}>` (semnătură verificată în Postmark) |
| `To` | `recipient_email` |
| `ReplyTo` | emailul vizitatorului |
| `Subject` | din setări, cu `{post_title}`, `{site_name}` |
| `TextBody` / `HtmlBody` | din `templates/email.php`, conținut escapat; `HtmlBody` doar dacă formatul din setări este HTML |
| `MessageStream` | din setări (implicit `outbound`) |
| `Tag` | `location-lead` |
| `Metadata` | `{ "llt_lead_id": "123" }` |
| `TrackOpens` / `TrackLinks` | `false` |

`Metadata.llt_lead_id` este folosit pentru verificarea anti-duplicat (§7.4) și pentru corelarea webhookurilor (v2).

### 7.3. Clasificarea rezultatelor (`ErrorClassifier`)

Verificat în documentația Postmark (API overview, octombrie 2026). Postmark întoarce `ErrorCode` în corpul JSON și în headerul `X-PM-ApiErrorCode`.

| HTTP | `ErrorCode` | Semnificație | Categorie |
| --- | --- | --- | --- |
| 200 | 0 | Acceptat | `accepted` — salvează `MessageID`, `sent_at` |
| — | — | Conexiune eșuată înainte de trimitere (DNS, refuz, TLS) | `temporary` |
| — | — | Timeout **după** trimiterea cererii | `ambiguous` → §7.4 |
| 429 | — | Rate limit depășit | `temporary`, respectă `Retry-After` |
| 500 | 101 | Eroare internă Postmark (mesajul se pierde, confirmat de Postmark) | `temporary` |
| 503 | 100 | Mentenanță | `temporary` |
| 401 | 10 | Token invalid sau de tip greșit | `config` |
| 422 | 412 | Cont în aprobare: destinatarul trebuie să aibă același domeniu ca expeditorul | `config` |
| 422 | 413 | Contul nu este aprobat pentru trimitere | `config` |
| 422 | 422 | Server invalid | `config` |
| 422 | 1235 / 1236 | Message Stream inexistent sau de tip greșit | `config` |
| 422 | 1480 | IP-ul serverului nu are voie să trimită | `config` |
| 422 | 403 | Câmpuri invalide (ex.: `From` invalid sau neverificat) | `config` |
| 422 | 400 | Semnătură de expeditor negăsită (cod mai vechi, tratat defensiv dacă apare) | `config` |
| 422 | 402 | JSON invalid (bug în plugin) | `config` |
| 422 | 300 | Validare trimitere: adresă invalidă, limite de metadata sau headere | `permanent` (doar pentru acest lead) |
| 422 | 406 | Destinatar inactiv (suprimat după bounce sau plângere de spam) | `permanent` (doar pentru acest lead) |
| 413 | — | Mesaj prea mare (peste 10 MB) | `permanent` |
| altele | altele | Necunoscut | `permanent` |

- `temporary` = leadul rămâne `pending` și se reprogramează conform backoff-ului (§9.2).
- `ambiguous` = leadul rămâne `pending` și se verifică înainte de retrimitere (§7.4).
- `config` = eroare care afectează **toate** leadurile: leadul devine `failed` și se trimite **alertă imediată**, fără să se aștepte pragul.
- `permanent` = problema ține doar de acest lead: `failed`, alertă conform pragului.
- Textul erorii salvat în `last_error_message` este mesajul Postmark trunchiat la 255 de caractere, cu adresele de email înlocuite cu `***`.
- Pentru 406, alerta și lista din admin (v3) explică faptul că adresa locației trebuie reactivată în Postmark (Suppressions / Bounces) înainte de retrimitere.

### 7.3.1. Mesaj de test

Butonul „Trimite email de test” din setări folosește aceeași clasificare și afișează codul și explicația în română, de exemplu: „Contul Postmark este încă în aprobare (412): poți trimite doar către adrese de pe domeniul expeditorului.”

### 7.4. Timeout ambiguu, fără `manual_review`

Problema: cererea a plecat, dar răspunsul nu a venit. Postmark poate să fi trimis emailul sau nu.

Soluția, complet automată:

1. Leadul rămâne `pending`, cu `last_error_code = ambiguous_timeout` și `next_retry_at = acum + 5 minute`.
2. La următoarea rulare, înainte de a retrimite, workerul caută mesajul în Postmark:
   `GET /messages/outbound?count=1&offset=0&messagestream={stream}&metadata_llt_lead_id={id}`
3. **Găsit** (status `Sent`, `Processed` sau `Queued`) → `accepted`, cu `MessageID` din rezultat. Nu se retrimite.
4. **Negăsit** → se retrimite normal.
5. **Căutarea eșuează** → se reprogramează conform backoff-ului; numărul de încercări este tot limitat de `max_retries`.

**Verificat în documentația Postmark (Messages API):**

- Filtrul `metadata_{cheie}` există; se poate filtra după un singur câmp de metadata o dată, suficient aici.
- `count` și `offset` sunt obligatorii.
- Fără `messagestream`, căutarea se face doar în streamul `outbound`. Pluginul trimite mereu streamul din setări, ca verificarea să funcționeze și cu un stream personalizat.
- Filtrele de dată folosesc fusul orar Eastern Time; pluginul nu le folosește, deci problema nu apare.
- Mesajele rămân căutabile pe durata retenției contului Postmark (implicit 45 de zile), mult peste fereastra de verificare.

Pauza de 5 minute lasă timp ca mesajul să apară în căutare; durata exactă de indexare nu este documentată, deci se testează pe contul real.

---

## 8. Modelul de stări

### 8.1. `send_status`

```mermaid
stateDiagram-v2
    [*] --> pending: lead creat
    pending --> accepted: Postmark a acceptat
    pending --> pending: eroare temporară sau timeout ambiguu
    pending --> failed: eroare permanentă / retry-uri epuizate
    failed --> pending: retrimitere manuală (CLI sau admin v3)
    accepted --> [*]
```

`failed` este singura stare care necesită atenție; administratorul este anunțat prin email și o poate relua din CLI sau, din v3, din lista de leaduri eșuate. Un lead `failed` poate fi marcat ca ignorat (`dismissed_at`) fără a-i schimba starea.

### 8.2. `delivery_status` (v2)

```mermaid
stateDiagram-v2
    [*] --> unknown
    unknown --> deferred: bounce temporar
    unknown --> delivered: Delivery
    unknown --> bounced: bounce definitiv
    deferred --> delivered
    deferred --> bounced
    delivered --> [*]
    bounced --> [*]
```

În v1, `delivery_status` rămâne mereu `unknown`.

Regula de monotonie: `delivered` și `bounced` nu se suprascriu. Tranziția se face prin `UPDATE ... WHERE delivery_status IN (<stări permise>)`.

---

## 9. Coada de trimitere

### 9.1. Cron

- Recomandat: `DISABLE_WP_CRON = true` + cron real pe server la fiecare minut:
  `* * * * * wp cron event run --due-now --path=/cale/site`
- Fără cron real, pluginul funcționează cu WP-Cron standard, dar retry-urile depind de trafic.

| Eveniment | Frecvență | Componentă | Etapă |
| --- | --- | --- | --- |
| `llt_send_lead` | o dată, la submit | `SendWorker` | v1 |
| `llt_process_queue` | la 1 minut | `SendWorker` | v1 |
| `llt_health_check` | orar | `HealthCheck` | v1 |
| `llt_retention_cleanup` | zilnic | `RetentionService` | v1 |
| `llt_refresh_proxy_ranges` | săptămânal | `ProxyRanges` | v1 |
| `llt_reconcile_delivery` | orar | `Reconciler` | v2 |

Workerul salvează un heartbeat în `llt_state`; dacă nu a rulat de peste 15 minute, tabul **Stare** și `wp llt status` afișează avertisment, iar `HealthCheck` semnalează prin alertă leadurile blocate în `pending`.

### 9.2. Backoff

Program configurabil (implicit `1, 5, 15, 60, 360` minute), jitter ±20%, respectă `Retry-After`:

`next_retry_at = now + max( interval[retry_count] × (1 ± 0,2), retry_after )`

După ultimul retry: `failed`, `last_error_code = retries_exhausted`.

### 9.3. Locking

```sql
UPDATE {prefix}location_leads
SET locked_until = UTC_TIMESTAMP() + INTERVAL 5 MINUTE, lock_token = %s
WHERE id = %d
  AND send_status = 'pending'
  AND next_retry_at <= UTC_TIMESTAMP()
  AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP());
```

`rows_affected = 1` → workerul deține leadul. Lock-ul expiră singur dacă procesul moare.

### 9.4. Algoritm

1. Selectează până la 20 de leaduri `pending` scadente, ordonate după `next_retry_at`.
2. Pentru fiecare: claim → (dacă `ambiguous_timeout`: verificare §7.4) → încarcă mesajul → trimite → actualizează → eliberează lock-ul.
3. Lipsa conținutului → `failed`, `content_missing`.
4. După fiecare `failed`, se informează `AdminNotifier`.

---

## 10. Alerte pentru administrator (`AdminNotifier`)

### 10.1. Când

| Declanșator | Moment |
| --- | --- |
| Eroare de configurare (categoria `config`, §7.3) | imediat |
| N leaduri `failed` consecutive (setare, implicit 3) | la atingerea pragului |
| Leaduri `pending` scadente de peste 1 oră, dar netrimise (cron oprit sau Postmark indisponibil); leadurile care așteaptă un retry programat nu se numără | la verificarea orară (`HealthCheck`) |
| v2: bounce | opțional (setare), grupat |
| v2: webhook aparent oprit (§13.5) | la verificarea orară |
| Revenire la normal după o alertă | o singură notificare „Trimiterea funcționează din nou” |

Contorul de eșecuri consecutive se resetează la prima trimitere acceptată.

### 10.2. Cum

- Canal: `wp_mail()`, **nu** Postmark. Dacă problema este chiar Postmark, alerta tot ajunge (prin mailerul implicit al serverului sau prin pluginul SMTP al site-ului).
- Risc: multe hostinguri blochează funcția `mail()` din PHP. Fără un plugin SMTP configurat, `wp_mail()` poate eșua în tăcere și alertele nu ajung. De aceea:
  - tabul **Alerte** are butonul **„Trimite alertă de test”**, care trimite prin exact același canal și afișează dacă `wp_mail()` a raportat eroare (hookul `wp_mail_failed`);
  - dacă trimiterea unei alerte eșuează, eșecul se salvează în `llt_state` și apare în tabul **Stare** și în `wp llt status`;
  - din v3, contorul din meniul admin semnalează leadurile eșuate chiar dacă emailul de alertă nu a ajuns.
- Opțiune în tabul Alerte: **„Trimite alertele prin Postmark dacă `wp_mail()` eșuează”**. Utilă când serverul nu poate trimite email, dar Postmark funcționează (ex.: un singur lead cu adresă invalidă). Nu ajută când problema este chiar contul Postmark.
- Destinatar: setarea **„Email pentru alerte”** (implicit `admin_email`).
- Limitare: cel mult o alertă de același tip la X ore (setare, implicit 6); evenimentele din interval se cumulează în următoarea alertă.
- Conținut **fără date personale**: număr de leaduri afectate, ID-uri, cod și mesaj de eroare, data primului și ultimului eșec, plus comenzile CLI recomandate.

Exemplu:

```
Subiect: [Site] 3 leaduri nu au putut fi trimise

Postmark a respins trimiterea: tokenul API este invalid (ErrorCode 10).
Verifică tokenul în Setări → Location Lead Tracker → Postmark.
Leaduri afectate: #812, #813, #814 (primul eșec: 20.10.2026 14:02 UTC).

Conținutul acestor mesaje se păstrează 14 zile.
După remediere, retrimite-le din:
https://domeniu.md/wp-admin/options-general.php?page=location-lead-tracker&tab=failed
sau rulează:  wp llt retry --failed
```

Linkul către lista de leaduri eșuate apare din v3; în v1 alerta conține doar comanda CLI.

---

## 11. Confidențialitate și GDPR

### 11.1. Ce sunt „exporters / erasers”

WordPress are două unelte în **Unelte → Exportă date personale** și **Unelte → Șterge date personale**. Când o persoană cere datele ei (drept garantat de GDPR), administratorul introduce adresa ei de email, iar WordPress întreabă fiecare plugin ce date deține despre ea.

Pluginul se înregistrează la aceste unelte:

- **Export** (`PersonalDataExporter`): găsește în `location_lead_messages` mesajele cu `sender_email` = adresa cerută și le include în arhiva generată de WordPress (nume, email, telefon, mesaj, dată).
- **Ștergere** (`PersonalDataEraser`): șterge aceste mesaje. Leadul minimal rămâne, deoarece nu conține date ale vizitatorului.

Astfel, o cerere GDPR se rezolvă din interfața standard WordPress, fără acces la baza de date.

### 11.2. Ce date se păstrează

| Date | Unde | Cât timp |
| --- | --- | --- |
| Nume, email, telefon, mesaj vizitator | `location_lead_messages` | Temporar (§12) |
| ID postare, email destinatar (locație), stări, date | `location_leads` | Permanent (istoric) |
| Data consimțământului | `location_leads.consent_at` | Permanent |
| IP vizitator | Doar hash, în transient de rate limiting | 1 oră |
| Copia emailului trimis | Postmark (Activity) | Retenția contului Postmark (implicit 45 de zile, recomandat 7–14) |

### 11.3. Text pentru politica de confidențialitate

Prin `wp_add_privacy_policy_content()`, pluginul propune în **Setări → Confidențialitate** un text care descrie: datele colectate, scopul, trimiterea prin Postmark, Cloudflare Turnstile (dacă este activ), perioadele de păstrare. Administratorul îl copiază în pagina proprie.

---

## 12. Retenție (`RetentionService`)

| Situație | Conținut în `lead_messages` | Etapă |
| --- | --- | --- |
| `pending` (retry în curs) | păstrat | v1 |
| `accepted` | șters după X ore (setare, implicit 24) | v1 |
| `failed` | șters după Y zile (setare, implicit 14) | v1 |
| `delivered` | **șters imediat** de `EventProcessor` | v2 |
| `accepted` + `unknown` / `deferred` (nicio confirmare) | șters după Z zile (setare, implicit 7) | v2 |
| `bounced` | șters după Y zile | v2 |
| Oricând | șters manual (CLI, admin v3) sau la o cerere GDPR (§11.1) | v1 |

În v2, regula „X ore după acceptare” se înlocuiește cu „imediat după livrare / Z zile fără confirmare”.

Ștergerea rulează zilnic, în loturi de 500. Leadul minimal nu se șterge automat.

Leadurile `failed` pot fi retrimise doar cât timp conținutul există (Y zile). Dacă este nevoie de mai mult timp pentru remediere, se mărește Y din setări.

Backupurile, logurile serverului și copiile păstrate de Postmark nu sunt afectate și se menționează în politica de confidențialitate. Postmark păstrează mesajele (inclusiv conținutul) pe durata retenției contului: implicit 45 de zile, configurabilă între 7 și 365 de zile din contul Postmark. Pentru minimizarea datelor, retenția Postmark se poate reduce la 7 zile; verificarea anti-duplicat (§7.4) și reconcilierea (§13.5) au nevoie doar de câteva zile.

---

## 13. Webhook de livrare (v2)

### 13.1. Endpoint

```
POST https://domeniu.md/wp-json/llt/v1/postmark
```

Configurare în Postmark (Server → Message Stream → Webhooks): evenimentele **Delivery** și **Bounce**, URL cu Basic Auth:

```
https://{user}:{parolă}@domeniu.md/wp-json/llt/v1/postmark
```

Utilizatorul și parola se generează din pagina de setări; URL-ul complet se copiază cu un buton.

**Verificat în documentația Postmark:**

- Postmark **nu semnează** webhookurile (fără HMAC). Metodele recomandate sunt exact Basic Auth + HTTPS + opțional filtrarea după IP.
- La crearea sau editarea webhookului, Postmark trimite câte o cerere de test pentru fiecare tip de eveniment și așteaptă `200`. Endpointul trebuie deci să răspundă `200` și la payload-urile de test (evenimente necorelate → `200`).
- Delivery și Bounce se activează separat, pe același URL.

**Application Passwords:** pe site-urile care folosesc parole de aplicație, WordPress ar încerca să autentifice credențialele Basic Auth ale Postmark ca utilizator WordPress și ar respinge cererea cu 401 înainte să ajungă la plugin. Pluginul exclude doar ruta webhookului din această verificare (filtrul `application_password_is_api_request`).

**Filtru opțional de IP** (setare, dezactivat implicit): acceptă cereri doar de la IP-urile de webhook publicate de Postmark (`3.134.147.250`, `50.31.156.6`, `50.31.156.77`, `18.217.206.57` la data verificării). IP-ul sursă se determină cu `ClientIp` (§6.5), deci funcționează și în spatele Cloudflare. Este dezactivat implicit pentru că Postmark poate schimba IP-urile, iar Basic Auth este suficient.

### 13.2. Procesare

```mermaid
sequenceDiagram
    participant PM as Postmark
    participant WC as WebhookController
    participant EP as EventProcessor
    participant DB as Baza de date

    PM->>WC: POST JSON
    WC->>WC: Basic Auth (hash_equals), filtru IP opțional, limită body
    alt autentificare eșuată
        WC-->>PM: 401
    end
    WC->>EP: handle(payload)
    EP->>DB: caută după MessageID, apoi Metadata.llt_lead_id
    EP->>DB: UPDATE cu regulă de monotonie
    alt Delivery aplicat
        EP->>DB: DELETE conținut temporar
    end
    alt eroare internă
        WC-->>PM: 500 (Postmark reîncearcă)
    else
        WC-->>PM: 200
    end
```

### 13.3. Maparea evenimentelor

Verificat în documentația Postmark (Bounce API → Bounce types, Bounce webhook). Payload-ul Bounce conține `Type`, `TypeCode`, `Inactive` (adresa a fost dezactivată de Postmark), `CanActivate`, `BouncedAt`, `MessageID` și `Metadata`. Plângerile de spam și dezactivările manuale au în mod normal webhookuri separate; dacă totuși apar pe acest endpoint, se tratează conform tabelului.

**Regula principală:** dacă `Inactive = true`, Postmark a suprimat adresa, deci rezultatul este definitiv → `bounced`, indiferent de tip.

Altfel, după `TypeCode`:

| `Type` (`TypeCode`) | `delivery_status` |
| --- | --- |
| `Delivery` (eveniment separat) | `delivered`, `delivered_at = DeliveredAt` |
| `HardBounce` (1), `BadEmailAddress` (100000), `Blocked` (100006), `DMARCPolicy` (100009), `SMTPApiError` (100007), `ManuallyDeactivated` (100002) | `bounced` |
| `Transient` (2), `DnsError` (256), `SoftBounce` (4096), `Unknown` (2048) | `deferred` — Postmark poate încă livra; se așteaptă `Delivery` sau un bounce definitiv |
| `AutoResponder` (64), `SpamNotification` (512) | ignorat — mesajul a fost de fapt livrat (răspuns automat / filtrat ca spam la destinatar); se așteaptă `Delivery` |
| `Unsubscribe`, `Subscribe`, `AddressChange`, `OpenRelayTest`, `VirusNotification`, `ChallengeVerification` și orice alt tip | ignorat |

Tipul bounce-ului se salvează în `last_error_code` (ex.: `bounce:HardBounce`), iar `Inactive` în `last_error_message`, pentru a fi afișate în lista din admin (v3).

### 13.4. Reguli

**Coduri de răspuns** (verificat: Postmark reîncearcă doar la 5xx, 408, 429 și erori de rețea; orice alt 4xx înseamnă că evenimentul este abandonat):

| Situație | Răspuns |
| --- | --- |
| Basic Auth greșit / IP nepermis | `401` (Postmark nu reîncearcă — corect, cererea nu e de la Postmark sau configurația e greșită) |
| Eveniment procesat, duplicat, necorelat sau de tip ignorat | `200` |
| Eroare de bază de date sau altă eroare internă | `500` — Postmark reîncearcă |
| Payload invalid (nu e JSON, lipsește `RecordType`) | `200` + log, ca un eveniment stricat să nu blocheze trimiterile |

**Idempotență:** actualizarea condiționată (`UPDATE ... WHERE delivery_status NOT IN ('delivered','bounced')`) face ca un eveniment repetat să nu aibă efect. Postmark trimite și headerul `X-PM-Webhook-Trace-Id`, stabil între reîncercări; se salvează în log pentru depanare, dar nu este necesar pentru corectitudine.

**Alte reguli:**

- Webhookul nu creează leaduri.
- Webhook sosit înaintea salvării `MessageID` (posibil, deoarece trimiterea e asincronă) → corelare prin `Metadata.llt_lead_id`, iar `postmark_message_id` se completează atunci.
- Handler rapid, fără apeluri externe.

### 13.5. Reconcilierea evenimentelor ratate (`Reconciler`)

**De ce:** documentația Postmark arată că un eveniment eșuat se reîncearcă de 6 ori, la 1, 5, 10, 10, 10 și 15 minute (aproximativ 50 de minute în total), apoi este abandonat. Dacă endpointul eșuează repetat, Postmark marchează tipul de eveniment ca neverificat și **oprește** trimiterea lui până la o nouă verificare manuală. Un site căzut o oră sau o actualizare greșită ar duce la pierderea definitivă a confirmărilor.

**Soluția:** un job orar verifică direct în Postmark leadurile rămase fără confirmare.

1. Selectează până la 50 de leaduri cu `send_status = 'accepted'`, `delivery_status IN ('unknown','deferred')`, `sent_at` mai vechi de 2 ore și mai noi de Z zile.
2. Pentru fiecare: `GET /messages/outbound/{MessageID}/details`.
3. Din `MessageEvents`:
   - `Delivered` → `delivered`, cu `ReceivedAt` ca `delivered_at`;
   - `Bounced` → evenimentul conține doar `BounceID`, deci se cere `GET /bounces/{BounceID}` pentru `TypeCode` și `Inactive`, apoi se aplică maparea din §13.3;
   - `Transient` → `deferred`.
4. Actualizarea folosește același `EventProcessor`, deci aceleași reguli de monotonie și de ștergere a conținutului.
5. Cererile se fac secvențial, cu cel mult 50 de leaduri pe rulare, pentru a nu atinge rate limit-ul Postmark; un răspuns 429 oprește rularea curentă.

**Alertă de webhook oprit:** dacă există leaduri acceptate în ultimele 24 de ore, dar niciun webhook nu a fost primit în acest interval, iar reconcilierea găsește livrări, `AdminNotifier` trimite: „Webhookul Postmark pare oprit. Verifică-l în Postmark → Server → Message Stream → Webhooks și apasă Verify.”

Reconcilierea transformă webhookul din singura sursă de adevăr într-o optimizare: confirmarea vine în câteva secunde când webhookul merge și în cel mult o oră când nu merge.

---

## 14. Modelul de date

### 14.1. `{prefix}location_leads`

```sql
CREATE TABLE {prefix}location_leads (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  object_id           BIGINT UNSIGNED NOT NULL,
  recipient_email     VARCHAR(254)    NOT NULL,
  postmark_message_id VARCHAR(64)     NULL,
  send_status         VARCHAR(20)     NOT NULL DEFAULT 'pending',
  delivery_status     VARCHAR(20)     NOT NULL DEFAULT 'unknown',
  retry_count         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_retries         TINYINT UNSIGNED NOT NULL DEFAULT 5,
  next_retry_at       DATETIME        NULL,
  locked_until        DATETIME        NULL,
  lock_token          CHAR(32)        NULL,
  last_attempt_at     DATETIME        NULL,
  last_error_code     VARCHAR(40)     NULL,
  last_error_message  VARCHAR(255)    NULL,
  consent_at          DATETIME        NOT NULL,
  manual_resend_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_resend_by      BIGINT UNSIGNED NULL,
  last_resend_at      DATETIME        NULL,
  dismissed_at        DATETIME        NULL,
  dismissed_by        BIGINT UNSIGNED NULL,
  created_at          DATETIME        NOT NULL,
  sent_at             DATETIME        NULL,
  delivered_at        DATETIME        NULL,
  updated_at          DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_message_id (postmark_message_id),
  KEY idx_object (object_id),
  KEY idx_send_status (send_status),
  KEY idx_delivery_status (delivery_status),
  KEY idx_queue (send_status, next_retry_at),
  KEY idx_reconcile (send_status, delivery_status, sent_at),
  KEY idx_created (created_at)
);
```

- `UNIQUE` pe `postmark_message_id` acceptă mai multe valori `NULL` în MySQL/MariaDB, deci este unic doar când are valoare.
- Toate datele se salvează în UTC; se afișează în fusul orar al site-ului.

Coloane adăugate față de specificația inițială: `locked_until`, `lock_token` (locking), `consent_at` (dovada consimțământului), `manual_resend_count`, `last_resend_by`, `last_resend_at` (urma retrimiterilor manuale, v3), `dismissed_at`, `dismissed_by` (leaduri ignorate, v3). `*_by` conțin ID-ul utilizatorului WordPress.

### 14.2. `{prefix}location_lead_messages`

```sql
CREATE TABLE {prefix}location_lead_messages (
  lead_id      BIGINT UNSIGNED NOT NULL,
  sender_name  VARCHAR(200)    NOT NULL,
  sender_email VARCHAR(254)    NOT NULL,
  sender_phone VARCHAR(50)     NULL,
  message      TEXT            NOT NULL,
  created_at   DATETIME        NOT NULL,
  PRIMARY KEY  (lead_id),
  KEY idx_sender_email (sender_email),
  KEY idx_created (created_at)
);
```

Indexul pe `sender_email` servește exportului/ștergerii GDPR.

### 14.3. Opțiuni

| Opțiune | Conținut | Autoload |
| --- | --- | --- |
| `llt_settings` | Toate setările; secretele criptate | nu |
| `llt_db_version` | Versiune schemă | da |
| `llt_state` | Heartbeat cron, contor eșecuri consecutive, ultima alertă pe tip, ultima alertă eșuată, ultimul webhook primit, ultima reconciliere | nu |
| `llt_proxy_ranges` | Lista IP-urilor Cloudflare + data actualizării | nu |

---

## 15. Pagina de setări

**Setări → Location Lead Tracker**, capabilitate `manage_options`, Settings API.

| Tab | Câmpuri | Etapă |
| --- | --- | --- |
| **General** | Email expeditor, nume expeditor, subiect (cu `{post_title}`, `{site_name}`), format email (text / HTML) | v1 |
| **Postmark** | Server API Token (secret), Message Stream, timeout; buton **„Trimite email de test”** | v1 |
| **Formular** | Text consimțământ GDPR (textarea, HTML limitat), mesaj de confirmare pentru vizitator | v1 |
| **Anti-spam** | Activează Turnstile, Site Key, Secret Key (secret), comportament dacă Turnstile nu răspunde, limită submit-uri / IP / oră, timp minim de completare, sursa IP, proxy-uri suplimentare, diagnostic IP (§6.5) | v1 |
| **Retry** | Număr maxim de retry-uri, program în minute | v1 |
| **Alerte** | Email pentru alerte, prag eșecuri consecutive, interval minim între alerte (ore), fallback prin Postmark, buton „Trimite alertă de test”, alertă la bounce (v2) | v1 |
| **Retenție** | Ore după acceptare (v1), zile pentru eșuate, zile fără webhook (v2), șterge datele la dezinstalare | v1 / v2 |
| **Webhook** | Activează, URL (doar citire, buton „Copiază”), utilizator + parolă Basic Auth (buton „Generează”), filtru IP Postmark (opțional), data ultimului webhook primit, data ultimei reconcilieri | v2 |
| **Stare** | Ultima rulare a cronului, leaduri `pending` / `failed`, ultima alertă trimisă, ultima alertă eșuată, data listei Cloudflare (doar citire) | v1 |
| **Leaduri eșuate** | Listă, filtre, retrimitere în masă (§17) | v3 |

### 15.1. Secrete (`SecretStore`)

- Token Postmark, Turnstile Secret Key, parola webhook se stochează criptate (`sodium_crypto_secretbox`).
- Cheie: `LLT_ENCRYPTION_KEY` din `wp-config.php` dacă există (opțional), altfel derivată din `wp_salt( 'secure_auth' )`.
- Un secret salvat nu se afișează niciodată înapoi; câmpul arată `••••••••`. Câmp gol la salvare = valoarea rămâne neschimbată.
- Dacă salturile WordPress se schimbă, secretele nu mai pot fi decriptate: pagina detectează situația și cere reintroducerea lor.

Nicio altă setare nu este hardcodată în constante.

### 15.2. Validare

- Emailuri cu `is_email()`; programul de retry: întregi pozitivi, crescători, max. 10 valori; numărul de retry-uri nu poate depăși numărul de intervale.
- Proxy-urile suplimentare: CIDR IPv4 sau IPv6 valid pe fiecare linie.
- Textul GDPR trece prin `wp_kses` cu lista de taguri permise.
- Erorile apar prin `add_settings_error()`.

---

## 16. WP-CLI

Comenzile nu afișează date personale ale vizitatorilor decât la cerere explicită.

| Comandă | Acțiune |
| --- | --- |
| `wp llt status` | Stare cron, leaduri pe stări, ultimele erori, configurare validă / invalidă |
| `wp llt leads list [--status=failed] [--since=2026-10-01] [--format=table\|csv\|json]` | Listă leaduri (fără conținut) |
| `wp llt leads show <id> [--with-content]` | Detalii lead; cu `--with-content` și mesajul, dacă încă există |
| `wp llt retry <id>…` / `wp llt retry --failed` | Readuce leadurile în `pending` și resetează `retry_count` |
| `wp llt send-test <email>` | Email de test prin Postmark |
| `wp llt queue run` | Rulează workerul imediat |
| `wp llt cleanup run [--dry-run]` | Rulează retenția |
| `wp llt reconcile run` | Rulează reconcilierea imediat (v2) |
| `wp llt content delete <id>` | Șterge manual conținutul unui lead |
| `wp llt stats [--since] [--until] [--by=object]` | Număr de leaduri acceptate / eșuate; în v2 și livrate / respinse, opțional pe locație |

`retry` refuză leadurile al căror conținut a fost deja șters și explică motivul.

Comenzile CLI și pagina din admin (v3) folosesc aceeași clasă `LeadActions`, deci se comportă identic.

---

## 17. Leaduri eșuate în admin (v3)

### 17.1. Acces

- Tab **„Leaduri eșuate”** în **Setări → Location Lead Tracker**:
  `options-general.php?page=location-lead-tracker&tab=failed`
- Capabilitate: `manage_options` (doar administratori), verificată la afișare, la fiecare acțiune și la fiecare cerere AJAX. Editorii și alte roluri nu văd nici tabul, nici contorul.
- Contor în meniu: **„Location Lead Tracker (3)”** — numărul de leaduri `failed`, neignorate, cu conținut disponibil. Calculul se păstrează în cache (transient de 5 minute, invalidat la orice schimbare de stare).

### 17.2. Banner de configurare

Dacă ultimul eșec este o eroare de configurare (categoria `config`, §7.3), deasupra listei apare:

> **Trimiterea nu funcționează: tokenul Postmark este invalid (ErrorCode 10).** Corectează setările din tabul Postmark și trimite un email de test înainte de a retrimite leadurile.

Bannerul conține linkul către tabul Postmark și butonul „Trimite email de test”. Retrimiterea nu este blocată, dar fără remediere leadurile vor eșua din nou.

### 17.3. Lista (`FailedLeadsTable`, bazată pe `WP_List_Table`)

| Coloană | Conținut |
| --- | --- |
| ☐ | Checkbox (activ pentru toate rândurile, ca leadurile fără conținut să poată fi ignorate în masă; la retrimitere sunt sărite și raportate) |
| ID | `#812` |
| Data | `created_at`, în fusul orar al site-ului |
| Locație | Titlul postării, cu link spre editare |
| Destinatar | `recipient_email` |
| Eroare | Cod + mesaj scurt; iconiță de avertizare pentru erori care probabil se repetă (adresă invalidă, destinatar inactiv în Postmark) |
| Încercări | `retry_count` automate + `manual_resend_count` manuale |
| Ultima încercare | `last_attempt_at` |
| Conținut | „Disponibil — se șterge în 9 zile” sau „Șters” |

**Vizualizări** (linkuri deasupra listei, cu număr):

| Vizualizare | Criteriu |
| --- | --- |
| Eșuate (implicit) | `send_status = failed`, `dismissed_at IS NULL` |
| Blocate | `send_status = pending` și `next_retry_at` depășit cu peste 1 oră |
| Respinse (v2) | `delivery_status = bounced`, `dismissed_at IS NULL` |
| Ignorate | `dismissed_at IS NOT NULL` |

**Filtre**: cod de eroare (dropdown), locație (căutare după titlu), interval de date.
**Paginare**: 20 pe pagină (configurabil din „Opțiuni ecran”). **Sortare**: dată, locație, încercări.

**Datele vizitatorului nu apar în listă.** Acțiunea de rând **„Vezi mesajul”** le încarcă la cerere (AJAX, nonce, `manage_options`): nume, email, telefon, mesaj. Utilă când administratorul vrea să contacteze direct vizitatorul.

### 17.4. Selecție

- Checkbox-ul din antet selectează toate leadurile de pe pagina curentă (comportament standard WordPress).
- După selecția paginii apare linkul **„Selectează toate cele 47 de leaduri care corespund filtrului”**. Acțiunea se aplică atunci pe server tuturor leadurilor care corespund filtrelor curente, nu doar celor vizibile.

### 17.5. Acțiuni

| Acțiune | Tip | Efect |
| --- | --- | --- |
| **Retrimite** | rând + masă | Readuce leadul în coada de trimitere |
| **Ignoră** | rând + masă | Setează `dismissed_at`, `dismissed_by`; leadul dispare din „Eșuate”, starea rămâne `failed` |
| **Restaurează** | rând + masă (în „Ignorate”) | Golește `dismissed_at` |
| **Șterge conținutul** | rând + masă | Șterge datele vizitatorului imediat; cere confirmare |
| **Vezi mesajul** | rând | Afișează datele vizitatorului (§17.3) |

Acțiuni disponibile pe vizualizare:

| Vizualizare | Acțiuni |
| --- | --- |
| Eșuate | Retrimite, Ignoră, Șterge conținutul, Vezi mesajul |
| Blocate | Vezi mesajul (leadurile sunt deja în coadă; se verifică cronul în tabul Stare) |
| Respinse (v2) | Ignoră, Șterge conținutul, Vezi mesajul — fără Retrimite, pentru că adresa a respins mesajul; se poate contacta direct vizitatorul |
| Ignorate | Restaurează, Șterge conținutul, Vezi mesajul |

### 17.6. Fluxul „Retrimite”

```mermaid
sequenceDiagram
    participant A as Administrator
    participant LA as LeadActions
    participant DB as Baza de date
    participant SW as SendWorker

    A->>LA: Retrimite (ID-uri sau „toate după filtru”)
    LA->>LA: check_admin_referer, manage_options
    LA->>DB: UPDATE … SET pending, retry_count = 0, next_retry_at = acum, manual_resend_count + 1, last_resend_by, last_resend_at, dismissed_at = NULL WHERE send_status = 'failed' AND conținut există
    LA->>LA: programează llt_process_queue + spawn_cron()
    LA-->>A: redirect + notice: „12 leaduri programate. 2 sărite: conținut șters.”
    SW->>DB: preia leadurile în următorul minut
```

Reguli:

- Retrimiterea **nu trimite emailurile în cererea din admin**: le pune în coadă. Astfel 200 de leaduri nu blochează pagina și nu depășesc timpul maxim de execuție PHP.
- `UPDATE ... WHERE send_status = 'failed'` este atomic: două click-uri rapide sau doi administratori simultan nu produc trimiteri duble.
- Leadurile fără conținut sunt sărite și raportate în notice.
- Leadurile cu erori care probabil se repetă (destinatar inactiv în Postmark) se retrimit dacă sunt selectate, dar notice-ul avertizează că pot eșua din nou. Pentru un destinatar dezactivat după bounce, adresa trebuie reactivată mai întâi în Postmark.
- Retrimiterea resetează `retry_count`, deci leadul primește din nou ciclul complet de retry-uri automate.
- Alertele email (§10) nu se trimit din nou pentru leadurile retrimise manual până la un nou eșec complet.
- Un lead retrimis cu succes trece în `accepted` și urmează retenția normală.

### 17.7. Notice-uri după acțiuni

Rezultatul fiecărei acțiuni se păstrează într-un transient per utilizator și se afișează ca admin notice după redirect, cu filtrele curente păstrate. Leadurile retrimise apar în vizualizarea „Blocate” doar dacă nu sunt trimise la peste o oră după ce au devenit scadente.

---

## 18. Securitate

| Risc | Măsură |
| --- | --- |
| Expunerea emailului destinatarului | Nu apare în HTML; citit server-side din post meta |
| Trimitere către altă postare | `object_id` semnat HMAC; recitit și validat la submit |
| Bot care ocolește time-trap-ul | Momentul randării este în contextul semnat |
| Spam | Honeypot, time-trap, rate limiting, Turnstile opțional |
| IP fals în headere (`CF-Connecting-IP`, `X-Forwarded-For`) | Headerele se citesc doar dacă conexiunea vine de la un proxy cunoscut (§6.5) |
| Blocarea formularului pentru toți vizitatorii în spatele unui proxy | Detectarea automată a IP-ului real (§6.5) + diagnostic în setări |
| Formular cache-uit | Fără nonce; context semnat fără expirare |
| Acces la setări | `manage_options` + nonce în admin |
| Secrete | Criptate în baza de date; niciodată afișate sau logate |
| SQL injection | `$wpdb->prepare()`, `insert()`, `update()` |
| XSS | Escapare în template-uri și email; `wp_kses` pe textul GDPR |
| Header injection | Eliminare `\r\n` din nume și subiect |
| Webhook fals (v2) | Basic Auth constant-time, HTTPS, limită body, filtru IP Postmark opțional (Postmark nu semnează webhookurile) |
| Date personale în loguri / alerte | Doar ID-uri, coduri de eroare, stări |
| Acces la leaduri în admin (v3) | `manage_options` la afișare, acțiuni și AJAX; datele vizitatorului doar la cerere |
| CSRF în admin (v3) | `check_admin_referer` pentru acțiuni în masă, nonce pentru AJAX |
| Trimiteri duble din admin (v3) | `UPDATE` condiționat de `send_status = 'failed'` + lock-ul cozii |

---

## 19. Hooks WordPress

| Hook | Componentă |
| --- | --- |
| `init` | Shortcode |
| `wp_enqueue_scripts` | `form.js` + Turnstile doar pe pagini cu shortcode |
| `admin_menu`, `admin_init` | Pagina de setări |
| `admin_post_llt_test_email` | Email de test |
| `admin_post_llt_test_alert` | Alertă de test |
| `admin_post_nopriv_llt_submit`, `admin_post_llt_submit` | Submit |
| `llt_send_lead`, `llt_process_queue`, `llt_health_check`, `llt_retention_cleanup` | Cron (§9.1) |
| `wp_mail_failed` | Detectarea alertelor netrimise |
| `wp_privacy_personal_data_exporters`, `wp_privacy_personal_data_erasers` | GDPR |
| `admin_init` → `wp_add_privacy_policy_content` | Text politică |
| `rest_api_init` | Webhook (v2) |
| `llt_reconcile_delivery` (orar) | `Reconciler` (v2) |
| `llt_refresh_proxy_ranges` (săptămânal) | `ProxyRanges` |
| `cli_init` / `WP_CLI::add_command` | WP-CLI |
| `admin_post_llt_lead_action` | Acțiuni pe leaduri (v3) |
| `wp_ajax_llt_lead_details` | „Vezi mesajul” (v3) |
| `admin_menu` (contor) | `MenuBadge` (v3) |
| `set-screen-option` | Număr de rânduri pe pagină (v3) |
| activare / dezactivare | `Installer` |

Filtre: `llt_email_subject`, `llt_email_body`, `llt_form_template`, `llt_recipient_email`.

---

## 20. Testare

| Nivel | v1 | v2 |
| --- | --- | --- |
| Unit | `ErrorClassifier`, `BackoffPolicy`, `ContextSigner`, `SecretStore`, `RecipientResolver`, `ClientIp` (direct, Cloudflare, proxy local, header falsificat) | Maparea evenimentelor (inclusiv `Inactive`), monotonia |
| Destinatar | Postare fără `email`, cu `email` invalid, depublicată între randare și submit, shortcode fără `object_id` în afara loop-ului | — |
| Reconciliere | — | Webhook oprit → leadurile ajung `delivered` / `bounced` prin Messages API; 429 oprește rularea |
| Integrare | Submit complet cu Postmark simulat (`pre_http_request`); token de test Postmark (`POSTMARK_API_TEST`) | Payload-uri reale Delivery / Bounce |
| Asincron | Răspuns rapid către vizitator; trimitere după răspuns; fallback prin cron | — |
| Timeout ambiguu | Mesaj găsit în Postmark → fără retrimitere; negăsit → retrimitere | — |
| Concurență | Trimitere imediată + worker pe același lead → un singur email | Webhook înainte de salvarea `MessageID` |
| Alerte | Prag, limitare în timp, alertă de revenire, livrare prin `wp_mail` când Postmark e căzut | Alertă bounce |
| GDPR | Export și ștergere după email; checkbox obligatoriu | — |
| Retenție | Ștergere după X ore / Y zile | Ștergere imediată după `Delivery` |
| CLI | Toate comenzile, inclusiv `retry` pe conținut șters | `stats` cu livrate / respinse |

**v3**:

| Ce se verifică | Rezultat așteptat |
| --- | --- |
| Acces | Editorii nu văd tabul, contorul și nu pot apela acțiunile sau AJAX-ul |
| Retrimitere în masă | Leadurile ajung `pending` și sunt trimise de worker; pagina răspunde rapid și la 500 de leaduri |
| „Selectează toate după filtru” | Acțiunea se aplică exact leadurilor care corespund filtrelor |
| Dublu click / doi administratori | O singură trimitere pe lead |
| Conținut șters | Checkbox dezactivat; leadul este sărit și raportat |
| Ignoră / Restaurează | Leadul dispare și reapare în vizualizări fără schimbarea stării |
| Contor meniu | Corect după fiecare acțiune |

---

## 21. Criterii de acceptare

### v1

- Shortcode-ul afișează formularul; emailul destinatarului nu apare în HTML.
- Destinatarul se citește din post meta `email` a postării indicate sau curente, la randare și din nou la submit.
- Rate limiting-ul funcționează per vizitator și în spatele Cloudflare sau al unui proxy; headerele falsificate sunt ignorate.
- Leadul și mesajul sunt salvate înainte de trimitere; vizitatorul primește răspuns fără să aștepte Postmark.
- Emailul este trimis prin Postmark API, iar `MessageID` se asociază leadului.
- Erorile temporare se reîncearcă automat; timeout-urile ambigue nu produc duplicate.
- Administratorul primește email la eșecuri, fără date personale.
- Leadurile eșuate se pot inspecta și relua din WP-CLI.
- Checkbox-ul GDPR este obligatoriu, cu text din setări.
- Datele unui vizitator se pot exporta și șterge din uneltele WordPress.
- Turnstile funcționează când este activat.
- Toate setările sunt în pagina de setări; secretele sunt criptate.
- Conținutul se șterge conform retenției; leadul minimal rămâne.

### v2

- Webhookul Postmark actualizează `delivery_status` și `delivered_at`.
- Evenimentele duplicate sau întârziate nu modifică stările finale.
- Conținutul se șterge imediat după `Delivery`.
- Evenimentele ratate de webhook sunt recuperate de reconciliere în cel mult o oră.
- `wp llt stats` raportează leaduri livrate și respinse.

### v3

- Tabul „Leaduri eșuate” este vizibil doar administratorilor.
- Leadurile eșuate se pot filtra, selecta (pe pagină sau toate după filtru) și retrimite fără WP-CLI.
- Retrimiterea folosește coada; nu produce duplicate și nu blochează pagina.
- Leadurile fără conținut nu pot fi retrimise și sunt semnalate clar.
- Leadurile se pot ignora și restaura; conținutul se poate șterge manual.
- Datele vizitatorului apar doar la cerere.
- Meniul afișează numărul de leaduri eșuate care necesită atenție.

---

## 22. Instalare și cerințe

### 22.1. Cerințe

| Cerință | Detalii |
| --- | --- |
| WordPress | 6.0+ |
| PHP | 7.4+, cu `sodium` (inclus implicit din PHP 7.2) |
| Bază de date | MySQL 5.7+ / MariaDB 10.3+ |
| Tip de instalare | **Site unic.** WordPress Multisite nu este suportat în această versiune (tabelele, setările și cronul ar trebui separate pe fiecare site din rețea). |
| HTTPS | Obligatoriu pentru webhook (v2) |
| Cron | Recomandat cron real de server la fiecare minut (§9.1) |

### 22.2. Configurare Postmark

1. **Domeniul expeditorului** se verifică în Postmark (Sender Signatures → Domains) cu înregistrările **DKIM** și **Return-Path** adăugate în DNS. Fără ele, emailurile ajung mai ușor în spam. Webhookul raportează corect „livrat” (serverul destinatarului a acceptat mesajul), dar asta nu garantează ajungerea în Inbox — confirmat explicit în documentația Postmark.
2. **Message Stream:** mesajele de contact sunt tranzacționale, deci se folosește streamul **`outbound`** (Default Transactional Stream), nu un stream de tip Broadcast. Pluginul verifică la „Trimite email de test” că streamul există (erorile 1235 / 1236).
3. **Cont în aprobare:** cât timp contul Postmark nu este aprobat, se poate trimite doar către adrese de pe același domeniu ca expeditorul (eroarea 412). Testele trebuie făcute cu o adresă de pe domeniul propriu.
4. **Retenție Postmark:** recomandat 7–14 zile, din setările serverului Postmark (§12).
5. **Webhook (v2):** se creează pe streamul `outbound`, cu Delivery și Bounce activate, folosind URL-ul copiat din setări, apoi se apasă **Verify**.

### 22.3. Mutarea site-ului (staging → producție)

Secretele (token Postmark, chei Turnstile, parolă webhook) sunt criptate cu o cheie derivată din salturile WordPress. Dacă site-ul este mutat pe un mediu cu alte salturi în `wp-config.php`, ele nu mai pot fi decriptate și trebuie reintroduse; pagina de setări detectează situația și afișează un avertisment.

Pentru a evita reintroducerea, se poate defini aceeași cheie pe toate mediile:

```php
define( 'LLT_ENCRYPTION_KEY', '...' ); // 32+ caractere aleatoare, identică pe staging și producție
```

Dacă `LLT_ENCRYPTION_KEY` se adaugă după ce secretele au fost salvate, acestea se reintroduc o singură dată.

### 22.4. Verificare după instalare

1. Postarea fiecărei locații are câmpul meta `email` completat cu o adresă validă.
2. Tabul **Postmark** → „Trimite email de test”.
3. Tabul **Alerte** → „Trimite alertă de test” (confirmă că `wp_mail()` funcționează).
4. Tabul **Anti-spam** → diagnosticul IP afișează IP-ul real al administratorului.
5. Tabul **Stare** → cronul a rulat în ultimele minute.
6. (v2) Postmark → Webhooks → Verify, apoi trimiterea unui lead de test și confirmarea stării `delivered` în `wp llt leads list`.

---

## 23. Surse verificate

Comportamentul Postmark și Cloudflare descris în acest document a fost verificat în documentația oficială în octombrie 2026:

- Postmark — [API overview: coduri HTTP și ErrorCode](https://postmarkapp.com/developer/api/overview)
- Postmark — [Messages API: căutare după metadata, detalii mesaj](https://postmarkapp.com/developer/api/messages-api)
- Postmark — [Bounce API: tipuri de bounce](https://postmarkapp.com/developer/api/bounce-api)
- Postmark — [Webhooks overview: autentificare, reîncercări, verificare](https://postmarkapp.com/developer/webhooks/webhooks-overview)
- Postmark — [Delivery webhook](https://postmarkapp.com/developer/webhooks/delivery-webhook)
- Postmark — [Bounce webhook](https://postmarkapp.com/developer/webhooks/bounce-webhook)
- Postmark — [IP-uri pentru firewall (webhookuri)](https://postmarkapp.com/support/article/800-ips-for-firewalls)
- Cloudflare — [IP Ranges](https://www.cloudflare.com/ips/)
