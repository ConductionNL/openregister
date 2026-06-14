<?php

/**
 * Standalone bootstrap for unit tests that don't require Nextcloud environment.
 *
 * Loads only the Composer autoloader plus stub interfaces so pure-logic
 * tests (RetentionEvaluator, ArchivalAnnotationValidator, etc.) can run
 * without a live Nextcloud installation.
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load Nextcloud OCP stubs so Entity, TimedJob, etc. are available
// for unit tests without a running Nextcloud installation.
$ocpStubsDir = __DIR__ . '/../vendor/nextcloud/ocp';
if (is_dir($ocpStubsDir) === true) {
    $loader = new \Composer\Autoload\ClassLoader();
    $loader->addPsr4('OCP\\', $ocpStubsDir . '/OCP');
    $loader->register(true);
}

// Load Doctrine DBAL from the Nextcloud 3rdparty directory when present.
// OCP\DB\QueryBuilder\IQueryBuilder type-hints Doctrine\DBAL\ParameterType,
// which ships in the server's 3rdparty tree rather than as a Composer
// dependency of this app. Probe the in-tree relative path first, then the
// container's canonical server root, so the type-hints resolve regardless
// of where the worktree is checked out.
foreach ([
    __DIR__ . '/../../../3rdparty/doctrine/dbal/src',
    '/var/www/html/3rdparty/doctrine/dbal/src',
] as $doctrineDbalDir) {
    if (is_dir($doctrineDbalDir) === true) {
        $loader2 = new \Composer\Autoload\ClassLoader();
        $loader2->addPsr4('Doctrine\\DBAL\\', $doctrineDbalDir);
        $loader2->register(true);
        break;
    }
}
