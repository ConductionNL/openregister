<?php

/**
 * The platform services a Flow link is kept and hydrated through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/integration-flow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\FlowLinkMapperInterface;
use OCP\App\IAppManager;
use OCP\IDBConnection;

/**
 * The platform bundle of {@see FlowLinkService}: the mapper the link rows
 * live in, the database the `flow_operations` fallback is read from, and the
 * app manager that says whether WorkflowEngine is installed.
 *
 * @spec openspec/specs/integration-flow/spec.md
 */
final class FlowLinkPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param FlowLinkMapperInterface $flowLinkMapper Persistence for link rows.
	 * @param IDBConnection           $db             Database connection.
	 * @param IAppManager             $appManager     NC app manager.
	 *
	 * @spec openspec/specs/integration-flow/spec.md
	 */
	public function __construct(
		public readonly FlowLinkMapperInterface $flowLinkMapper,
		public readonly IDBConnection $db,
		public readonly IAppManager $appManager,
	) {
	}//end __construct()
}//end class
