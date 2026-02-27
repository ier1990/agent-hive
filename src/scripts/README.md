
# src/scripts

This folder holds version-controlled worker + helper scripts.

## Releases

### Pack `/web/html` into a tarball (safe excludes)

Creates:

- `/web/private/releases/<app>/<app>-<host>-<date>.tar.gz`
- `/web/private/releases/<app>/<app>-<host>-<date>.tar.gz.sha256`

Run:

```bash
cd /web/html/src/scripts
chmod +x release_pack_and_push.sh
./release_pack_and_push.sh html /web/html
```

### Pack + publish to `/v1/releases/push`

```bash
cd /web/html/src/scripts
API_KEY="YOUR_KEY" ./release_pack_and_push.sh html /web/html https://api.iernc.net/v1/releases/push
```

Note: avoid `tar --exclude='.*'` because that drops `.htaccess` (needed for `/v1` routing).

### Fetch pinned release from GitHub (no SSH key required)

Downloads a pinned commit archive via HTTPS, repacks it into the update format expected by `admin/admin_Update.php` (`html/...` top folder), writes `latest.json`, and prunes old artifacts.

```bash
cd /web/html/src/scripts
chmod +x release_fetch_pinned.sh
./release_fetch_pinned.sh --sha <commit_sha>
```

Outputs in:

- `/web/private/releases/html/<app>-<host>-<timestamp>-<sha12>.tar.gz`
- `/web/private/releases/html/<filename>.sha256`
- `/web/private/releases/html/latest.json`

Retention defaults:

- keep at least latest 10 tarballs
- prune tarballs older than 90 days (beyond the kept minimum)
