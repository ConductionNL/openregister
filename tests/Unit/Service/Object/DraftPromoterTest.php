<?php

/**
 * DraftPromoterTest — promoting a draft: conflicts against the base version,
 * the save through the normal path, the draft deleted in the same transaction,
 * and `force` for administrators with the overwritten fields on the audit entry.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/content-versioning/spec.md#requirement-drafts-must-be-promotable-to-published-version
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrail\RevertOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\DraftVersionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\DraftConflictException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\DraftPromoter;
use OCA\OpenRegister\Service\Object\DraftService;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Object\DraftPromoter
 * @covers \OCA\OpenRegister\Exception\DraftConflictException
 * @uses   \OCA\OpenRegister\Service\Object\DraftService
 * @uses   \OCA\OpenRegister\Db\DraftVersion
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
final class DraftPromoterTest extends TestCase {

	private MockObject&DraftVersionMapper $draftVersions;

	private MockObject&PermissionCheckInterface $permissions;

	private MockObject&AuditTrailMapperInterface $auditTrails;

	private MockObject&ObjectOperationsInterface $objects;

	private MockObject&IDBConnection $db;

	private MockObject&IGroupManager $groups;

	private DraftPromoter $promoter;

	private ObjectEntity $object;

	private Schema $schema;

	/** @var array<int, string> */
	private array $transaction = [];

	protected function setUp(): void {
		$this->draftVersions = $this->createMock(DraftVersionMapper::class);
		$this->permissions   = $this->createMock(PermissionCheckInterface::class);
		$this->auditTrails   = $this->createMock(AuditTrailMapperInterface::class);
		$this->objects       = $this->createMock(ObjectOperationsInterface::class);
		$this->db            = $this->createMock(IDBConnection::class);
		$this->groups        = $this->createMock(IGroupManager::class);
		$this->groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$this->permissions->method('hasPermission')->willReturnCallback(
			static fn (PermissionAsk $ask, bool $_rbac = true): bool => $ask->action === 'update' && in_array($ask->userId, ['wendy', 'admin'], true)
		);
		foreach (['beginTransaction', 'commit', 'rollBack'] as $step) {
			$this->db->method($step)->willReturnCallback(function () use ($step): void {
				$this->transaction[] = $step;
			});
		}

		$this->promoter = new DraftPromoter(
			new DraftService($this->draftVersions, $this->permissions),
			$this->draftVersions,
			$this->auditTrails,
			$this->objects,
			$this->db,
			$this->groups
		);

		$this->object = new ObjectEntity();
		$this->object->setUuid('vergunning-1');
		$this->object->setRegister('3');
		$this->object->setVersion('1.0.2');
		$this->object->setObject(['titel' => 'Dakkapel', 'status' => 'nieuw']);
		$this->schema = new Schema();
	}

	private static function draft(array $delta, string $base): DraftVersion {
		$draft = new DraftVersion();
		$draft->setObjectUuid('vergunning-1');
		$draft->setDraftKey('status-update');
		$draft->setName('Status update');
		$draft->setCreatedBy('kees');
		$draft->setBaseVersion($base);
		$draft->setDelta($delta);
		return $draft;
	}

	/**
	 * The object as it was at the base version, answered by the audit trail.
	 */
	private function baseIs(array $data, string $version): void {
		$atBase = new ObjectEntity();
		$atBase->setObject($data);
		$this->auditTrails->expects(self::once())->method('revertObjectWith')
			->with(
				self::callback(fn (mixed $identifier): bool => $identifier instanceof ObjectEntity && $identifier !== $this->object && $identifier->getUuid() === 'vergunning-1'),
				self::callback(static fn (RevertOptions $options): bool => $options->until === $version && $options->overwriteVersion === true)
			)
			->willReturn($atBase);
	}

	public function testANonConflictingDraftIsSavedAuditedAndDeletedInOneTransaction(): void {
		$draft = self::draft(delta: ['status' => 'in_behandeling'], base: '1.0.2');
		$saved = new ObjectEntity();
		$this->auditTrails->expects(self::never())->method('revertObjectWith');
		$this->objects->expects(self::once())->method('saveObject')
			->with(
				['id' => 'vergunning-1', 'titel' => 'Dakkapel', 'status' => 'in_behandeling'],
				self::callback(
					fn (ObjectSaveOptions $options): bool => $options->uuid === 'vergunning-1'
						&& $options->target->register === '3'
						&& $options->target->schema === $this->schema
				)
			)
			->willReturnCallback(function () use ($saved): ObjectEntity {
				$this->transaction[] = 'save';
				return $saved;
			});
		$this->auditTrails->expects(self::once())->method('recordObjectEvent')
			->with(
				self::callback(
					static fn (ObjectAuditEvent $event): bool => $event->object === $saved
						&& $event->action === 'draft.promoted'
						&& $event->context === [
							'draft' => 'status-update',
							'name' => 'Status update',
							'baseVersion' => '1.0.2',
							'fields' => ['status'],
							'previous' => ['status' => 'nieuw'],
							'forced' => false,
							'overwritten' => [],
						]
				)
			)
			->willReturn(new AuditTrail());
		$this->draftVersions->expects(self::once())->method('delete')->with($draft)->willReturnCallback(function (DraftVersion $entity): DraftVersion {
			$this->transaction[] = 'delete';
			return $entity;
		});

		self::assertSame($saved, $this->promoter->promote(draft: $draft, object: $this->object, schema: $this->schema, caller: 'wendy'));
		self::assertSame(['beginTransaction', 'save', 'delete', 'commit'], $this->transaction);
	}

	public function testAFieldChangedOnBothSidesIsAConflictAndNothingIsWritten(): void {
		$this->object->setVersion('1.0.4');
		$this->object->setObject(['titel' => 'Dakkapel', 'status' => 'geweigerd']);
		$this->baseIs(data: ['titel' => 'Dakkapel', 'status' => 'nieuw'], version: '1.0.2');
		$this->objects->expects(self::never())->method('saveObject');
		$this->draftVersions->expects(self::never())->method('delete');

		try {
			$this->promoter->promote(draft: self::draft(delta: ['status' => 'in_behandeling'], base: '1.0.2'), object: $this->object, schema: $this->schema, caller: 'wendy');
			self::fail('A conflict must refuse the promotion.');
		} catch (DraftConflictException $conflict) {
			self::assertSame([['field' => 'status', 'draft' => 'in_behandeling', 'live' => 'geweigerd']], $conflict->getConflicts());
			self::assertSame(409, $conflict->getCode());
			self::assertStringContainsString('status', $conflict->getMessage());
		}

		self::assertSame([], $this->transaction);
	}

	public function testChangesToOtherFieldsSinceTheBaseAreNoConflict(): void {
		$this->object->setVersion('1.0.3');
		$this->object->setObject(['titel' => 'Dakkapel achterzijde', 'status' => 'nieuw']);
		$this->baseIs(data: ['titel' => 'Dakkapel', 'status' => 'nieuw'], version: '1.0.2');
		$this->objects->expects(self::once())->method('saveObject')
			->with(['id' => 'vergunning-1', 'titel' => 'Dakkapel achterzijde', 'status' => 'in_behandeling'], self::anything())
			->willReturn(new ObjectEntity());
		$this->draftVersions->expects(self::once())->method('delete');

		$this->promoter->promote(draft: self::draft(delta: ['status' => 'in_behandeling'], base: '1.0.2'), object: $this->object, schema: $this->schema, caller: 'wendy');

		self::assertSame(['beginTransaction', 'commit'], $this->transaction);
	}

	public function testABaseTheTrailCannotRebuildTreatsEveryDifferingFieldAsAConflict(): void {
		$this->object->setVersion('1.0.3');
		$this->auditTrails->method('revertObjectWith')->willThrowException(new RuntimeException('trail purged'));
		$this->objects->expects(self::never())->method('saveObject');

		$this->expectException(DraftConflictException::class);
		$this->promoter->promote(draft: self::draft(delta: ['status' => 'in_behandeling'], base: '1.0.2'), object: $this->object, schema: $this->schema, caller: 'wendy');
	}

	public function testConflictsIgnoreAFieldTheObjectAlreadyHasTheDraftsValueFor(): void {
		self::assertSame(
			[],
			DraftPromoter::conflicts(delta: ['status' => 'in_behandeling'], base: ['status' => 'nieuw'], live: ['status' => 'in_behandeling'])
		);
		self::assertSame(
			[['field' => 'notitie', 'draft' => 'x', 'live' => 'y']],
			DraftPromoter::conflicts(delta: ['notitie' => 'x'], base: [], live: ['notitie' => 'y']),
			'A field added on the published object since the base is a change on that side.'
		);
		self::assertSame([], DraftPromoter::conflicts(delta: ['notitie' => 'x'], base: [], live: []));
	}

	public function testACallerWithoutWriteAccessMayNotPromoteEvenTheirOwnDraft(): void {
		$this->objects->expects(self::never())->method('saveObject');

		$this->expectException(NotAuthorizedException::class);
		$this->promoter->promote(draft: self::draft(delta: ['status' => 'x'], base: '1.0.2'), object: $this->object, schema: $this->schema, caller: 'kees');
	}

	public function testANonAdministratorMayNotForce(): void {
		$this->objects->expects(self::never())->method('saveObject');
		$this->auditTrails->expects(self::never())->method('revertObjectWith');

		try {
			$this->promoter->promote(draft: self::draft(delta: ['status' => 'x'], base: '1.0.2'), object: $this->object, schema: $this->schema, caller: 'wendy', force: true);
			self::fail('A non-administrator forcing must be refused.');
		} catch (NotAuthorizedException $refused) {
			self::assertSame(403, $refused->getCode());
		}
	}

	public function testAnAdministratorForcesAndTheAuditEntryNamesTheOverwrittenFields(): void {
		$this->object->setVersion('1.0.4');
		$this->object->setObject(['titel' => 'Dakkapel', 'status' => 'geweigerd']);
		$this->baseIs(data: ['titel' => 'Dakkapel', 'status' => 'nieuw'], version: '1.0.2');
		$this->objects->expects(self::once())->method('saveObject')
			->with(['id' => 'vergunning-1', 'titel' => 'Dakkapel', 'status' => 'in_behandeling'], self::anything())
			->willReturn(new ObjectEntity());
		$this->auditTrails->expects(self::once())->method('recordObjectEvent')
			->with(
				self::callback(
					static fn (ObjectAuditEvent $event): bool => $event->context['forced'] === true
						&& $event->context['overwritten'] === ['status']
						&& $event->context['previous'] === ['status' => 'geweigerd']
				)
			)
			->willReturn(new AuditTrail());
		$this->draftVersions->expects(self::once())->method('delete');

		$this->promoter->promote(draft: self::draft(delta: ['status' => 'in_behandeling'], base: '1.0.2'), object: $this->object, schema: $this->schema, caller: 'admin', force: true);

		self::assertSame(['beginTransaction', 'commit'], $this->transaction);
	}

	public function testARefusedSaveRollsBackAndKeepsTheDraft(): void {
		$this->objects->method('saveObject')->willThrowException(new ValidationException('status is not allowed'));
		$this->draftVersions->expects(self::never())->method('delete');
		$this->auditTrails->expects(self::never())->method('recordObjectEvent');

		try {
			$this->promoter->promote(draft: self::draft(delta: ['status' => 'x'], base: '1.0.2'), object: $this->object, schema: $this->schema, caller: 'wendy');
			self::fail('The refusal must reach the caller.');
		} catch (ValidationException $refused) {
			self::assertSame('status is not allowed', $refused->getMessage());
		}

		self::assertSame(['beginTransaction', 'rollBack'], $this->transaction);
	}

	public function testAHookThatHoldsTheChangeKeepsWhatItStoredAndTheDraft(): void {
		$this->objects->method('saveObject')->willThrowException(new HookStoppedException('held', ['code' => 'change-pending']));
		$this->draftVersions->expects(self::never())->method('delete');

		try {
			$this->promoter->promote(draft: self::draft(delta: ['status' => 'x'], base: '1.0.2'), object: $this->object, schema: $this->schema, caller: 'wendy');
			self::fail('The hook refusal must reach the caller.');
		} catch (HookStoppedException $held) {
			self::assertSame(['code' => 'change-pending'], $held->getErrors());
		}

		self::assertSame(['beginTransaction', 'commit'], $this->transaction);
	}
}
