<?php

/**
 * AuditSealInterface: what callers outside the Service component ask of AuditHashService.
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
 * Sealing the audit rows left unsealed and counting the backlog, as the seal sweep job does. Only the methods that caller calls.
 */
interface AuditSealInterface {

	/**
	 * Count rows still awaiting a seal.
	 *
	 * Exposed so the sweep job can report progress and so an operator can see
	 * whether the backlog is draining. A backlog that only grows means sealing
	 * is failing somewhere, which is exactly the condition that went unnoticed
	 * for as long as no sweeper existed.
	 *
	 * @return int The number of unsealed rows.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function countUnsealed(): int;

	/**
	 * Seal rows that were left unsealed, oldest first.
	 *
	 * This is the "later seal pass" that the fail-soft log messages promise. Until now it did not exist:
	 * nothing swept unsealed rows, so every fail-soft skip was permanent. On the
	 * instance this was written against, 49,123 of 308,937 audit rows — 15.9% —
	 * had no hash, and never would have.
	 *
	 * That matters more than it sounds. The chain's whole purpose is to make
	 * tampering DETECTABLE; a row with no hash is a row nobody can vouch for.
	 *
	 * Works oldest-first and in id order so each batch chains onto an already
	 * settled predecessor, so a batch derives its predecessor once and chains
	 * forward rather than re-deriving it per row.
	 *
	 * @param int $limit Maximum rows to seal in one pass; the default is AuditHashService's sweep batch size.
	 *
	 * @return int The number of rows sealed.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function sealUnsealed(int $limit = 500): int;
}//end interface
