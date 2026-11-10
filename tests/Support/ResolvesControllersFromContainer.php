<?php

/**
 * Resolve controllers from the app container, overriding one collaborator.
 *
 * 🔴 WHY THIS EXISTS: A TEST THAT NAMES A CONSTRUCTOR PINS IT. The two big
 * controller suites built their controllers by hand, positionally, and every
 * dependency a controller gained broke them:
 *
 *   Too few arguments to RegistersController::__construct(), 16 passed ...
 *   and exactly 21 expected
 *
 * 160 tests errored before their first assertion, in suites CI never ran, so
 * nobody saw it. Resolving from the container instead means a new dependency
 * is wired by the same code that wires it in production, and a test only
 * names the collaborator it actually needs to control.
 *
 * The pattern is the repo's own: `tests/Service/RbacScopeDiscoveryIntegrationTest`
 * and `VerwerkingsactiviteitenControllerIntegrationTest::makeController()`
 * already resolve controllers from the container, and `tests/manual` and
 * `tests/test-chat-rag.php` take the app container from
 * `\OCA\OpenRegister\AppInfo\Application`. What is added here is the part
 * those two could not do: an override for the request (and the session), so a
 * test can carry a payload without building the controller by hand.
 *
 * ⚠️ TWO PROPERTIES THIS TRAIT EXISTS TO GUARANTEE, both measured:
 *
 *  1. The container CACHES what it resolves: `SimpleContainer::query()` stores
 *     the instance back as a service, so a second `get()` returns the first
 *     controller, still holding the FIRST test's request. Registering the
 *     controller class as a NON-SHARED service that delegates to `resolve()`
 *     gives a freshly wired controller per test, with no constructor list.
 *  2. An override left behind is a leak. Overriding `IRequest` on the app
 *     container changes what EVERY later test file resolves, which is the
 *     same failure the Service suite already paid for with a leaked admin
 *     session. `restoreContainerOverrides()` puts back exactly what was
 *     there, and belongs in tearDown.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Support;

use OCA\OpenRegister\AppInfo\Application;
use OCP\AppFramework\IAppContainer;

/**
 * Container-resolved controllers with per-test collaborator overrides.
 */
trait ResolvesControllersFromContainer {

	/**
	 * The app container, taken once per test.
	 *
	 * @var IAppContainer|null
	 */
	private ?IAppContainer $appContainer = null;

	/**
	 * What each overridden service id answered before this test touched it.
	 *
	 * @var array<string, mixed>
	 */
	private array $containerOverridesToRestore = [];

	/**
	 * The app container for `openregister`.
	 *
	 * @return IAppContainer The container production wires from.
	 */
	protected function appContainer(): IAppContainer {
		if ($this->appContainer === null) {
			$this->appContainer = (new Application())->getContainer();
		}

		return $this->appContainer;
	}//end appContainer()

	/**
	 * Make the container answer a service id with the given instance.
	 *
	 * Remembers what it answered before, so tearDown can put it back.
	 *
	 * @param string $id       The service id, usually an interface name.
	 * @param object $instance The double this test wants to control.
	 *
	 * @return void
	 */
	protected function overrideContainerService(string $id, object $instance): void {
		$container = $this->appContainer();

		if (array_key_exists($id, $this->containerOverridesToRestore) === false) {
			try {
				$this->containerOverridesToRestore[$id] = $container->get($id);
			} catch (\Throwable $e) {
				// Nothing was registered under this id; restoring means
				// putting the absence back, which the null records.
				$this->containerOverridesToRestore[$id] = null;
			}
		}

		$container->registerService($id, static fn () => $instance);
	}//end overrideContainerService()

	/**
	 * A freshly wired controller of the given class.
	 *
	 * Never names a constructor argument: whatever the controller declares is
	 * resolved by the container, so a new dependency needs no test change.
	 *
	 * @param string $class The controller class name.
	 *
	 * @return object The controller, wired from the container.
	 *
	 * @template T of object
	 * @psalm-param class-string<T> $class
	 * @psalm-return T
	 */
	protected function resolveController(string $class): object {
		$container = $this->appContainer();

		// Non-shared, so every test gets a controller carrying that test's
		// overrides rather than the first test's.
		$container->registerService(
			$class,
			static fn ($c) => $c->resolve($class),
			false
		);

		return $container->get($class);
	}//end resolveController()

	/**
	 * Put every overridden service back the way it was found.
	 *
	 * Call from tearDown. Without it the next test file resolves this test's
	 * doubles, which is how a suite starts lying to itself.
	 *
	 * @return void
	 */
	protected function restoreContainerOverrides(): void {
		if ($this->appContainer === null) {
			return;
		}

		foreach ($this->containerOverridesToRestore as $id => $instance) {
			if ($instance === null) {
				continue;
			}

			$this->appContainer->registerService($id, static fn () => $instance);
		}

		$this->containerOverridesToRestore = [];
	}//end restoreContainerOverrides()
}//end trait
