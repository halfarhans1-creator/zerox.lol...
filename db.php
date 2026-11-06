<?php
// Storage API for zerox.gg: profiles, view counters, staff, uploaded files (PHP version).
require __DIR__ . '/lib.php';
cors();
register_shutdown_function(function () { if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); maybe_sync(); });

$method = $_SERVER['REQUEST_METHOD'];
$me = substr(isset($_SERVER['HTTP_X_ME']) ? $_SERVER['HTTP_X_ME'] : '', 0, 64);
$tok = (string)cfg('ADMIN_TOKEN');
$admin = $tok !== '' && hash_equals($tok, isset($_SERVER['HTTP_X_ADMIN']) ? (string)$_SERVER['HTTP_X_ADMIN'] : '');
$ip = client_ip();
$COLS = ['profiles', 'seen', 'staff', 'disc'];

function visible($d, $me, $admin) { return $d && is_object($d) && empty($d->deleted) && (!isset($d->pub) || $d->pub !== false || $admin || ($me !== '' && isset($d->owner) && $d->owner === $me)); }
function pub_doc($c, $d, $me) { if ($c === 'profiles' && is_object($d) && !empty($d->owner) && $d->owner !== $me) { $x = clone $d; $x->owner = oh($d->owner); return $x; } return $d; }
function ctype() { return strtolower(trim(explode(';', isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '')[0])); }
function range_of($total, $cap) {
  if (!preg_match('/^bytes=(\d*)-(\d*)$/', isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : '', $m) || ($m[1] === '' && $m[2] === '')) return null;
  $a = $m[1] === '' ? max(0, $total - (int)$m[2]) : (int)$m[1];
  $z = ($m[2] === '' || $m[1] === '') ? $total - 1 : min((int)$m[2], $total - 1);
  if ($a >= $total || $a > $z) return [-1, -1];
  return [$a, min($z, $a + $cap - 1)];
}
function send_bytes($buf, $type, $status, $extra = []) {
  http_response_code($status);
  header('Content-Type: ' . $type); header('Content-Length: ' . strlen($buf)); header('Accept-Ranges: bytes'); header('X-Content-Type-Options: nosniff');
  foreach ($extra as $h) header($h);
  echo $buf; exit;
}

/* ---- health check: open /api/db.php?ping=1 in the browser. {"ok":true,...} = the API works ---- */
if ($method === 'GET' && isset($_GET['ping'])) {
  jout(['ok' => true, 'php' => PHP_VERSION, 'data_writable' => is_dir(DATA) && is_writable(DATA), 'curl' => function_exists('curl_init'), 'max_mb' => MAX_UPLOAD_MB, 'config' => is_file(__DIR__ . '/config.php')]);
}

/* ---- serve an uploaded file ---- */
if ($method === 'GET') {
  $id = isset($_GET['asset']) ? (string)$_GET['asset'] : '';
  if (!preg_match('/^[\w-]{8,64}$/', $id)) { http_response_code(404); echo 'Not found'; exit; }
  $man = asset_man($id);
  if (!$man) { http_response_code(404); echo 'Not found'; exit; }
  $total = (int)$man->size; $rg = range_of($total, RCAP);
  if ($rg && $rg[0] < 0) { http_response_code(416); header('Content-Range: bytes */' . $total); exit; }
  $r = $rg ?: ($total <= MAX_FILE ? [0, $total - 1] : [0, RCAP - 1]);
  $buf = read_range($id, $r[0], $r[1]);
  if ($buf === null) { http_response_code(404); echo 'Not found'; exit; }
  if (!$rg && $total <= MAX_FILE) send_bytes($buf, $man->type, 200, ['Cache-Control: public, max-age=31536000, immutable']);
  send_bytes($buf, $man->type, 206, ['Content-Range: bytes ' . $r[0] . '-' . $r[1] . '/' . $total, 'Cache-Control: private, max-age=86400']);
}
if ($method !== 'POST') jout(['error' => 'method'], 405);

$op0 = isset($_GET['op']) ? (string)$_GET['op'] : '';

