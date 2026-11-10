<?php

/**
 * OpenRegister SchemaObjectWrite
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

/**
 * The data a schema run writes back to one object: its migrated data, or
 * its pre-migration snapshot on rollback.
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
final class SchemaObjectWrite {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data The object data to save.
	 * @param string|null          $uuid The object uuid.
	 */
	public function __construct(
		public readonly array $data,
		public readonly ?string $uuid,
	) {
	}//end __construct()
}//end class
