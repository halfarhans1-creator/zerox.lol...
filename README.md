# zerox.gg - htdocs / PHP version (no Netlify, no Cloudflare)

Works on XAMPP (localhost) and on any PHP web host (InfinityFree, Hostinger, all-inkl, ...). Needs PHP 7.4+ with curl and Apache (.htaccess).

## Install
1. Upload everything in this folder into your **htdocs** (web root): `index.html`, `404.html`, `config.js`, `.htaccess`, `.user.ini` and the whole `api/` folder.
   Keep the hidden files (`.htaccess`) - turn on "show hidden files" in your FTP program.
2. Open `api/config.php` and fill in the Discord values (same ones you had as Netlify variables). Change `IP_SALT` to some random letters.
3. Discord Developer Portal -> your application -> OAuth2 -> Redirects -> add:
   `https://YOUR-DOMAIN/api/discord.php`   (XAMPP: `http://localhost/api/discord.php`)
4. Make sure `api/` is writable (the site creates `api/data/` itself; if uploads say "write", set the folder to 755/775 or 777 in your FTP program).
5. Open the site, go to Customize -> Media -> Upload.

## Good to know
- Uploads are sent in 1 MB pieces, and if your host refuses even that (error 413) the page automatically retries with smaller pieces (down to 64 KB). Max file size is `MAX_UPLOAD_MB` in `api/config.php` (default 1024 = 1 GB); your host's free disk space is the real limit. Unfinished uploads are cleaned up after 24 hours.
- Files that only show for you: these were saved in your own browser because the upload failed. Open Customize -> Media and press **Upload to server** (or just upload them again).
- Data (profiles, uploads, Discord links) lives in `api/data/`. It is blocked from the browser by `.htaccess`. Safer: set `DATA_DIR` in `config.php` to a folder outside htdocs.
- Discord badges are re-checked automatically about every 5 minutes while people visit the site. Optional exact schedule: set `SYNC_KEY` and call `https://YOUR-DOMAIN/api/sync.php?key=...` from cron-job.org.
- Site in a sub-folder (e.g. `http://localhost/zerox/`): set `base: "/zerox/"` in `config.js` and add the matching redirect in Discord.
- Page on GitHub Pages but api on a PHP host: put the PHP site address in `config.js` (`api: "https://your-domain.com"`) and set `ALLOWED_ORIGINS` in `config.php`.
- Old Netlify data was not copied; this starts empty.
