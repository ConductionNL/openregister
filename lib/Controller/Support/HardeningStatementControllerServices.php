<?php

/**
 * OpenRegister HardeningStatementController services
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

use OCA\OpenRegister\Service\Hardening\ElevationServiceInterface;
use OCA\OpenRegister\Service\Hardening\StatementServiceInterface;
use OCP\IUserSession;

/**
 * The collaborators of HardeningStatementController, wired by DI autowiring.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
 */
final class HardeningStatementControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param StatementServiceInterface $statements Publishes the statement, and records an acceptance.
	 * @param ElevationServiceInterface $elevation Guards the administration writes with a fresh sign-in.
	 * @param IUserSession $userSession Names the account, which is never read from the request.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 */
	public function __construct(
		public readonly StatementServiceInterface $statements,
		public readonly ElevationServiceInterface $elevation,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
