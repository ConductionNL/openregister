<?php

/**
 * DI-container regression tests for the built-in IntegrationProvider
 * factory wiring.
 *
 * The pre-existing provider tests (BuiltinProvidersMetadataTest, the
 * per-provider *Test classes) construct each provider DIRECTLY with
 * mocked constructor args. That shape proves a provider behaves
 * correctly once built, but it is blind to a mis-wired DI factory
 * closure in Application::registerBuiltinIntegrationProviders().
 *
 * That blind spot bit us in production: when CalendarProvider,
 * EmailProvider and TasksProvider gained Tier-2 constructor args, their
 * factory closures were not updated to pass them. The providers threw
 * on construction, the boot loop's try/catch silently swallowed the
 * Throwable, and the three tabs simply vanished from the OCS registry
 * (provider count 22 instead of 25) — only caught by a live-NC E2E.
 *
 * These tests close that gap by exercising the actual factory closures:
 *  - capture every closure registered by
 *    registerBuiltinIntegrationProviders() through a fake
 *    IRegistrationContext;
 *  - invoke each provider closure with a container that resolves any
 *    requested dependency to a mock, and assert it builds the right
 *    provider WITHOUT throwing.
 *
 * Running this test against the unfixed factories fails (the three
 * closures throw ArgumentCountError on the missing Tier-2 args).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-17
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TagsProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\Providers\BookmarksProvider;
use OCA\OpenRegister\Service\Integration\Providers\CalendarProvider;
use OCA\OpenRegister\Service\Integration\Providers\ContactsProvider;
use OCA\OpenRegister\Service\Integration\Providers\DeckProvider;
use OCA\OpenRegister\Service\Integration\Providers\EmailProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use OCA\OpenRegister\Service\Integration\Providers\PollsProvider;
use OCA\OpenRegister\Service\Integration\Providers\SharesProvider;
use OCA\OpenRegister\Service\Integration\Providers\TalkProvider;
use OCA\OpenRegister\Service\Integration\Providers\XwikiProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * Exercises the provider DI factory closures the way the NC container
 * does at boot, rather than instantiating providers directly.
 */
class BuiltinProviderDiFactoryTest extends TestCase {

	/**
	 * The 22 built-in provider classes whose factory closures must
	 * construct without throwing. (External + greenfield leaves are
	 * registered via $greenfieldProviders directly with ::class, which
	 * the container can autowire; this list is the closures that pass
	 * explicit constructor args — i.e. the ones a Tier-2 arg can break.)
	 *
	 * @var array<class-string<IntegrationProvider>>
	 */
	private const PROVIDER_CLASSES = [
		FilesProvider::class,
		NotesProvider::class,
		TasksProvider::class,
		TagsProvider::class,
		AuditTrailProvider::class,
		XwikiProvider::class,
		OpenProjectProvider::class,
		CalendarProvider::class,
		ContactsProvider::class,
		DeckProvider::class,
		EmailProvider::class,
		BookmarksProvider::class,
		PollsProvider::class,
		SharesProvider::class,
		TalkProvider::class,
	];

	/**
	 * Capture every closure registered by
	 * registerBuiltinIntegrationProviders() keyed by its service id.
	 *
	 * @return array<string, callable> service-id => factory closure
	 */
	private function captureFactories(): array {
		$factories = [];

		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')
			->willReturnCallback(
				static function (string $id, callable $factory) use (&$factories): void {
					$factories[$id] = $factory;
				}
			);

		$app = new Application();
		$method = new ReflectionMethod(Application::class, 'registerBuiltinIntegrationProviders');
		$method->setAccessible(true);
		$method->invoke($app, $context);

		return $factories;
	}//end captureFactories()

	/**
	 * A container that resolves any requested service to a PHPUnit mock.
	 * The factory closures only need their dependencies to be the right
	 * *type*; the closure bodies don't call into them, so mocks suffice
	 * to prove the wiring (arg count + named args) is correct.
	 */
	private function mockingContainer(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(
				function (string $id) use (&$container) {
					if ($id === ContainerInterface::class) {
						return $container;
					}

					if (interface_exists($id) === true || class_exists($id) === true) {
						return $this->createMock($id);
					}

					// Unknown service id — surface it loudly.
					throw new \RuntimeException('Unresolvable dependency: ' . $id);
				}
			);
		$container->method('has')->willReturn(true);

		return $container;
	}//end mockingContainer()

	/**
	 * Every built-in provider factory closure must construct its
	 * provider through the (mocked) container without throwing. This is
	 * the assertion that fails on the unfixed Tier-2 wiring for
	 * Calendar/Email/Tasks.
	 */
	public function testEveryProviderFactoryConstructsWithoutThrowing(): void {
		$factories = $this->captureFactories();
		$container = $this->mockingContainer();

		foreach (self::PROVIDER_CLASSES as $providerClass) {
			$this->assertArrayHasKey(
				$providerClass,
				$factories,
				$providerClass . ' has no registered DI factory closure'
			);

			try {
				$provider = ($factories[$providerClass])($container);
			} catch (\Throwable $e) {
				$this->fail(
					'DI factory for ' . $providerClass . ' threw on construction: '
					. get_class($e) . ': ' . $e->getMessage()
				);
			}

			$this->assertInstanceOf(
				$providerClass,
				$provider,
				$providerClass . ' factory returned the wrong type'
			);
			$this->assertInstanceOf(
				IntegrationProvider::class,
				$provider,
				$providerClass . ' is not an IntegrationProvider'
			);
		}
	}//end testEveryProviderFactoryConstructsWithoutThrowing()

	/**
	 * Guard the specific Tier-2 regressions: the three providers whose
	 * factory closures were the production bug must each build with
	 * their full constructor satisfied.
	 *
	 * @dataProvider tier2ProviderProvider
	 */
	public function testTier2ProviderFactoriesAreConstructed(string $providerClass): void {
		$factories = $this->captureFactories();
		$this->assertArrayHasKey($providerClass, $factories);

		$provider = ($factories[$providerClass])($this->mockingContainer());
		$this->assertInstanceOf($providerClass, $provider);
	}//end testTier2ProviderFactoriesAreConstructed()

	/**
	 * The three providers whose factory closures gained Tier-2 args.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function tier2ProviderProvider(): array {
		return [
			'calendar' => [CalendarProvider::class],
			'email' => [EmailProvider::class],
			'tasks' => [TasksProvider::class],
		];
	}//end tier2ProviderProvider()

}//end class
