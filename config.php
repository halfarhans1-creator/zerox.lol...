<?php
// zerox.gg settings. Edit the values, keep the quotes. Nobody can read this file through the browser (PHP runs it).
return [
  // Discord (Developer Portal -> your application)
  'DISCORD_CLIENT_ID'      => '',
  'DISCORD_CLIENT_SECRET'  => '',
  'DISCORD_GUILD_ID'       => '',     // your server id
  'DISCORD_STAFF_ROLE_IDS' => '',     // staff role id(s), comma separated
  'DISCORD_BOT_TOKEN'      => '',     // Bot tab; the bot must be in your server

  // Optional
  'ADMIN_TOKEN'  => '',               // password for the staff tools on the site
  'IP_SALT'      => 'CHANGE-ME-to-some-random-letters',  // also scrambles the data file names: change it once, before first use
  'ALLOWED_ORIGINS' => '',            // only if the page is hosted on ANOTHER domain (e.g. GitHub Pages): https://USER.github.io
  'DISCORD_REDIRECT_URI' => '',       // leave empty: https://YOUR-DOMAIN/api/discord.php
  'TRUST_PROXY_IP' => false,          // true only if the site is behind Cloudflare's proxy (orange cloud)
  'DATA_DIR' => '',                   // optional: folder OUTSIDE htdocs for the data, e.g. /home/you/zerox-data (must be writable)
  'MAX_UPLOAD_MB' => 1024,            // biggest file one person can upload, in MB (1024 = 1 GB). Your host's free disk space is the real limit.
  'SYNC_KEY' => '',                   // optional: secret for api/sync.php?key=... (external cron, e.g. cron-job.org)
];
