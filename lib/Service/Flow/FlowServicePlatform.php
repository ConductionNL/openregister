<?php

/**
 * OpenRegister Flow Service Platform
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The acting user, the log and the lazy service lookup the flow service works with.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
 */
final readonly class FlowServicePlatform {

	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession $userSession Identifies the acting user.
	 * @param LoggerInterface $logger Records refusals and failures.
	 * @param ContainerInterface $container Resolves OrganisationService lazily.
	 */
	public function __construct(
		public IUserSession $userSession,
		public LoggerInterface $logger,
		public ContainerInterface $container,
	) {
	}//end __construct()
}//end class
