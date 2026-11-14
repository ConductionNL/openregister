<?php

/**
 * RelationAddressRewrite: after a move, the relation rows of an object open it
 * where it now lives, at whichever end it sits.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\ObjectRelationMapperInterface;
use OCA\OpenRegister\Service\Object\RelationAddressRewrite;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\RelationAddressRewrite
 * @uses \OCA\OpenRegister\Db\ObjectRelation
 */
final class RelationAddressRewriteTest extends TestCase {

	private const UUID = '0b7c6a0e-3d0b-4c39-8a0b-6d4f1c2e9b01';

	private const OTHER = '7f1d2c3b-4a5e-4f60-8b71-92a3b4c5d6e7';

	/**
	 * @var ObjectRelationMapperInterface&MockObject
	 */
	private ObjectRelationMapperInterface $mapper;

	/**
	 * The rows saved, in order.
	 *
	 * @var array<int, ObjectRelation>
	 */
	private array $saved = [];

	protected function setUp(): void {
		parent::setUp();
		$this->saved  = [];
		$this->mapper = $this->createMock(ObjectRelationMapperInterface::class);
		$this->mapper->method('save')->willReturnCallback(
			function (ObjectRelation $row): ObjectRelation {
				$this->saved[] = $row;
				return $row;
			}
		);
	}

	/**
	 * A relation row between two objects at the given addresses.
	 *
	 * @param string $source         The source uuid.
	 * @param int    $sourceRegister The source register.
	 * @param int    $sourceSchema   The source schema.
	 * @param string $target         The target uuid.
	 * @param int    $targetRegister The target register.
	 * @param int    $targetSchema   The target schema.
	 *
	 * @return ObjectRelation The row.
	 */
	private function row(string $source, int $sourceRegister, int $sourceSchema, string $target, int $targetRegister, int $targetSchema): ObjectRelation {
		$row = new ObjectRelation();
		$row->setSourceUuid($source);
		$row->setSourceRegister($sourceRegister);
		$row->setSourceSchema($sourceSchema);
		$row->setTargetUuid($target);
		$row->setTargetRegister($targetRegister);
		$row->setTargetSchema($targetSchema);

		return $row;
	}

	public function testARowHangingOffTheObjectGetsItsSourceAddressRewritten(): void {
		$row = $this->row(self::UUID, 1, 10, self::OTHER, 5, 50);
		$this->mapper->expects(self::once())->method('findTouching')->with([self::UUID])->willReturn([$row]);

		$count = (new RelationAddressRewrite($this->mapper))->rewrite(self::UUID, 2, 20);

		self::assertSame(1, $count);
		self::assertSame([$row], $this->saved);
		self::assertSame(2, $row->getSourceRegister());
		self::assertSame(20, $row->getSourceSchema());
		self::assertSame(5, $row->getTargetRegister(), 'the other end did not move');
		self::assertSame(50, $row->getTargetSchema());
	}

	public function testARowPointingAtTheObjectGetsItsTargetAddressRewritten(): void {
		$row = $this->row(self::OTHER, 5, 50, self::UUID, 1, 10);
		$this->mapper->method('findTouching')->willReturn([$row]);

		$count = (new RelationAddressRewrite($this->mapper))->rewrite(self::UUID, 2, 20);

		self::assertSame(1, $count);
		self::assertSame(2, $row->getTargetRegister());
		self::assertSame(20, $row->getTargetSchema());
		self::assertSame(5, $row->getSourceRegister(), 'the other end did not move');
		self::assertSame(50, $row->getSourceSchema());
	}

	public function testARowFromTheObjectToItselfHasBothEndsRewrittenAndIsSavedOnce(): void {
		$row = $this->row(self::UUID, 1, 10, self::UUID, 1, 10);
		$this->mapper->method('findTouching')->willReturn([$row]);

		$count = (new RelationAddressRewrite($this->mapper))->rewrite(self::UUID, 2, 20);

		self::assertSame(1, $count);
		self::assertCount(1, $this->saved);
		self::assertSame([2, 20, 2, 20], [$row->getSourceRegister(), $row->getSourceSchema(), $row->getTargetRegister(), $row->getTargetSchema()]);
	}

	public function testOnlyTheSchemaChangingStillRewrites(): void {
		$row = $this->row(self::UUID, 2, 10, self::OTHER, 5, 50);
		$this->mapper->method('findTouching')->willReturn([$row]);

		$count = (new RelationAddressRewrite($this->mapper))->rewrite(self::UUID, 2, 20);

		self::assertSame(1, $count);
		self::assertSame(20, $row->getSourceSchema());
	}

	public function testARowAlreadyNamingTheNewAddressIsNotWritten(): void {
		$current = $this->row(self::UUID, 2, 20, self::OTHER, 5, 50);
		$stale   = $this->row(self::OTHER, 5, 50, self::UUID, 1, 10);
		$this->mapper->method('findTouching')->willReturn([$current, $stale]);

		$count = (new RelationAddressRewrite($this->mapper))->rewrite(self::UUID, 2, 20);

		self::assertSame(1, $count);
		self::assertSame([$stale], $this->saved);
	}

	public function testAnObjectWithoutRelationsWritesNothing(): void {
		$this->mapper->method('findTouching')->willReturn([]);
		$this->mapper->expects(self::never())->method('save');

		self::assertSame(0, (new RelationAddressRewrite($this->mapper))->rewrite(self::UUID, 2, 20));
	}

	public function testAnEmptyUuidReadsNothing(): void {
		$this->mapper->expects(self::never())->method('findTouching');

		self::assertSame(0, (new RelationAddressRewrite($this->mapper))->rewrite('', 2, 20));
	}
}
