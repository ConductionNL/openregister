<?php

/**
 * Characterisation test for DeepLinkRegistryService::requestRegistrations() and resolve().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins when the registry asks the apps for their deep links, how often, through
 * which dispatcher, what it logs, and which registration resolve() returns.
 *
 * @covers \OCA\OpenRegister\Service\DeepLinkRegistryService
 * @uses   \OCA\OpenRegister\Event\DeepLinkRegistrationEvent
 * @uses   \OCA\OpenRegister\Dto\DeepLinkRegistration
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class DeepLinkRegistryRequestGoldenTest extends TestCase {

	private array $log = [];

	protected function setUp(): void {
		DeepLinkRegistryService::reset();
		$this->log = [];
	}//end setUp()

	protected function tearDown(): void {
		DeepLinkRegistryService::reset();
	}//end tearDown()

	public static function dispatcherCases(): array {
		return [
			'given dispatcher' => ['given', ['given.dispatch']],
			'container dispatcher' => ['container', ['container.get', 'container.dispatch']],
			'container returns something else' => ['other', ['container.get']],
			'container throws' => ['throws', ['container.get', 'warning [DeepLinkRegistry] Asking the apps for their deep links failed: {error} no dispatcher']],
			'dispatch throws' => ['dispatchThrows', ['given.dispatch', 'warning [DeepLinkRegistry] Asking the apps for their deep links failed: {error} listener broke']],
		];
	}//end dispatcherCases()

	/**
	 * @dataProvider dispatcherCases
	 */
	public function testRequestRegistrationsAsksOnceThroughTheRightDispatcher(string $mode, array $expected): void {
		$registry = new DeepLinkRegistryService($this->container($mode), $this->logger());
		$given = null;
		if (in_array($mode, ['given', 'dispatchThrows'], true) === true) {
			$given = $this->dispatcher('given', $mode === 'dispatchThrows');
		}

		$registry->requestRegistrations($given);
		$registry->requestRegistrations($given);

		$this->assertSame($expected, $this->log);
	}//end testRequestRegistrationsAsksOnceThroughTheRightDispatcher()

	public static function resolveCases(): array {
		return [
			'both slugs known' => [10, 20, 'app-a'],
			'register slug empty' => [11, 20, null],
			'register slug null' => [12, 20, null],
			'schema unknown' => [10, 99, null],
			'no registration for the pair' => [13, 21, null],
			'register unknown' => [99, 20, null],
		];
	}//end resolveCases()

	/**
	 * @dataProvider resolveCases
	 */
	public function testResolve(int $registerId, int $schemaId, ?string $expectedApp): void {
		$registry = new DeepLinkRegistryService($this->container('container'), $this->logger());
		$registry->register('app-a', 'reg', 'sch', '/a/{uuid}');
		$registry->register('app-b', 'reg-other', 'sch', '/b/{uuid}');

		$this->assertSame($expectedApp, $registry->resolve($registerId, $schemaId)?->appId);
	}//end testResolve()

	public function testResolveWithoutRegistrationsAsksTheAppsAndReturnsNull(): void {
		$registry = new DeepLinkRegistryService($this->container('container'), $this->logger());

		$this->assertNull($registry->resolve(10, 20));
		$this->assertSame(['container.get', 'container.dispatch'], $this->log);
	}//end testResolveWithoutRegistrationsAsksTheAppsAndReturnsNull()

	private function dispatcher(string $name, bool $throws): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($name, $throws): void {
				$this->assertInstanceOf(DeepLinkRegistrationEvent::class, $event);
				$this->log[] = $name . '.dispatch';
				if ($throws === true) {
					throw new RuntimeException('listener broke');
				}
			}
		);
		return $dispatcher;
	}//end dispatcher()

	private function container(string $mode): ContainerInterface {
		$registers = [];
		foreach ([10 => 'reg', 11 => '', 12 => null, 13 => 'reg-x'] as $id => $slug) {
			$register = new Register();
			$register->setId($id);
			$register->setSlug($slug);
			$registers[] = $register;
		}

		$schemas = [];
		foreach ([20 => 'sch', 21 => 'sch-x'] as $id => $slug) {
			$schema = new Schema();
			$schema->setId($id);
			$schema->setSlug($slug);
			$schemas[] = $schema;
		}

		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$registerMapper->method('findAll')->willReturn($registers);
		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$schemaMapper->method('findAll')->willReturn($schemas);
		$dispatcher = $this->dispatcher('container', false);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($mode, $registerMapper, $schemaMapper, $dispatcher) {
				if ($id === RegisterMapperInterface::class) {
					return $registerMapper;
				}

				if ($id === SchemaMapperInterface::class) {
					return $schemaMapper;
				}

				$this->log[] = 'container.get';
				if ($mode === 'throws') {
					throw new RuntimeException('no dispatcher');
				}

				return $mode === 'other' ? new \stdClass() : $dispatcher;
			}
		);
		return $container;
	}//end container()

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->log[] = 'warning ' . $message . ' ' . ($context['error'] ?? '');
			}
		);
		return $logger;
	}//end logger()
}//end class
