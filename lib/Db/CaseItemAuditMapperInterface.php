<?php

/**
 * CaseItemAuditMapperInterface: what callers outside the component use of CaseItemAuditMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The append-only case-item audit the case plan services and the case fixtures write and read.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to CaseItemAuditMapper.
 */
interface CaseItemAuditMapperInterface {

	/**
	 * Append an entry, stamping `created`.
	 *
	 * @param Entity $entity The entry to append.
	 *
	 * @return CaseItemAudit The appended entry.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function insert(Entity $entity): CaseItemAudit;

	/**
	 * The audit trail of one plan item, oldest first.
	 *
	 * @param int $caseItemId The item's row id.
	 *
	 * @return array<int, CaseItemAudit> The entries.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function findForItem(int $caseItemId): array;

	/**
	 * The audit trail of a whole plan, oldest first.
	 *
	 * @param array<int, int> $caseItemIds The plan's row ids.
	 *
	 * @return array<int, CaseItemAudit> The entries.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function findForItems(array $caseItemIds): array;
}//end interface
