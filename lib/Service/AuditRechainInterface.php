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
 * Re-chaining the audit trail end to end, verifying it and counting the unsealed backlog.
 * Only the methods RechainAuditTrailCommand and RechainAuditTrailForFlowAttribution call.
 */
interface AuditRechainInterface {

	/**
	 * Re-derive every audit row's hash in id order so the trail is one chain again.
	 *
	 * Retention tombstones keep their stored hash and are carried forward as the
	 * next row's predecessor. A deliberate operator repair only.
	 *
	 * @param int $batchSize Rows re-chained per query window.
	 *
	 * @return array{rechained: int, tombstonesPreserved: int} Rows rewritten, and tombstones left alone.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function rechainAll(int $batchSize = 500): array;

	/**
	 * Verify the hash chain, optionally over an id range.
	 *
	 * @param int|null $from First audit trail id to verify (inclusive), or null for the start.
	 * @param int|null $to   Last audit trail id to verify (inclusive), or null for the end.
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
