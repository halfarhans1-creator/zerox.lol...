<?php
// Shared helpers for zerox.gg (PHP / htdocs version). Data is stored in plain files.
ini_set('display_errors', '0');
// no compression on API answers: a hand-set Content-Length + host-side gzip = broken response = "Failed to fetch"
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
if (!is_file(__DIR__ . '/config.php')) { http_response_code(500); header('Content-Type: application/json'); echo '{"error":"no_config"}'; exit; }
$CFG = require __DIR__ . '/config.php';
function cfg($k, $d = '') { global $CFG; $v = isset($CFG[$k]) ? $CFG[$k] : $d; return is_string($v) ? trim($v) : $v; }

define('CK', 1024 * 1024);                 // upload chunk size (must match the page: 1 MB, fits every host's POST limit)
define('RCAP', 3 * 1024 * 1024);           // max bytes per range / download response
define('MAX_DOC', 600 * 1024);
define('MAX_FILE', 4.5 * 1024 * 1024);
define('MAX_UPLOAD_MB', max(1, (int)cfg('MAX_UPLOAD_MB', 1024)));   // biggest single upload (config.php)
define('MAX_CHUNKS', MAX_UPLOAD_MB);                                 // 1 MB pieces -> pieces == MB
define('OK_TYPES', '#^(image/(png|jpeg|gif|webp)|video/(mp4|webm|quicktime)|audio/[\w.+-]+)$#');

