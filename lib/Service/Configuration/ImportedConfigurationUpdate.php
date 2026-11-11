<?php

/**
 * A Configuration and the result of the import that updates it.
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

use OCA\OpenRegister\Db\Configuration;

/**
 * The Configuration an import tracks, and the import result, which later
 * steps may still add to.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportedConfigurationUpdate {

	/**
	 * Constructor.
	 *
	 * @param Configuration        $configuration The tracked configuration.
	 * @param array<string, mixed> $result        The import result.
	 */
	public function __construct(
		public readonly Configuration $configuration,
		public array $result,
	) {
	}//end __construct()
}//end class
