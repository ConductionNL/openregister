<?php

/**
 * OpenRegister UniqueHintLookup
 *
 * One nominated property's incoming value, and the register and schema it is
 * looked up in.
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
 * What the soft-uniqueness alert looks up: a value for a property inside one register and schema.
 *
 * The value and the references stay untyped (`mixed`), exactly as the checker
 * received them, so carrying them here coerces and refuses nothing.
 *
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
 */
final readonly class UniqueHintLookup {

	/**
	 * Constructor.
	 *
	 * @param string $property The nominated property.
	 * @param mixed $value The incoming scalar value.
	 * @param mixed $register Register reference (int|string).
	 * @param mixed $schema Schema reference (int|string).
	 */
	public function __construct(
		public string $property,
		public mixed $value,
		public mixed $register,
		public mixed $schema,
	) {
	}//end __construct()
}//end class
