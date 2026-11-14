<?php

/**
 * SyncRecordResolver: the conflict queue, mappedData and each resolution.
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

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SyncRecord;
use OCA\OpenRegister\Db\SyncRecordMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Sync\HarvestImportServices;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\ResolutionRefused;
use OCA\OpenRegister\Service\Sync\SyncConflictResolver;
use OCA\OpenRegister\Service\Sync\SyncRecordResolver;
use OCA\OpenRegister\Service\Sync\SyncRecordStatus;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Sync\SyncRecordResolver
 * @covers \OCA\OpenRegister\Service\Sync\ResolutionRefused
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Db\SyncRecord
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Sync\HarvestImportServices
 * @uses   \OCA\OpenRegister\Service\Sync\SyncConflictResolver
 * @uses   \OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator
 * @uses   \OCA\OpenRegister\Service\Sync\HarvestPipelineService
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 */
class SyncRecordResolverTest extends TestCase {

	/** @var SyncRecordMapperInterface&MockObject */
	private SyncRecordMapperInterface $records;

	/** @var ObjectOperationsInterface&MockObject */
	private ObjectOperationsInterface $objects;

	/** @var MappingMapperInterface&MockObject */
	private MappingMapperInterface $mappings;

	/** @var HarvestPipelineService&MockObject */
	private HarvestPipelineService $pipeline;

	/** @var PermissionHandlerInterface&MockObject */
	private PermissionHandlerInterface $permissions;

	private SyncRecordResolver $resolver;

	protected function setUp(): void {
		$this->records     = $this->createMock(SyncRecordMapperInterface::class);
		$this->objects     = $this->createMock(ObjectOperationsInterface::class);
		$this->mappings    = $this->createMock(MappingMapperInterface::class);
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->pipeline    = $this->getMockBuilder(HarvestPipelineService::class)
			->disableOriginalConstructor()->onlyMethods(['applyMapping'])->getMock();
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());

		$this->records->method('transitionStatus')->willReturnCallback(
			static function (SyncRecord $record, string $status): SyncRecord {
				$record->setStatus($status);
				return $record;
			}
		);

