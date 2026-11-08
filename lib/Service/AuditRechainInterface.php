<?php

/**
 * AuditRechainInterface: what the operator re-chain command and repair step ask of AuditHashService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Re-chaining the whole audit trail, verifying it and counting the unsealed backlog.
 *
 * Only the methods the re-chain command and the flow-attribution repair step call.
 */
interface AuditRechainInterface {

	/**
	 * Re-chain every audit row that can be re-chained, oldest first.
	 *
	 * Rewrites stored hashes; it must only ever run as a deliberate operator repair.
	 *
	 * @param int $batchSize Rows re-chained per query window; the default is AuditHashService's sweep batch size.
	 *
	 * @return array{rechained: int, tombstonesPreserved: int} Rows rewritten, and tombstones left alone.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function rechainAll(int $batchSize = 500): array;

	/**
	 * Verify the integrity of the audit hash chain.
	 *
	 * @param int|null $from Start entry ID (inclusive), null for beginning
	 * @param int|null $to   End entry ID (inclusive), null for end
	 *
	 * @return array{
	 *     valid: bool,
	 *     entriesVerified: int,
	 *     brokenAt: int|null,
	 *     skippedNullHashes: int,
	 *     purgedTombstones: int,
	 *     range?: array{from: int, to: int}
	 * }
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function verifyChain(?int $from = null, ?int $to = null): array;

	/**
	 * Count rows still awaiting a seal.
	 *
	 * @return int The number of unsealed rows.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function countUnsealed(): int;
}//end interface
