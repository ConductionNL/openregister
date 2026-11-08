<?php

/**
 * AuditHashServiceInterface: what callers outside the component use of AuditHashService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * The audit hash-chain checks the audit trail endpoints run.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AuditHashServiceInterface {

	/**
	 * A cheap, page-load-safe summary of chain health.
	 *
	 * Deliberately NOT a verification. {@see verifyChain()} reads every row and
	 * recomputes every hash — on the instance this was written against that is
	 * 308,937 SHA-256 computations, far too slow to run because somebody opened
	 * a settings page. This is three COUNT/MAX queries, so an admin sees the one
	 * number that actually rots over time — how many rows the chain cannot vouch
	 * for — without paying for a full walk.
	 *
	 * Verification stays an explicit, operator-initiated action. The distinction
	 * matters: "no rows are unsealed" says the sweeper is keeping up, and says
	 * nothing at all about whether the hashes that ARE stored still agree with
	 * their rows. Only verifyChain() can answer that, and the UI must not let
	 * the cheap number stand in for the expensive one.
	 *
	 * @return array{total: int, sealed: int, unsealed: int, coverage: float, lastSealedId: int|null}
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function getIntegrityStatus(): array;

	/**
	 * Verify the integrity of the hash chain.
	 *
	 * Iterates audit trail entries in order and validates that each entry's
	 * stored hash matches the recomputed hash.
	 *
	 * ## Retention tombstones
	 *
	 * A row purged under a retention policy keeps its `id`, `created`, `hash`
	 * and `previous_hash` and is stamped with `purged_at`; its payload is
	 * blanked (see {@see \OCA\OpenRegister\Db\AuditTrailMapper::clearLogs()}).
	 * Its content therefore no longer re-hashes to the stored value, but the
	 * stored value is still the link the NEXT row committed to — so the chain
	 * is carried forward across it and the row is counted as a declared
	 * tombstone rather than reported as a break.
	 *
	 * This is what makes a lawful purge distinguishable from tampering. Before
	 * tombstoning, a purge physically removed the row and verification failed
	 * at the row AFTER it, producing exactly the symptom an attacker would
	 * (or#2265): the audit trail could not tell the two apart.
	 *
	 * ⚠️ RESIDUAL LIMITATION, stated plainly: `purgedAt` is deliberately not
	 * part of the canonical JSON, because adding any key to it would change
	 * the hash of every row ever written and invalidate the whole existing
	 * chain. So the flag itself is not hash-protected: an attacker with direct
	 * table write access could set `purged_at` and blank a row's payload to
	 * suppress a record without breaking verification. That is strictly
	 * weaker and strictly more VISIBLE than the previous situation — the row,
	 * its timestamp and its position all remain, and `purgedTombstones` is
	 * reported and countable — but it is not cryptographic proof that a
	 * tombstone was lawful. Binding the flag into the hash requires a chain
	 * re-seal and is tracked separately.
	 *
	 * @param int|null $from Start entry ID (inclusive), null for beginning
	 * @param int|null $to End entry ID (inclusive), null for end
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function verifyChain(?int $from = null, ?int $to = null): array;
}//end interface
