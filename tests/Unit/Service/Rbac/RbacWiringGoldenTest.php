<?php

/**
 * Golden test for RbacWiring::register(): which services it registers, in which
 * order, and what each factory builds.
 *
 * The permission path depends on these registrations being explicit and shared: an
 * unshared token-grant source evaluates every scoped token as unscoped, and an
 * unregistered hierarchy expander silently stops inheriting grants. This pins the
 * registrations so a refactor of register() cannot drop or reorder one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\RbacWiring;
use OCA\OpenRegister\Service\Rbac\RevealCollector;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\RbacWiring
 */
class RbacWiringGoldenTest extends TestCase {

	/**
	 * Run register() against a context that records every registration.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: bool}> Name, factory and shared flag, in order.
	 */
	private function registrations(): array {
		$recorded = [];
		$context  = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory, bool $shared=true) use (&$recorded): void {
				$recorded[] = [$name, $factory, $shared];
			}
		);

		RbacWiring::register($context);

		return $recorded;
	}

	/**
	 * A container that resolves the collaborators the factories ask for.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$services = [
			IDBConnection::class => $this->createMock(IDBConnection::class),
			SchemaMapperInterface::class => $this->createMock(SchemaMapperInterface::class),
			RegisterMapperInterface::class => $this->createMock(RegisterMapperInterface::class),
			LoggerInterface::class => new NullLogger(),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use (&$services, $container) {
				if ($id === HierarchyDescender::class) {
					return new HierarchyDescender(
						db: $services[IDBConnection::class],
						schemaMapper: $services[SchemaMapperInterface::class],
						registerMapper: $services[RegisterMapperInterface::class],
						logger: $services[LoggerInterface::class],
					);
				}

				if ($id === HierarchyGrantExpander::class) {
					return new HierarchyGrantExpander(
						descender: $container->get(HierarchyDescender::class),
						logger: $services[LoggerInterface::class],
					);
				}

				return $services[$id];
			}
		);

		return $container;
	}

	public function testItRegistersTheSevenSharedServicesInOrder(): void {
		$registrations = $this->registrations();

		$this->assertSame(
			[
				TokenGrantSource::class,
				TokenGrantNarrower::class,
				HierarchyDescender::class,
				HierarchyGrantExpander::class,
				ObjectGrantResolver::class,
				RevealCollector::class,
				ExplicitReveal::class,
			],
			array_map(static fn (array $entry): string => $entry[0], $registrations)
		);
		foreach ($registrations as [$name, , $shared]) {
			$this->assertTrue($shared, $name . ' must be registered shared');
		}
	}

	public function testEachFactoryBuildsTheServiceItIsRegisteredUnder(): void {
		$container = $this->container();

		foreach ($this->registrations() as [$name, $factory]) {
			$this->assertInstanceOf($name, $factory($container), $name);
		}
	}

	public function testTheObjectGrantResolverIsBuiltWithTheHierarchyExpander(): void {
		$registrations = $this->registrations();
		$resolver      = $registrations[4][1]($this->container());

		$hierarchy = (new \ReflectionProperty(ObjectGrantResolver::class, 'hierarchy'))->getValue($resolver);

		$this->assertInstanceOf(HierarchyGrantExpander::class, $hierarchy);
	}

	public function testTheTokenGrantSourceFactoryBuildsAFreshSourceEachCall(): void {
		$factory = $this->registrations()[0][1];

		$this->assertNotSame($factory(), $factory());
	}
}
