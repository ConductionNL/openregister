<?php

/**
 * HarvestTombstonePass: tombstones after a complete run, and the delete strategies.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sync
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sync;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SyncRecordMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Service\Sync\HarvestTombstonePass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Sync\HarvestTombstonePass
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 */
class HarvestTombstonePassTest extends TestCase {

	/** @var SyncRecordMapperInterface&MockObject */
	private SyncRecordMapperInterface $records;

	/** @var ObjectOperationsInterface&MockObject */
	private ObjectOperationsInterface $objects;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private HarvestTombstonePass $pass;

	protected function setUp(): void {
		$this->records = $this->createMock(SyncRecordMapperInterface::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->pass    = new HarvestTombstonePass(records: $this->records, objects: $this->objects, logger: $this->logger);
	}

	private function source(?string $strategy): Source {
		$source = new Source();
		$source->setId(7);
		$source->setDeleteStrategy($strategy);
		$source->setTargetRegister('3');
		$source->setTargetSchema('5');
		return $source;
	}

	public function testAnEmptyOrUnknownStrategyReadsAsFlag(): void {
		$this->assertSame('flag', HarvestTombstonePass::strategyOf($this->source(null)));
		$this->assertSame('flag', HarvestTombstonePass::strategyOf($this->source('vanish')));
		$this->assertSame('soft-delete', HarvestTombstonePass::strategyOf($this->source('soft-delete')));
	}

	public function testFlagTombstonesAndDeletesNothing(): void {
		$this->records->expects($this->once())->method('markTombstones')
			->with(7, ['a' => true], $this->isInstanceOf(\DateTime::class))
			->willReturn(2);
		$this->records->expects($this->never())->method('objectUuidsTombstonedAt');
		$this->objects->expects($this->never())->method('deleteObject');

		$this->assertSame(['tombstoned' => 2, 'deleted' => 0], $this->pass->apply($this->source('flag'), ['a' => true]));
	}

	public function testIgnoreTombstonesNothing(): void {
		$this->records->expects($this->never())->method('markTombstones');
		$this->objects->expects($this->never())->method('deleteObject');

		$this->assertSame(['tombstoned' => 0, 'deleted' => 0], $this->pass->apply($this->source('ignore'), []));
	}

	public function testSoftDeleteRemovesTheTombstonedObjectsNotPermanently(): void {
		$at = null;
		$this->records->method('markTombstones')->willReturnCallback(
			function (int $sourceId, array $seen, \DateTime $when) use (&$at): int {
				$at = $when;
				return 2;
			}
		);
		$this->records->expects($this->once())->method('objectUuidsTombstonedAt')
			->willReturnCallback(
				function (int $sourceId, \DateTime $when) use (&$at): array {
					$this->assertSame(7, $sourceId);
					$this->assertSame($at, $when);
					return ['u-1', 'u-2'];
				}
			);

		$deletions = [];
		$this->objects->expects($this->exactly(2))->method('deleteObject')->willReturnCallback(
			function (ObjectDeletion $deletion) use (&$deletions): bool {
				$deletions[] = $deletion;
				return true;
			}
		);

		$this->assertSame(['tombstoned' => 2, 'deleted' => 2], $this->pass->apply($this->source('soft-delete'), []));
		$this->assertSame(['u-1', 'u-2'], array_map(static fn (ObjectDeletion $d): string => $d->uuid, $deletions));
		$this->assertFalse($deletions[0]->permanent);
		$this->assertSame('3', $deletions[0]->target->register);
		$this->assertSame('5', $deletions[0]->target->schema);
	}

	public function testHardDeleteIsPermanent(): void {
		$this->records->method('markTombstones')->willReturn(1);
		$this->records->method('objectUuidsTombstonedAt')->willReturn(['u-1']);
		$this->objects->expects($this->once())->method('deleteObject')
			->with($this->callback(static fn (ObjectDeletion $d): bool => $d->permanent === true && $d->uuid === 'u-1'))
			->willReturn(true);

		$this->assertSame(['tombstoned' => 1, 'deleted' => 1], $this->pass->apply($this->source('hard-delete'), []));
	}

	public function testNothingNewlyTombstonedDeletesNothing(): void {
		$this->records->method('markTombstones')->willReturn(0);
		$this->records->expects($this->never())->method('objectUuidsTombstonedAt');
		$this->objects->expects($this->never())->method('deleteObject');

		$this->assertSame(['tombstoned' => 0, 'deleted' => 0], $this->pass->apply($this->source('soft-delete'), []));
	}

	public function testAFailedDeleteIsLoggedAndThePassGoesOn(): void {
		$this->records->method('markTombstones')->willReturn(2);
		$this->records->method('objectUuidsTombstonedAt')->willReturn(['u-1', 'u-2']);
		$this->objects->method('deleteObject')->willReturnCallback(
			static function (ObjectDeletion $deletion): bool {
				if ($deletion->uuid === 'u-1') {
					throw new RuntimeException('locked');
				}

				return true;
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('locked'));

		$this->assertSame(['tombstoned' => 2, 'deleted' => 1], $this->pass->apply($this->source('soft-delete'), []));
	}
}
