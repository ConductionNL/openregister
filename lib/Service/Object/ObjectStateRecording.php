<?php

/**
 * OpenRegister ObjectStateRecording
 *
 * Where an archive or freeze transition is recorded: the audit trail and the log.
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
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Where an archive or freeze transition is recorded: the audit trail and the log.
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */
final readonly class ObjectStateRecording {

	/**
	 * Bundle the services.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit entries for each transition.
	 * @param LoggerInterface           $logger           PSR-3 logger.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public AuditTrailMapperInterface $auditTrailMapper,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