/* ---- big file: 1 MB pieces (each piece may itself arrive in smaller parts, &o= = offset inside the piece); the last part publishes it ---- */
if ($op0 === 'chunk') {
  $type = ctype();
  if ($me === '') jout(['error' => 'identity'], 400);
  if (!preg_match(OK_TYPES, $type)) jout(['error' => 'type'], 415);
  $cid = isset($_GET['id']) ? (string)$_GET['id'] : ''; $i = (int)($_GET['i'] ?? -1); $n = (int)($_GET['n'] ?? 0); $s = (int)($_GET['s'] ?? 0); $o = (int)($_GET['o'] ?? 0);
  if ($s > MAX_CHUNKS * CK) jout(['error' => 'toobig', 'max_mb' => MAX_UPLOAD_MB], 413);
  if (!preg_match('/^[\w-]{8,64}$/', $cid) || !($n >= 1 && $n <= MAX_CHUNKS && $i >= 0 && $i < $n) || !($s > 0 && $s <= MAX_CHUNKS * CK) || $n !== (int)ceil($s / CK)) jout(['error' => 'chunk'], 400);
  if (asset_man($cid)) jout(['error' => 'exists'], 409);
  $plen = $i < $n - 1 ? CK : $s - ($n - 1) * CK;           // exact size this piece must end up with
  if ($o < 0 || $o >= $plen) jout(['error' => 'chunk'], 400);
  $buf = file_get_contents('php://input');
  $len = strlen($buf);
  if ($len === 0 || $o + $len > $plen) jout(['error' => 'size'], 413);
  $dir = asset_dir($cid); if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $fh = @fopen($dir . '/' . $i . '.bin', 'c+b');
  if (!$fh || fseek($fh, $o) !== 0 || fwrite($fh, $buf) !== $len) { if ($fh) fclose($fh); jout(['error' => 'write'], 500); }
  fclose($fh);
  if ($i === $n - 1 && $o + $len === $plen) {
    clearstatcache();
    for ($k = 0; $k < $n; $k++) {
      $want = $k < $n - 1 ? CK : $plen; $f = $dir . '/' . $k . '.bin';
      if (!is_file($f) || filesize($f) !== $want) jout(['error' => 'missing'], 400);
    }
    write_json($dir . '/manifest.json', (object)['type' => $type, 'size' => $s]);
    jout(['url' => '/api/db.php?asset=' . $cid]);
  }
  jout(['ok' => true]);
}

/* ---- small file in one request ---- */
if ($op0 === 'upload') {
  $type = ctype();
  if ($me === '') jout(['error' => 'identity'], 400);
  if (!preg_match(OK_TYPES, $type)) jout(['error' => 'type'], 415);
  $buf = file_get_contents('php://input'); $len = strlen($buf);
  if ($len === 0 || $len > MAX_FILE) jout(['error' => 'size'], 413);
  $id = bin2hex(random_bytes(16)); $dir = asset_dir($id); @mkdir($dir, 0775, true);
  for ($k = 0; $k * CK < $len; $k++) if (file_put_contents($dir . '/' . $k . '.bin', substr($buf, $k * CK, CK)) === false) jout(['error' => 'write'], 500);
  write_json($dir . '/manifest.json', (object)['type' => $type, 'size' => $len]);
  jout(['url' => '/api/db.php?asset=' . $id]);
}

$b = json_decode(file_get_contents('php://input'));
if (!is_object($b)) jout(['error' => 'body'], 400);
$op = isset($b->op) ? $b->op : null; $c = isset($b->c) ? $b->c : null; $id = isset($b->id) ? $b->id : null;

if ($op === 'whoami') jout(['admin' => $admin, 'configured' => $tok !== '']);

if ($op === 'multi') {
  $out = new stdClass();
  foreach (array_slice(is_array($b->cols ?? null) ? $b->cols : [], 0, 4) as $x) {
    if (!is_object($x) || !isset($x->c) || !in_array($x->c, $COLS, true)) continue;
    $all = col_load($x->c); $lim = min(max((int)($x->limit ?? 200) ?: 200, 1), 1000); $list = [];
    foreach ($all as $k => $d) {
      $k = (string)$k;
      if (!visible($d, $me, $admin)) continue;
      if (count($list) >= $lim) break;
      $list[] = $x->c === 'seen'
        ? ['id' => oh($k), 'data' => (object)['c' => (isset($d->c) && $d->c !== null) ? $d->c : new stdClass()]]
        : ['id' => $k, 'data' => pub_doc($x->c, $d, $me)];
    }
    $out->{$x->c} = $list;
  }
  jout(['cols' => $out]);
}

/* ---- download a file in pieces over the JSON API (what the page uses) ---- */
if ($op === 'asset') {
  $aid = (string)($b->id ?? '');
  if (!preg_match('/^[\w-]{8,64}$/', $aid)) jout(['error' => 'id'], 400);
  $man = asset_man($aid);
  if (!$man) jout(['error' => 'notfound'], 404);
  $off = max(0, (int)($b->off ?? 0)); $len = min(max((int)($b->len ?? RCAP) ?: RCAP, 1), RCAP); $size = (int)$man->size;
  if ($off >= $size) jout(['type' => $man->type, 'size' => $size, 'off' => $off, 'b64' => '']);
  $buf = read_range($aid, $off, min($off + $len, $size) - 1);
  if ($buf === null) jout(['error' => 'notfound'], 404);
  jout(['type' => $man->type, 'size' => $size, 'off' => $off, 'b64' => base64_encode($buf)]);
}

if (!is_string($c) || !in_array($c, $COLS, true)) jout(['error' => 'collection'], 400);

