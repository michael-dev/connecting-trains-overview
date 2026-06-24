<?php
declare(strict_types=1);

use function Bahn\make_finder;

require __DIR__ . '/../src/bootstrap.php';

/* ----- helpers ----- */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
function fmt(?DateTimeImmutable $t): string
{
    return $t ? $t->format('H:i') : '--:--';
}
/** @param Bahn\Train $t */
function delayBadge(Bahn\Train $t): string
{
    $d = $t->delayMinutes();
    if ($d === null || $d <= 0) {
        return '<span class="ok">pünktlich</span>';
    }
    $cls = $d >= 5 ? 'late' : 'slight';
    return '<span class="' . $cls . '">+' . $d . '</span>';
}
/** Render a platform, showing planned → new when the track was switched. */
function platformHtml(Bahn\Train $t): string
{
    $cur = $t->platform();
    if ($cur === '') {
        return '';
    }
    if ($t->platformChanged()) {
        return '<span class="track-switch" title="Gleiswechsel">'
            . '<span class="track-old">' . e($t->plannedPlatform) . '</span>'
            . '<span class="track-arrow">→</span>'
            . '<span class="track-new">' . e($cur) . '</span></span>';
    }
    return e($cur);
}
/** Format an ISO-8601 timestamp as HH:MM. */
function logTime(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    try {
        return (new DateTimeImmutable($iso))->format('H:i');
    } catch (Throwable) {
        return '';
    }
}
/** Status chip (reuses the legend chip styles, incl. the "ended" state). */
function statusChip(string $code): string
{
    return '<span class="chip ' . e(Bahn\ConnectionLog::classFor($code)) . '">'
        . e(Bahn\ConnectionLog::labelFor($code)) . '</span>';
}
/** Render a decision's status history as a chain: chip (time) -> chip (time). */
function historyChain(array $history): string
{
    $parts = [];
    foreach ($history as $h) {
        $parts[] = statusChip((string) ($h['status'] ?? ''))
            . ' <span class="hts">' . e(logTime($h['ts'] ?? null)) . '</span>';
    }
    return implode(' <span class="harr">→</span> ', $parts);
}

/* ----- data ----- */
$cfgPath = __DIR__ . '/../config.yaml';
try {
    $cfg            = Bahn\Config::load($cfgPath);
    $defaultName    = $cfg->defaultStationName();
    $defaultEva     = $cfg->defaultEva();
    $refreshSeconds = $cfg->boardRefreshSeconds();
    $boardCacheTtl  = $cfg->boardCacheTtl();
} catch (Throwable) {
    $defaultName    = Bahn\DEFAULT_STATION;
    $defaultEva     = Bahn\DEFAULT_EVA;
    $refreshSeconds = 60;
    $boardCacheTtl  = 45;
}

$station  = substr(trim((string) ($_GET['station'] ?? '')), 0, 60);
$evaParam = trim((string) ($_GET['eva'] ?? ''));
if ($station === '' && $evaParam === '') {
    $station = $defaultName;
}

$error = null;
$data  = null;
$eva   = null;
try {
    $finder = make_finder($cfgPath);

    if ($evaParam !== '' && ctype_digit($evaParam)) {
        // A suggestion was picked — resolve directly by its EVA number.
        $eva = (int) $evaParam;
        if ($station === '') {
            $station = (new Bahn\StationDirectory(__DIR__ . '/../resources/stations.tsv'))
                ->resolve($evaParam)['name'] ?? ('EVA ' . $eva);
        }
    } elseif ($station === $defaultName) {
        // The default station is known — no lookup needed.
        $eva = $defaultEva;
    } else {
        // Free-text entry: bundled directory first (exact name/EVA), then the
        // live timetables resolver as a fallback for anything not bundled.
        $resolved = (new Bahn\StationDirectory(__DIR__ . '/../resources/stations.tsv'))->resolve($station);
        if ($resolved !== null) {
            $eva     = $resolved['eva'];
            $station = $resolved['name'];
        } else {
            $eva = $finder->resolveEva($station);
        }
    }

    if ($eva === null) {
        $error = "Station „{$station}“ konnte nicht gefunden werden.";
    } else {
        $data = Bahn\find_cached($finder, $eva, __DIR__ . '/../data/cache/board', $boardCacheTtl);
    }
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}

