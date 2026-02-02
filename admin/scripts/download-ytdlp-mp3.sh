#!/usr/bin/env bash
# download-ytdlp-mp3.sh
#
#  Download YouTube videos/playlists as MP3 files.
#  Usage:
#   ./download-ytdlp-mp3.sh URL1 [URL2 ...]
#   or pipe a file with URLs: cat urls.txt | xargs -n1 -I{} ./download-ytdlp-mp3.sh {}
#
#  Requires: yt-dlp, ffmpeg
#  Author: yourname

set -euo pipefail

TARGET_DIR=/var/www/html/music/ytmp3

# Helper: quote a string for safe filename creation
quote() { printf '%q' "$1"; }

for url in "$@"; do
    # Derive a sane output pattern; we store per‑playlist if it is one.
    # yt-dlp will auto‑create subfolders when the playlist flag is used.
    outtmpl="%($(basename $url)?)"  # dummy

    echo "Downloading: $url"
    yt-dlp \
        -x \                        # extract audio only
        --audio-format mp3 \         # MP3 output
        --audio-quality 0 \          # best quality (≈320 kbps)
        -o "${TARGET_DIR}/%(playlist_title)s/%(title)s.%(ext)s" \  # folder per playlist
        "$url"
done

echo "Done. Files are in ${TARGET_DIR}"
