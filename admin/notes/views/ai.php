<?php

declare(strict_types=0);

// Expects: $tree, $db_for_render

echo renderAiTree($tree ?? [], $db_for_render ?? null);