// The decision log is kept and shown only for the configured default station,
// identified by EVA so it holds regardless of how the station was entered.
$isDefaultStation = ($eva === $defaultEva);
$logRecords = [];
$lastPoll   = null;
$pollStale  = false;
if ($isDefaultStation) {
    try {
        $logData = (new Bahn\ConnectionLog(__DIR__ . '/../data/connection_log.json'))->load();
    } catch (Throwable) {
        $logData = ['lastPoll' => null, 'records' => []];
    }
    $logRecords = array_values($logData['records']);
    usort($logRecords, fn (array $a, array $b): int => strcmp((string) ($b['lastSeen'] ?? ''), (string) ($a['lastSeen'] ?? '')));
    $lastPoll  = $logData['lastPoll'] ?? null;
    $pollStale = $lastPoll !== null && (time() - (int) strtotime($lastPoll)) > 150;
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="<?= (int) $refreshSeconds ?>">
<title>Anschlüsse · <?= e($station) ?></title>
<style>
  :root { --db:#ec0016; --ink:#1a1a1a; --muted:#6b7280; --line:#e5e7eb; --bg:#f5f6f8; }
  * { box-sizing:border-box; }
  body { margin:0; font:15px/1.45 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
         color:var(--ink); background:var(--bg); }
  header { background:var(--db); color:#fff; padding:18px 24px; }
  header h1 { margin:0; font-size:20px; }
  header .sub { opacity:.9; font-size:13px; margin-top:4px; }
  .station-form { margin-top:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
  .combo { position:relative; }
  .combo input { padding:8px 11px; border:0; border-radius:6px; font-size:14px; width:320px; max-width:80vw; }
  .station-form button { padding:8px 14px; border:0; border-radius:6px; background:#fff; color:var(--db);
                         font-weight:700; font-size:14px; cursor:pointer; }
  .station-form .reset { color:#fff; font-size:13px; text-decoration:none; opacity:.9; }
  .station-form .reset:hover { text-decoration:underline; }
  .suggest { position:absolute; z-index:30; top:calc(100% + 4px); left:0; width:360px; max-width:90vw;
             margin:0; padding:4px; list-style:none; background:#fff; color:var(--ink);
             border:1px solid var(--line); border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.18);
             max-height:340px; overflow:auto; }
  .suggest[hidden] { display:none; }
  .suggest .head { padding:6px 10px 4px; font-size:11px; text-transform:uppercase; letter-spacing:.05em;
                   color:var(--muted); }
  .suggest li.opt { padding:8px 10px; border-radius:6px; cursor:pointer; display:flex; justify-content:space-between;
                    gap:10px; align-items:baseline; }
  .suggest li.opt .nm { font-weight:600; }
  .suggest li.opt .ct { color:var(--muted); font-size:12px; white-space:nowrap; }
  .suggest li.opt.active, .suggest li.opt:hover { background:#fdeaea; }
  .suggest li.empty { padding:8px 10px; color:var(--muted); font-size:13px; }
  main { max-width:1000px; margin:0 auto; padding:24px 16px 64px; }
  h2 { font-size:17px; margin:28px 0 12px; }
  .note { background:#fff; border:1px solid var(--line); border-left:4px solid var(--db);
          padding:14px 16px; border-radius:6px; color:var(--muted); }
  .muted-h { color:var(--muted); font-weight:400; }
  .legend { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
  .chip { font-size:12px; padding:3px 10px; border-radius:999px; border:1px solid transparent; }
  .chip.wait   { background:#e6f4ea; color:#1a7f37; border-color:#bfe3c9; }
  .chip.alt    { background:#fdf2e0; color:#9a6700; border-color:#f0dcb0; }
  .chip.broken { background:#fdeaea; color:#a40000; border-color:#f3c2c2; }
  .chip.ended  { background:#eef0f2; color:#555; border-color:#d8dbdf; }
  .chip.unknown{ background:#eef0f2; color:#555; border-color:#d8dbdf; }
  .log-meta { color:var(--muted); font-size:13px; margin-bottom:10px; }
  .warn { color:var(--db); font-weight:600; }
  table.log td { vertical-align:top; }
  .muted2 { color:var(--muted); font-size:12px; }
  .chain { white-space:normal; line-height:2; }
  .hts { color:var(--muted); font-size:12px; }
  .harr { color:var(--muted); }
  .revtag { display:inline-block; margin-left:4px; font-size:11px; font-weight:700; color:#b7791f;
            background:#fdf2e0; border-radius:999px; padding:1px 7px; }
  tr.revised td { background:#fffdf5; }
  .conn { background:#fff; border:1px solid var(--line); border-left:5px solid var(--muted);
          border-radius:10px; padding:14px 16px; margin-bottom:12px; }
  .conn-row { display:grid; grid-template-columns:1fr auto 1fr; gap:12px; align-items:center; }
  .conn.wait   { border-left-color:#1a7f37; }
  .conn.alt    { border-left-color:#b7791f; }
  .conn.broken { border-left-color:var(--db); }
  .train .name { font-weight:700; }
  .train .name .cat { color:var(--db); }
  .train .meta { color:var(--muted); font-size:13px; }
  .rel { text-align:center; white-space:nowrap; }
  .rel .badge { display:inline-block; font-size:11px; font-weight:700; text-transform:uppercase;
                letter-spacing:.04em; padding:2px 9px; border-radius:999px; }
  .conn.wait   .badge { background:#e6f4ea; color:#1a7f37; }
  .conn.alt    .badge { background:#fdf2e0; color:#9a6700; }
  .conn.broken .badge { background:#fdeaea; color:#a40000; }
  .rel .rel-text { display:block; font-size:12px; color:var(--muted); margin-top:3px; }
  .rel .icon { display:block; font-size:20px; line-height:1; margin-top:3px; }
  .conn.wait   .icon { color:#1a7f37; }
  .conn.alt    .icon { color:#b7791f; }
  .conn.broken .icon { color:var(--db); }
  .conn.broken .rel .rel-text { text-decoration:line-through; }
  .conn-note { margin-top:10px; padding-top:9px; border-top:1px dashed var(--line);
               font-size:13px; color:var(--muted); }
  table { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--line); border-radius:8px; overflow:hidden; }
  th,td { text-align:left; padding:8px 12px; border-bottom:1px solid var(--line); font-size:14px; }
  th { background:#fafafa; color:var(--muted); font-weight:600; }
  tr:last-child td { border-bottom:0; }
  .ok { color:#1a7f37; } .slight { color:#b7791f; } .late { color:var(--db); font-weight:700; }
  .track-old { text-decoration:line-through; color:var(--muted); }
  .track-arrow { margin:0 3px; color:var(--db); }
  .track-new { color:var(--db); font-weight:700; }
  .cols { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
  @media (max-width:760px){ .conn{grid-template-columns:1fr;} .cols{grid-template-columns:1fr;} }
  .err { background:#fff3f3; border:1px solid #f3c2c2; color:#a40000; padding:14px 16px; border-radius:8px; }
  .banner { margin:0 0 18px; display:flex; flex-direction:column; gap:6px; }
  .msg { padding:10px 14px; border-radius:8px; font-size:14px; border:1px solid; display:flex; gap:10px; align-items:baseline; }
  .msg .tag { font-weight:700; }
  .msg .val { font-size:12.5px; opacity:.85; }
  .msg.crit { background:#fdeaea; border-color:#f3c2c2; color:#a40000; }
  .msg.warn { background:#fdf6e3; border-color:#f0dcb0; color:#8a5a00; }
  .msg.info { background:#eef1f5; border-color:#dde2e8; color:#41506b; }
  details.msg > summary { cursor:pointer; list-style:none; display:flex; gap:10px; align-items:baseline; }
  details.msg > summary::-webkit-details-marker { display:none; }
  details.msg > summary::after { content:'▸'; margin-left:auto; opacity:.6; }
  details.msg[open] > summary::after { content:'▾'; }
  .msg-detail { margin-top:8px; padding-top:8px; border-top:1px solid rgba(0,0,0,.08); font-size:13px; }
  .msg-detail > div { margin:2px 0; }
  .reasons { color:var(--muted); font-size:12px; margin-top:2px; font-weight:400; }
  .cancelled { color:var(--db); font-weight:700; text-transform:uppercase; font-size:12px; letter-spacing:.03em; }
  tr.cancelled-row td { background:#fff6f6; }
  tr.cancelled-row s { color:var(--muted); }
  footer { color:var(--muted); font-size:12px; text-align:center; margin-top:32px; }
</style>
</head>
<body>
<header>
  <h1>Anschlüsse — <?= e($station) ?></h1>
  <div class="sub">
    Welche abfahrenden Züge warten auf welche ankommenden Züge.
    <?php if ($data): ?>· Stand <?= e($data['generatedAt']->format('d.m.Y H:i')) ?> · EVA <?= (int) $data['eva'] ?><?php endif ?>
  </div>
  <form class="station-form" method="get" action="">
    <div class="combo">
      <input type="text" name="station" id="stationInput" value="<?= e($station) ?>"
             placeholder="Bahnhof suchen… (z. B. Berlin, München, Köln)"
             aria-label="Bahnhof" autocomplete="off" role="combobox"
             aria-expanded="false" aria-controls="suggest" aria-autocomplete="list">
      <input type="hidden" name="eva" id="stationEva" value="">
      <ul class="suggest" id="suggest" role="listbox" hidden></ul>
    </div>
    <button type="submit">anzeigen</button>
    <?php if (!$isDefaultStation): ?>
      <a class="reset" href="?">↺ <?= e($defaultName) ?></a>
    <?php endif ?>
  </form>
</header>
<main>

<?= $data ? renderMessages($data['messages']) : '' ?>

<?php if ($error): ?>
  <div class="err"><strong>Fehler:</strong> <?= e($error) ?></div>
<?php else: ?>

  <h2>Anschlüsse <span class="muted-h">— Warteentscheidungen</span></h2>
  <div class="legend">
    <span class="chip wait">wartet — Anschluss wird gehalten</span>
    <span class="chip alt">Alternative — Ersatzanschluss</span>
    <span class="chip broken">wartet nicht — Anschluss gebrochen</span>
  </div>
  <?php $waiting = $data['waiting']; ?>
  <?php if ($waiting === []): ?>
    <div class="note">
      Aktuell meldet die DB für <?= e($station) ?> keine Anschluss-Entscheidungen.
      Ob ein abfahrender Zug auf einen ankommenden <strong>wartet</strong>,
      <strong>nicht (mehr) wartet</strong> oder ein <strong>Alternativanschluss</strong>
      angeboten wird, liefert die API nur, solange eine solche Entscheidung aktiv ist
      (typisch bei Verspätungen) — die Seite aktualisiert sich automatisch jede Minute.
    </div>
  <?php else: foreach ($waiting as $c):
        /** @var Bahn\WaitingConnection $c */ ?>
    <div class="conn <?= e($c->statusClass()) ?>">
      <div class="conn-row">
        <div class="train">
          <div class="name"><span class="cat"><?= e($c->outbound->name()) ?></span>
            → <?= e($c->outbound->destination() ?: '–') ?></div>
          <div class="meta">
            ab <?= fmt($c->outbound->time()) ?> <?= delayBadge($c->outbound) ?>
            <?php if ($c->outbound->platform() !== ''): ?>· Gl. <?= platformHtml($c->outbound) ?><?php endif ?>
          </div>
        </div>
        <div class="rel">
          <span class="badge"><?= e($c->statusLabel()) ?></span>
          <span class="rel-text"><?= e($c->relationLabel()) ?></span>
          <span class="icon"><?= e($c->statusIcon()) ?></span>
        </div>
        <div class="train">
          <div class="name"><span class="cat"><?= e($c->inbound->name()) ?></span>
            <?php if ($c->inbound->origin() !== ''): ?>aus <?= e($c->inbound->origin()) ?><?php endif ?></div>
          <div class="meta">
            an <?= fmt($c->inbound->time()) ?> <?= delayBadge($c->inbound) ?>
            <?php if ($c->inbound->platform() !== ''): ?>· Gl. <?= platformHtml($c->inbound) ?><?php endif ?>
          </div>
        </div>
      </div>
      <div class="conn-note"><?= e($c->statusSentence()) ?></div>
    </div>
  <?php endforeach; endif ?>

  <div class="cols">
    <div>
      <h2>Abfahrten</h2>
      <?= renderBoard($data['departures'], true) ?>
    </div>
    <div>
      <h2>Ankünfte</h2>
      <?= renderBoard($data['arrivals'], false) ?>
    </div>
  </div>

<?php endif ?>

  <?php if ($isDefaultStation): ?>
  <h2>Protokoll der Warteentscheidungen</h2>
  <div class="log-meta">
    <?php if ($lastPoll !== null): ?>
      Letzter Poll: <?= e(logTime($lastPoll)) ?> Uhr
      <?php if ($pollStale): ?><span class="warn">· Poller scheint nicht zu laufen</span><?php endif ?>
    <?php else: ?>
      <span class="warn">Noch kein Poll — Logger starten mit <code>bin/poll-loop.sh</code></span>
    <?php endif ?>
    · <?= count($logRecords) ?> protokollierte Entscheidung(en)
  </div>
  <?php if ($logRecords === []): ?>
    <div class="note">Noch keine Entscheidungen protokolliert. Der Logger pollt minütlich und
      hält jede Warteentscheidung samt Revisionen fest.</div>
  <?php else: ?>
    <table class="log">
      <thead><tr><th>Abfahrt</th><th>Anschluss an</th><th>Status</th><th>Verlauf (Revisionen)</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($logRecords, 0, 60) as $r):
            $revised = count($r['history'] ?? []) > 1; ?>
        <tr class="<?= $revised ? 'revised' : '' ?>">
          <td><strong><?= e($r['outbound']['name'] ?? '') ?></strong> → <?= e($r['outbound']['place'] ?? '–') ?>
            <div class="muted2"><?= e(logTime($r['outbound']['planned'] ?? null)) ?> Uhr</div></td>
          <td><strong><?= e($r['inbound']['name'] ?? '') ?></strong>
            <?php if (($r['inbound']['place'] ?? '') !== ''): ?>aus <?= e($r['inbound']['place']) ?><?php endif ?></td>
          <td><?= statusChip((string) ($r['status'] ?? '')) ?>
            <?php if ($revised): ?><span class="revtag">revidiert</span><?php endif ?></td>
          <td class="chain"><?= historyChain($r['history'] ?? []) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>
  <?php endif // $isDefaultStation ?>

<footer>Quelle: DB Timetables API (IRIS) · Seite aktualisiert sich automatisch.<?php if (!$isDefaultStation): ?>
  · Das Protokoll der Warteentscheidungen wird nur für <?= e($defaultName) ?> geführt.<?php endif ?></footer>
</main>
<script>
(function () {
  var form  = document.querySelector('.station-form');
  var input = document.getElementById('stationInput');
  var eva   = document.getElementById('stationEva');
  var box   = document.getElementById('suggest');
  if (!form || !input || !eva || !box) { return; }

  var RECENT_KEY = 'bahn.recentStations', RECENT_MAX = 8;
  var items = [], active = -1, timer = null, lastQ = null;

  function recents() {
    try { return JSON.parse(localStorage.getItem(RECENT_KEY)) || []; } catch (e) { return []; }
  }
  function remember(st) {
    var list = recents().filter(function (r) { return r.eva !== st.eva; });
    list.unshift({ name: st.name, eva: st.eva, city: st.city || '' });
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, RECENT_MAX))); } catch (e) {}
  }

  function close() { box.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function render(list, headLabel) {
    items = list;
    box.innerHTML = '';
    if (headLabel) {
      var h = document.createElement('li'); h.className = 'head'; h.textContent = headLabel; box.appendChild(h);
    }
    if (!list.length) {
      var e = document.createElement('li'); e.className = 'empty'; e.textContent = 'Keine Treffer';
      box.appendChild(e); box.hidden = false; input.setAttribute('aria-expanded', 'true'); return;
    }
    list.forEach(function (it, i) {
      var li = document.createElement('li');
      li.className = 'opt'; li.setAttribute('role', 'option'); li.dataset.i = i;
      li.innerHTML = '<span class="nm">' + esc(it.name) + '</span>' +
                     (it.city ? '<span class="ct">' + esc(it.city) + '</span>' : '');
      li.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(it); });
      box.appendChild(li);
    });
    active = -1;
    box.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function highlight(d) {
    var opts = box.querySelectorAll('li.opt');
    if (!opts.length) { return; }
    active = (active + d + opts.length) % opts.length;
    opts.forEach(function (o, i) { o.classList.toggle('active', i === active); });
    opts[active].scrollIntoView({ block: 'nearest' });
  }

  function choose(it) {
    input.value = it.name; eva.value = it.eva; remember(it); close(); form.submit();
  }

  function showRecents() {
    var r = recents();
    if (r.length) { render(r, 'Zuletzt gewählt'); } else { close(); }
  }

  input.addEventListener('input', function () {
    eva.value = ''; // typing invalidates a previous pick
    var q = input.value.trim();
    if (q.length < 2) { lastQ = null; showRecents(); return; }
    if (q === lastQ) { return; }
    lastQ = q;
    clearTimeout(timer);
    timer = setTimeout(function () {
      fetch('api/stations.php?q=' + encodeURIComponent(q))
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (list) { if (input.value.trim() === q) { render(list, null); } })
        .catch(function () {});
    }, 160);
  });

  input.addEventListener('focus', function () { if (input.value.trim().length < 2) { showRecents(); } });

  input.addEventListener('keydown', function (ev) {
    if (box.hidden) { return; }
    if (ev.key === 'ArrowDown') { ev.preventDefault(); highlight(1); }
    else if (ev.key === 'ArrowUp') { ev.preventDefault(); highlight(-1); }
    else if (ev.key === 'Enter') {
      if (active >= 0 && items[active]) { ev.preventDefault(); choose(items[active]); }
    } else if (ev.key === 'Escape') { close(); }
  });

  document.addEventListener('click', function (ev) {
    if (!form.contains(ev.target)) { close(); }
  });
})();
</script>
</body>
</html>
<?php

/** @param Bahn\Train[] $trains */
function renderBoard(array $trains, bool $isDeparture): string
{
    if ($trains === []) {
        return '<div class="note">Keine Daten im betrachteten Zeitfenster.</div>';
    }
    $dirLabel = $isDeparture ? 'Ziel' : 'Von';
    $rows = '';
    foreach (array_slice($trains, 0, 40) as $t) {
        $where = $isDeparture ? $t->destination() : $t->origin();
        if ($t->isCancelled()) {
            $timeCell = '<s>' . fmt($t->time()) . '</s> <span class="cancelled">fällt aus</span>';
            $rows .= '<tr class="cancelled-row">';
        } else {
            $timeCell = fmt($t->time()) . ' ' . delayBadge($t);
            $rows .= '<tr>';
        }
        $reasons = $t->reasons !== []
            ? '<div class="reasons">' . e(implode(' · ', array_slice(array_unique($t->reasons), 0, 3))) . '</div>'
            : '';
        $rows .= '<td>' . $timeCell . '</td>'
            . '<td><strong>' . e($t->fullName()) . '</strong>' . $reasons . '</td>'
            . '<td>' . e($where ?: '–') . '</td>'
            . '<td>' . ($t->isCancelled() ? '' : platformHtml($t)) . '</td>'
            . '</tr>';
    }
    return '<table><thead><tr><th>Zeit</th><th>Zug</th><th>' . $dirLabel
        . '</th><th>Gl.</th></tr></thead><tbody>' . $rows . '</tbody></table>';
}

/**
 * Disruption banner from the HIM messages (the API carries no text, only a
 * category + validity, so we show those). Information notices are collapsed.
 *
 * @param array<int,array{cat:string,from:?DateTimeImmutable,to:?DateTimeImmutable,priority:int}> $messages
 */
function renderMessages(array $messages): string
{
    $classOf = ['Großstörung' => 'crit', 'Störung' => 'crit', 'Bauarbeiten' => 'warn'];
    $prominent = [];
    $infoCount = 0;
    foreach ($messages as $m) {
        if (($m['cat'] ?? '') === 'Information') {
            $infoCount++;
        } else {
            $prominent[] = $m;
        }
    }
    if ($prominent === [] && $infoCount === 0) {
        return '';
    }
    $span = function (?DateTimeImmutable $from, ?DateTimeImmutable $to): string {
        $f = fn (?DateTimeImmutable $d) => $d ? $d->format('d.m. H:i') : '';
        if ($from && $to) {
            return 'gültig ' . $f($from) . ' – ' . $f($to) . ' Uhr';
        }
        return $from ? 'seit ' . $f($from) . ' Uhr' : '';
    };
    $fdt = fn (?DateTimeImmutable $d): string => $d ? $d->format('d.m. H:i') : '';
    $html = '<div class="banner">';
    foreach (array_slice($prominent, 0, 8) as $m) {
        $cls = $classOf[$m['cat']] ?? 'info';
        $detail = '<div class="msg-detail">';
        $detail .= '<div>Priorität ' . (int) $m['priority'] . '</div>';
        if (!empty($m['updated'])) {
            $detail .= '<div>aktualisiert ' . e($fdt($m['updated'])) . ' Uhr</div>';
        }
        $count = (int) ($m['affectedCount'] ?? 0);
        if ($count > 0) {
            $list = array_slice($m['affected'], 0, 15);
            $detail .= '<div><strong>Betrifft ' . $count . ' ' . ($count === 1 ? 'Zug' : 'Züge')
                . ' an dieser Station:</strong> '
                . e(implode(', ', $list)) . ($count > 15 ? ' …' : '') . '</div>';
        }
        $detail .= '</div>';
        $html .= '<details class="msg ' . $cls . '"><summary>⚠ <span class="tag">' . e($m['cat'])
            . '</span> <span class="val">' . e($span($m['from'], $m['to'])) . '</span></summary>'
            . $detail . '</details>';
    }
    if ($infoCount > 0) {
        $html .= '<div class="msg info">ℹ <span class="tag">' . $infoCount
            . ' weitere Hinweise</span></div>';
    }
    return $html . '</div>';
}
