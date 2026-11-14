<?php

/**
 * OpenRegister Harvest Batch
 *
 * What a whole-document fetcher returns in one call: the items by external id, whether the whole
 * source was read, the next checkpoint and the per-item errors.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sync;

/**
 * One batch of harvested items.
 *
 * `complete` is true only when the fetcher read the whole source without a
 * failed page. A per-item error (a dataset refused on validation) does not
 * make a batch incomplete: the fetcher still read the source.
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
final readonly class HarvestBatch {

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>>                 $items      Raw payload per external id.
	 * @param bool                                                $complete   Whether the whole source was read.
	 * @param string|null                                         $checkpoint The next incremental checkpoint, written to lastSyncToken.
	 * @param list<array{externalId: string|null, message: string}> $errors     Per-item (or per-page, without an id) errors.
	 */
	public function __construct(
		public array $items,
		public bool $complete,
		public ?string $checkpoint = null,
		public array $errors = [],
	) {
	}//end __construct()

	/**
	 * Every external id this batch saw: its items and every error that names one.
	 *
	 * @return array<string, true> The ids, as keys.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function seenIds(): array {
		$seen = [];
		foreach (array_keys($this->items) as $externalId) {
			$seen[(string)$externalId] = true;
		}

		foreach ($this->errorsById() as $externalId => $message) {
			$seen[$externalId] = true;
		}

		return $seen;
	}//end seenIds()

	/**
	 * The error message per external id, for errors that name one (the last wins).
	 *
	 * @return array<string, string> Message per external id.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function errorsById(): array {
		$byId = [];
		foreach ($this->errors as $error) {
			$externalId = ($error['externalId'] ?? null);
			if ($externalId === null || $externalId === '') {
				continue;
			}

			$byId[(string)$externalId] = (string)($error['message'] ?? '');
		}

		return $byId;
	}//end errorsById()

	/**
	 * This batch cut to the first $max items; a cut batch is incomplete.
	 *
	 * @param int $max The most items one run takes.
	 *
	 * @return self The batch, or a cut, incomplete copy.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function capped(int $max): self {
		if (count($this->items) <= $max) {
			return $this;
		}

		return new self(
			items: array_slice($this->items, 0, $max, true),
			complete: false,
			checkpoint: $this->checkpoint,
			errors: $this->errors
		);
	}//end capped()
}//end class
