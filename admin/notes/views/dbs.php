<?php

declare(strict_types=0);

// Expects: $db_browser_html

echo $db_browser_html ?? '<div class="muted">DB browser unavailable.</div>';
