<?php

/**
 * CascadeTarget
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Where a nested object of a pre-validation cascade is created: under which
 * parent, in which register, and from which property definition.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class CascadeTarget {
	/**
	 * The property definition the nested object is created from.
	 *
	 * @var array
	 */
	private array $definition = [];

	/**
	 * Constructor.
	 *
	 * @param string   $parentUuid The uuid of the parent object.
	 * @param int|null $register   The register the parent is saved in.
	 */
	public function __construct(
		public readonly string $parentUuid,
		public readonly ?int $register,
	) {
	}//end __construct()

	/**
	 * This target with another property definition.
	 *
	 * @param array $definition The property definition.
	 *
	 * @return self
	 */
	public function with(array $definition): self {
		$target = clone $this;
		$target->definition = $definition;

		return $target;
	}//end with()

	/**
	 * The property definition the nested object is created from.
	 *
	 * @return array
	 */
	public function definition(): array {
		return $this->definition;
	}//end definition()
}//end class
