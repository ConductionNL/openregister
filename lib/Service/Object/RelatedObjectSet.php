<?php

/**
 * OpenRegister RelatedObjectSet
 *
 * The related objects one outgoing lookup collects, each wanted uuid at most once per table read.
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
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Collects the related objects the per-table reads return, then falls back to the located rows.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class RelatedObjectSet {

	/**
	 * The objects collected from the per-table reads, in read order.
	 *
	 * @var ObjectEntity[]
	 */
	private array $objects = [];

	/**
	 * The uuids collected so far.
	 *
	 * @var array<int, string|null>
	 */
	private array $foundUuids = [];

	/**
	 * Constructor.
	 *
	 * @param array<mixed, string> $wanted The related uuids the lookup asks for.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(private readonly array $wanted) {
	}//end __construct()

	/**
	 * Keep an object a per-table read returned when its uuid is wanted and not collected yet.
	 *
	 * @param ObjectEntity $object The returned object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function offer(ObjectEntity $object): void {
		$uuid = $object->getUuid();
		if (in_array($uuid, $this->wanted, true) === true && in_array($uuid, $this->foundUuids, true) === false) {
			$this->objects[] = $object;
			$this->foundUuids[] = $uuid;
		}
	}//end offer()

	/**
	 * The collected objects, followed by every located row whose uuid no per-table read returned.
	 *
	 * @param ObjectEntity[] $located The rows the cross-table lookup found.
	 *
	 * @return ObjectEntity[]
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function withFallback(array $located): array {
		$missingUuids = array_diff($this->wanted, $this->foundUuids);
		$objects = $this->objects;
		foreach ($located as $candidate) {
			if (in_array($candidate->getUuid(), $missingUuids, true) === true) {
				$objects[] = $candidate;
			}
		}

		return $objects;
	}//end withFallback()
}//end class
