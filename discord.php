<?php
// Discord login for zerox.gg: links a website profile to a Discord account once (PHP version).
// Redirect URL to add in the Discord Developer Portal (OAuth2 -> Redirects): https://YOUR-DOMAIN/api/discord.php
require __DIR__ . '/lib.php';
cors();
register_shutdown_function(function () { if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); maybe_sync(); });

$method = $_SERVER['REQUEST_METHOD'];
$op = isset($_GET['op']) ? (string)$_GET['op'] : '';
$cid = cfg('DISCORD_CLIENT_ID'); $sec = cfg('DISCORD_CLIENT_SECRET'); $gid = cfg('DISCORD_GUILD_ID');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$origin = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$redirect = cfg('DISCORD_REDIRECT_URI') !== '' ? cfg('DISCORD_REDIRECT_URI') : $origin . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/discord.php';
$me = substr(isset($_SERVER['HTTP_X_ME']) ? $_SERVER['HTTP_X_ME'] : '', 0, 64);
$configured = ($cid !== '' && $sec !== '' && $gid !== '');

function back($r, $ret = '') { header('Location: ' . ($ret !== '' ? $ret : '/') . '?dc=' . $r . '#/account/settings'); header('Cache-Control: no-store'); http_response_code(302); exit; }

// the page asks for a Discord login URL
if ($method === 'POST' && $op === 'start') {
  if (!$configured) jout(['error' => 'not_configured'], 503);
  if (strlen($me) < 16) jout(['error' => 'identity'], 400);
  $st = bin2hex(random_bytes(16)); $ret = '';
  $body = json_decode(file_get_contents('php://input'));
  $reqOrigin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
  $list = array_filter(array_map('trim', explode(',', (string)cfg('ALLOWED_ORIGINS'))));
  $okOrigin = preg_match('#^https?://[\w.-]+(:\d+)?$#', $reqOrigin) && (!$list || in_array($reqOrigin, $list, true));
  $base = (is_object($body) && isset($body->base) && is_string($body->base) && preg_match('#^/[\w\-./]*$#', $body->base)) ? $body->base : '/';
  $base = substr($base, -1) === '/' ? $base : $base . '/';
  if ($okOrigin && $reqOrigin !== $origin) $ret = $reqOrigin . $base; else $ret = $base;
  kv_set('dcstate/' . $st, (object)['me' => $me, 't' => ms(), 'ret' => $ret]);
  $q = http_build_query(['client_id' => $cid, 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => 'identify guilds.members.read', 'state' => $st]);
  jout(['url' => 'https://discord.com/oauth2/authorize?' . $q]);
}

// who is signed in
if ($method === 'POST' && $op === 'me') {
  if (strlen($me) < 16) jout(['error' => 'identity'], 400);
  $mine = oh($me); $id = kv_get('dcid/' . $mine);
  if (!$id) jout(['linked' => false, 'configured' => $configured]);
  $f = kv_get('dcme/' . $mine);
  jout(['linked' => true, 'n' => $id->n ?? '', 'u' => $id->u ?? '', 'i' => $id->i ?? '', 'a' => $id->a ?? '', 'd' => ($f->d ?? '') ?: ($id->d ?? ''), 'm' => $f->m ?? 0, 's' => $f->s ?? 0]);
}

// sign out
if ($method === 'POST' && $op === 'unlink') {
  if (strlen($me) < 16) jout(['error' => 'identity'], 400);
  $mine = oh($me);
  mutate('dclinks', function ($m) use ($mine) { foreach (array_keys(get_object_vars($m)) as $k) if ($m->{$k} === $mine) unset($m->{$k}); });
  $keys = [];
  foreach (col_load('profiles') as $k => $p) if ($p && !empty($p->owner) && oh($p->owner) === $mine) $keys[] = (string)$k;
  if ($keys) mutate('disc', function ($m) use ($keys) { foreach ($keys as $k) unset($m->{$k}); });
  kv_del('dcme/' . $mine); kv_del('dcid/' . $mine);
  jout(['ok' => true]);
}

