<?php
/**
 * CodeWalker Admin Router
 * Routes admin menu access to the main CodeWalker dashboard
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

// Route all traffic to the main CodeWalker implementation
require_once __DIR__ . '/codewalker.php';
