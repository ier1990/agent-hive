<?php

/*

1) chat_shrinker (the most valuable tool)
Purpose

Keep chat threads short + high-signal, and feed the summary back into memory.

Job name

chat.shrink

Payload
{
  "thread_id": 123,
  "target_tokens": 1200,
  "keep_last_messages": 12,
  "style": "facts+decisions+open_questions"
}

What it does

Load last N messages from your conversation DB

Render AI_Header Chat Shrinker v1

Ask model for:

summary

decisions

TODOs

“memory candidates” (key/value)

Store:

threads.summary

optional memory updates into memory DB

Optionally prune/compact older messages (or just mark them archived)

This is the backbone of “awesome chat/memory prompt”.
*/
?>
