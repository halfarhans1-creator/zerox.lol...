<?php
// Optional: call https://YOUR-DOMAIN/api/sync.php?key=SYNC_KEY from an external cron (e.g. cron-job.org, every 5 min).
// Without it the badges are also re-checked automatically while people visit the site.
require __DIR__ . '/lib.php';
$k = (string)cfg('SYNC_KEY');
if ($k === '' || !hash_equals($k, (string)($_GET['key'] ?? ''))) jout(['error' => 'forbidden'], 403);
@set_time_limit(120);
jout(sync_batch(200));
