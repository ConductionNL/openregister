<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A revert handed the object it reverts does not look the object up again.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail\RevertOptions;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class AuditTrailMapperRevertTargetTest extends TestCase {

	/**
	 * The uuids the trail was read for.
	 *
	 * @var array<int, string>
	 */
	private array $trailReads = [];

	/**
	 * A mapper whose trail is empty and whose container hands out the given object mapper.
	 *
	 * @param MagicMapper $objects The object mapper the container answers with.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapper(MagicMapper $objects): AuditTrailMapper {
		$mapper = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUntil'])
			->getMock();
		$mapper->method('findByObjectUntil')->willReturnCallback(
			function (string $objectUuid): array {
				$this->trailReads[] = $objectUuid;
				return [];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(\OCA\OpenRegister\Db\MagicMapperInterface::class)->willReturn($objects);
		\Closure::bind(function () use ($container): void {
			$this->container = $container;
		}, $mapper, AuditTrailMapper::class)();

		return $mapper;
	}//end mapper()

	private static function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setVersion('1.0.4');
		return $object;
	}//end object()

	public function testAnObjectHandedInIsRevertedWithoutALookup(): void {
		$objects = $this->createMock(MagicMapper::class);
		$objects->expects($this->never())->method('find');
		$checked = self::object(uuid: 'checked-uuid');

		$reverted = $this->mapper(objects: $objects)->revertObjectWith(identifier: $checked, options: new RevertOptions());

		$this->assertSame(['checked-uuid'], $this->trailReads);
		$this->assertSame('checked-uuid', $reverted->getUuid());
		$this->assertNotSame($checked, $reverted);
		$this->assertSame('1.0.5', $reverted->getVersion());
		$this->assertSame('1.0.4', $checked->getVersion());
	}

	public function testAnIdentifierIsStillLookedUp(): void {
		$objects = $this->createMock(MagicMapper::class);
		$objects->expects($this->once())->method('find')->with('11')->willReturn(self::object(uuid: 'found-uuid'));

		$reverted = $this->mapper(objects: $objects)->revertObjectWith(identifier: '11', options: new RevertOptions(overwriteVersion: true));

		$this->assertSame(['found-uuid'], $this->trailReads);
		$this->assertSame('1.0.4', $reverted->getVersion());
	}
}//end class