$dd = cfg('DATA_DIR');
define('DATA', $dd !== '' ? rtrim($dd, '/\\') : __DIR__ . '/data');
foreach ([DATA, DATA . '/col', DATA . '/kv', DATA . '/assets'] as $d) if (!is_dir($d)) @mkdir($d, 0775, true);
if (!is_file(DATA . '/.htaccess')) {
  @file_put_contents(DATA . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
  @file_put_contents(DATA . '/index.html', '');
}

function jout($o, $s = 200) {
  http_response_code($s);
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  $body = json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($body === false) $body = '{"error":"encode"}';
  while (ob_get_level() > 0) @ob_end_clean();   // drop stray output (spaces, notices) so the JSON stays clean
  echo $body;
  if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); else { @ob_flush(); @flush(); }
  exit;
}
function ms() { return (int)round(microtime(true) * 1000); }
function oh($s) { return 'h' . substr(hash('sha256', 'o|' . $s), 0, 24); }   // owner id -> public hash (owner ids are login secrets)
function fname($name) { return preg_replace('/[^\w.-]/', '_', $name) . '.' . substr(hash('sha256', cfg('IP_SALT', 'glitchbio') . '|f|' . $name), 0, 16) . '.json'; }
function client_ip() {
  if (cfg('TRUST_PROXY_IP') && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
  return isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '';
}
function ip_hash($ip) { return substr(hash('sha256', cfg('IP_SALT', 'glitchbio') . '|' . $ip), 0, 40); }

function cors() {
  $o = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
  $list = array_filter(array_map('trim', explode(',', (string)cfg('ALLOWED_ORIGINS'))));
  $allow = !$list ? ($o !== '' ? $o : '*') : (in_array($o, $list, true) ? $o : '');
  if ($allow !== '') {
    header('Access-Control-Allow-Origin: ' . $allow);
    header('Access-Control-Allow-Headers: content-type,x-me,x-admin,range');
    header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
    header('Access-Control-Expose-Headers: content-range,accept-ranges,content-length');
    header('Vary: Origin');
  }
  if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') { header('Access-Control-Max-Age: 86400'); http_response_code(204); exit; }
}

/* ---- JSON files (objects, so {} stays {}) ---- */
function read_json($p) { if (!is_file($p)) return null; $s = @file_get_contents($p); return $s === false ? null : json_decode($s); }
function write_json($p, $o) {
  $t = $p . '.' . bin2hex(random_bytes(4)) . '.tmp';
  file_put_contents($t, json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  rename($t, $p);
}
function col_load($c) { $j = read_json(DATA . '/col/' . fname($c)); return is_object($j) ? $j : new stdClass(); }
// read -> change -> write, one at a time. $fn gets the collection object; return ['error'=>..,'status'=>..] to abort.
function mutate($c, $fn) {
  $lock = fopen(DATA . '/lock_' . preg_replace('/\W/', '', $c), 'c');
  flock($lock, LOCK_EX);
  try {
    $all = col_load($c);
    $res = $fn($all);
    if (is_array($res) && isset($res['error'])) return $res;
    write_json(DATA . '/col/' . fname($c), $all);
    return ['ok' => true];
  } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function kv_path($n) { return DATA . '/kv/' . fname($n); }
function kv_get($n) { return read_json(kv_path($n)); }
function kv_set($n, $v) { write_json(kv_path($n), $v); }
function kv_del($n) { @unlink(kv_path($n)); }

/* ---- uploaded files: assets/<id>/<k>.bin (1 MB pieces) + manifest.json ---- */
function asset_dir($id) { return DATA . '/assets/' . $id; }
function asset_man($id) { return read_json(asset_dir($id) . '/manifest.json'); }
function read_range($id, $a, $z) {
  $out = '';
  for ($k = intdiv($a, CK); $k <= intdiv($z, CK); $k++) {
    $f = asset_dir($id) . '/' . $k . '.bin';
    if (!is_file($f)) return null;
    $s = max($a, $k * CK) - $k * CK; $e = min($z, ($k + 1) * CK - 1) - $k * CK;
    $fh = fopen($f, 'rb'); fseek($fh, $s); $out .= fread($fh, $e - $s + 1); fclose($fh);
  }
  return $out;
}

/* ---- Discord ---- */
function http($method, $url, $headers = [], $body = null) {
  $ch = curl_init($url);
  $headers[] = 'User-Agent: DiscordBot (https://zerox.gg, 1.0)';
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$code, $r === false ? '' : $r];
}
function staff_ids() { return array_filter(array_map('trim', explode(',', (string)cfg('DISCORD_STAFF_ROLE_IDS')))); }
// {m: in server, s: staff, d: avatar frame, t: time} or null when Discord could not answer (then nothing changes)
function member_flags($did) {
  $tok = cfg('DISCORD_BOT_TOKEN'); $gid = cfg('DISCORD_GUILD_ID');
  if ($tok === '' || $gid === '') return null;
  list($code, $body) = http('GET', 'https://discord.com/api/v10/guilds/' . $gid . '/members/' . $did, ['Authorization: Bot ' . $tok]);
  if ($code === 404) { $j = json_decode($body); return ($j && isset($j->code) && $j->code === 10007) ? (object)['m' => 0, 's' => 0, 'd' => '', 't' => ms()] : null; }
  if ($code < 200 || $code >= 300) return null;
  $m = json_decode($body);
  $roles = (isset($m->roles) && is_array($m->roles)) ? $m->roles : [];
  $d = (isset($m->user->avatar_decoration_data->asset)) ? $m->user->avatar_decoration_data->asset : '';
  return (object)['m' => 1, 's' => array_intersect($roles, staff_ids()) ? 1 : 0, 'd' => $d, 't' => ms()];
}
function apply_many($items) {
  if (!$items) return;
  $by = [];
  foreach ($items as $it) { kv_set('dcme/' . $it['h'], $it['f']); $by[$it['h']] = $it['f']; }
  $upd = [];
  foreach (col_load('profiles') as $k => $p) {
    if ($p && !empty($p->owner) && empty($p->deleted)) { $h = oh($p->owner); if (isset($by[$h])) $upd[(string)$k] = $by[$h]; }
  }
  if ($upd) mutate('disc', function ($m) use ($upd) { foreach ($upd as $k => $v) $m->{$k} = $v; });
}
// re-check linked Discord accounts (joined / left / staff role); a slice per run so it never takes long
function sync_batch($limit = 40) {
  if (cfg('DISCORD_BOT_TOKEN') === '') return ['skipped' => true];
  foreach (glob(DATA . '/kv/dcstate_*') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
  clean_partial_uploads();
  $links = col_load('dclinks'); $ids = array_map('strval', array_keys(get_object_vars($links)));
  $cur = kv_get('synccur'); $start = ($cur && isset($cur->i)) ? (int)$cur->i : 0;
  if ($start >= count($ids)) $start = 0;
  $slice = array_slice($ids, $start, $limit); $items = [];
  foreach ($slice as $did) { $f = member_flags($did); if ($f) $items[] = ['h' => $links->{$did}, 'f' => $f]; }
  apply_many($items);
  kv_set('synccur', (object)['i' => $start + $limit >= count($ids) ? 0 : $start + $limit]);
  return ['linked' => count($ids), 'checked' => count($slice), 'updated' => count($items)];
}
// called after the answer was sent: at most every 5 minutes
function maybe_sync() {
  if (cfg('DISCORD_BOT_TOKEN') === '') return;
  $lt = kv_get('lastsync');
  if ($lt && isset($lt->t) && time() - (int)$lt->t < 300) return;
  kv_set('lastsync', (object)['t' => time()]);
  @ignore_user_abort(true); @set_time_limit(60);
  try { sync_batch(40); } catch (Throwable $e) { /* next time */ }
}

// remove pieces of uploads that were never finished (no manifest.json) after 24 h, so failed big uploads don't eat disk space
function clean_partial_uploads() {
  foreach (glob(DATA . '/assets/*', GLOB_ONLYDIR) ?: [] as $d) {
    if (is_file($d . '/manifest.json') || filemtime($d) > time() - 86400) continue;
    foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
    @rmdir($d);
  }
}
