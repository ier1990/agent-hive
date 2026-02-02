<?php

declare(strict_types=0);

// Expects: $search_cache_rows, $search_cache_edit, $search

echo renderSearchCacheEntries($search_cache_rows ?? [], $search_cache_edit ?? null, $search ?? null);
