<?php

/**
 * DestructionListRun claims only an approved list, re-checks every entry and records the outcome.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Service\ExecutedDestruction;
use Exception;
use OCA\OpenRegister\BackgroundJob\DestructionListRun;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\RecordStateInterface;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\DeletionRequest;
use OCA\OpenRegister\Service\Object\RetentionDestructionInterface;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCA\OpenRegister\Tests\Support\AuditTrailEvents;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Behaviour of DestructionListRun::execute().
 */
class DestructionListRunBehaviourTest extends TestCase {

	/**
	 * Objects the mapper knows, by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $objects = [];

	/**
	 * Uuids whose find() throws.
	 *
	 * @var string[]
	 */
	private array $unreadable = [];

	/**
	 * Uuids under an active legal hold.
	 *
	 * @var string[]
	 */
	private array $held = [];

	/**
	 * Uuids deleted, in order.
	 *
	 * @var string[]
	 */
	private array $deleted = [];

	/**
	 * Uuids whose delete throws.
	 *
	 * @var string[]
	 */
	private array $undeletable = [];

	/**
	 * Audit entries written: [uuid, action, context, archiefstatus at write time].
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $audits = [];

	/**
	 * Snapshots of every object passed to update().
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $updates = [];

	/**
	 * Certificate saves: [register, schema, data].
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $certificateSaves = [];

	/**
	 * Warning messages logged.
	 *
	 * @var string[]
	 */
	private array $warnings = [];

	/**
	 * Info messages logged.
	 *
	 * @var string[]
	 */
	private array $infos = [];

	/**
	 * Notifications sent: [user, object type, object id, subject, params].
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $notified = [];

	/**
	 * Number of times the destruction frame was opened.
	 *
	 * @var integer
	 */
	private int $frames = 0;

	/**
	 * Build a list object with the given status and entries.
	 *
	 * @param string                     $status  List status.
	 * @param list<array<string, mixed>> $entries List entries.
	 * @param array<string, mixed>       $extra   Extra list fields.
	 *
	 * @return ObjectEntity
	 */
	private function listObject(string $status, array $entries, array $extra = []): ObjectEntity {
		$list = new ObjectEntity();
		$list->setUuid('list-1');
		$list->setObject(array_merge(['status' => $status, 'objects' => $entries], $extra));
		$this->objects['list-1'] = $list;
		return $list;
	}

	/**
	 * Register a destroyable object.
	 *
	 * @param string $uuid Object uuid.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('7');
		$object->setSchema('8');
		$object->setRetention(['archiefnominatie' => 'vernietigen']);
		$this->objects[$uuid] = $object;
		return $object;
	}

	/**
	 * Build the run against scripted collaborators.
	 *
	 * @param array<string, mixed>     $settings  Archival settings.
	 * @param string[]|null|string     $archivist Members of the archivist group; null for no group, 'throw' to fail.
	 * @param bool                     $certFails Whether the certificate save throws.
	 *
	 * @return DestructionListRun
	 */
	private function makeRun(array $settings = [], array|string|null $archivist = [], bool $certFails = false): DestructionListRun {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		$logger->method('info')->willReturnCallback(
			function (string $message): void {
				$this->infos[] = $message;
			}
		);

		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function (string|int $id): ObjectEntity {
				if (in_array($id, $this->unreadable, true) === true) {
					throw new RuntimeException('not found '.$id);
				}

				return $this->objects[$id];
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (ObjectEntity $entity): ObjectEntity {
				$this->updates[] = $entity->getObject();
				return $entity;
			}
		);

		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->method('hasActiveLegalHold')->willReturnCallback(
			fn (ObjectEntity $object): bool => in_array($object->getUuid(), $this->held, true)
		);
		$retention->method('certify')->willReturnCallback(
			fn (ExecutedDestruction $execution): array => ['certificateFor' => $execution->destructionList['status'], 'count' => $execution->destroyedCount, 'at' => $execution->executedAt]
		);

		$settingsReader = $this->createMock(ArchivalSettingsReaderInterface::class);
		$settingsReader->method('getArchivalSettingsOnly')->willReturn($settings);

		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(
			function (ObjectEntity $object, string $action, array $context): AuditTrail {
				$this->audits[] = [$object->getUuid(), $action, $context, ($object->getRetention()['archiefstatus'] ?? null)];
				return new AuditTrail();
			}
		));

