<?php
/**
 * OpenRegister LifecycleGuardContext
 *
 * What a transition guard judges: the incoming data, the stored object, the
 * transition and the caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Lifecycle
 * @package  OCA\OpenRegister\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Read-only input of LifecycleTransitionGuardInterface::checkTransition().
 *
 * `stored` is the entity being saved, as it is in the register before this write.
 * Facts the writer must not be able to change (which object this is, who filed
 * it, which run it belongs to) are read from there; `object` is what the writer
 * sends, and its `id` may name another object, so a guard never resolves the
 * object it judges from it.
 *
 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-a-transition-guard-can-judge-the-stored-object
 */
final class LifecycleGuardContext {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $object The incoming data, as it would be stored.
	 * @param ObjectEntity         $stored The entity being saved, as stored before this write.
	 * @param string               $action The transition being applied.
	 * @param string               $userId The uid of the caller, '' when there is none.
	 */
	public function __construct(
		public readonly array $object,
		public readonly ObjectEntity $stored,
		public readonly string $action,
		public readonly string $userId,
	) {
	}//end __construct()

	/**
	 * The uuid of the object being saved, from the stored entity.
	 *
	 * @return string
	 */
	public function uuid(): string {
		return (string)$this->stored->getUuid();
	}//end uuid()

	/**
	 * The stored object's data.
	 *
	 * @return array<string, mixed>
	 */
	public function storedData(): array {
		return ($this->stored->getObject() ?? []);
	}//end storedData()
}//end class
