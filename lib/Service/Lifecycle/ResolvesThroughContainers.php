<?php

/**
 * OpenRegister ResolvesThroughContainers
 *
 * The container fallback shared by the lifecycle registries: try OR's app
 * container first, then the Nextcloud server container.
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
 * Looks a service id up in the app container, then the server container.
 *
 * The trait also owns the using class's constructor, so the three lifecycle
 * registries (guards, actions, providers) declare their identical wiring once:
 * `$container` (OR's app container), `$serverContainer` (the injected
 * IServerContainer, used instead of the static \OC::$server accessor which
 * lib/ bans) and `$logger`.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
trait ResolvesThroughContainers {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container OR app container, asked first (covers OR-internal services).
	 * @param IServerContainer $serverContainer NC server container, the fallback for FQCN-tagged services in other apps.
	 * @param LoggerInterface $logger Logger for resolution diagnostics.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IServerContainer $serverContainer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Look a service id up, app container first, then the server container.
	 *
	 * When no service is handed out (both lookups throw, or the first answer is
	 * null), the lookup errors are logged after `$logPrefix` and null is
	 * returned; the caller refuses.
	 *
	 * @param string $id        The service id or FQCN to look up.
	 * @param string $logPrefix The start of the error line logged on a miss.
	 *
	 * @return mixed The service, or null when none was handed out.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	protected function lookUp(string $id, string $logPrefix): mixed {
		$instance = null;
		$errors = [];
		foreach ([$this->container, $this->serverContainer] as $candidate) {
			try {
				$instance = $candidate->get($id);
				break;
			} catch (\Throwable $e) {
				$errors[] = $e->getMessage();
			}
		}

		if ($instance === null) {
			$this->logger->error($logPrefix . implode(' | ', $errors));
		}
		return $instance;
	}//end lookUp()
}//end trait
