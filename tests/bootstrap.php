<?php

/**
 * PHPUnit bootstrap for the Crate unit suite.
 *
 * Loads composer's autoloader and manually registers the OCP namespace
 * (nextcloud/ocp ships stubs without composer autoload rules). Tests that
 * need real Nextcloud runtime state should live in a separate integration
 * suite run inside a full Nextcloud container.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// nextcloud/ocp provides stub interfaces / classes for static analysis but
// does not declare composer autoload rules, so register the OCP namespace
// manually for any test that needs to touch Nextcloud base classes.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'OCP\\')) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4));
    $path     = __DIR__ . '/../vendor/nextcloud/ocp/OCP/' . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
});
