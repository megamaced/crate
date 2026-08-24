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

// The OCP stubs define IQueryBuilder's PARAM_* constants in terms of
// doctrine/dbal's, but nextcloud/ocp does not depend on doctrine/dbal — so
// loading IQueryBuilder (which mocking IDBConnection does) fails on a class
// that is not there. Supply the handful of constant holders it reaches for,
// with doctrine's own values, unless a real doctrine/dbal is installed.
spl_autoload_register(static function (string $class): void {
    switch ($class) {
        case 'Doctrine\\DBAL\\ParameterType':
            eval('namespace Doctrine\\DBAL; class ParameterType {
                public const NULL = 0;
                public const INTEGER = 1;
                public const STRING = 2;
                public const LARGE_OBJECT = 3;
                public const BOOLEAN = 5;
                public const BINARY = 16;
                public const ASCII = 17;
            }');
            break;
        case 'Doctrine\\DBAL\\ArrayParameterType':
            eval('namespace Doctrine\\DBAL; class ArrayParameterType {
                public const INTEGER = 101;
                public const STRING = 102;
                public const BINARY = 116;
                public const ASCII = 117;
            }');
            break;
        case 'Doctrine\\DBAL\\Types\\Types':
            eval('namespace Doctrine\\DBAL\\Types; class Types {
                public const BOOLEAN = "boolean";
                public const DATE_MUTABLE = "date";
                public const DATE_IMMUTABLE = "date_immutable";
                public const DATETIME_MUTABLE = "datetime";
                public const DATETIME_IMMUTABLE = "datetime_immutable";
                public const DATETIMETZ_MUTABLE = "datetimetz";
                public const DATETIMETZ_IMMUTABLE = "datetimetz_immutable";
                public const TIME_MUTABLE = "time";
                public const TIME_IMMUTABLE = "time_immutable";
            }');
            break;
    }
});
