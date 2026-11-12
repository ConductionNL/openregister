<?php

/**
 * An imported schema definition and the stored schema it updates.
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

use OCA\OpenRegister\Db\Schema;

/**
 * The pair ImportHandler::importSchema() compares when a schema already exists.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportedSchemaUpdate {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data     The imported schema definition.
	 * @param Schema               $existing The stored schema.
	 */
	public function __construct(
		public readonly array $data,
		public readonly Schema $existing,
	) {
	}//end __construct()

	/**
	 * The version the import ships, `0.0.0` when it declares none.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function incomingVersion(): mixed {
		return ($this->data['version'] ?? '0.0.0');
	}//end incomingVersion()

	/**
	 * The stored version, `0.0.0` when the schema has none.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function existingVersion(): string {
		return ($this->existing->getVersion() ?? '0.0.0');
	}//end existingVersion()
}//end class
