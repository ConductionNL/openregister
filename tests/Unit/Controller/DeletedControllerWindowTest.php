<?php

/**
 * The delete window and the recorded destruction, at the controller.
 *
 * Each answer here used to be something else: the trash listing publishes
 * the window, a restore inside the window is one act and is recorded with
 * its actor, a destroy inside the window refuses and says how long is left,
 * a destroy after it records the act before the row goes, the record stays
 * readable once the object is gone, and the preview counts the scope
 * without touching it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use OCA\OpenRegister\Controller\DeletedController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DeletionWindow;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionScope;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DeletedControllerWindowTest extends TestCase {
	/**
	 * Request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Object mapper double.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $objectMapper;

	/**
	 * Schema mapper double.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Window service double.
	 *
	 * @var DeletionWindowService&MockObject
	 */
	private DeletionWindowService&MockObject $windows;

	/**
	 * Destroy-right service double.
	 *
	 * @var DestroyRightService&MockObject
	 */
	private DestroyRightService&MockObject $rights;

	/**
	 * Destruction scope service double.
	 *
	 * @var DestructionScopeService&MockObject
	 */
	private DestructionScopeService&MockObject $scopes;

	/**
	 * Destruction recorder double.
	 *
	 * @var DestructionRecorder&MockObject
	 */
	private DestructionRecorder&MockObject $recorder;

	/**
	 * Retention clock service double.
	 *
	 * @var RetentionClockService&MockObject
	 */
	private RetentionClockService&MockObject $clocks;

	/**
	 * Audit trail mapper double.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper&MockObject $auditTrails;

	/**
	 * Controller under test.
	 *
	 * @var DeletedController
	 */
	private DeletedController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->windows = $this->createMock(DeletionWindowService::class);
		$this->rights = $this->createMock(DestroyRightService::class);
		$this->scopes = $this->createMock(DestructionScopeService::class);
		$this->recorder = $this->createMock(DestructionRecorder::class);
		$this->clocks = $this->createMock(RetentionClockService::class);
		$this->auditTrails = $this->createMock(AuditTrailMapper::class);

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('recordmanager-1');
		$user->method('getDisplayName')->willReturn('Record Manager');
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$this->schemaMapper->method('find')->willReturn(new Schema());

		$this->controller = new DeletedController(
			'openregister',
			$this->request,
			$this->objectMapper,
			$this->createMock(RegisterMapper::class),
			$this->schemaMapper,
			$session,
			$groups,
			$this->createMock(PermissionHandler::class),
			$this->windows,
			$this->rights,
			$this->scopes,
			$this->recorder,
			$this->clocks,
			$this->auditTrails
		);
	}//end setUp()

	private function trashed(string $uuid = 'zaak-100'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('2');
		$object->setDeleted(['deletedAt' => '2026-03-19T09:00:00+00:00', 'deletedBy' => 'behandelaar-1']);

		return $object;
	}//end trashed()

	private function window(int $daysRemaining): DeletionWindow {
		return new DeletionWindow(
			new DateTimeImmutable('2026-04-18T09:00:00+00:00'),
			$daysRemaining,
			30,
			DeletionWindow::SOURCE_INSTANCE,
			new DateTimeImmutable('2026-03-19T09:00:00+00:00')
		);
	}//end window()

	public function testTheTrashListingNamesTheDateAndTheDaysRemaining(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->objectMapper->method('findDeletedAcrossAllMagicTables')->willReturn([$this->trashed()]);
		$this->objectMapper->method('countDeletedAcrossAllMagicTables')->willReturn(1);
		$this->windows->method('windowFor')->willReturn($this->window(30));

		$data = $this->controller->index()->getData();
		$row = $data['results'][0];

		self::assertSame('2026-04-18T09:00:00+00:00', $row['deletionWindow']['destroyableFrom']);
		self::assertSame(30, $row['deletionWindow']['daysRemaining']);
		self::assertFalse($row['deletionWindow']['lapsed']);
	}//end testTheTrashListingNamesTheDateAndTheDaysRemaining()

	public function testARestoreInsideTheWindowIsOneActAndIsRecorded(): void {
		$object = $this->trashed();
		$this->objectMapper->method('find')->willReturn($object);
		$this->objectMapper->expects(self::once())->method('restoreObject')->willReturn($object);
		$this->windows->method('windowFor')->willReturn($this->window(30));

		$recorded = [];
		$this->auditTrails->expects(self::once())
			->method('createAuditTrailEntry')
			->willReturnCallback(
				function (ObjectEntity $entity, string $action, array $context, ?string $actorId) use (&$recorded): AuditTrail {
					$recorded = ['action' => $action, 'context' => $context, 'actor' => $actorId];

					return new AuditTrail();
				}
			);

		$response = $this->controller->restore('zaak-100');

		self::assertSame(200, $response->getStatus());
		self::assertSame(30, $response->getData()['restoredWithin']['daysRemaining']);
		self::assertSame('object.restored', $recorded['action']);
		self::assertSame('recordmanager-1', $recorded['actor']);
		self::assertSame('recordmanager-1', $recorded['context']['restoredBy']);
	}//end testARestoreInsideTheWindowIsOneActAndIsRecorded()

	public function testADestroyInsideTheWindowRefusesAndSaysHowLongIsLeft(): void {
		$this->objectMapper->method('find')->willReturn($this->trashed());
		$this->request->method('getParam')->willReturn(false);
		$this->windows->method('windowFor')->willReturn($this->window(12));

		// Spies rather than never() expectations: an unmet expectation raises
		// inside the controller's own try/catch and comes back as a 500, which
		// would redden this test for the wrong reason and hide whether the
		// window was honoured at all.
		$destroyed = [];
		$this->objectMapper->method('delete')->willReturnCallback(
			static function (ObjectEntity $entity) use (&$destroyed): ObjectEntity {
				$destroyed[] = (string)$entity->getUuid();

				return $entity;
			}
		);
		$this->scopes->method('destroy')->willReturn(
			['scope' => [], 'destroyed' => [], 'total' => 0, 'failed' => []]
		);
		$this->recorder->method('record')->willReturn([]);

		$response = $this->controller->destroy('zaak-100');
		$body = $response->getData();

		self::assertSame(409, $response->getStatus());
		self::assertSame('recovery-window-open', $body['rule']);
		self::assertStringContainsString('2026-04-18', $body['message']);
		self::assertSame(12, $body['deletionWindow']['daysRemaining']);
		self::assertSame([], $destroyed);
	}//end testADestroyInsideTheWindowRefusesAndSaysHowLongIsLeft()

	public function testAfterTheWindowTheObjectIsDestroyedAndTheActIsRecordedFirst(): void {
		$object = $this->trashed();
		$this->objectMapper->method('find')->willReturn($object);
		$this->request->method('getParam')->willReturn(false);
		$this->windows->method('windowFor')->willReturn($this->window(0));

		$this->scopes->method('destroy')->willReturn(
			[
				'scope' => [DestructionScope::NOTES],
				'destroyed' => [DestructionScope::NOTES => 4],
				'total' => 4,
				'failed' => [],
			]
		);

		$order = [];
		$this->recorder->expects(self::once())
			->method('record')
			->willReturnCallback(
				static function () use (&$order): array {
					$order[] = 'record';

					return ['destroyedBy' => 'recordmanager-1', 'destroyedTotal' => 4];
				}
			);
		$this->objectMapper->expects(self::once())
			->method('delete')
			->willReturnCallback(
				static function (ObjectEntity $entity) use (&$order): ObjectEntity {
					$order[] = 'delete';

					return $entity;
				}
			);

		$response = $this->controller->destroy('zaak-100');

		self::assertSame(200, $response->getStatus());
		self::assertSame(4, $response->getData()['destruction']['destroyedTotal']);

		// The record is written BEFORE the row goes, so a crash between them
		// leaves an over-recorded destruction rather than an unrecorded one.
		self::assertSame(['record', 'delete'], $order);
	}//end testAfterTheWindowTheObjectIsDestroyedAndTheActIsRecordedFirst()

	public function testTheDestructionRecordIsReadableAfterTheObjectIsGone(): void {
		$record = new AuditTrail();
		$record->setAction(DestructionScope::DESTRUCTION_ACTION);
		$record->setObjectUuid('zaak-100');

		$this->auditTrails->expects(self::once())
			->method('findForObjectByAction')
			->with(self::equalTo('zaak-100'), self::equalTo([DestructionScope::DESTRUCTION_ACTION]))
			->willReturn([$record]);

		// No object lookup at all: the record has to be readable when the
		// object it describes no longer resolves.
		$this->objectMapper->expects(self::never())->method('find');

		$response = $this->controller->destructionRecord('zaak-100');

		self::assertSame(200, $response->getStatus());
		self::assertSame(1, $response->getData()['total']);
	}//end testTheDestructionRecordIsReadableAfterTheObjectIsGone()
	public function testThePreviewReportsTheScopeWithCountsAndDestroysNothing(): void {
		$this->objectMapper->method('find')->willReturn($this->trashed());
		$this->rights->method('refusalFor')->willReturn(null);
		$this->windows->method('windowFor')->willReturn($this->window(7));
		$this->clocks->method('clocksFor')->willReturn(
			[
				'avg'      => ['date' => null, 'rule' => 'no-processing-activity'],
				'archive'  => ['date' => null, 'rule' => 'no-selectielijst'],
				'conflict' => null,
				'held'     => false,
			]
		);

		$this->scopes->expects(self::once())
			->method('preview')
			->willReturn(
				[
					'scope'       => [DestructionScope::NOTES],
					'counts'      => [DestructionScope::NOTES => 4],
					'total'       => 4,
					'unknown'     => [],
					'unavailable' => [],
					'destroyable' => true,
				]
			);

		// A preview that destroys is not a preview. Spies rather than never()
		// expectations, for the reason the destroy test above gives.
		$destroyed = [];
		$this->scopes->method('destroy')->willReturnCallback(
			static function () use (&$destroyed): array {
				$destroyed[] = 'scope';

				return ['scope' => [], 'destroyed' => [], 'total' => 0, 'failed' => []];
			}
		);
		$this->objectMapper->method('delete')->willReturnCallback(
			static function (ObjectEntity $entity) use (&$destroyed): ObjectEntity {
				$destroyed[] = (string)$entity->getUuid();

				return $entity;
			}
		);

		$response = $this->controller->destructionPreview('zaak-100');
		$body = $response->getData();

		self::assertSame(200, $response->getStatus());
		self::assertSame('zaak-100', $body['objectUuid']);
		self::assertSame([DestructionScope::NOTES], $body['preview']['scope']);
		self::assertSame(4, $body['preview']['counts'][DestructionScope::NOTES]);
		self::assertSame(4, $body['preview']['total']);
		self::assertTrue($body['preview']['destroyable']);
		self::assertSame(7, $body['deletionWindow']['daysRemaining']);
		self::assertNull($body['clocks']['conflict']);
		self::assertSame([], $destroyed);
	}//end testThePreviewReportsTheScopeWithCountsAndDestroysNothing()

}//end class