if ($op === 'list') {
  $all = col_load($c); $lim = min(max((int)($b->limit ?? 200) ?: 200, 1), 1000); $docs = [];
  foreach ($all as $k => $d) {
    if (!visible($d, $me, $admin)) continue;
    if (count($docs) >= $lim) break;
    $docs[] = ['id' => (string)$k, 'data' => pub_doc($c, $d, $me)];
  }
  jout(['docs' => $docs]);
}

if (!is_string($id) || !preg_match('/^[\w-]{1,64}$/', $id)) jout(['error' => 'id'], 400);

if ($op === 'get') {
  $all = col_load($c); $d = isset($all->{$id}) ? $all->{$id} : null;
  if ($c === 'profiles' && $d && isset($d->pub) && $d->pub === false && empty($d->deleted) && !$admin && (!isset($d->owner) || $d->owner !== $me))
    jout(['exists' => true, 'data' => (object)['user' => $id, 'owner' => oh($d->owner ?? ''), 'saved' => false, 'private' => true]]); // draft: name stays taken, content hidden
  jout(['exists' => (bool)$d, 'data' => $d ? pub_doc($c, $d, $me) : null]);
}

if ($op === 'set') {
  $data = isset($b->data) ? $b->data : null;
  if (!is_object($data) || strlen(json_encode($data, JSON_UNESCAPED_UNICODE)) > MAX_DOC) jout(['error' => 'data'], 400);
  // one profile per IP address (only a salted hash is stored)
  $ipKey = null; $ipPrev = null;
  if ($c === 'profiles' && !$admin) {
    if (strlen($me) < 16) jout(['error' => 'identity'], 400);
    if ($ip === '') jout(['error' => 'ip_limit'], 403);
    $ipKey = ip_hash($ip); $profs = col_load('profiles');
    $ipr = mutate('ips', function ($m) use ($ipKey, $id, $me, $profs, &$ipPrev) {
      $cur = isset($m->{$ipKey}) ? $m->{$ipKey} : null; $ipPrev = $cur;
      if ($cur && $cur->user !== $id) {
        $old = isset($profs->{$cur->user}) ? $profs->{$cur->user} : null;
        if ($cur->owner !== $me || !$old || !empty($old->deleted)) return ['error' => 'ip_limit', 'status' => 403];
      }
      $m->{$ipKey} = (object)['user' => $id, 'owner' => $me, 't' => ms()];
    });
    if (isset($ipr['error'])) jout(['error' => $ipr['error']], $ipr['status'] ?? 400);
  }
  $r = mutate($c, function ($all) use ($c, $id, $me, $admin, $data) {
    $ex = isset($all->{$id}) ? $all->{$id} : null;
    if (($c === 'staff' || $c === 'disc') && !$admin) return ['error' => 'forbidden', 'status' => 403];
    if ($c === 'seen' && !$admin && $id !== $me) return ['error' => 'forbidden', 'status' => 403];
    if ($c === 'profiles' && !$admin) {
      if (!preg_match('/^[a-z0-9_]{2,20}$/', $id)) return ['error' => 'id', 'status' => 400];
      if ($me === '' || !isset($data->owner) || $data->owner !== $me || !isset($data->user) || $data->user !== $id) return ['error' => 'forbidden', 'status' => 403];
      if ($ex && !empty($ex->owner) && $ex->owner !== $me) return ['error' => 'taken', 'status' => 403];
    }
    $all->{$id} = $data;
  });
  if (isset($r['error'])) {
    if ($ipKey) mutate('ips', function ($m) use ($ipKey, $ipPrev) { if ($ipPrev) $m->{$ipKey} = $ipPrev; else unset($m->{$ipKey}); }); // give the IP slot back
    jout(['error' => $r['error']], $r['status'] ?? 400);
  }
  if ($c === 'profiles' && !$admin) {
    $f = kv_get('dcme/' . oh($me)); // Discord badges the owner already earned (server-side flags only)
    if ($f) mutate('disc', function ($m) use ($id, $f) { $m->{$id} = (object)['m' => $f->m ?? 0, 's' => $f->s ?? 0, 'd' => $f->d ?? '', 't' => $f->t ?? 0]; });
  }
  jout(['ok' => true]);
}

if ($op === 'delete') {
  $r = mutate($c, function ($all) use ($c, $id, $me, $admin) {
    $ex = isset($all->{$id}) ? $all->{$id} : null;
    if (!$ex) return null;
    if (!$admin && ($c !== 'profiles' || $me === '' || !isset($ex->owner) || $ex->owner !== $me)) return ['error' => 'forbidden', 'status' => 403];
    if ($c === 'profiles' && !$admin) $all->{$id} = (object)['user' => $id, 'owner' => $ex->owner, 'saved' => false, 'deleted' => true, 'updated' => ms()]; // name stays reserved
    else unset($all->{$id});
  });
  if (isset($r['error'])) jout(['error' => $r['error']], $r['status'] ?? 400);
  jout(['ok' => true]);
}

jout(['error' => 'op'], 400);
