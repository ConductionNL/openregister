<?php

/**
 * OpenRegister bulk save reporting
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * What a bulk save reports to: the log, the lifecycle events and the audit trail.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkSaveReporting {
	/**
	 * Bundle the services.
	 *
	 * @param LoggerInterface                $logger           Logger for error and debug logging
	 * @param IEventDispatcher|null          $eventDispatcher  Event dispatcher for object lifecycle events
	 * @param AuditTrailMapperInterface|null $auditTrailMapper Audit trail mapper for logging bulk changes
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
		public readonly ?IEventDispatcher $eventDispatcher = null,
		public readonly ?AuditTrailMapperInterface $auditTrailMapper = null,
	) {
	}//end __construct()
}//end class
