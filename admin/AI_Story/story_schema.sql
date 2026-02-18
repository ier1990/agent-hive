-- ============================================================
-- story.db — AI_Story collaborative narrative database
-- Location: PRIVATE_ROOT/db/memory/story.db
-- ============================================================

PRAGMA journal_mode=WAL;
PRAGMA foreign_keys=ON;

-- -------------------------------------------------------
-- Stories — one row per campaign/session
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS stories (
    story_id        TEXT PRIMARY KEY,           -- UUID v4
    title           TEXT NOT NULL DEFAULT 'Untitled',
    template_id     TEXT NOT NULL,              -- FK to ai_templates (slug)
    template_name   TEXT NOT NULL,              -- denormalized for display
    world_state     TEXT NOT NULL DEFAULT '{}', -- JSON blob: inventory, facts, variables
    summary         TEXT NOT NULL DEFAULT '',   -- rolling AI-generated chapter summary
    turn_count      INTEGER NOT NULL DEFAULT 0,
    status          TEXT NOT NULL DEFAULT 'active', -- active | paused | ended
    server_origin   TEXT NOT NULL DEFAULT 'local',  -- which server started this
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

-- -------------------------------------------------------
-- Turns — one row per player action + AI response
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS turns (
    turn_id         TEXT PRIMARY KEY,           -- UUID v4
    story_id        TEXT NOT NULL REFERENCES stories(story_id),
    turn_number     INTEGER NOT NULL,           -- sequential within story
    player_id       TEXT NOT NULL DEFAULT 'local', -- player or remote server_id
    player_action   TEXT NOT NULL,              -- raw player input
    compiled_prompt TEXT NOT NULL,              -- full prompt sent to AI (template+state+action)
    raw_response    TEXT NOT NULL,              -- raw AI output (full JSON string)
    narrative       TEXT NOT NULL DEFAULT '',   -- parsed: story paragraph
    choices         TEXT NOT NULL DEFAULT '[]', -- parsed: JSON array of 3 choice strings
    wildcard        TEXT NOT NULL DEFAULT '',   -- parsed: wildcard twist option
    state_delta     TEXT NOT NULL DEFAULT '{}', -- JSON: changes to world_state this turn
    model           TEXT NOT NULL DEFAULT '',
    tokens_used     INTEGER NOT NULL DEFAULT 0,
    latency_ms      INTEGER NOT NULL DEFAULT 0,
    server_id       TEXT NOT NULL DEFAULT 'local', -- which server generated this turn
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

-- -------------------------------------------------------
-- Federation log — track cross-server relays
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS federation_log (
    log_id          TEXT PRIMARY KEY,
    story_id        TEXT NOT NULL,
    turn_id         TEXT,
    direction       TEXT NOT NULL, -- 'sent' | 'received'
    remote_server   TEXT NOT NULL,
    endpoint        TEXT NOT NULL,
    payload_hash    TEXT NOT NULL,
    http_status     INTEGER,
    response_ms     INTEGER,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

-- -------------------------------------------------------
-- Indexes
-- -------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_turns_story     ON turns(story_id, turn_number);
CREATE INDEX IF NOT EXISTS idx_turns_player    ON turns(player_id);
CREATE INDEX IF NOT EXISTS idx_fed_story       ON federation_log(story_id);
CREATE INDEX IF NOT EXISTS idx_stories_status  ON stories(status);
