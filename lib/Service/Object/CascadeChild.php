<?php

/**
 * OpenRegister CascadeChild
 *
 * One checked `cascade: true` child of a root delete.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A child entity and the cascade property that refers to it.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final readonly class CascadeChild {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $entity   The stored child.
	 * @param string       $property The root's property that refers to it.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public ObjectEntity $entity,
		public string $property,
	) {
	}//end __construct()

	/**
	 * The child as a cascade target of a deletion analysis.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function cascadeTarget(): array {
		return [
			'objectUuid' => $this->entity->getUuid(),
			'register'   => $this->entity->getRegister(),
			'schema'     => $this->entity->getSchema(),
			'property'   => $this->property,
			'chain'      => [],
		];
	}//end cascadeTarget()
}//end class
