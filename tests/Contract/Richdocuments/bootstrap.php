<?php

/**
 * Bootstrap for the richdocuments contract suite.
 *
 * Loads OpenRegister's standalone unit bootstrap (OCP from nextcloud/ocp), then
 * the richdocuments tree named by RICHDOCUMENTS_PATH. Without that variable the
 * suite refuses to run: a contract check that silently skips reads the same as
 * one that passed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Contract\Richdocuments
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap-unit-standalone.php';

$richdocumentsPath = getenv('RICHDOCUMENTS_PATH');
if (is_string($richdocumentsPath) === false || $richdocumentsPath === '') {
	fwrite(STDERR, "RICHDOCUMENTS_PATH is not set. Point it at an unpacked richdocuments app (its appinfo/ and lib/).\n");
	exit(1);
}

$richdocumentsPath = rtrim($richdocumentsPath, '/');
if (is_file($richdocumentsPath . '/appinfo/info.xml') === false || is_dir($richdocumentsPath . '/lib') === false) {
	fwrite(STDERR, "RICHDOCUMENTS_PATH ($richdocumentsPath) holds no richdocuments app: appinfo/info.xml or lib/ is missing.\n");
	exit(1);
}

define('RICHDOCUMENTS_CONTRACT_PATH', $richdocumentsPath);

// The release tarball ships its own classmap; a git checkout has only lib/.
// Both are registered, the app's own loader first.
if (is_file($richdocumentsPath . '/composer/autoload.php') === true) {
	require_once $richdocumentsPath . '/composer/autoload.php';
}

$richdocumentsLoader = new \Composer\Autoload\ClassLoader();
$richdocumentsLoader->addPsr4('OCA\\Richdocuments\\', $richdocumentsPath . '/lib');
$richdocumentsLoader->register();
