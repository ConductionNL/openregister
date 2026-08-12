<?php

/**
 * OpenRegister LifecycleActionRegistry
 *
 * Resolves a declared lifecycle `action` name to a concrete
 * `LifecycleActionInterface` instance. OpenRegister ships built-in handlers
 * (keyed by action name); apps register additional handlers via DI, where the
 * service id / tag equals the declared action name (e.g. `materialise-gl-transaction`).
 *
 * Mirrors `LifecycleGuardRegistry` exactly: OR container first, server
 * container fallback, per-request cache, and — the anti-phantom guard from
 * approval-chains (#396) — a declared action naming a handler that resolves to
 * nothing FAILS LOUDLY instead of silently no-oping.
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

use OCA\OpenRegister\Lifecycle\Action\SetFieldsAction;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCP\IServerContainer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolves action name → handler instance.
 *
 * A declared action that resolves to no handler is fail-loud: the executor lets
 * the thrown `RuntimeException` propagate, which aborts the save and surfaces
 * the misconfiguration instead of hiding a dead capability. This is the same
 * missing-tag policy `LifecycleGuardRegistry` enforces for guards.
 *
 * Not declared `final`: LifecycleActionExecutorTest and LifecycleActionListenerTest
 * double this class (they control handler resolution without a live container).
 * Mirrors TransitionEngine's doubling rationale.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class LifecycleActionRegistry {

	/**
	 * Built-in action-name → handler-FQCN map.
	 *
	 * OpenRegister ships these; they are resolved through the OR container so
	 * their own dependencies are autowired. Fleet-declared but not-yet-built-in
	 * action names (e.g. `materialise-gl-transaction`, `audit-trail-append`,
	 * `emit-event`) are intentionally absent — an app that declares one MUST
	 * register a handler under that id, or resolution fails loudly.
	 *
	 * @var array<string, class-string<LifecycleActionInterface>>
	 */
	private const BUILTINS = [
		'set-fields' => SetFieldsAction::class,
		'set-field' => SetFieldsAction::class,
	];

	/**
	 * Per-request cache.
	 *
	 * @var array<string, LifecycleActionInterface>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container OR app container, used for built-ins + OR-internal handlers.
	 * @param IServerContainer $serverContainer NC server container, used as fallback for FQCN-tagged handlers in other apps.
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
	 * Resolve a declared action name to its handler.
	 *
	 * @param string $actionName The declared `action` value (e.g. `set-fields`).
	 *
	 * @return LifecycleActionInterface
	 *
	 * @throws RuntimeException When the action resolves to no service, or the resolved service does not implement the interface.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function resolve(string $actionName): LifecycleActionInterface {
		if (isset($this->cache[$actionName]) === true) {
			return $this->cache[$actionName];
		}

		// Built-in handlers resolve by their FQCN through the OR container so
		// their constructor dependencies are autowired.
		$lookup = ($actionName);
		if (isset(self::BUILTINS[$actionName]) === true) {
			$lookup = self::BUILTINS[$actionName];
		}

		$instance = null;
		$errors = [];
		// Try OR's app container first (covers built-ins + OR-internal
		// handlers) and fall back to the injected server container (covers
		// FQCN-based references to handlers in other apps that Nextcloud can
		// autowire). The server container is injected via IServerContainer
		// rather than the static \OC::$server accessor, which lib/ bans.
		foreach ([$this->container, $this->serverContainer] as $candidate) {
			try {
				$instance = $candidate->get($lookup);
				break;
			} catch (\Throwable $e) {
				$errors[] = $e->getMessage();
			}
		}

		if ($instance === null) {
			$this->logger->error(
				sprintf('Lifecycle action "%s" could not be resolved: %s', $actionName, implode(' | ', $errors))
			);
			throw new RuntimeException(
				message: sprintf(
					'Lifecycle action "%s" is declared but no handler is registered. '
					. 'Register a service implementing %s under the id "%s", or remove the action from the schema.',
					$actionName,
					LifecycleActionInterface::class,
					$actionName
				)
			);
		}

		if (($instance instanceof LifecycleActionInterface) === false) {
			throw new RuntimeException(
				sprintf(
					'Service resolved for lifecycle action "%s" does not implement %s.',
					$actionName,
					LifecycleActionInterface::class
				)
			);
		}

		$this->cache[$actionName] = $instance;
		return $instance;
	}//end resolve()
}//end class
