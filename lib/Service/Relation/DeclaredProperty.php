<?php

/**
 * One property of a schema whose relation declaration is being validated.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * A schema property by name, with its definition as the schema holds it.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class DeclaredProperty {
	/**
	 * Hold the property.
	 *
	 * @param string $name       The property name.
	 * @param mixed  $definition The property definition, as the schema holds it.
	 */
	public function __construct(
		public readonly string $name,
		public readonly mixed $definition,
	) {
	}//end __construct()
}//end class
