<?php

/**
 * ProtectedPropertyRead: one protected property met while filtering an object for reading.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;

/**
 * A property with an authorization block, met while an object is filtered
 * for reading: the schema, the property, its block and the object.
 *
 * ADR-011 Rule 2 component-local value object shared by
 * PropertyRbacHandler::keepIfReadable() and recordReveal().
 *
 * @internal
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
final class ProtectedPropertyRead {

	/**
	 * Describe it.
	 *
	 * @param Schema $schema        The schema the object belongs to.
	 * @param string $property      The property name.
	 * @param mixed  $authorization The property's authorization block.
	 * @param array  $object        The object being filtered.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $property,
		public readonly mixed $authorization,
		public readonly array $object,
	) {
	}//end __construct()
}//end class
