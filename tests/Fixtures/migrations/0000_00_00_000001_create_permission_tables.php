<?php

/**
 * Spatie Permission publishes its schema as a stub rather than auto-loading it,
 * so the harness runs the installed version's stub directly. Requiring the
 * vendor file rather than copying it keeps the suite in step with whichever
 * version composer resolved.
 */
return require __DIR__.'/../../../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
