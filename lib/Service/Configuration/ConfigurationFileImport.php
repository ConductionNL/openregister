<?php

/**
 * One configuration file to import for an app.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * The app, the file path of its configuration, the version it ships and whether the import is forced.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationFileImport {

	/**
	 * Constructor.
	 *
	 * @param string $appId    The importing app.
	 * @param string $filePath The path to the configuration file (relative to Nextcloud root).
	 * @param string $version  The configuration version.
	 * @param bool   $force    Whether to import regardless of version.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Force flag to override version checks
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $filePath,
		public readonly string $version,
		public readonly bool $force = false,
	) {
	}//end __construct()
}//end class
