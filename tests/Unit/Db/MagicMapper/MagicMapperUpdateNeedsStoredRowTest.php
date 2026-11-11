<?php

/**
 * MagicMapper update without a readable stored row
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ObjectLookupFailedException;
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
use RuntimeException;

/**
 * An update whose stored row cannot be read first is refused, so no event carries the new state as the old one.
 */
class MagicMapperUpdateNeedsStoredRowTest extends TestCase {

	public function testUpdateIsRefusedWhenTheStoredRowCannotBeRead(): void {
		$events = $this->createMock(IEventDispatcher::class);
		$events->expects($this->never())->method('dispatchTyped');

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs(
				[
					$this->createMock(IDBConnection::class),
					new MagicMapperLookups($this->createMock(SchemaMapper::class), $this->createMock(RegisterMapper::class), $this->container()),
					new MagicMapperIdentity($this->createMock(IUserSession::class), $this->createMock(IGroupManager::class), $this->createMock(IUserManager::class)),
					new MagicMapperRuntime(
						$this->createMock(IConfig::class),
						$this->createMock(IAppConfig::class),
						$events,
						$this->createMock(LoggerInterface::class)
					),
				]
			)
			->onlyMethods(['find', 'updateObjectEntity'])
			->getMock();
		$mapper->method('find')->willThrowException(new RuntimeException('connection lost'));
		$mapper->expects($this->never())->method('updateObjectEntity');

		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);
		$entity = new ObjectEntity();
		$entity->setUuid('5b1f1a1e-0000-4000-8000-000000000001');

		$this->expectException(ObjectLookupFailedException::class);
		$mapper->update(entity: $entity, register: $register, schema: $schema);
	}//end testUpdateIsRefusedWhenTheStoredRowCannotBeRead()

	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): ?object => match ($id) {
				\OCA\OpenRegister\Service\DateTimeNormalizerInterface::class => $this->createMock(\OCA\OpenRegister\Service\DateTimeNormalizer::class),
				\OCA\OpenRegister\Service\ConditionMatcher::class => $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class),
				\OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface::class
					=> $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class),
				default => null,
			}
		);
		return $container;
	}//end container()
}//end class
