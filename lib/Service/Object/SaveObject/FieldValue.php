<?php

/**
 * OpenRegister FieldValue
 *
 * A field name with the raw value read from the object data at that path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * A field path and the value found there, for relation-to-name resolution.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final readonly class FieldValue {

	/**
	 * Constructor.
	 *
	 * @param string $name The field path, also the schema property name.
	 * @param mixed $value The raw value at that path.
	 */
	public function __construct(
		public string $name,
		public mixed $value,
	) {
	}//end __construct()
}//end class
