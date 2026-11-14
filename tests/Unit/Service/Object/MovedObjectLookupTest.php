<?php

/**
 * MovedObjectLookup: the old address answers for a year, and only for the
 * address the object actually moved away from.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\MovedObjectLookup;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\MovedObjectLookup
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
final class MovedObjectLookupTest extends TestCase {

	private const UUID = '0b7c6a0e-3d0b-4c39-8a0b-6d4f1c2e9b01';

	/**
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface $objects;

	/**
	 * @var AuditTrailMapperInterface&MockObject
	 */
	private AuditTrailMapperInterface $trail;

	private MovedObjectLookup $lookup;

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(MagicMapperInterface::class);
		$this->trail   = $this->createMock(AuditTrailMapperInterface::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-13T12:00:00+00:00'));
		$this->lookup = new MovedObjectLookup($this->objects, $this->trail, $time);
	}

	/**
	 * The object now living in register 2, schema 20.
	 *
	 * @param array $deleted The deletion metadata.
	 *
	 * @return ObjectEntity
	 */
	private static function object(array $deleted=[]): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::UUID);
		$object->setRegister('2');
		$object->setSchema('20');
		$object->setDeleted($deleted);

		return $object;
	}

	/**
	 * A `moved` entry.
	 *
	 * @param string $created When.
	 * @param array  $from    The old address.
	 *
	 * @return AuditTrail
	 */
	private static function moved(string $created, array $from): AuditTrail {
		$entry = new AuditTrail();
		$entry->setAction('moved');
		$entry->setCreated(new DateTime($created));
		$entry->setChanged(['from' => $from, 'to' => ['register' => 2, 'schema' => 20]]);

		return $entry;
	}

	public function testTheOldAddressAnswersWithTheNewOne(): void {
		$this->objects->expects($this->once())->method('findAcrossAllSources')
			->with(self::UUID, false, true, true, null)
			->willReturn(['object' => self::object(), 'register' => null, 'schema' => null]);
		$this->trail->expects($this->once())->method('findObjectActions')
			->with(self::UUID, $this->callback(static fn (ActionLookupOptions $o): bool => $o->actions === ['moved']))
			->willReturn([self::moved('2026-10-01T09:00:00+00:00', ['register' => 1, 'schema' => 10])]);

		self::assertSame(
			['register' => '2', 'schema' => '20', 'uuid' => self::UUID, 'movedAt' => '2026-10-01T09:00:00+00:00'],
			$this->lookup->movedFrom(self::UUID, '1', '10', true)
		);
	}

	public function testAnAddressTheObjectNeverLeftStaysAMiss(): void {
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => self::object()]);
		$this->trail->method('findObjectActions')->willReturn([self::moved('2026-10-01T09:00:00+00:00', ['register' => 1, 'schema' => 10])]);

		self::assertNull($this->lookup->movedFrom(self::UUID, '1', '11', true));
		self::assertNull($this->lookup->movedFrom(self::UUID, '3', '10', true));
	}

	public function testAMoveOlderThanAYearNoLongerAnswers(): void {
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => self::object()]);
		$this->trail->method('findObjectActions')->willReturn([
			self::moved('2025-11-13T11:59:59+00:00', ['register' => 1, 'schema' => 10]),
			self::moved('2025-11-13T12:00:00+00:00', ['register' => 4, 'schema' => 40]),
		]);

		self::assertNull($this->lookup->movedFrom(self::UUID, '1', '10', false));
		self::assertNotNull($this->lookup->movedFrom(self::UUID, '4', '40', false));
	}

	public function testAnObjectThatStillLivesHereIsNotOurs(): void {
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => self::object()]);
		$this->trail->expects($this->never())->method('findObjectActions');

		self::assertNull($this->lookup->movedFrom(self::UUID, '2', '20', true));
	}

	public function testAnObjectTheCallerCannotReadOrThatIsDeletedIsAMiss(): void {
		$this->trail->expects($this->never())->method('findObjectActions');
		$this->objects->method('findAcrossAllSources')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('gone')),
			['object' => null],
			['object' => self::object(['deletedAt' => '2026-11-01'])]
		);

		self::assertNull($this->lookup->movedFrom(self::UUID, '1', '10', true));
		self::assertNull($this->lookup->movedFrom(self::UUID, '1', '10', true));
		self::assertNull($this->lookup->movedFrom(self::UUID, '1', '10', true));
	}

	public function testAnEntryWithoutAFromAddressIsIgnored(): void {
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => self::object()]);
		$entry = new AuditTrail();
		$entry->setAction('moved');
		$entry->setCreated(new DateTime('2026-10-01T09:00:00+00:00'));
		$this->trail->method('findObjectActions')->willReturn([$entry]);

		self::assertNull($this->lookup->movedFrom(self::UUID, '1', '10', true));
	}
}
