<?php

/**
 * How one schema is imported: by whom, for which app and version.
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
 * The caller's settings for one ImportHandler::importSchema() call.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class SchemaImportOptions {

	/**
	 * Constructor.
	 *
	 * @param string|null $owner   The owner to set on the schema, null to leave it.
	 * @param string|null $appId   The application importing the schema, null to leave it.
	 * @param string|null $version The version of the import.
	 * @param bool        $force   Apply the import even when its version is not newer.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Force flag to override version checks
	 */
	public function __construct(
		public readonly ?string $owner,
		public readonly ?string $appId,
		public readonly ?string $version,
		public readonly bool $force,
	) {
	}//end __construct()
}//end class
