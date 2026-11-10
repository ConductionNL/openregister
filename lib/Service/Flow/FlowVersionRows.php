<?php

/**
 * OpenRegister Flow Version Rows
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowVersionMapperInterface;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * What a flow version transition writes: the version rows, the flows whose
 * head mirrors them, the connection that makes the writing one transaction,
 * and the log a failed transition is reported to.
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */
final class FlowVersionRows {
	/**
	 * Bundle the rows.
	 *
	 * @param FlowVersionMapperInterface $versions The version rows.
	 * @param FlowMapperInterface        $flows    The flows whose head mirrors the versions.
	 * @param IDBConnection              $db       Wraps each transition in one transaction.
	 * @param LoggerInterface            $logger   Diagnostics.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function __construct(
		public readonly FlowVersionMapperInterface $versions,
		public readonly FlowMapperInterface $flows,
		public readonly IDBConnection $db,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
