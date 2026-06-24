# Anschlüsse — DB connection / waiting board

A small PHP application that shows, for **any German station**, which **outbound** trains
are currently **waiting** for which **inbound** trains, alongside live departure and arrival
boards. Pick a station with the search box; the **default station is configurable**
(`station:` in `config.yaml`, **Frankfurt (Main) Hbf** out of the box), and the decision
log (below) is kept only for that default station.

It reads the [DB Timetables API (IRIS)](https://developers.deutschebahn.com/db-api-marketplace/apis/product/timetables),
merges the planned timetable (`/plan`) with the live change feed (`/fchg`), and reads the
DB connection elements (`<conn>`) that encode "this departure waits for that arrival".
Station search/autocomplete is backed by the [StaDa – Station Data](https://developers.deutschebahn.com/db-api-marketplace/apis/product/stada)
API plus a bundled offline directory.

## How "waiting" is determined

DB exposes wait decisions as `<conn>` elements nested inside a stop. The `cs`
(connection status) attribute is the key:

| `cs` | Meaning                          | Shown as           |
|------|----------------------------------|--------------------|
| `w`  | departure **waits** for the train | *wartet*           |
| `n`  | connection **broken** / no longer waiting | *wartet nicht mehr* |
| `a`  | an **alternative** connection is offered | *Alternative*      |

These elements only appear while a decision is actively in effect — typically during
delays. When none are active, the page says so and still shows the live departure and
arrival boards for context. The page auto-refreshes every 60 seconds; the board result is
cached server-side for ~45 s (`find_cached`) so the refresh doesn't re-issue ~9 API calls
each time. The minute poller bypasses that cache to stay live.

## Live board details

The departure/arrival boards mirror what the official station board shows during
disruptions:

- **Cancellations** — a stop with `cs="c"` is shown as *fällt aus* (struck-through) and
  excluded from the waiting logic.
- **Disruption banner** — distinct HIM messages (`t="h"`: *Großstörung*, *Störung*,
  *Bauarbeiten*) are surfaced at the top, ranked by severity, with *Information* notices
  collapsed to a count. The Timetables API carries no text for these, only a category +
  validity, so each notice is **expandable** (`<details>`) to show its full validity window,
  priority, last update, and the **trains it currently affects** at this station.
- **Delay causes** — the numeric delay-cause codes (`<m t="d">`) on each train are
  translated to plain text (e.g. *Bauarbeiten*, *Defekt an der Strecke*) and shown as a
  subline under the train (see `MessageCatalog`). This is the "why" behind a delay/cancellation.
- **Delayed-train window** — the board spans a few hours *back* as well as forward and is
  keyed by *expected* time, so a train planned hours ago but running now still appears.
- **Wing trains** — coupled units (a `wings` reference) are shown together, e.g.
  `ICE 2592 / ICE 2840`.
- **Split stations** — some stations expose their IRIS timetable under a sibling EVA
  (e.g. Berlin Hbf: StaDa says `8011160`, but the board lives under `8098160`). The
  requested EVA is probed and, if empty, resolved to the main-line sibling (preferring
  non-S-Bahn trains); the mapping is cached under `data/cache/eva/`.

## Configuration

Credentials for the DB API live in `config.yaml` (DB API Marketplace client id + key).
Copy the template and fill it in — `config.yaml` is git-ignored so the secrets stay out of
the repo:

```bash
cp config.yaml.example config.yaml
```

```yaml
backend:
  clientid: <your-client-id>
  apikey: <your-api-key>

# Default / reference station (shown on first load; the only station the
# decision log is kept for). Optional — defaults to Frankfurt (Main) Hbf.
station:
  name: "Frankfurt (Main) Hbf"
  eva: 8000105
```

## Requirements

- PHP 8.1+
- `ext-simplexml` (required — XML parsing)
- `ext-curl` (recommended; falls back to `file_get_contents` if `allow_url_fopen=On`)
- `ext-yaml` (optional; a built-in minimal YAML reader is used otherwise)

On Debian/Ubuntu: `sudo apt-get install php-xml php-curl php-yaml`

## Install

```bash
cp config.yaml.example config.yaml     # then fill in your DB API credentials
php bin/update-stations.php             # generate the offline station fallback
```

`resources/stations.tsv` is **generated**, not committed — run `bin/update-stations.php`
once at install (and whenever you want to refresh it). The app still runs without it
(autocomplete then relies on the live StaDa API only), but the offline fallback and the
`station_directory_test` need it.

## Web root / security

**`public/` is the only web-served directory.** Everything else — `config.yaml`,
`credentials`, `src/`, `bin/`, `tests/` — lives *above* the web root and must never be
served. This keeps the API credentials unreachable over HTTP.

- `bin/serve.sh` pins the dev server's document root to `public/`.
- `public/.htaccess` grants web access for that directory.
- `.htaccess` in the project root denies all access as defense-in-depth, in case a
  server is ever misconfigured to point its `DocumentRoot` at the project root.

Apache (`DocumentRoot` → the `public/` directory):

```apache
DocumentRoot /home/entwickler/bahnapi/public
<Directory /home/entwickler/bahnapi/public>
    Require all granted
</Directory>
```

nginx:

```nginx
server {
    root /home/entwickler/bahnapi/public;   # NOT the project root
    index index.php;
    location ~ \.php$ { fastcgi_pass unix:/run/php/php-fpm.sock; include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; }
}
```

## Run

### Web UI

```bash
bin/serve.sh                 # serves public/ at http://127.0.0.1:8077/
bin/serve.sh 0.0.0.0:9000    # custom host:port
```

Pick **any German station** with the search box in the header. As you type, a dropdown
proposes matching stations and the box also remembers your **recently selected** stations
(stored in the browser's `localStorage`, shown on focus). You can also pass a name or EVA
number via the URL (`?station=Leipzig%20Hbf`, `?eva=8011160`). The decision log below is kept
and shown **only for the configured default station** (Frankfurt (Main) Hbf by default) — other stations show live boards only.

### Station search & caching

Autocomplete is served by `public/api/stations.php` (`GET ?q=…` → JSON `[{name, eva, city}]`).
`StationSuggester` **merges two sources**, de-duplicated by EVA:

1. The DB **StaDa – Station Data** API (`StadaClient`) — authoritative name + city — with a
   **local file cache** under `data/cache/stations/` so repeated/keystroke queries don't
   hammer the rate limit. Cache entries live for 7 days (bounded to 2,000 entries, oldest
   evicted); a no-match (HTTP 404) is cached too, and a stale entry is reused if the API is
   temporarily unavailable. The query length is capped server-side.
2. The bundled offline directory (`resources/stations.tsv`, ~5,400 stations) — carries
   traffic-based **importance weights**, so merging guarantees **top stations rank first**
   even when StaDa's ordering would bury them. It also covers StaDa outages and
   ASCII-spelled umlauts (`koeln`), which StaDa itself does not fold.

Picking a suggestion submits its EVA number directly (`?eva=…`), so resolution is exact.

#### Refreshing the offline fallback

```bash
php bin/update-stations.php            # regenerate resources/stations.tsv from the open dataset
php bin/update-stations.php <url>      # or from a custom source URL
```

The script downloads the open [`db-stations`](https://github.com/derhuerst/db-stations)
dataset and rewrites `resources/stations.tsv` atomically (a failed run leaves the existing
file untouched).

### Command line

```bash
php bin/show.php                 # default station (from config.yaml)
php bin/show.php "Leipzig Hbf"   # any German station
```

### Logger (decision history)

A poller records every connection (waiting) decision for the **configured default station** once per minute and
detects **revisions** — when a decision changes (e.g. `wartet` → `wartet nicht`) or is
withdrawn (`beendet`). The history is shown in the web UI under *"Protokoll der
Warteentscheidungen"* (only for the default station).

```bash
bin/poll-loop.sh                 # poll the default station every 60s
php bin/poll.php                 # a single poll (for cron)
```

Cron alternative (every minute):

```cron
* * * * * php /home/entwickler/bahnapi/bin/poll.php >> /home/entwickler/bahnapi/data/poll.log 2>&1
```

The log is stored as JSON in `data/connection_log.json` (created automatically, **outside
the web root**). Each decision keeps a `history` of `{time, status}` entries; a record with
more than one entry was revised. Records are pruned after 365 days.

### Tests

An offline test exercises the connection logic against a fixture (no API/no live delay
needed):

```bash
php tests/waiting_connection_test.php
```

## Layout

```
.htaccess                         Deny-all (project root must not be served)
config.yaml                       DB API credentials  (outside web root)
credentials                       (outside web root)
public/                           === WEB ROOT ===
  index.php                       Web UI (only file served over HTTP)
  .htaccess                       Allow access to this directory
resources/stations.tsv            ~5,400 German stations (generated, git-ignored)
data/                             Decision log + poll log + station cache (outside web root)
  connection_log.json
  cache/stations/                 Cached StaDa search responses (auto-created)
  cache/board/                    Short-TTL board snapshots (auto-created)
  cache/eva/                      Resolved board-EVA mappings (auto-created)
public/
  index.php                       Web UI
  api/stations.php                Station autocomplete endpoint
  .htaccess                       Allow access to this directory
bin/
  serve.sh                        Dev server, document root pinned to public/
  show.php                        CLI
  poll.php                        Single poll -> records decisions (default station)
  poll-loop.sh                    Polls every 60s
  update-stations.php             Regenerate resources/stations.tsv
tests/
  waiting_connection_test.php     Offline test of the waiting logic
  connection_log_test.php         Offline test of the decision log + revisions
  station_directory_test.php      Offline test of station search/resolve
  stada_client_test.php           Offline test of StaDa ranking + caching
  station_suggester_test.php      Offline test of the StaDa+offline merge ranking
  board_features_test.php         Offline test of cancellations/window/wings/messages
  board_cache_test.php            Offline test of the short-TTL board cache
  board_eva_resolution_test.php   Offline test of split-station EVA resolution
src/
  bootstrap.php                   Autoloader + factories
  Config.php                      Reads config.yaml
  Http.php                        Minimal HTTP GET helper
  TimetablesSource.php            API abstraction (interface)
  TimetablesClient.php            DB Timetables API client
  ConnectionFinder.php            Merges plan + changes, extracts connections
  ConnectionLog.php               Persistent decision log + revision detection
  MessageCatalog.php              DB delay-cause code -> German text
  StationDirectory.php            Offline station search / resolution (fallback)
  StadaClient.php                 DB StaDa station search + local cache
  StationSuggester.php            Merges StaDa + offline hits, ranks top stations first
  Train.php                       One arrival/departure event
  WaitingConnection.php           Outbound + inbound + status
```

The station list in `resources/stations.tsv` is derived from the open
[`db-stations`](https://github.com/derhuerst/db-stations) dataset (DB station data).

## License

Copyright (C) 2026 michael-dev

Licensed under the **GNU Affero General Public License v3.0** — see [LICENSE](LICENSE).
Because the AGPL covers network use, anyone who runs a modified version of this app as a
network service must also offer users the corresponding source.
