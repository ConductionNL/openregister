<?php

/**
 * SeedDirectoryInterface: the import of the operator's seed directory.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Configuration;

/**
 * Imports the seed directory an operator mounted and names it in config.php.
 *
 * The repair step that seeds a fresh installation asks for this, not for the
 * importer that does the work.
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md#requirement-an-installation-is-seeded-from-an-operators-mounted-directory-without-the-admin-req-smd-001
 */
interface SeedDirectoryInterface {

	/**
	 * The `config.php` key naming the directory.
	 */
	public const CONFIG_KEY = 'openregister.seed_directory';

	/**
	 * Import the directory, whatever its digest.
	 *
	 * @return SeedOutcomeInterface|null The outcome; null when no directory is configured.
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md#requirement-an-installation-is-seeded-from-an-operators-mounted-directory-without-the-admin-req-smd-001
	 */
	public function import(): ?SeedOutcomeInterface;
}//end interface
