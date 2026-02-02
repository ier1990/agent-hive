
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

