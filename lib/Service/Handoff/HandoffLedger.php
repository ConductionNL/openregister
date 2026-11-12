<?php

/**
 * OpenRegister Handoff Ledger
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
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

namespace OCA\OpenRegister\Service\Handoff;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\HandoffQueueEntryMapperInterface;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Where a handoff leaves its trace: the audit rows, the queue, the transaction and the log.
 *
 * @spec openspec/specs/semantic-object-handoff/spec.md#requirement-handoffservice-executes-conversions-on-top-of-semantictyperesolver
 */
final readonly class HandoffLedger {

	/**
	 * Bundle the collaborators.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper Immutable audit rows.
	 * @param HandoffQueueEntryMapperInterface $queueMapper Durable queue-mode storage.
	 * @param IDBConnection $db Transaction boundary.
	 * @param LoggerInterface $logger Structured logging.
	 */
	public function __construct(
		public AuditTrailMapperInterface $auditTrailMapper,
		public HandoffQueueEntryMapperInterface $queueMapper,
		public IDBConnection $db,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
