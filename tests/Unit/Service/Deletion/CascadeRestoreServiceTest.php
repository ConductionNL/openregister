<?php

/**
 * Unit tests for CascadeRestoreService: what a restore brings back, and what it refuses to touch.
 *
 * The classes that matter are the ones that protect someone else's work:
 * a dependant deleted again later, a reference set to another value since,
 * and an object the caller may not write, which refuses the whole act.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Deletion;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\OpenRegister\Service\Deletion\CascadeRestoreRefusedException;
use OCA\OpenRegister\Service\Deletion\CascadeRestoreService;
use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizerInterface;
use OCA\OpenRegister\Service\Deletion\DeletionRetention;
use OCA\OpenRegister\Service\Deletion\DeletionWindow;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Deletion\CascadeRestoreService
 * @covers \OCA\OpenRegister\Service\Deletion\CascadeRestorePlan
 * @covers \OCA\OpenRegister\Service\Deletion\CascadeRestoreItem
 * @covers \OCA\OpenRegister\Service\Deletion\CascadeRestoreResult
 * @covers \OCA\OpenRegister\Service\Deletion\CascadeRestoreRefusedException
 */
final class CascadeRestoreServiceTest extends TestCase {
	private const ROOT = 'root-0000-0000-0000-000000000001';
	private const DELETED_AT = '2026-11-20T10:00:00+00:00';

	private AuditTrailMapperInterface&MockObject $auditTrails;
	private MagicMapperInterface&MockObject $objects;
	private DeletedObjectAuthorizerInterface&MockObject $authorizer;
	private DeletionWindowService&MockObject $windows;
	private ObjectOperationsInterface&MockObject $operations;
	private IDBConnection&MockObject $db;

	/**
	 * Uuids the caller may not update.
	 *
	 * @var array<string, true>
	 */
	private array $noUpdate = [];

	/**
	 * Uuids the caller may not read.
	 *
	 * @var array<string, true>
	 */
	private array $noRead = [];

	/**
	 * Uuids whose recovery window has passed.
	 *
	 * @var array<string, true>
	 */
	private array $expired = [];

	private CascadeRestoreService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->auditTrails = $this->createMock(AuditTrailMapperInterface::class);
		$this->objects     = $this->createMock(MagicMapperInterface::class);
		$this->authorizer  = $this->createMock(DeletedObjectAuthorizerInterface::class);
		$this->windows     = $this->createMock(DeletionWindowService::class);
		$this->operations  = $this->createMock(ObjectOperationsInterface::class);
		$this->db          = $this->createMock(IDBConnection::class);

		$this->authorizer->method('userMayActOnDeletedObject')->willReturnCallback(
			function (ObjectEntity $object, string $action): bool {
				$uuid = (string)$object->getUuid();
				if ($action === 'update') {
					return isset($this->noUpdate[$uuid]) === false;
				}

				return isset($this->noRead[$uuid]) === false;
			}
		);
		$this->authorizer->method('resolveSchema')->willReturnCallback(
			static function (ObjectEntity $object): Schema {
				$schema = new Schema();
				$schema->setSlug('schema-'.(string)$object->getSchema());

				return $schema;
			}
		);
		$this->windows->method('windowOf')->willReturnCallback(
			function ($query): DeletionWindow {
				$until = new DateTimeImmutable('2027-01-01T00:00:00+00:00');
				if (isset($this->expired[(string)$query->object->getUuid()]) === true) {
					$until = new DateTimeImmutable('2026-11-21T00:00:00+00:00');
				}

				return new DeletionWindow(
					destroyableFrom: $until,
					daysRemaining: 1,
					retention: new DeletionRetention(days: 30, source: DeletionWindow::SOURCE_DEFAULT),
					deletedAt: new DateTimeImmutable(self::DELETED_AT)
				);
			}
		);

