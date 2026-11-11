<?php

/**
 * SchemaObjectReaderInterface: the part of SchemaObjectReader that the schema migration controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

/**
 * The methods of SchemaObjectReader that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SchemaObjectReaderInterface {

	/**
	 * Whether stored values can be read (the object mapper is available).
	 *
	 * @return boolean True when a conversion preview can read stored values.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function canReadValues(): bool;

	/**
	 * Every stored value of one property, across the schema's objects.
	 *
	 * @param integer $schemaId The schema id.
	 * @param string $property The property name.
	 *
	 * @return array<int,mixed> The stored values, one per object.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function storedValues(int $schemaId, string $property): array;

	/**
	 * Resolve a register id that contains the given schema.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return int|null A register id, or null when none contains the schema.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function resolveRegisterId(int $schemaId): ?int;
}//end interface
