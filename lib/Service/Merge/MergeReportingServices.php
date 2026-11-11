<?php

/**
 * OpenRegister MergeReportingServices
 *
 * Where a merge reports what happened: the event, the audit trail and the log.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Where a merge reports what happened: the event, the audit trail and the log.
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.1
 */
final readonly class MergeReportingServices {

	/**
	 * Bundle the services.
	 *
	 * @param IEventDispatcher          $eventDispatcher  Dispatcher used to fire `ObjectsMergedEvent`.
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit writer for a refused merge attempt.
	 * @param LoggerInterface           $logger           PSR logger.
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.1
	 */
	public function __construct(
		public IEventDispatcher $eventDispatcher,
		public AuditTrailMapperInterface $auditTrailMapper,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
