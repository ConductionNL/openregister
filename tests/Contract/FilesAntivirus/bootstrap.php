<?php

/**
 * Bootstrap for the files_antivirus contract suite.
 *
 * Loads OpenRegister's standalone unit bootstrap (OCP from nextcloud/ocp), then
 * the files_antivirus tree named by FILES_ANTIVIRUS_PATH. Without that variable the
 * suite refuses to run: a contract check that silently skips reads the same as
 * one that passed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Contract\FilesAntivirus
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap-unit-standalone.php';

$antivirusPath = getenv('FILES_ANTIVIRUS_PATH');
if (is_string($antivirusPath) === false || $antivirusPath === '') {
	fwrite(STDERR, "FILES_ANTIVIRUS_PATH is not set. Point it at an unpacked files_antivirus app (its appinfo/ and lib/).\n");
	exit(1);
}

$antivirusPath = rtrim($antivirusPath, '/');
if (is_file($antivirusPath . '/appinfo/info.xml') === false || is_dir($antivirusPath . '/lib') === false) {
	fwrite(STDERR, "FILES_ANTIVIRUS_PATH ($antivirusPath) holds no files_antivirus app: appinfo/info.xml or lib/ is missing.\n");
	exit(1);
}

define('FILES_ANTIVIRUS_CONTRACT_PATH', $antivirusPath);

// The release tarball ships its own classmap; a git checkout has only lib/.
// Both are registered, the app's own loader first.
if (is_file($antivirusPath . '/composer/autoload.php') === true) {
	require_once $antivirusPath . '/composer/autoload.php';
}

$antivirusLoader = new \Composer\Autoload\ClassLoader();
$antivirusLoader->addPsr4('OCA\\Files_Antivirus\\', $antivirusPath . '/lib');
$antivirusLoader->register();
