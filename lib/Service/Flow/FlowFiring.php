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
 * The constructor takes what the firing moved; the rest is set through the
 * `with`-style copies, each returning a new firing.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
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
	 * The engine's per-place items after the firing.
	 *
	 * @var array<string, array>
	 */
	private array $placeItems = [];

	/**
	 * The places this firing held claims on.
	 *
	 * @var array<int, string>
	 */
	private array $claimedPlaces = [];

	/**
	 * Other streams whose tokens a join consumed.
	 *
	 * @var array<int, string>
	 */
	private array $consumedStreamIds = [];

	/**
	 * The engine's log entry for the step row.
	 *
	 * @var array
	 */
	private array $logEntry = [];

	/**
	 * Whether any transition is enabled after the delta.
	 *
	 * @var boolean
	 */
	private bool $enabledAfter = false;

	/**
	 * The carrier stream's status after the firing.
	 *
	 * @var string
	 */
	private string $streamStatus = 'running';

	/**
	 * The carrier's error, when the step failed under `continue`.
	 *
	 * @var string|null
	 */
	private ?string $streamError = null;

	/**
	 * The firing stream's ordinal path, used to mint its row when none exists yet.
	 *
	 * @var string
	 */
	private string $streamPath = '0001';

	/**
	 * The firing stream's parent id, for the same minting.
	 *
	 * @var string|null
	 */
	private ?string $streamParent = null;

	/**
	 * Constructor.
	 *
	 * @param string $streamId The stream that fired.
	 * @param string $transition The transition name.
	 * @param array<int, string> $froms The input places consumed.
	 * @param array<int, string> $taken The output places actually taken, in declaration order.
	 */
	public function __construct(
		public readonly string $streamId,
		public readonly string $transition,
		public readonly array $froms,
		public readonly array $taken,
	) {

	}//end __construct()

	/**
	 * A copy carrying the engine's per-place items after the firing.
	 *
	 * @param array<string, array> $placeItems The engine's per-place items.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function withPlaceItems(array $placeItems): self {
		$copy = clone $this;
		$copy->placeItems = $placeItems;
		return $copy;
	}//end withPlaceItems()

	/**
	 * A copy holding claims on the given places.
	 *
	 * @param array<int, string> $claimedPlaces The places this firing claimed.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function withClaimedPlaces(array $claimedPlaces): self {
		$copy = clone $this;
		$copy->claimedPlaces = $claimedPlaces;
		return $copy;
	}//end withClaimedPlaces()

	/**
	 * A copy that consumes the tokens of other streams, as a join does.
	 *
	 * @param array<int, string> $consumedStreamIds The consumed streams.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function withConsumedStreamIds(array $consumedStreamIds): self {
		$copy = clone $this;
		$copy->consumedStreamIds = $consumedStreamIds;
		return $copy;
	}//end withConsumedStreamIds()

	/**
	 * A copy recording the given log entry and whether work remains after it.
	 *
	 * @param array $logEntry The engine's log entry.
	 * @param bool $enabledAfter Whether any transition is enabled after the firing.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function withLogEntry(array $logEntry, bool $enabledAfter): self {
		$copy = clone $this;
		$copy->logEntry = $logEntry;
		$copy->enabledAfter = $enabledAfter;
		return $copy;
	}//end withLogEntry()

	/**
	 * A copy leaving the carrier stream in the given status.
	 *
	 * @param string $status The carrier's status after the firing.
	 * @param string|null $error The carrier's error under a `continue` failure.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function withStreamState(string $status, ?string $error): self {
		$copy = clone $this;
		$copy->streamStatus = $status;
		$copy->streamError = $error;
		return $copy;
	}//end withStreamState()

	/**
	 * A copy placed in the stream tree, for minting the stream's row.
	 *
	 * @param string $path The firing stream's ordinal path.
	 * @param string|null $parent The firing stream's parent id.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function withStreamPath(string $path, ?string $parent): self {
		$copy = clone $this;
		$copy->streamPath = $path;
		$copy->streamParent = $parent;
		return $copy;
	}//end withStreamPath()

	/**
	 * The items now on each taken place.
	 *
	 * @return array<string, array> The items.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function getItemsByPlace(): array {
		$items = [];
		foreach ($this->taken as $to) {
			$items[$to] = ($this->placeItems[$to] ?? []);
		}

		return $items;
	}//end getItemsByPlace()

	/**
	 * The engine's per-place items after the firing, every place included.
	 *
	 * @return array<string, array> The items.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function getPlaceItems(): array {
		return $this->placeItems;
	}//end getPlaceItems()

	/**
	 * The places this firing held claims on.
	 *
	 * @return array<int, string> The places.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function getClaimedPlaces(): array {
		return $this->claimedPlaces;
	}//end getClaimedPlaces()

	/**
	 * Other streams whose tokens a join consumed.
	 *
	 * @return array<int, string> The stream ids.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function getConsumedStreamIds(): array {
		return $this->consumedStreamIds;
	}//end getConsumedStreamIds()

	/**
	 * The engine's log entry for the step row.
	 *
	 * @return array The entry.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function getLogEntry(): array {
		return $this->logEntry;
	}//end getLogEntry()

	/**
	 * Whether any transition is enabled after the delta.
	 *
	 * @return bool True when work remains.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function isEnabledAfter(): bool {
		return $this->enabledAfter;
	}//end isEnabledAfter()

	/**
	 * The carrier stream's status after the firing.
	 *
	 * @return string The status.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function getStreamStatus(): string {
		return $this->streamStatus;
	}//end getStreamStatus()

	/**
	 * The carrier's error, when the step failed under `continue`.
	 *
	 * @return string|null The error.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function getStreamError(): ?string {
		return $this->streamError;
	}//end getStreamError()

	/**
	 * The firing stream's ordinal path.
	 *
	 * @return string The path.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function getStreamPath(): string {
		return $this->streamPath;
	}//end getStreamPath()

	/**
	 * The firing stream's parent id.
	 *
	 * @return string|null The parent.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function getStreamParent(): ?string {
		return $this->streamParent;
	}//end getStreamParent()
}//end class
