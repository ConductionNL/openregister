<?php

/**
 * OpenRegister v1 audit-chain verification run
 *
 * The state one walk of the audit chain under the frozen v1 canonical form
 * keeps between rows and windows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

/**
 * One walk of the audit chain under v1: the link carried forward, the
 * paging cursor and the counts the verdict reports.
 *
 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
 */
final class V1ChainVerificationRun {

	/**
	 * The hash the next sealed row must commit to; null until the first link, meaning the v1 genesis hash.
	 *
	 * @var string|null
	 */
	public ?string $previousHash = null;

	/**
	 * The last row id read, the lower bound of the next window.
	 *
	 * @var integer
	 */
	public int $afterId = 0;

	/**
	 * Sealed rows whose link verified.
	 *
	 * @var integer
	 */
	public int $entriesVerified = 0;

	/**
	 * Unsealed rows skipped.
	 *
	 * @var integer
	 */
	public int $skippedNullHashes = 0;

	/**
	 * Purged tombstones carried across.
	 *
	 * @var integer
	 */
	public int $purgedTombstones = 0;

	/**
	 * The id of the first row whose link did not verify, if any.
	 *
	 * @var integer|null
	 */
	public ?int $brokenAt = null;

	/**
	 * The verdict of the walk so far.
	 *
	 * @return array{valid: bool, entriesVerified: int, brokenAt: int|null, skippedNullHashes: int, purgedTombstones: int} The verdict.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
	 */
	public function verdict(): array {
		return [
			'valid' => ($this->brokenAt === null),
			'entriesVerified' => $this->entriesVerified,
			'brokenAt' => $this->brokenAt,
			'skippedNullHashes' => $this->skippedNullHashes,
			'purgedTombstones' => $this->purgedTombstones,
		];
	}//end verdict()
}//end class
