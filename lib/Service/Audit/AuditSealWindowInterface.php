<?php

/**
 * AuditSealWindowInterface: sealing one window of audit rows, as the seal-backlog driver asks of AuditHashService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * Seal one window of already-inserted audit rows into the hash chain.
 */
interface AuditSealWindowInterface {

	/**
	 * Seal the unsealed rows in the id range of the given ids, under the seal lock.
	 *
	 * Already sealed rows in the range are never rewritten; they only give the
	 * next row its link. Fail-soft: when the seal lock cannot be taken nothing
	 * is written and 0 is returned.
	 *
	 * @param int[] $ids The audit-trail row ids to seal.
	 *
	 * @return int Number of rows sealed.
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-the-seal-pass-shall-never-re-hash-an-already-sealed-row
	 */
	public function sealRows(array $ids): int;
}//end interface
