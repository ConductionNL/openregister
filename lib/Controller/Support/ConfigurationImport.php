<?php

/**
 * OpenRegister ConfigurationImport
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\Configuration;

/**
 * A finished import: the configuration it created and what it imported.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationImport {
	/**
	 * Describe the import.
	 *
	 * @param Configuration $configuration The imported configuration
	 * @param array         $result        The import result with registers, schemas and objects
	 */
	public function __construct(
		public readonly Configuration $configuration,
		public readonly array $result,
	) {
	}//end __construct()

	/**
	 * How many registers, schemas and objects were imported.
	 *
	 * @return array{registersCount: int, schemasCount: int, objectsCount: int} The counts
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function counts(): array {
		return [
			'registersCount' => count($this->result['registers']),
			'schemasCount' => count($this->result['schemas']),
			'objectsCount' => count($this->result['objects']),
		];
	}//end counts()
}//end class
