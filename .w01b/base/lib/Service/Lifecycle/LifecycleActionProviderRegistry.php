<?php

/**
 * OpenRegister LifecycleActionProviderRegistry
 *
 * Resolves an annotation's `provider` DI tag to a concrete
 * `LifecycleActionProviderInterface` instance. Apps register providers via
 * Application's `registerService()` keyed by tag, or let Nextcloud autowire
 * the FQCN the annotation names.
 *
 * Cache per request — several reads of the same object during one request
 * reuse the resolved instance.
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

use OCA\OpenRegister\Exception\LifecycleProviderException;
use OCA\OpenRegister\Lifecycle\LifecycleActionProviderInterface;
use OCP\IServerContainer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves DI tag → provider instance.
 *
 * Missing tag is fail-closed, the same policy `LifecycleGuardRegistry` and
 * `LifecycleActionRegistry` enforce. It matters more here than for either of
 * them: an unresolved provider that returned an empty list would render as a
 * lifecycle with no moves, which is indistinguishable from a correct answer.
 * So it throws, and the controller reports 502.
 *
 * Not declared `final`: the engine tests substitute a registry that resolves
 * without a live container, mirroring `LifecycleActionRegistry`'s rationale.
 */
class LifecycleActionProviderRegistry {

	/**
	 * Per-request cache.
	 *
	 * @var array<string, LifecycleActionProviderInterface>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container OR app container used to resolve provider services first.
	 * @param IServerContainer $serverContainer NC server container used as fallback for FQCN-tagged providers in other apps.
	 * @param LoggerInterface $logger Logger for provider resolution diagnostics.
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
	 * Resolve a provider tag to its registered implementation.
	 *
	 * @param string $tag DI service tag or FQCN (e.g. `OCA\Dossiq\Lifecycle\CaseActionProvider`).
	 *
	 * @return LifecycleActionProviderInterface
	 *
	 * @throws LifecycleProviderException When the tag is not registered or the resolved service does not implement the interface.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function resolve(string $tag): LifecycleActionProviderInterface {
		if (isset($this->cache[$tag]) === true) {
			return $this->cache[$tag];
		}

		$instance = null;
		$errors = [];
		// Try OR's app container first (covers OR-internal providers) and
		// fall back to the injected server container (covers FQCN-based
		// references to providers in other apps that Nextcloud can autowire).
		// The server container is injected via OCP\IServerContainer rather
		// than reached through the static \OC::$server accessor, which lib/
		// bans.
		foreach ([$this->container, $this->serverContainer] as $candidate) {
			try {
				$instance = $candidate->get($tag);
				break;
			} catch (\Throwable $e) {
				$errors[] = $e->getMessage();
			}
		}

		if ($instance === null) {
			$this->logger->error(
				sprintf('Lifecycle provider tag "%s" could not be resolved: %s', $tag, implode(' | ', $errors))
			);
			throw new LifecycleProviderException(
				message: sprintf('Lifecycle provider "%s" is not registered.', $tag)
			);
		}

		if (($instance instanceof LifecycleActionProviderInterface) === false) {
			throw new LifecycleProviderException(
				message: sprintf(
					'Service "%s" does not implement %s.',
					$tag,
					LifecycleActionProviderInterface::class
				)
			);
		}

		$this->cache[$tag] = $instance;
		return $instance;
	}//end resolve()
}//end class
