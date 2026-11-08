<?php

/**
 * OpenRegister FlowRunMigrationController services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCP\IUserSession;

/**
 * The collaborators of FlowRunMigrationController, wired by DI autowiring.
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
 */
final class FlowRunMigrationControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowRunMapperInterface $mapper The run store.
	 * @param IUserSession $userSession Who is asking.
	 * @param FlowRunnableGuard $guard Whether the caller may run this flow at all.
	 * @param ?FlowRunMigrationServiceInterface $migrations Moves runs onto another version. Nullable;
	 *                                                     absent, the endpoints report the surface unavailable rather than migrating unvalidated.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md
	 */
	public function __construct(
		public readonly FlowRunMapperInterface $mapper,
		public readonly IUserSession $userSession,
		public readonly FlowRunnableGuard $guard,
		public readonly ?FlowRunMigrationServiceInterface $migrations = null,
	) {
	}//end __construct()
}//end class
