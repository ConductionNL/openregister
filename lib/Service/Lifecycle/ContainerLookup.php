<?php

/**
 * OpenRegister ContainerLookup
 *
 * The two containers a lifecycle registry looks a service id up in, and its logger.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCP\IServerContainer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Looking a service id up the way the lifecycle registries do: OpenRegister's
 * app container first, the Nextcloud server container as the fallback for
 * FQCN-tagged services in other apps, and the logger that reports a miss
 * (ADR-011 Rule 4 capability aggregate, used through ResolvesThroughContainers).
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ContainerLookup {

	/**
	 * Bundle the services.
	 *
	 * @param ContainerInterface $container       OR app container, asked first (covers OR-internal services).
	 * @param IServerContainer   $serverContainer NC server container, the fallback for FQCN-tagged services in other apps.
	 * @param LoggerInterface    $logger          Logger for resolution diagnostics.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ContainerInterface $container,
		public readonly IServerContainer $serverContainer,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
