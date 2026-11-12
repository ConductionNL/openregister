<?php

/**
 * OpenRegister LifecycleGuardRegistry
 *
 * Resolves a transition's `requires` DI tag to a concrete
 * `LifecycleGuardInterface` instance. Apps register guards via Application's
 * `registerService()` keyed by tag (e.g. `decidesk.meeting.openGuard`).
 *
 * Cache per request — multiple transitions on the same object during one
 * request reuse the resolved instance.
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

use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use RuntimeException;

/**
 * Resolves DI tag → guard instance.
 *
 * Missing tag is fail-closed: a transition that references an unregistered
 * guard cannot proceed. The runtime exception is mapped to HTTP 500 in the
 * controller; install-time `occ openregister:check-guards` (future) catches
 * the same problem before it reaches a request.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleGuardRegistry implements LifecycleGuardRegistryInterface {
	use ResolvesThroughContainers;

	/**
	 * Per-request cache.
	 *
	 * @var array<string, LifecycleGuardInterface>
	 */
	private array $cache = [];

	/**
	 * Resolve a guard tag to its registered implementation.
	 *
	 * @param string $tag DI service tag (e.g. `decidesk.meeting.openGuard`).
	 *
	 * @return LifecycleGuardInterface
	 *
	 * @throws RuntimeException When the tag is not registered or the resolved service does not implement the interface.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function resolve(string $tag): LifecycleGuardInterface {
		if (isset($this->cache[$tag]) === false) {
			$this->cache[$tag] = $this->load(tag: $tag);
		}

		return $this->cache[$tag];
	}//end resolve()

	/**
	 * Resolve a guard tag through the containers, refusing a miss or a wrong type.
	 *
	 * OR's app container is tried first (OR-internal guards), then the injected
	 * server container (FQCN-based references to guards in other apps that
	 * Nextcloud can autowire). The server container is injected via
	 * OCP\IServerContainer rather than reached through the static \OC::$server
	 * accessor, which lib/ bans (docs/development-notes/AUDIT_2026-05-01.md).
	 *
	 * @param string $tag DI service tag.
	 *
	 * @return LifecycleGuardInterface
	 *
	 * @throws RuntimeException When the tag is not registered or has the wrong type.
	 */
	private function load(string $tag): LifecycleGuardInterface {
		$instance = $this->lookUp(
			id: $tag,
			logPrefix: sprintf('Lifecycle guard tag "%s" could not be resolved: ', $tag)
		);
		if ($instance === null) {
			throw new RuntimeException(message: sprintf('Lifecycle guard "%s" is not registered.', $tag));
		}

		if (($instance instanceof LifecycleGuardInterface) === false) {
			throw new RuntimeException(
				message: sprintf('Service "%s" does not implement %s.', $tag, LifecycleGuardInterface::class)
			);
		}

		return $instance;
	}//end load()
}//end class
