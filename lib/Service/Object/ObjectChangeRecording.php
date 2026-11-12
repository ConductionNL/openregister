<?php

/**
 * OpenRegister object change recording
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
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Changing a stored object outside a save, and recording the change: the
 * magic-table store the object is written to, the audit trail the change is
 * entered in, the log for what could not be written, and the dispatcher that
 * announces it.
 *
 * Shared by the handlers that move, lock-release and archive objects (ADR-011
 * Rule 4 aggregate). It holds no access guard: each handler keeps its own
 * permission check as a direct constructor parameter.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
final class ObjectChangeRecording {
	/**
	 * Bundle the services.
	 *
	 * @param MagicMapperInterface      $objects    The magic-table store the object is read from and written to.
	 * @param AuditTrailMapperInterface $auditTrail The audit trail the change is entered in.
	 * @param LoggerInterface           $logger     The log, for a change that could not be completed or recorded.
	 * @param IEventDispatcher          $events     The dispatcher that announces the change.
	 */
	public function __construct(
		public readonly MagicMapperInterface $objects,
		public readonly AuditTrailMapperInterface $auditTrail,
		public readonly LoggerInterface $logger,
		public readonly IEventDispatcher $events,
	) {
	}//end __construct()
}//end class
