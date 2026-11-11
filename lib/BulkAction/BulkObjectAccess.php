<?php

/**
 * OpenRegister bulk object access
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
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

namespace OCA\OpenRegister\BulkAction;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;

/**
 * How a bulk action reaches the member it works on: the member's schema, the
 * object write (or a run as the job's actor), and the log a failed write goes to.
 *
 * Shared by ApplyRuleAction, RestorePriorValuesAction and RunActionAction
 * (ADR-011 Rule 4). It holds no guard: a schema read states its own `_rbac`
 * and `_multitenancy` flags at the call.
 */
final class BulkObjectAccess {

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects Writes the member, or runs a call as the job's actor.
	 * @param SchemaMapperInterface     $schemas Reads the member's schema.
	 * @param LoggerInterface           $logger  Where a failed write is logged.
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objects,
		public readonly SchemaMapperInterface $schemas,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
