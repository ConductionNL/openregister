<?php

/**
 * One firing's effect, as handed to FlowRunCommit.
 *
 * A plain value carrier: which stream fired which transition, the places it
 * consumed and the places it actually took, the items now on those places,
 * the log entry to record, and whether anything is enabled afterwards. The
 * commit fills in the stream lineage it settled (`carrierStreamId`,
 * `childStreamIds`) so the engine can keep its in-memory picture aligned.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The delta and bookkeeping of one firing.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A value carrier; every field
 * is one thing the commit writes in its single transaction.
 */
class FlowFiring {

	/**
	 * The stream the commit settled as carrying the token onward.
	 *
	 * @var string
	 */
	public string $carrierStreamId = '';

	/**
	 * Children minted by a split, keyed by taken output place.
	 *
	 * @var array<string, string>
	 */
	public array $childStreamIds = [];

	/**
	 * Constructor.
	 *
	 * @param string $streamId The stream that fired.
	 * @param string $transition The transition name.
	 * @param array<int, string> $froms The input places consumed.
	 * @param array<int, string> $taken The output places actually taken, in declaration order.
	 * @param array<string, array> $itemsByPlace The items now on each taken place.
	 * @param array<int, string> $claimedPlaces The places this firing held claims on.
	 * @param array<int, string> $consumedStreamIds Other streams whose tokens a join consumed.
	 * @param array $logEntry The engine's log entry for the step row.
	 * @param bool $enabledAfter Whether any transition is enabled after the delta.
	 * @param string $streamStatus The carrier stream's status after the firing.
	 * @param string|null $streamError The carrier's error, when the step failed under `continue`.
	 * @param string $streamPath The firing stream's ordinal path, used to mint its row when none exists yet.
	 * @param string|null $streamParent The firing stream's parent id, for the same minting.
	 */
	public function __construct(
		public readonly string $streamId,
		public readonly string $transition,
		public readonly array $froms,
		public readonly array $taken,
		public readonly array $itemsByPlace,
		public readonly array $claimedPlaces,
		public readonly array $consumedStreamIds,
		public readonly array $logEntry,
		public readonly bool $enabledAfter,
		public readonly string $streamStatus = 'running',
		public readonly ?string $streamError = null,
		public readonly string $streamPath = '0001',
		public readonly ?string $streamParent = null,
	) {

	}//end __construct()
}//end class
