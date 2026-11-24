<?php

/**
 * PHPStan bootstrap file - registers OCP autoloader for static analysis.
 */

$autoloader = require __DIR__ . '/vendor/autoload.php';
$autoloader->addPsr4('OCP\\', __DIR__ . '/vendor/nextcloud/ocp/OCP/');
$autoloader->addPsr4('NCU\\', __DIR__ . '/vendor/nextcloud/ocp/NCU/');

// Keep the old name of every moved class resolvable, as Application::register() does at
// boot (ADR-012 Rule 4): an implementer or caller that still names a moved interface by
// its old name in appinfo/class-aliases.json must analyse as it runs.
\OCA\OpenRegister\AppInfo\ClassAliasMap::fromFile(__DIR__ . '/appinfo/class-aliases.json')->aliasClasses();
