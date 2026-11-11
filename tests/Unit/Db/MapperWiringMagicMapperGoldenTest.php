<?php

/**
 * Characterisation of how MapperWiring builds the MagicMapper: which services it reads from
 * the container first, in which order, and where each one lands.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MapperWiring;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Golden MagicMapper wiring.
 *
 * @covers \OCA\OpenRegister\Db\MapperWiring
 */
final class MapperWiringMagicMapperGoldenTest extends TestCase {

	/**
	 * The MagicMapper is built from the container's services, read in this order.
	 *
	 * @return void
	 */
	public function testMagicMapperWiring(): void {
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);
		MapperWiring::register($context);

		$services = [
			'OCP\IDBConnection' => $this->createMock(IDBConnection::class),
			SchemaMapperInterface::class => $this->createMock(SchemaMapperInterface::class),
			RegisterMapperInterface::class => $this->createMock(RegisterMapperInterface::class),
			'OCP\IUserSession' => $this->createMock(IUserSession::class),
			'OCP\IGroupManager' => $this->createMock(IGroupManager::class),
			'OCP\IUserManager' => $this->createMock(IUserManager::class),
			'OCP\IConfig' => $this->createMock(IConfig::class),
			'OCP\IAppConfig' => $this->createMock(IAppConfig::class),
			'OCP\EventDispatcher\IEventDispatcher' => $this->createMock(IEventDispatcher::class),
			'Psr\Log\LoggerInterface' => $this->createMock(LoggerInterface::class),
		];
		$asked = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$asked, $services): mixed {
				$asked[] = $id;
				if (isset($services[$id]) === true) {
					return $services[$id];
				}

				return $this->createMock($id);
			}
		);

		$mapper = $factories[MagicMapper::class]($container);

		$this->assertInstanceOf(MagicMapper::class, $mapper);
		$this->assertSame(array_keys($services), array_slice($asked, 0, count($services)));
		$landing = [
			'db' => 'OCP\IDBConnection',
			'schemaMapper' => SchemaMapperInterface::class,
			'registerMapper' => RegisterMapperInterface::class,
			'userSession' => 'OCP\IUserSession',
			'groupManager' => 'OCP\IGroupManager',
			'userManager' => 'OCP\IUserManager',
			'config' => 'OCP\IConfig',
			'appConfig' => 'OCP\IAppConfig',
			'eventDispatcher' => 'OCP\EventDispatcher\IEventDispatcher',
			'logger' => 'Psr\Log\LoggerInterface',
		];
		foreach ($landing as $property => $id) {
			$this->assertSame($services[$id], (new ReflectionProperty(MagicMapper::class, $property))->getValue($mapper), $property);
		}

		$this->assertSame($container, (new ReflectionProperty(MagicMapper::class, 'container'))->getValue($mapper));
	}//end testMagicMapperWiring()
}//end class
