<?php

/**
 * OpenRegister portal task platform
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

use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * The platform services of the portal task API.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
 */
final class PortalTaskPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param ?IGroupManager   $groupManager Decides administrator status;
	 * @param ?LoggerInterface $logger       Where an unexpected failure's detail
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
	 */
	public function __construct(
		public readonly ?IGroupManager $groupManager = null,
		public readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()
}//end class
