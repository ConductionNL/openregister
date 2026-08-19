<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Conduction
// SPDX-License-Identifier: EUPL-1.2

/**
 * Test bootstrap.
 *
 * `nextcloud/coding-standard` depends on `php-cs-fixer/shim`, which ships
 * php-cs-fixer as a PHAR and `"replace"`s `friendsofphp/php-cs-fixer` — so
 * `PhpCsFixer\Config` is NOT on composer's autoload path and requiring the
 * real package alongside the shim is a resolution conflict, not an option.
 *
 * In normal use this never comes up: php-cs-fixer loads
 * `.php-cs-fixer.dist.php` from inside its own runtime, where its classes are
 * already present. It only bites a standalone test that instantiates the
 * config itself, which is exactly what the invariant suite does.
 *
 * So: load the PHAR's own autoloader first. Failing loudly here matters —
 * if this silently did nothing, `class_exists(Config::class)` would be false
 * and a suite that skipped every assertion would exit 0.
 */

$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($composerAutoload)) {
	fwrite(STDERR, "composer install has not been run — no vendor/autoload.php\n");
	exit(2);
}
require_once $composerAutoload;

if (!class_exists(\PhpCsFixer\Config::class, false)) {
	$phar = __DIR__ . '/../vendor/php-cs-fixer/shim/php-cs-fixer.phar';

	if (!is_file($phar)) {
		fwrite(STDERR, "php-cs-fixer shim PHAR not found at $phar\n");
		exit(2);
	}

	$pharAutoload = 'phar://' . $phar . '/vendor/autoload.php';
	if (!file_exists($pharAutoload)) {
		fwrite(STDERR, "the shim PHAR carries no vendor/autoload.php — its layout changed\n");
		exit(2);
	}

	require_once $pharAutoload;
}

if (!class_exists(\PhpCsFixer\Config::class)) {
	fwrite(STDERR, "PhpCsFixer\\Config is still unavailable after bootstrapping.\n");
	exit(2);
}
