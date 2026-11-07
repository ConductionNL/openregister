<?php

/**
 * An entity that holds only part of its row is never written back.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\PartialObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\PartialObjectEntity
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicMapperPartialReadWriteTest extends TestCase {

	private function mapper(array $values = []): MagicMapper {
		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		$values += ['logger' => $this->createMock(LoggerInterface::class)];
		foreach ($values as $name => $value) {
			$reflection->getProperty($name)->setValue($mapper, $value);
		}

		return $mapper;
	}//end mapper()

	private function partial(): ObjectEntity {
		$row = ['_uuid' => 'u-1', '_name' => 'One', '_register' => '3'];

		return (new ReflectionClass(MagicMapper::class))->getMethod('rowToObjectEntity')->invoke($this->mapper(), $row);
	}//end partial()

	public function testTheCrossTableConversionGivesAPartialEntity(): void {
		$this->assertInstanceOf(PartialObjectEntity::class, $this->partial());
		$this->assertInstanceOf(PartialObjectEntity::class, clone $this->partial());
	}//end testTheCrossTableConversionGivesAPartialEntity()

	public function testAnUpdateRefusesItBeforeAnyHookOrWrite(): void {
		$events = $this->createMock(IEventDispatcher::class);
		$events->expects($this->never())->method('dispatchTyped');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Cannot write object u-1 back');

		$this->mapper(['eventDispatcher' => $events])->updateObjectEntity($this->partial(), new Register(), new Schema(), new ObjectEntity());
	}//end testAnUpdateRefusesItBeforeAnyHookOrWrite()

	public function testTheFullRowSoftDeleteRefusesIt(): void {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->expects($this->never())->method('find');
		$mapper = $this->mapper(['registerMapper' => $registers]);

		$written = (new ReflectionClass(MagicMapper::class))->getMethod('softDeleteSingleFullRow')->invoke($mapper, $this->partial());

		$this->assertFalse($written);
	}//end testTheFullRowSoftDeleteRefusesIt()
}//end class
