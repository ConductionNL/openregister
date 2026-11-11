<?php

/**
 * OpenRegister move reporting
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
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Where a move is reported: the one `moved` audit entry, and the log for a
 * stranded source row or an entry that could not be written.
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
 */
final class MoveReporting {
	/**
	 * Bundle the services.
	 *
	 * @param AuditTrailMapperInterface $audit  The audit trail.
	 * @param LoggerInterface           $logger The logger.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly AuditTrailMapperInterface $audit,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