		$this->resolver = new SyncRecordResolver(
			records: $this->records,
			import: new HarvestImportServices(
				mappingMapper: $this->mappings,
				mappingService: $this->createMock(MappingService::class),
				objectService: $this->objects,
				conflictResolver: new SyncConflictResolver()
			),
			pipeline: $this->pipeline,
			permissions: $this->permissions,
			schemas: $schemas
		);
	}

	private function source(): Source {
		$source = new Source();
		$source->setId(2);
		$source->setUuid('src-1');
		$source->setMappingId(8);
		$source->setTargetRegister('1');
		$source->setTargetSchema('6');
		$source->setProtectedFields(['status']);
		$source->setProvenance(['externalIdProperty' => 'sourceId']);
		return $source;
	}

	private function conflict(?string $objectUuid='obj-1'): SyncRecord {
		$record = new SyncRecord();
		$record->setId(11);
		$record->setSourceId(2);
		$record->setExternalId('ext-1');
		$record->setStatus(SyncRecordStatus::CONFLICT);
		$record->setObjectUuid($objectUuid);
		$record->setRawData(['t' => 'Harvested title', 's' => 'published']);
		return $record;
	}

	private function local(): ObjectEntity {
		$local = new ObjectEntity();
		$local->setUuid('obj-1');
		$local->setSchema('6');
		$local->setOwner('alice');
		$local->setObject(['title' => 'Local title', 'status' => 'draft', 'note' => 'mine']);
		return $local;
	}

	private function mapsTo(array $mapped): void {
		$this->mappings->method('find')->with(8)->willReturn(new Mapping());
		$this->pipeline->method('applyMapping')->willReturn($mapped);
	}

	public function testMappedDataIsTheGuardedMappingAndAProtectedFieldIsAbsent(): void {
		$this->mapsTo(['title' => 'Harvested title', 'status' => 'published']);

		$this->assertSame(
			['mappedData' => ['title' => 'Harvested title', 'sourceId' => 'ext-1']],
			$this->resolver->mappedOf($this->source(), $this->conflict())
		);
	}

	public function testAFailingMappingGivesNullMappedDataAndTheError(): void {
		$this->mappings->method('find')->willThrowException(new DoesNotExistException('mapping 8 gone'));

		$this->assertSame(
			['mappedData' => null, 'mappingError' => 'mapping 8 gone'],
			$this->resolver->mappedOf($this->source(), $this->conflict())
		);
	}

	public function testTheQueueShowsANonAdminOnlyRecordsTheyMayUpdate(): void {
		$mine   = $this->conflict();
		$theirs = $this->conflict(objectUuid: 'obj-2');
		$theirs->setId(12);
		$orphan = $this->conflict(objectUuid: null);
		$orphan->setId(13);
		$this->records->method('findLatestBySource')->with(2, 'conflict')->willReturn([$mine, $theirs, $orphan]);
		$this->mapsTo(['title' => 'Harvested title']);

		$other = $this->local();
		$other->setUuid('obj-2');
		$other->setOwner('bob');
		$this->objects->method('find')->willReturnCallback(fn (string $id): ObjectEntity => ($id === 'obj-1') ? $this->local() : $other);
		$this->permissions->method('hasPermission')->willReturnCallback(
			static fn (Schema $schema, string $action, ?string $userId=null, ?string $objectOwner=null): bool => $objectOwner === 'alice'
		);

		$rows = $this->resolver->queue($this->source(), 'conflict', isAdmin: false);

		$this->assertSame([11], array_column($rows, 'id'));
		$this->assertSame(['title' => 'Harvested title', 'sourceId' => 'ext-1'], $rows[0]['mappedData']);
		$this->assertCount(3, $this->resolver->queue($this->source(), 'conflict', isAdmin: true));
	}

	public function testKeepLocalShadowsAndAppendsTheResolution(): void {
		$this->objects->method('find')->willReturn($this->local());
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->objects->expects($this->never())->method('saveObject');

		$record = $this->resolver->resolve($this->source(), $this->conflict(), ['action' => 'keep-local'], 'alice');

		$this->assertSame(SyncRecordStatus::SHADOWED, $record->getStatus());
		$this->assertCount(1, $record->getResolutions());
		$resolution = $record->getResolutions()[0];
		$this->assertSame(['alice', 'keep-local', [], 'obj-1', false], [
			$resolution['resolvedBy'], $resolution['action'], $resolution['fields'], $resolution['objectUuid'], $resolution['bulk'],
		]);
		$this->assertNotEmpty($resolution['resolvedAt']);
	}

	public function testDiscardRejectsAndClearsTheRawData(): void {
		$this->objects->method('find')->willReturn($this->local());
		$this->permissions->method('hasPermission')->willReturn(true);

		$record = $this->resolver->resolve($this->source(), $this->conflict(), ['action' => 'discard'], 'alice', bulk: true);

		$this->assertSame(SyncRecordStatus::REJECTED, $record->getStatus());
		$this->assertNull($record->getRawData());
		$this->assertTrue($record->getResolutions()[0]['bulk']);
	}

	public function testUseHarvestedWritesTheMappedItemOverTheLocalObject(): void {
		$this->objects->method('find')->willReturn($this->local());
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->mapsTo(['title' => 'Harvested title', 'status' => 'published']);
		$saved = $this->local();
		$this->objects->expects($this->once())->method('saveObject')
			->with(
				['title' => 'Harvested title', 'sourceId' => 'ext-1'],
				$this->callback(static fn (ObjectSaveOptions $o): bool => $o->uuid === 'obj-1' && $o->target->schema === '6')
			)
			->willReturn($saved);

		$record = $this->resolver->resolve($this->source(), $this->conflict(), ['action' => 'use-harvested'], 'alice');

		$this->assertSame(SyncRecordStatus::IMPORTED, $record->getStatus());
		$this->assertNotNull($record->getLastAppliedAt());
		$this->assertNull($record->getRawData());
		$this->assertSame('obj-1', $record->getObjectUuid());
	}

	public function testMergeTakesOnlyTheHarvestedFieldsAndNeverAProtectedOne(): void {
		$this->objects->method('find')->willReturn($this->local());
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->mapsTo(['title' => 'Harvested title', 'status' => 'published']);
		$this->objects->expects($this->once())->method('saveObject')
			->with(['title' => 'Harvested title', 'status' => 'draft', 'note' => 'mine', 'sourceId' => 'ext-1'])
			->willReturn($this->local());

		$record = $this->resolver->resolve(
			$this->source(),
			$this->conflict(),
			['action' => 'merge', 'fields' => ['title' => 'harvested', 'status' => 'harvested', 'note' => 'local']],
			'alice'
		);

		$this->assertSame(SyncRecordStatus::IMPORTED, $record->getStatus());
		$this->assertSame(['title' => 'harvested', 'status' => 'harvested', 'note' => 'local'], $record->getResolutions()[0]['fields']);
	}

	public function testACallerWhoMayNotUpdateTheObjectIsRefused403AndTheRecordStaysAConflict(): void {
		$this->objects->method('find')->willReturn($this->local());
		$this->permissions->method('hasPermission')->willReturn(false);
		$this->objects->expects($this->never())->method('saveObject');
		$this->records->expects($this->never())->method('transitionStatus');
		$record = $this->conflict();

		try {
			$this->resolver->resolve($this->source(), $record, ['action' => 'use-harvested'], 'mallory');
			$this->fail('Expected a refusal');
		} catch (ResolutionRefused $refused) {
			$this->assertSame(403, $refused->status);
		}

		$this->assertSame(SyncRecordStatus::CONFLICT, $record->getStatus());
		$this->assertNull($record->getResolutions());
	}

	public function testAPermissionCheckThatThrowsRefuses(): void {
		$this->objects->method('find')->willReturn($this->local());
		$this->permissions->method('hasPermission')->willThrowException(new RuntimeException('no session'));

		$this->expectException(ResolutionRefused::class);
		$this->resolver->resolve($this->source(), $this->conflict(), ['action' => 'keep-local'], 'alice');
	}

	public function testOnlyAConflictIsResolvedAndTheActionMustBeKnown(): void {
		$record = $this->conflict();
		$record->setStatus(SyncRecordStatus::IMPORTED);
		try {
			$this->resolver->resolve($this->source(), $record, ['action' => 'keep-local'], 'alice');
			$this->fail('Expected a refusal');
		} catch (ResolutionRefused $refused) {
			$this->assertSame(409, $refused->status);
		}

		try {
			$this->resolver->resolve($this->source(), $this->conflict(), ['action' => 'overwrite'], 'alice');
			$this->fail('Expected a refusal');
		} catch (ResolutionRefused $refused) {
			$this->assertSame(422, $refused->status);
			$this->assertSame(['error' => $refused->getMessage()], $refused->toArray());
		}
	}

	public function testAMergeWithoutFieldsOrWithABadSideIs422(): void {
		foreach ([[], ['title' => 'theirs']] as $fields) {
			try {
				$this->resolver->resolve($this->source(), $this->conflict(), ['action' => 'merge', 'fields' => $fields], 'alice');
				$this->fail('Expected a refusal');
			} catch (ResolutionRefused $refused) {
				$this->assertSame(422, $refused->status);
			}
		}
	}

	public function testUseHarvestedOnADeletedObjectNeedsTheCreateRightAndCreates(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($this->isInstanceOf(Schema::class), 'create')->willReturn(true);
		$this->mapsTo(['title' => 'Harvested title']);
		$created = new ObjectEntity();
		$created->setUuid('obj-new');
		$this->objects->expects($this->once())->method('saveObject')
			->with($this->anything(), $this->callback(static fn (ObjectSaveOptions $o): bool => $o->uuid === null))
			->willReturn($created);

		$record = $this->resolver->resolve($this->source(), $this->conflict(), ['action' => 'use-harvested'], 'alice');

		$this->assertSame('obj-new', $record->getObjectUuid());
	}
}
