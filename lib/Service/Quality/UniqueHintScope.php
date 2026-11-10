<?php

/**
 * OpenRegister UniqueHintScope
 *
 * The register and schema a soft-uniqueness check looks inside.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

/**
 * Where the soft-uniqueness alert looks: one register and one schema.
 *
 * The references stay untyped (`mixed`), exactly as the writer passes them,
 * so carrying them here coerces and refuses nothing.
 *
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
 */
final readonly class UniqueHintScope {

	/**
	 * Constructor.
	 *
	 * @param mixed $register Register reference (int|string).
	 * @param mixed $schema   Schema reference (int|string).
	 */
	public function __construct(
		public mixed $register,
		public mixed $schema,
	) {
	}//end __construct()

	/**
	 * What to look up for one nominated property's incoming value, inside this scope.
	 *
	 * @param string $property The nominated property.
	 * @param mixed  $value    The incoming scalar value.
	 *
	 * @return UniqueHintLookup The lookup.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function lookup(string $property, mixed $value): UniqueHintLookup {
		return new UniqueHintLookup(property: $property, value: $value, register: $this->register, schema: $this->schema);
	}//end lookup()
}//end class