		$delete = $this->createMock(DeleteObject::class);
		$delete->method('deleteObject')->willReturnCallback(
			function (DeletionRequest $request, bool $rbac, bool $multitenancy): bool {
				[$register, $schema, $uuid, $original] = [$request->register, $request->schema, $request->uuid, $request->options->originalObjectId];
				if (in_array($uuid, $this->undeletable, true) === true) {
					throw new RuntimeException('locked '.$uuid);
				}

				$this->deleted[] = [$register, $schema, $uuid, $original, $rbac, $multitenancy];
				return true;
			}
		);

		$frame = $this->createMock(RetentionDestructionInterface::class);
		$frame->method('run')->willReturnCallback(
			function (callable $operation): mixed {
				$this->frames++;
				return $operation();
			}
		);

		$saver = $this->createMock(SaveObjectWriterInterface::class);
		$saver->method('saveObject')->willReturnCallback(
			function ($register, $schema, array $data) use ($certFails): ObjectEntity {
				if ($certFails === true) {
					throw new RuntimeException('cert store down');
				}

				$this->certificateSaves[] = [$register, $schema, $data];
				$saved = new ObjectEntity();
				$saved->setUuid('cert-uuid');
				return $saved;
			}
		);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$notifications->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->notified[] = $this->pending[spl_object_id($notification)];
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		if ($archivist === 'throw') {
			$groups->method('get')->willThrowException(new RuntimeException('groups down'));
		} else if ($archivist === null) {
			$groups->method('get')->with('archivaris')->willReturn(null);
		} else {
			$users = [];
			foreach ($archivist as $uid) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$users[] = $user;
			}

			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn($users);
			$groups->method('get')->with('archivaris')->willReturn($group);
		}

		$services = [
			RetentionServiceInterface::class       => $retention,
			ArchivalSettingsReaderInterface::class => $settingsReader,
			MagicMapperInterface::class            => $mapper,
			AuditTrailMapperInterface::class       => $audit,
			DeleteObject::class                    => $delete,
			RetentionDestructionInterface::class   => $frame,
			SaveObjectWriterInterface::class       => $saver,
			INotificationManager::class            => $notifications,
			IGroupManager::class                   => $groups,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $services[$id]);

		return new DestructionListRun($container, $logger);
	}

	/**
	 * What each notification mock was given, by object id.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $pending = [];

	/**
	 * A notification mock that records what the run sets on it.
	 *
	 * @return INotification
	 */
	private function notification(): INotification {
		$mock = $this->createMock(INotification::class);
		$id   = spl_object_id($mock);
		$this->pending[$id] = [null, null, null, null, null, null];
		$mock->method('setApp')->willReturnCallback(
			function (string $app) use ($mock, $id): INotification {
				$this->pending[$id][5] = $app;
				return $mock;
			}
		);
		$mock->method('setUser')->willReturnCallback(
			function (string $user) use ($mock, $id): INotification {
				$this->pending[$id][0] = $user;
				return $mock;
			}
		);
		$mock->method('setDateTime')->willReturnSelf();
		$mock->method('setObject')->willReturnCallback(
			function (string $type, string $objectId) use ($mock, $id): INotification {
				$this->pending[$id][1] = $type;
				$this->pending[$id][2] = $objectId;
				return $mock;
			}
		);
		$mock->method('setSubject')->willReturnCallback(
			function (string $subject, array $params) use ($mock, $id): INotification {
				$this->pending[$id][3] = $subject;
				$this->pending[$id][4] = $params;
				return $mock;
			}
		);
		return $mock;
	}

	public function testListThatIsNotApprovedIsNeitherClaimedNorTouched(): void {
		$this->listObject('draft', [['uuid' => 'a']]);
		$this->object('a');

		$this->makeRun()->execute('list-1');

		$this->assertSame([], $this->updates);
		$this->assertSame([], $this->deleted);
		$this->assertSame([], $this->audits);
		$this->assertSame(['[DestructionExecutionJob] List not approved: list-1'], $this->warnings);
	}

	public function testListWithoutStatusIsTreatedAsNotApproved(): void {
		$list = new ObjectEntity();
		$list->setUuid('list-1');
		$list->setObject(['objects' => [['uuid' => 'a']]]);
		$this->objects['list-1'] = $list;
		$this->object('a');

		$this->makeRun()->execute('list-1');

		$this->assertSame([], $this->updates);
		$this->assertSame([], $this->deleted);
	}

	public function testApprovedListIsClaimedAsExecutingBeforeAnyEntryIsDestroyed(): void {
		$this->listObject('approved', [['uuid' => 'a']]);
		$this->object('a');

		$this->makeRun()->execute('list-1');

		$this->assertCount(2, $this->updates);
		$this->assertSame('executing', $this->updates[0]['status']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $this->updates[0]['executingAt']);
		$this->assertArrayNotHasKey('executedAt', $this->updates[0]);
		$this->assertSame('executed', $this->updates[1]['status']);
	}

	public function testDestroyedObjectIsMarkedAuditedThenDeletedInsideTheDestructionFrame(): void {
		$this->listObject('approved', [['uuid' => 'a', 'classification' => '1.1']], ['approvals' => [['userId' => 'ann'], ['userId' => 'bob']]]);
		$this->object('a');

		$this->makeRun()->execute('list-1');

		$this->assertSame(
			[['a', 'archival.destroyed', ['destructionListUuid' => 'list-1', 'classification' => '1.1', 'approvedBy' => ['ann', 'bob']], RecordStateInterface::DESTROYED]],
			$this->audits
		);
		$this->assertSame(['archiefnominatie' => 'vernietigen', 'archiefstatus' => RecordStateInterface::DESTROYED], $this->objects['a']->getRetention());
		$this->assertSame([['7', '8', 'a', null, false, false]], $this->deleted);
		$this->assertSame(1, $this->frames);
		$this->assertSame(1, $this->updates[1]['destroyedCount']);
	}

	public function testAuditContextWithoutClassificationOrApprovalsCarriesNullAndEmpty(): void {
		$this->listObject('approved', [['uuid' => 'a']]);
		$this->object('a');

		$this->makeRun()->execute('list-1');

		$this->assertSame(['destructionListUuid' => 'list-1', 'classification' => null, 'approvedBy' => []], $this->audits[0][2]);
	}

	public function testObjectWithNullRetentionStillGetsTheDestroyedStatus(): void {
		$this->listObject('approved', [['uuid' => 'a']]);
		$object = $this->object('a');
		$object->setRetention(null);

		$this->makeRun()->execute('list-1');

		$this->assertSame(['archiefstatus' => RecordStateInterface::DESTROYED], $object->getRetention());
	}

	public function testEntryWithoutUuidCountsAsErrorAndIsNotLookedUp(): void {
		$this->listObject('approved', [['classification' => 'A']]);

		$this->makeRun()->execute('list-1');

		$final = $this->updates[1];
		$this->assertSame(1, $final['skippedErrors']);
		$this->assertSame(0, $final['destroyedCount']);
		$this->assertSame([], $this->audits);
	}

	public function testRetainAndTransferDecisionsAreWithheldButDestroyIsCarriedOut(): void {
		$this->listObject(
			'approved',
			[
				['uuid' => 'keep', 'decision' => 'retain'],
				['uuid' => 'move', 'decision' => 'transfer'],
				['uuid' => 'go', 'decision' => 'destroy'],
			]
		);
		$this->object('keep');
		$this->object('move');
		$this->object('go');

		$this->makeRun()->execute('list-1');

		$this->assertSame(['go'], array_column($this->deleted, 2));
		$this->assertSame(['go'], array_column($this->audits, 0));
		$this->assertSame(2, $this->updates[1]['skippedDecisions']);
		$this->assertSame(1, $this->updates[1]['destroyedCount']);
		$this->assertContains('[DestructionExecutionJob] Refusing to destroy keep: review decision "retain"', $this->warnings);
		$this->assertContains('[DestructionExecutionJob] Refusing to destroy move: review decision "transfer"', $this->warnings);
		$this->assertArrayNotHasKey('archiefstatus', $this->objects['keep']->getRetention());
	}

	public function testHeldObjectIsNeitherAuditedNorDeletedAndArchivistsAreNotified(): void {
		$this->listObject('approved', [['uuid' => 'held'], ['uuid' => 'free']]);
		$this->object('held');
		$this->object('free');
		$this->held = ['held'];

		$this->makeRun([], ['ann', 'bob'])->execute('list-1');

		$this->assertSame(['free'], array_column($this->deleted, 2));
		$this->assertSame(['free'], array_column($this->audits, 0));
		$this->assertArrayNotHasKey('archiefstatus', $this->objects['held']->getRetention());
		$this->assertSame(1, $this->updates[1]['skippedHolds']);
		$params = ['listUuid' => 'list-1', 'skippedCount' => 1];
		$this->assertSame(
			[
				['ann', 'destruction_list', 'list-1', 'destruction_holds_skipped', $params, 'openregister'],
				['bob', 'destruction_list', 'list-1', 'destruction_holds_skipped', $params, 'openregister'],
			],
			$this->notified
		);
	}

	public function testNoHoldsMeansNoNotification(): void {
		$this->listObject('approved', [['uuid' => 'a']]);
		$this->object('a');

		$this->makeRun([], ['ann'])->execute('list-1');

		$this->assertSame([], $this->notified);
	}

	public function testMissingArchivistGroupSendsNothing(): void {
		$this->listObject('approved', [['uuid' => 'held']]);
		$this->object('held');
		$this->held = ['held'];

		$this->makeRun([], null)->execute('list-1');

		$this->assertSame([], $this->notified);
		$this->assertSame('executed', $this->updates[1]['status']);
	}

	public function testNotificationFailureIsLoggedAndTheListStillEndsExecuted(): void {
		$this->listObject('approved', [['uuid' => 'held']]);
		$this->object('held');
		$this->held = ['held'];

		$this->makeRun([], 'throw')->execute('list-1');

		$this->assertContains('[DestructionExecutionJob] Hold notify error: groups down', $this->warnings);
		$this->assertSame('executed', $this->updates[1]['status']);
	}

	public function testUnreadableAndUndeletableObjectsAreCountedAsErrorsAndTheRestContinues(): void {
		$this->listObject('approved', [['uuid' => 'gone'], ['uuid' => 'locked'], ['uuid' => 'ok']]);
		$this->object('locked');
		$this->object('ok');
		$this->unreadable = ['gone'];
		$this->undeletable = ['locked'];

		$this->makeRun()->execute('list-1');

		$this->assertSame(['ok'], array_column($this->deleted, 2));
		$this->assertSame(2, $this->updates[1]['skippedErrors']);
		$this->assertSame(1, $this->updates[1]['destroyedCount']);
		$this->assertContains('[DestructionExecutionJob] Failed: gone: not found gone', $this->warnings);
		$this->assertContains('[DestructionExecutionJob] Failed: locked: locked locked', $this->warnings);
	}

	public function testFinalListCarriesEveryCounterAndTheDoneLine(): void {
		$this->listObject(
			'approved',
			[['uuid' => 'a'], ['uuid' => 'b'], ['uuid' => 'held'], ['uuid' => 'keep', 'decision' => 'retain'], ['nouuid' => true]]
		);
		$this->object('a');
		$this->object('b');
		$this->object('held');
		$this->object('keep');
		$this->held = ['held'];

		$this->makeRun([], null)->execute('list-1');

		$final = $this->updates[1];
		$this->assertSame('executed', $final['status']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $final['executedAt']);
		$this->assertSame([2, 1, 1, 1], [$final['destroyedCount'], $final['skippedHolds'], $final['skippedErrors'], $final['skippedDecisions']]);
		$this->assertSame('[DestructionExecutionJob] Done: 2 destroyed, 1 held, 1 withheld by decision, 1 errors', end($this->infos));
	}

	public function testEntriesAreProcessedInConfiguredBatches(): void {
		$entries = [];
		for ($i = 1; $i <= 5; $i++) {
			$entries[] = ['uuid' => 'o'.$i];
			$this->object('o'.$i);
		}

		$this->listObject('approved', $entries);

		$this->makeRun(['destructionBatchSize' => 2])->execute('list-1');

		$batches = array_values(array_filter($this->infos, fn (string $line): bool => str_contains($line, 'Batch')));
		$this->assertSame(
			['[DestructionExecutionJob] Batch 1/3', '[DestructionExecutionJob] Batch 2/3', '[DestructionExecutionJob] Batch 3/3'],
			$batches
		);
		$this->assertSame(['o1', 'o2', 'o3', 'o4', 'o5'], array_column($this->deleted, 2));
	}

	public function testDefaultBatchSizeIsFifty(): void {
		$entries = [];
		for ($i = 1; $i <= 51; $i++) {
			$entries[] = ['uuid' => 'o'.$i, 'decision' => 'retain'];
		}

		$this->listObject('approved', $entries);

		$this->makeRun()->execute('list-1');

		$batches = array_values(array_filter($this->infos, fn (string $line): bool => str_contains($line, 'Batch')));
		$this->assertSame(['[DestructionExecutionJob] Batch 1/2', '[DestructionExecutionJob] Batch 2/2'], $batches);
	}

	public function testListWithoutObjectsKeyEndsExecutedWithZeroCounts(): void {
		$list = new ObjectEntity();
		$list->setUuid('list-1');
		$list->setObject(['status' => 'approved']);
		$this->objects['list-1'] = $list;

		$this->makeRun()->execute('list-1');

		$final = $this->updates[1];
		$this->assertSame('executed', $final['status']);
		$this->assertSame(0, $final['destroyedCount']);
		$this->assertSame([], array_filter($this->infos, fn (string $line): bool => str_contains($line, 'Batch')));
	}

	public function testCertificateIsSavedInTheArchivalRegisterAndLinkedOnTheList(): void {
		$this->listObject('approved', [['uuid' => 'a']]);
		$this->object('a');

		$this->makeRun(['archivalRegister' => 5, 'destructionListSchema' => 6])->execute('list-1');

		$this->assertCount(1, $this->certificateSaves);
		[$register, $schema, $data] = $this->certificateSaves[0];
		$this->assertSame([5, 6], [$register, $schema]);
		$this->assertSame('executed', $data['certificateFor']);
		$this->assertSame(1, $data['count']);
		$this->assertSame($this->updates[1]['executedAt'], $data['at']);
		$this->assertSame('cert-uuid', $this->updates[1]['certificateUuid']);
	}

	public function testCertificateIsNotSavedWithoutRegister(): void {
		$this->listObject('approved', [['uuid' => 'a']]);
		$this->object('a');

		$this->makeRun(['destructionListSchema' => 6])->execute('list-1');

		$this->assertSame([], $this->certificateSaves);
		$this->assertArrayNotHasKey('certificateUuid', $this->updates[1]);
	}

	public function testCertificateIsNotSavedWithoutSchema(): void {
		$this->listObject('approved', [['uuid' => 'a']]);
		$this->object('a');

		$this->makeRun(['archivalRegister' => 5])->execute('list-1');

		$this->assertSame([], $this->certificateSaves);
		$this->assertArrayNotHasKey('certificateUuid', $this->updates[1]);
	}

	public function testCertificateSaveFailureIsLoggedAndTheListStillEndsExecuted(): void {
		$this->listObject('approved', [['uuid' => 'a']]);
		$this->object('a');

		$this->makeRun(['archivalRegister' => 5, 'destructionListSchema' => 6], [], true)->execute('list-1');

		$this->assertContains('[DestructionExecutionJob] Certificate save error: cert store down', $this->warnings);
		$this->assertSame('executed', $this->updates[1]['status']);
		$this->assertArrayNotHasKey('certificateUuid', $this->updates[1]);
	}
}