		$this->service = new CascadeRestoreService(
			auditTrails: $this->auditTrails,
			objects: $this->objects,
			authorizer: $this->authorizer,
			windows: $this->windows,
			operations: $this->operations,
			db: $this->db
		);
	}//end setUp()

	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-11-24T12:00:00+00:00');
	}//end now()

	private static function root(): ObjectEntity {
		return self::object(uuid: self::ROOT, schema: 1, deletedAt: self::DELETED_AT);
	}//end root()

	private static function object(string $uuid, int $schema, ?string $deletedAt=null, array $data=[]): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema((string)$schema);
		$object->setRegister('7');
		$object->setObject($data);
		if ($deletedAt !== null) {
			$object->setDeleted(['deletedAt' => $deletedAt, 'deletedBy' => 'manager']);
		}

		return $object;
	}//end object()

	private static function row(string $uuid, string $action, array $changed=[], int $schema=2): AuditTrail {
		$row = new AuditTrail();
		$row->setObjectUuid($uuid);
		$row->setAction($action);
		$row->setChanged($changed + ['triggerObject' => self::ROOT]);
		$row->setSchema($schema);

		return $row;
	}//end row()

	private static function cascadeRow(string $uuid, int $schema=2): AuditTrail {
		return self::row(uuid: $uuid, action: CascadeRestoreService::ACTION_CASCADE_DELETE, schema: $schema);
	}//end cascadeRow()

	private static function setNullRow(string $uuid, string $property='lead'): AuditTrail {
		return self::row(
			uuid: $uuid,
			action: CascadeRestoreService::ACTION_SET_NULL,
			changed: ['property' => $property, 'previousValue' => self::ROOT, 'newValue' => null],
			schema: 3
		);
	}//end setNullRow()

	/**
	 * @param list<AuditTrail>   $rows    The evidence the lookup returns.
	 * @param list<ObjectEntity> $objects The objects the batched lookup returns.
	 */
	private function given(array $rows, array $objects): void {
		$this->auditTrails->method('findTriggeredActions')->willReturnCallback(
			static function (string $triggerObject, ActionLookupOptions $options) use ($rows): array {
				self::assertSame(self::ROOT, $triggerObject);
				self::assertSame(
					[
						CascadeRestoreService::ACTION_CASCADE_DELETE,
						CascadeRestoreService::ACTION_SET_NULL,
						CascadeRestoreService::ACTION_SET_DEFAULT,
					],
					$options->actions
				);
				self::assertSame(CascadeRestoreService::MAX_ROWS + 1, $options->limit);

				return $rows;
			}
		);
		$this->objects->method('findMultipleAcrossAllMagicTables')->willReturn($objects);
	}//end given()

	/**
	 * @return array<string, string> uuid => class.
	 */
	private function classes(): array {
		$classes = [];
		foreach ($this->service->preview(root: self::root(), now: self::now())->items as $item) {
			$classes[$item->uuid] = $item->class;
		}

		return $classes;
	}//end classes()

	public function testADependantDeletedInTheSameActIsRestored(): void {
		$this->given(
			rows: [self::cascadeRow(uuid: 'act-1'), self::cascadeRow(uuid: 'act-2')],
			objects: [
				self::object(uuid: 'act-1', schema: 2, deletedAt: '2026-11-20T09:59:59+00:00'),
				self::object(uuid: 'act-2', schema: 2, deletedAt: self::DELETED_AT),
			]
		);

		self::assertSame(['act-1' => 'restore', 'act-2' => 'restore'], $this->classes());
	}//end testADependantDeletedInTheSameActIsRestored()

	public function testADependantRestoredSinceIsAlready(): void {
		$this->given(rows: [self::cascadeRow(uuid: 'act-1')], objects: [self::object(uuid: 'act-1', schema: 2)]);

		$plan = $this->service->preview(root: self::root(), now: self::now());

		self::assertSame('already', $plan->items[0]->class);
		self::assertSame('restored or recreated since', $plan->items[0]->reason);
	}//end testADependantRestoredSinceIsAlready()

	public function testADependantDeletedAgainLaterIsAlreadyAndNotRestoredBehindTheirBack(): void {
		$this->given(
			rows: [self::cascadeRow(uuid: 'act-1')],
			objects: [self::object(uuid: 'act-1', schema: 2, deletedAt: '2026-11-22T08:00:00+00:00')]
		);

		$plan = $this->service->preview(root: self::root(), now: self::now());

		self::assertSame('already', $plan->items[0]->class);
		self::assertSame('deleted again later, by another act', $plan->items[0]->reason);
	}//end testADependantDeletedAgainLaterIsAlreadyAndNotRestoredBehindTheirBack()

	public function testADestroyedDependantIsGoneAndNamedByItsSchemaOnly(): void {
		$this->given(rows: [self::cascadeRow(uuid: 'act-1', schema: 9)], objects: []);

		$plan = $this->service->preview(root: self::root(), now: self::now());

		self::assertSame('gone', $plan->items[0]->class);
		self::assertSame('destroyed', $plan->items[0]->reason);
		self::assertSame(['reason' => 'destroyed', 'schema' => '9'], $plan->items[0]->toArray());
	}//end testADestroyedDependantIsGoneAndNamedByItsSchemaOnly()

	public function testADependantPastItsWindowIsGone(): void {
		$this->expired['act-1'] = true;
		$this->given(rows: [self::cascadeRow(uuid: 'act-1')], objects: [self::object(uuid: 'act-1', schema: 2, deletedAt: self::DELETED_AT)]);

		$plan = $this->service->preview(root: self::root(), now: self::now());

		self::assertSame('gone', $plan->items[0]->class);
		self::assertSame('its recovery window has passed', $plan->items[0]->reason);
	}//end testADependantPastItsWindowIsGone()

	public function testAClearedSingleReferenceStillNullIsRelinkedToTheRoot(): void {
		$this->given(rows: [self::setNullRow(uuid: 'quote-1')], objects: [self::object(uuid: 'quote-1', schema: 3, data: ['lead' => null])]);

		$item = $this->service->preview(root: self::root(), now: self::now())->items[0];

		self::assertSame('relink', $item->class);
		self::assertSame('lead', $item->property);
		self::assertSame(self::ROOT, $item->relinkTo);
	}//end testAClearedSingleReferenceStillNullIsRelinkedToTheRoot()

	public function testAReferenceSetToAnotherValueSinceIsChangedAndLeftAlone(): void {
		$this->given(rows: [self::setNullRow(uuid: 'quote-2')], objects: [self::object(uuid: 'quote-2', schema: 3, data: ['lead' => 'other-lead'])]);

		$plan = $this->service->preview(root: self::root(), now: self::now());

		self::assertSame('changed', $plan->items[0]->class);
		self::assertSame(
			[['uuid' => 'quote-2', 'reason' => 'the field was changed since the delete', 'schema' => 'schema-3', 'property' => 'lead']],
			$plan->skipped()['changed']
		);
	}//end testAReferenceSetToAnotherValueSinceIsChangedAndLeftAlone()

	public function testAReferenceAlreadyBackIsAlready(): void {
		$this->given(rows: [self::setNullRow(uuid: 'quote-1')], objects: [self::object(uuid: 'quote-1', schema: 3, data: ['lead' => self::ROOT])]);

		self::assertSame(['quote-1' => 'already'], $this->classes());
	}//end testAReferenceAlreadyBackIsAlready()

	public function testAnArrayReferenceGetsTheUuidBackOnlyWhenAbsent(): void {
		$this->given(
			rows: [self::setNullRow(uuid: 'quote-1', property: 'leads'), self::setNullRow(uuid: 'quote-2', property: 'leads')],
			objects: [
				self::object(uuid: 'quote-1', schema: 3, data: ['leads' => ['a', 'b']]),
				self::object(uuid: 'quote-2', schema: 3, data: ['leads' => ['a', self::ROOT]]),
			]
		);

		$items = $this->service->preview(root: self::root(), now: self::now())->items;

		self::assertSame('relink', $items[0]->class);
		self::assertSame(['a', 'b', self::ROOT], $items[0]->relinkTo);
		self::assertSame('already', $items[1]->class);
	}//end testAnArrayReferenceGetsTheUuidBackOnlyWhenAbsent()

	public function testADefaultedReferenceIsRelinkedOnlyWhileItHoldsTheDefault(): void {
		$changed = ['property' => 'owner', 'previousValue' => self::ROOT, 'defaultValue' => 'unassigned'];
		$this->given(
			rows: [
				self::row(uuid: 'quote-1', action: CascadeRestoreService::ACTION_SET_DEFAULT, changed: $changed),
				self::row(uuid: 'quote-2', action: CascadeRestoreService::ACTION_SET_DEFAULT, changed: $changed),
			],
			objects: [
				self::object(uuid: 'quote-1', schema: 3, data: ['owner' => 'unassigned']),
				self::object(uuid: 'quote-2', schema: 3, data: ['owner' => 'someone-else']),
			]
		);

		self::assertSame(['quote-1' => 'relink', 'quote-2' => 'changed'], $this->classes());
	}//end testADefaultedReferenceIsRelinkedOnlyWhileItHoldsTheDefault()

	public function testASurvivorDeletedSinceIsChanged(): void {
		$this->given(rows: [self::setNullRow(uuid: 'quote-1')], objects: [self::object(uuid: 'quote-1', schema: 3, deletedAt: '2026-11-23T00:00:00+00:00')]);

		$plan = $this->service->preview(root: self::root(), now: self::now());

		self::assertSame('changed', $plan->items[0]->class);
		self::assertSame('deleted since', $plan->items[0]->reason);
	}//end testASurvivorDeletedSinceIsChanged()

	public function testAnObjectTheCallerMayNotUpdateIsForbiddenAndNeverNamed(): void {
		$this->noUpdate['act-secret'] = true;
		$this->given(
			rows: [self::cascadeRow(uuid: 'act-1'), self::cascadeRow(uuid: 'act-secret', schema: 4)],
			objects: [
				self::object(uuid: 'act-1', schema: 2, deletedAt: self::DELETED_AT),
				self::object(uuid: 'act-secret', schema: 4, deletedAt: self::DELETED_AT),
			]
		);

		$plan = $this->service->preview(root: self::root(), now: self::now());

		self::assertTrue($plan->isForbidden());
		self::assertSame(['count' => 1, 'schemas' => ['schema-4' => 1]], $plan->forbiddenSummary());
		self::assertStringNotContainsString('act-secret', (string)json_encode($plan->toArray()));
	}//end testAnObjectTheCallerMayNotUpdateIsForbiddenAndNeverNamed()

	public function testAnObjectTheCallerMayNotReadIsListedWithoutItsUuid(): void {
		$this->noRead['act-1'] = true;
		$this->given(rows: [self::cascadeRow(uuid: 'act-1')], objects: [self::object(uuid: 'act-1', schema: 2, deletedAt: self::DELETED_AT)]);

		$plan = $this->service->preview(root: self::root(), now: self::now())->toArray();

		self::assertSame([['reason' => 'deleted with this object', 'schema' => 'schema-2']], $plan['restore']);
	}//end testAnObjectTheCallerMayNotReadIsListedWithoutItsUuid()

	public function testDuplicateRowsAndTheRootItselfAreListedOnce(): void {
		$this->given(
			rows: [self::cascadeRow(uuid: 'act-1'), self::cascadeRow(uuid: 'act-1'), self::cascadeRow(uuid: self::ROOT)],
			objects: [self::object(uuid: 'act-1', schema: 2, deletedAt: self::DELETED_AT)]
		);

		self::assertSame(['act-1' => 'restore'], $this->classes());
	}//end testDuplicateRowsAndTheRootItselfAreListedOnce()

	public function testADeleteWithoutEvidenceSaysTheCascadeIsNotKnown(): void {
		$this->given(rows: [], objects: []);
		$this->objects->expects($this->never())->method('findMultipleAcrossAllMagicTables');

		$plan = $this->service->preview(root: self::root(), now: self::now());

		self::assertFalse($plan->cascadeKnown);
		self::assertSame([], $plan->items);
	}//end testADeleteWithoutEvidenceSaysTheCascadeIsNotKnown()

	public function testACascadeOverTheCapIsRefusedWith409(): void {
		$rows = [];
		for ($i = 0; $i <= CascadeRestoreService::MAX_ROWS; $i++) {
			$rows[] = self::cascadeRow(uuid: 'act-'.$i);
		}

		$this->given(rows: $rows, objects: []);

		try {
			$this->service->preview(root: self::root(), now: self::now());
			self::fail('A cascade over the cap must be refused');
		} catch (CascadeRestoreRefusedException $refusal) {
			self::assertSame(409, $refusal->status);
			self::assertStringContainsString('5000', $refusal->getMessage());
		}
	}//end testACascadeOverTheCapIsRefusedWith409()

	public function testRestoreWithoutCascadeRestoresTheObjectAlone(): void {
		$this->auditTrails->expects($this->never())->method('findTriggeredActions');
		$this->db->expects($this->never())->method('beginTransaction');
		$this->objects->expects($this->once())->method('restoreObject')->with(self::ROOT)->willReturn(self::root());

		$result = $this->service->restore(root: self::root(), cascade: false);

		self::assertSame(['restored' => 1, 'relinked' => 0, 'cascade' => false], $result->toArray());
	}//end testRestoreWithoutCascadeRestoresTheObjectAlone()

	public function testRestoreBringsBackDependantsAndRelinksInOneTransaction(): void {
		$this->given(
			rows: [self::cascadeRow(uuid: 'act-1'), self::cascadeRow(uuid: 'act-2'), self::setNullRow(uuid: 'quote-1'), self::setNullRow(uuid: 'quote-2')],
			objects: [
				self::object(uuid: 'act-1', schema: 2, deletedAt: self::DELETED_AT),
				self::object(uuid: 'act-2', schema: 2, deletedAt: self::DELETED_AT),
				self::object(uuid: 'quote-1', schema: 3, data: ['lead' => null]),
				self::object(uuid: 'quote-2', schema: 3, data: ['lead' => 'another-lead']),
			]
		);

		$restored = [];
		$this->objects->method('restoreObject')->willReturnCallback(
			static function (string $uuid) use (&$restored): ObjectEntity {
				$restored[] = $uuid;

				return self::object(uuid: $uuid, schema: 2);
			}
		);
		$patches = [];
		$this->operations->method('patchObject')->willReturnCallback(
			static function (ObjectPatch $patch) use (&$patches): ObjectEntity {
				$patches[] = [$patch->objectId, $patch->data, $patch->target->register, $patch->target->schema];

				return new ObjectEntity();
			}
		);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$result = $this->service->restore(root: self::root());

		self::assertSame([self::ROOT, 'act-1', 'act-2'], $restored);
		self::assertSame([['quote-1', ['lead' => self::ROOT], '7', '3']], $patches);
		$counts = $result->toArray();
		self::assertSame(3, $counts['restored']);
		self::assertSame(1, $counts['relinked']);
		self::assertCount(1, $counts['skipped']['changed']);
		self::assertSame('quote-2', $counts['skipped']['changed'][0]['uuid']);
		self::assertTrue($counts['cascadeKnown']);
	}//end testRestoreBringsBackDependantsAndRelinksInOneTransaction()

	public function testOneForbiddenDependantRestoresNothing(): void {
		$this->noUpdate['act-2'] = true;
		$this->given(
			rows: [self::cascadeRow(uuid: 'act-1'), self::cascadeRow(uuid: 'act-2')],
			objects: [
				self::object(uuid: 'act-1', schema: 2, deletedAt: self::DELETED_AT),
				self::object(uuid: 'act-2', schema: 2, deletedAt: self::DELETED_AT),
			]
		);
		$this->objects->expects($this->never())->method('restoreObject');
		$this->operations->expects($this->never())->method('patchObject');
		$this->db->expects($this->never())->method('beginTransaction');

		try {
			$this->service->restore(root: self::root());
			self::fail('A forbidden dependant must refuse the act');
		} catch (CascadeRestoreRefusedException $refusal) {
			self::assertSame(403, $refusal->status);
			self::assertSame(['forbidden' => ['count' => 1, 'schemas' => ['schema-2' => 1]]], $refusal->details);
			self::assertStringNotContainsString('act-2', $refusal->getMessage());
		}
	}//end testOneForbiddenDependantRestoresNothing()

	public function testAFailedRelinkRollsBackTheWholeAct(): void {
		$this->given(
			rows: [self::cascadeRow(uuid: 'act-1'), self::setNullRow(uuid: 'quote-1')],
			objects: [
				self::object(uuid: 'act-1', schema: 2, deletedAt: self::DELETED_AT),
				self::object(uuid: 'quote-1', schema: 3, data: ['lead' => null]),
			]
		);
		$this->objects->method('restoreObject')->willReturn(new ObjectEntity());
		$this->operations->method('patchObject')->willThrowException(new RuntimeException('validation failed'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->never())->method('commit');
		$this->db->expects($this->once())->method('rollBack');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('validation failed');

		$this->service->restore(root: self::root());
	}//end testAFailedRelinkRollsBackTheWholeAct()

	public function testDeletedWithAcceptsTheCascadeSpanAndRefusesALaterDeletion(): void {
		$root = self::root();

		self::assertTrue(CascadeRestoreService::deletedWith(object: self::object(uuid: 'a', schema: 2, deletedAt: '2026-11-20T09:56:00+00:00'), root: $root));
		self::assertTrue(CascadeRestoreService::deletedWith(object: self::object(uuid: 'a', schema: 2, deletedAt: '2026-11-20T10:00:01+00:00'), root: $root));
		self::assertFalse(CascadeRestoreService::deletedWith(object: self::object(uuid: 'a', schema: 2, deletedAt: '2026-11-20T10:00:02+00:00'), root: $root));
		self::assertFalse(CascadeRestoreService::deletedWith(object: self::object(uuid: 'a', schema: 2, deletedAt: '2026-11-20T09:54:59+00:00'), root: $root));
	}//end testDeletedWithAcceptsTheCascadeSpanAndRefusesALaterDeletion()
}//end class
