<?php

/**
 * OpenRegister IntegrationsController services
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

use OCA\OpenRegister\Service\Integration\IntegrationCatalogueInterface;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of IntegrationsController, wired by DI autowiring.
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-18
 */
final class IntegrationsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param IntegrationCatalogueInterface $registry Integration registry.
	 * @param IUserSession $userSession Current user session.
	 * @param IGroupManager $groupManager Group manager (admin check).
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-18
	 */
	public function __construct(
		public readonly IntegrationCatalogueInterface $registry,
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
