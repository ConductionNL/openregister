<?php

/**
 * OpenRegister object integrations access
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

use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;
use OCA\OpenRegister\Service\Object\PermissionHandler;

/**
 * The timeline visibility filter and the object update right the integrations API applies.
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-19
 */
final class ObjectIntegrationsAccess {
	/**
	 * Bundle the services.
	 *
	 * @param TimelineVisibilityServiceInterface $visibility  Internal/public filter for the timeline leaves.
	 * @param PermissionHandler                  $permissions Answers the object update right for integration writes.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-19
	 */
	public function __construct(
		public readonly TimelineVisibilityServiceInterface $visibility,
		public readonly PermissionHandler $permissions,
	) {
	}//end __construct()
}//end class
