<?php

/**
 * AuditSealBacklogCursorInterface: the reads the seal-backlog driver walks the backlog with.
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
 * Where the backlog of unsealed audit rows is, read without loading payloads.
 */
interface AuditSealBacklogCursorInterface {

	/**
	 * The newest row that is a verified chain link: it carries a hash and is no retention tombstone.
	 *
	 * Rows after it can be sealed in place; an unsealed row before it cannot,
	 * because the link after it already chained over it.
	 *
	 * @return int The id, or 0 when no row is a link yet.
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
	 */
	public function lastLinkId(): int;

	/**
	 * Up to `$limit` ids of rows without a hash after the given id, ascending.
	 *
	 * @param int $afterId Exclusive lower bound on `id`.
	 * @param int $limit   Most ids to return.
	 *
	 * @return list<int> The ids, oldest first.
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
	 */
	public function unsealedIdsAfter(int $afterId, int $limit): array;

	/**
	 * How many rows without a hash sit before the given id.
	 *
	 * @param int $beforeId Exclusive upper bound on `id`.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
	 */
	public function countUnsealedBefore(int $beforeId): int;
}//end interface
