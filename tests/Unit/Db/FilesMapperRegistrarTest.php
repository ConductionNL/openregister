<?php

/**
 * The Files area builds and binds the entity relation mapper itself.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\FilesMapperRegistrar;
use OCA\OpenRegister\Db\MapperWiring;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\EntityEventsInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\FilesMapperRegistrar
 * @covers \OCA\OpenRegister\Db\FilesMapperBindings
 * @uses   \OCA\OpenRegister\Db\DbRegistrar
 * @uses   \OCA\OpenRegister\Db\MapperWiring
 */
final class FilesMapperRegistrarTest extends TestCase {

	public function testItRegistersTheMapperAndBindsItsInterface(): void {
		$context = new RecordingRegistrationContext();
		FilesMapperRegistrar::register(context: $context);

		$this->assertSame([EntityRelationMapper::class], $context->services);
		$this->assertSame([EntityRelationMapperInterface::class => EntityRelationMapper::class], array_column($context->aliases, 'target', 'alias'));
	}

	public function testTheFactoryAsksTheContainerInConstructorOrder(): void {
		$context = new RecordingRegistrationContext();
		FilesMapperRegistrar::register(context: $context);

		$services = [
			'OCP\IDBConnection'               => $this->createMock(IDBConnection::class),
			AuditTrailMapperInterface::class  => $this->createMock(AuditTrailMapperInterface::class),
			'OCP\IUserSession'                => $this->createMock(IUserSession::class),
			EntityEventsInterface::class      => $this->createMock(EntityEventsInterface::class),
			'Psr\Log\LoggerInterface'         => $this->createMock(LoggerInterface::class),
		];
		$asked     = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services, &$asked): object {
				$asked[] = $id;
				return $services[$id];
			}
		);

		$mapper = ($context->factories[EntityRelationMapper::class])($container);

		$this->assertInstanceOf(EntityRelationMapper::class, $mapper);
		$this->assertSame(array_keys($services), $asked);
	}

	public function testNoCoreRegistrarNamesTheMapperAnyMore(): void {
		$context = new RecordingRegistrationContext();
		MapperWiring::register(context: $context);
		DbRegistrar::register(context: $context);

		$this->assertNotContains(EntityRelationMapper::class, $context->services);
		$this->assertArrayNotHasKey(EntityRelationMapperInterface::class, array_column($context->aliases, 'target', 'alias'));
	}
}//end class