// "Re-check" for an already linked account
if ($method === 'POST' && $op === 'recheck') {
  if (strlen($me) < 16) jout(['error' => 'identity'], 400);
  if (cfg('DISCORD_BOT_TOKEN') === '') jout(['error' => 'no_bot'], 503);
  $mine = oh($me); $did = null;
  foreach (col_load('dclinks') as $k => $h) if ($h === $mine) { $did = (string)$k; break; }
  if ($did === null) jout(['error' => 'not_linked'], 404);
  $f = member_flags($did);
  if (!$f) jout(['error' => 'discord'], 502);
  apply_many([['h' => $mine, 'f' => $f]]);
  $idb = kv_get('dcid/' . $mine);
  if ($idb) { $idb->d = $f->d ?: ''; kv_set('dcid/' . $mine, $idb); }
  jout(['ok' => true, 'm' => $f->m, 's' => $f->s, 'd' => $f->d ?: '']);
}

// Discord sends the person back here after login
if ($method === 'GET' && ($op === 'cb' || isset($_GET['code']) || isset($_GET['error']))) {
  if (!$configured) back('err');
  if (!empty($_GET['error'])) back('denied');
  $code = isset($_GET['code']) ? (string)$_GET['code'] : ''; $st = isset($_GET['state']) ? (string)$_GET['state'] : '';
  if ($code === '' || !preg_match('/^[\w-]{8,64}$/', $st)) back('err');
  $s = kv_get('dcstate/' . $st); kv_del('dcstate/' . $st);
  $ret = ($s && isset($s->ret) && is_string($s->ret)) ? $s->ret : '';
  if (!$s || ms() - $s->t > 10 * 60 * 1000) back('expired', $ret);
  $mine = oh($s->me);
  try {
    list($tc, $tb) = http('POST', 'https://discord.com/api/v10/oauth2/token', ['Content-Type: application/x-www-form-urlencoded'],
      http_build_query(['client_id' => $cid, 'client_secret' => $sec, 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect]));
    if ($tc < 200 || $tc >= 300) back('err', $ret);
    $auth = ['Authorization: Bearer ' . json_decode($tb)->access_token];
    list($uc, $ub) = http('GET', 'https://discord.com/api/v10/users/@me', $auth);
    if ($uc < 200 || $uc >= 300) back('err', $ret);
    $du = json_decode($ub); $duid = (string)$du->id;

    $links0 = col_load('dclinks');   // one Discord account <-> one website owner
    if (isset($links0->{$duid}) && $links0->{$duid} !== $mine) back('used', $ret);

    // roles: ask the bot if configured, otherwise use the person's own login
    $f = member_flags($duid);
    if (!$f) {
      list($mc, $mb) = http('GET', 'https://discord.com/api/v10/users/@me/guilds/' . $gid . '/member', $auth);
      if ($mc >= 200 && $mc < 300) {
        $m = json_decode($mb); $roles = (isset($m->roles) && is_array($m->roles)) ? $m->roles : [];
        $f = (object)['m' => 1, 's' => array_intersect($roles, staff_ids()) ? 1 : 0, 'd' => '', 't' => ms()];
      } elseif ($mc === 404) {
        $j = json_decode($mb);
        if ($j && isset($j->code) && $j->code === 10007) $f = (object)['m' => 0, 's' => 0, 'd' => '', 't' => ms()]; else back('err', $ret);
      } else back('err', $ret);
    }
    $f->d = isset($du->avatar_decoration_data->asset) ? $du->avatar_decoration_data->asset : '';
    mutate('dclinks', function ($m) use ($mine, $duid) {
      foreach (array_keys(get_object_vars($m)) as $k) if ($m->{$k} === $mine && (string)$k !== $duid) unset($m->{$k});
      $m->{$duid} = $mine;
    });
    apply_many([['h' => $mine, 'f' => $f]]);
    $av = !empty($du->avatar)
      ? 'https://cdn.discordapp.com/avatars/' . $duid . '/' . $du->avatar . '.png?size=128'
      : 'https://cdn.discordapp.com/embed/avatars/' . (((int)$duid >> 22) % 6) . '.png';
    kv_set('dcid/' . $mine, (object)['n' => !empty($du->global_name) ? $du->global_name : $du->username, 'u' => $du->username, 'i' => $duid, 'a' => $av, 'd' => $f->d]);
    back($f->m ? 'ok' : 'nomember', $ret);
  } catch (Throwable $e) {
    back('err', $ret);
  }
}
jout(['error' => 'op'], 400);
