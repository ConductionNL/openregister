<?php

/**
 * ComputedExpression — one computed property a schema declares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Schema;

/**
 * The schema, the computed property's name and its Twig expression.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class ComputedExpression {
	/**
	 * Hold the computed property.
	 *
	 * @param Schema $schema       The schema declaring it (resolves cross-references).
	 * @param string $propertyName The property name.
	 * @param string $expression   The Twig expression.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $propertyName,
		public readonly string $expression,
	) {
	}//end __construct()

	/**
	 * The sandbox template name the expression is rendered under.
	 *
	 * @return string `computed_<property>_<xxh128 of the expression>`.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function templateName(): string {
		return 'computed_' . $this->propertyName . '_' . hash(algo: 'xxh128', data: $this->expression);
	}//end templateName()
}//end class
