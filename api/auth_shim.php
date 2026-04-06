<?php
/**
 * auth_shim.php — REST-to-query-param bridge for auth endpoints.
 *
 * Called by .htaccess rewrites:
 *   POST /api/auth/login    → auth_shim.php?action=login
 *   POST /api/auth/register → auth_shim.php?action=register
 *   GET  /api/auth/me       → auth_shim.php?action=me
 *
 * Field translations (mobile → backend):
 *   login    : identifier → email
 *   register : name       → displayName
 */

// Field translation is handled directly in auth.php
// (accepts both 'name'/'displayName' and 'identifier'/'email')
require __DIR__ . '/auth.php';
