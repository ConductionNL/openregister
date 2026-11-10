<?php

/**
 * OpenRegister ObjectsMergedEvent
 *
 * Dispatched after MergeService::executeMerge() or ::reverseMerge() completes.
 * Carries the survivor uuid, the merged-from uuids, the mergeOperation id, and
 * a flag distinguishing a merge from a reversal, so downstream apps (leaf-app
 * sync, OR's own WebhookService) can subscribe and react. OpenRegister does
 * NOT enqueue an app-specific sync queue — this event IS the propagation
 * surface (ADR-045).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Fired after a merge (or a merge reversal) completes.
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#3.1
 */
class ObjectsMergedEvent extends Event {
	/**
	 * Capture the merge participants for downstream listeners.
	 *
	 * @param string $survivorUuid UUID of the surviving object.
	 * @param array<int, string> $mergedFromUuids UUIDs of the merged-away objects.
	 * @param string $mergeOperationId UUID of the persisted `mergeOperation` row.
	 * @param bool $isReversal True when this event represents a reversal, not a merge.
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#3.1
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Immutable read-only event
	 *   data, not a control-flow switch: `isReversal` is a fact about what
	 *   already happened (a merge vs. its reversal), the shape the spec
	 *   requires so a single subscriber can distinguish the two without a
	 *   second event class.
	 */
	public function __construct(
		private readonly string $survivorUuid,
		private readonly array $mergedFromUuids,
		private readonly string $mergeOperationId,
		private readonly bool $isReversal = false,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Read the surviving object's uuid.
	 *
	 * @return string Survivor uuid.
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#3.1
	 */
	public function getSurvivorUuid(): string {
		return $this->survivorUuid;
	}//end getSurvivorUuid()

	/**
	 * Read the merged-away object uuids.
	 *
	 * @return array<int, string> Merged-from uuids.
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#3.1
	 */
	public function getMergedFromUuids(): array {
		return $this->mergedFromUuids;
	}//end getMergedFromUuids()

	/**
	 * Read the persisted `mergeOperation` row id.
	 *
	 * @return string Merge-operation uuid.
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#3.1
	 */
	public function getMergeOperationId(): string {
		return $this->mergeOperationId;
	}//end getMergeOperationId()

	/**
	 * Whether this event represents a reversal rather than a merge.
	 *
	 * @return bool True when this is a reversal.
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#3.1
	 */
	public function isReversal(): bool {
		return $this->isReversal;
	}//end isReversal()
}//end class
