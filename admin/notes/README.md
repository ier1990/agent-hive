# Notes (admin/notes)

This directory is the **Notes UI + API-adjacent clone** used by `/web/html/admin/notes/index.php`.

It is designed to be **reliable on fresh installs**: if the DB or upload paths are missing or not writable, the UI should show actionable errors instead of crashing.

## What this is

- A single-page Notes UI (dark theme) with:
  - Human notes (threaded)
  - AI metadata view
  - Bash history view
  - Search cache view (edit/delete/bulk-delete)
  - Prompts view
- SQLite-backed storage
- Local-LAN access guard (by IP prefix) at the top of `index.php`

## Requirements

- PHP 7.3+
- PHP SQLite3 extension enabled
- Web server user (commonly `www-data`) able to write to:
  - `/web/private/db/memory/`
  - `/web/private/uploads/memory/`

## Files / dependencies

- Entry point: `index.php`
- Local core library: `notes_core.php`
  - This is intentionally **local** to `admin/notes` so changes here won't impact older pages.
  - If you also maintain `/web/html/lib/notes_core.php`, treat it as a separate “legacy compatibility” helper.

## Fresh install / permission fix

If you see errors like “DB init failed” or “directory is not writable”, run the following on the server:

```bash
sudo mkdir -p /web/private/db/memory /web/private/uploads/memory
sudo chown -R www-data:www-data /web/private/db/memory /web/private/uploads/memory
sudo chmod 775 /web/private/db/memory /web/private/uploads/memory
```

Then reload the page. The app will create SQLite DB files on first run.

## Access control

`index.php` currently restricts access to common RFC1918 ranges:

- `192.168.*`
- `10.*`
- `172.16.*`

If you access from a different subnet, you’ll get: `Access denied: not on local LAN.`

## PHP 7.3 compatibility

See [admin/notes/AI_SHEET.md](admin/notes/AI_SHEET.md) for the rules we’re using to keep this compatible with PHP 7.3 and easy to copy into other directories.

Quick workflow:

```bash
cd /web/html/admin/notes
./ai_sheet_setup.py --target 7.3 --write-sheet
./ai_sheet_setup.py --target 7.3 --scan --root /web/html
```

## Should we move notes_core.php into this directory?

Yes — this repo now does that.

- `admin/notes/index.php` includes `admin/notes/notes_core.php`.
- This isolates notes changes so they don’t break older code that still uses `/web/html/lib/notes_core.php`.
