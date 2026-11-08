<?php

/**
 * OpenRegister Case Plan Store
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItemAuditMapper;
use OCA\OpenRegister\Db\CaseItemMapperInterface;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Where a plan lives: the plan-item table, the append-only audit, the transaction and the failure log.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
final readonly class CasePlanStore {

	/**
	 * Bundle the collaborators.
	 *
	 * @param CaseItemMapperInterface $items The plan-item table.
	 * @param CaseItemAuditMapper $audits The append-only audit.
	 * @param IDBConnection $db Holds the creation transactions.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		public CaseItemMapperInterface $items,
		public CaseItemAuditMapper $audits,
		public IDBConnection $db,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
