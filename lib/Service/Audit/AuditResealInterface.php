<?php

/**
 * AuditResealInterface: what an act that rewrites stored audit rows asks of AuditHashService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
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

namespace OCA\OpenRegister\Service\Audit;

/**
 * Verify the chain before a lawful rewrite, then re-seal it from the first rewritten row.
 *
 * ADR-003 Rule 4's order: verify, record the verdict, re-seal. The caller
 * records the verdict; this interface gives it the two halves.
 */
interface AuditResealInterface {

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
	 *     unkeyedEntries: int,
	 *     keyedFrom: int|null,
	 *     keyBoundaryIntact: bool,
	 *     unsealedAfterCutover: int,
	 *     sealCutoverId: int|null,
	 *     range?: array{from: int, to: int}
	 * }
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function verifyChain(?int $from = null, ?int $to = null): array;

	/**
	 * Re-derive the hash of every row from one id to the end of the chain.
	 *
	 * @param int $fromId The first row whose content changed.
	 *
	 * @return array{rechained: int, tombstonesPreserved: int} Rows rewritten, and tombstones left alone.
	 *
	 * @throws \RuntimeException When the seal lock cannot be taken; nothing is re-sealed then.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
	 */
	public function resealFrom(int $fromId): array;
}//end interface
