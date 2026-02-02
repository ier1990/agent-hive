#!/bin/bash
set -euo pipefail

# Save bash history into notes.php SQLite DB using thread replies
# Usage: ./save_bash_history_threaded.sh samekhi

USER_NAME="${1:-}"
if [[ -z "$USER_NAME" ]]; then
  echo "Usage: $0 <username>"
  exit 1
fi

DB="/web/private/db/memory/human_notes.db"
HOST="$(hostname)"
TODAY="$(date +%F)"
NOW_SQL="datetime('now')"

# Pick history file
if [[ "$USER_NAME" == "root" ]]; then
  HIST_FILE="/root/.bash_history"
else
  HIST_FILE="/home/$USER_NAME/.bash_history"
fi

[[ -f "$HIST_FILE" ]] || exit 0

# Ensure state table exists
sqlite3 "$DB" <<'SQL'
CREATE TABLE IF NOT EXISTS history_state (
  host TEXT NOT NULL,
  path TEXT NOT NULL,
  inode TEXT,
  last_line INTEGER DEFAULT 0,
  updated_at TEXT,
  PRIMARY KEY (host, path)
);
CREATE INDEX IF NOT EXISTS idx_notes_parent ON notes(parent_id);
CREATE INDEX IF NOT EXISTS idx_notes_type_topic_created ON notes(notes_type, topic, created_at);
SQL

# Current file stats
LINE_COUNT="$(wc -l < "$HIST_FILE" | tr -d ' ')"
INODE="$(stat -c %i "$HIST_FILE" 2>/dev/null || stat -f %i "$HIST_FILE" 2>/dev/null || echo "")"

# Load previous state
STATE="$(sqlite3 "$DB" "SELECT inode||'|'||COALESCE(last_line,0) FROM history_state WHERE host='$HOST' AND path='$HIST_FILE' LIMIT 1;")"
OLD_INODE=""
LAST_LINE=0
if [[ -n "$STATE" ]]; then
  OLD_INODE="${STATE%%|*}"
  LAST_LINE="${STATE#*|}"
fi

# Determine starting line
if [[ -n "$OLD_INODE" && "$OLD_INODE" == "$INODE" && "$LINE_COUNT" -ge "$LAST_LINE" ]]; then
  START_LINE=$((LAST_LINE + 1))
else
  # history rotated/cleared/new inode: start over
  START_LINE=1
fi

# Nothing new -> update state and exit
if [[ "$START_LINE" -gt "$LINE_COUNT" ]]; then
  sqlite3 "$DB" "INSERT INTO history_state(host,path,inode,last_line,updated_at)
                 VALUES('$HOST','$HIST_FILE','$INODE',$LINE_COUNT,$NOW_SQL)
                 ON CONFLICT(host,path) DO UPDATE SET
                   inode=excluded.inode,
                   last_line=excluded.last_line,
                   updated_at=excluded.updated_at;"
  exit 0
fi

# Collect new lines
NEW_LINES="$(tail -n +$START_LINE "$HIST_FILE")"

# If it's empty for some reason, just update state
if [[ -z "${NEW_LINES//[[:space:]]/}" ]]; then
  sqlite3 "$DB" "INSERT INTO history_state(host,path,inode,last_line,updated_at)
                 VALUES('$HOST','$HIST_FILE','$INODE',$LINE_COUNT,$NOW_SQL)
                 ON CONFLICT(host,path) DO UPDATE SET
                   inode=excluded.inode,
                   last_line=excluded.last_line,
                   updated_at=excluded.updated_at;"
  exit 0
fi

# Build / find daily parent note
TOPIC="bash_history"
PARENT_TITLE="Bash History — $TODAY"
PARENT_NOTE="### $PARENT_TITLE
**Host:** $HOST  
**User:** $USER_NAME  
"

# Escape single quotes for SQL
ESC_PARENT_NOTE="${PARENT_NOTE//\'/\'\'}"

PARENT_ID="$(sqlite3 "$DB" "
  SELECT id
  FROM notes
  WHERE parent_id = 0
    AND notes_type = 'logs'
    AND topic = '$TOPIC'
    AND note LIKE '%$PARENT_TITLE%'
    AND note LIKE '%Host:** $HOST%'
    AND note LIKE '%User:** $USER_NAME%'
  ORDER BY id DESC
  LIMIT 1;
")"

if [[ -z "$PARENT_ID" ]]; then
  sqlite3 "$DB" "
    INSERT INTO notes (notes_type, topic, note, parent_id, created_at, updated_at)
    VALUES ('logs', '$TOPIC', '$ESC_PARENT_NOTE', 0, $NOW_SQL, $NOW_SQL);
  "
  PARENT_ID="$(sqlite3 "$DB" "SELECT last_insert_rowid();")"
fi

# Create child note with only the new lines
CHILD_NOTE="**New commands appended:** lines $START_LINE–$LINE_COUNT  
\`\`\`bash
$NEW_LINES
\`\`\`
"

ESC_CHILD_NOTE="${CHILD_NOTE//\'/\'\'}"

sqlite3 "$DB" "
  INSERT INTO notes (notes_type, topic, note, parent_id, created_at, updated_at)
  VALUES ('logs', '$TOPIC', '$ESC_CHILD_NOTE', $PARENT_ID, $NOW_SQL, $NOW_SQL);
"

# Update state
sqlite3 "$DB" "INSERT INTO history_state(host,path,inode,last_line,updated_at)
               VALUES('$HOST','$HIST_FILE','$INODE',$LINE_COUNT,$NOW_SQL)
               ON CONFLICT(host,path) DO UPDATE SET
                 inode=excluded.inode,
                 last_line=excluded.last_line,
                 updated_at=excluded.updated_at;"
exit 0