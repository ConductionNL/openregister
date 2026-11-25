<?php

/**
 * DeletedController with the cascade restore: the counts, the refusals, the
 * `cascade: false` switch, the preview route and the per-dependant audit entries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\DeletedController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Deletion\CascadeRestoreItem;
use OCA\OpenRegister\Service\Deletion\CascadeRestorePlan;
use OCA\OpenRegister\Service\Deletion\CascadeRestoreRefusedException;
use OCA\OpenRegister\Service\Deletion\CascadeRestoreResult;
use OCA\OpenRegister\Service\Deletion\CascadeRestoreService;
use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizer;
use OCA\OpenRegister\Service\Deletion\DeletionServiceBundle;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\Deletion\DestructionServices;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Tests\Unit\Controller\Support\DeletedControllerFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\DeletedController
 */
final class DeletedControllerCascadeRestoreTest extends TestCase {
	private const ROOT = 'lead-0000-0000-0000-000000000001';

	private IRequest&MockObject $request;
	private MagicMapper&MockObject $objects;
	private AuditTrailMapper&MockObject $auditTrails;
	private DeletedObjectAuthorizer&MockObject $authorizer;
	private CascadeRestoreService&MockObject $cascade;
	private IUserSession&MockObject $session;

	/**
	 * Request parameters the controller reads.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Every audit event the controller recorded.
	 *
	 * @var list<ObjectAuditEvent>
	 */
	private array $recorded = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request     = $this->createMock(IRequest::class);
		$this->objects     = $this->createMock(MagicMapper::class);
		$this->auditTrails = $this->createMock(AuditTrailMapper::class);
		$this->authorizer  = $this->createMock(DeletedObjectAuthorizer::class);
		$this->cascade     = $this->createMock(CascadeRestoreService::class);
		$this->session     = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager');
		$user->method('getDisplayName')->willReturn('Sales Manager');
		$this->session->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default=null) => array_key_exists($key, $this->params) === true ? $this->params[$key] : $default
		);
		$this->auditTrails->method('recordObjectEvent')->willReturnCallback(
			function (ObjectAuditEvent $event): AuditTrail {
				$this->recorded[] = $event;

				return new AuditTrail();
			}
		);
		$this->objects->method('find')->willReturnCallback(static fn (string $id): ObjectEntity => self::deleted(uuid: $id));
	}//end setUp()

	private function controller(bool $withCascade=true): DeletedController {
		$deletion = new DeletionServiceBundle(
			$this->createMock(originalClassName: DeletionWindowService::class),
			$this->createMock(originalClassName: DestroyRightService::class),
			$this->createMock(originalClassName: RetentionClockService::class),
			new DestructionServices($this->createMock(originalClassName: DestructionScopeService::class), $this->createMock(originalClassName: DestructionRecorder::class)),
			$withCascade === true ? $this->cascade : null,
		);

		return DeletedControllerFactory::create(
			$this->request,
			$this->objects,
			$this->createMock(RegisterMapper::class),
			$this->session,
			$this->auditTrails,
			$deletion,
			$this->authorizer,
			$this->createMock(RenderObject::class)
		);
	}//end controller()

	private static function deleted(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('1');
		$object->setDeleted(['deletedAt' => '2026-11-20T10:00:00+00:00']);

		return $object;
	}//end deleted()

	private function mayUpdate(bool $may): void {
		$this->authorizer->method('userMayActOnDeletedObject')->willReturn($may);
	}//end mayUpdate()

	private static function plan(): CascadeRestorePlan {
		return new CascadeRestorePlan(
			root: self::ROOT,
			cascadeKnown: true,
			items: [
				new CascadeRestoreItem(uuid: 'act-1', class: 'restore', reason: 'deleted with this object', schema: 'activity', visible: true),
				new CascadeRestoreItem(uuid: 'act-2', class: 'restore', reason: 'deleted with this object', schema: 'activity', visible: true),
				new CascadeRestoreItem(uuid: 'quote-1', class: 'relink', reason: 'the field still holds what the delete wrote', schema: 'quote', visible: true, property: 'lead'),
				new CascadeRestoreItem(uuid: 'quote-2', class: 'changed', reason: 'the field was changed since the delete', schema: 'quote', visible: true, property: 'lead'),
			]
		);
	}//end plan()

	public function testARestoreReportsTheCascadeCountsAndRecordsEveryDependant(): void {
		$this->mayUpdate(may: true);
		$this->cascade->expects($this->once())->method('restore')
			->with($this->callback(static fn (ObjectEntity $root): bool => $root->getUuid() === self::ROOT), true)
			->willReturn(new CascadeRestoreResult(dependants: [self::deleted(uuid: 'act-1'), self::deleted(uuid: 'act-2')], relinked: 1, plan: self::plan()));
		$this->objects->expects($this->never())->method('restoreObject');

		$response = $this->controller()->restore(self::ROOT);

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertSame(3, $data['restored']);
		self::assertSame(1, $data['relinked']);
		self::assertSame('quote-2', $data['skipped']['changed'][0]['uuid']);
		self::assertSame([], $data['skipped']['gone']);

		self::assertCount(3, $this->recorded);
		self::assertSame(self::ROOT, $this->recorded[0]->object->getUuid());
		self::assertSame(3, $this->recorded[0]->context['restored']);
		self::assertSame(1, $this->recorded[0]->context['relinked']);
		foreach ([1 => 'act-1', 2 => 'act-2'] as $i => $uuid) {
			self::assertSame('object.restored', $this->recorded[$i]->action);
			self::assertSame($uuid, $this->recorded[$i]->object->getUuid());
			self::assertSame(self::ROOT, $this->recorded[$i]->context['restoredWith']);
		}
	}//end testARestoreReportsTheCascadeCountsAndRecordsEveryDependant()

	public function testCascadeFalseIsPassedOn(): void {
		$this->mayUpdate(may: true);
		$this->params['cascade'] = 'false';
		$this->cascade->expects($this->once())->method('restore')
			->with($this->anything(), false)
			->willReturn(new CascadeRestoreResult(dependants: [], relinked: 0));

		$data = $this->controller()->restore(self::ROOT)->getData();

		self::assertSame(1, $data['restored']);
		self::assertFalse($data['cascade']);
	}//end testCascadeFalseIsPassedOn()

	public function testAForbiddenDependantAnswers403AndRecordsNothing(): void {
		$this->mayUpdate(may: true);
		$plan = new CascadeRestorePlan(
			root: self::ROOT,
			cascadeKnown: true,
			items: [new CascadeRestoreItem(uuid: 'act-secret', class: 'forbidden', reason: 'you may not update it', schema: 'activity')]
		);
		$this->cascade->method('restore')->willThrowException(CascadeRestoreRefusedException::forbidden(plan: $plan));

		$response = $this->controller()->restore(self::ROOT);

		self::assertSame(403, $response->getStatus());
		self::assertSame(['count' => 1, 'schemas' => ['activity' => 1]], $response->getData()['forbidden']);
		self::assertStringNotContainsString('act-secret', (string)json_encode($response->getData()));
		self::assertSame([], $this->recorded);
	}//end testAForbiddenDependantAnswers403AndRecordsNothing()

	public function testACascadeOverTheCapAnswers409(): void {
		$this->mayUpdate(may: true);
		$this->cascade->method('restore')->willThrowException(CascadeRestoreRefusedException::tooLarge(cap: 5000));

		$response = $this->controller()->restore(self::ROOT);

		self::assertSame(409, $response->getStatus());
		self::assertSame(5000, $response->getData()['cap']);
	}//end testACascadeOverTheCapAnswers409()

	public function testNoRightOnTheRootNeverReachesTheCascade(): void {
		$this->mayUpdate(may: false);
		$this->cascade->expects($this->never())->method('restore');

		self::assertSame(403, $this->controller()->restore(self::ROOT)->getStatus());
	}//end testNoRightOnTheRootNeverReachesTheCascade()

	public function testWithoutTheServiceTheObjectIsRestoredAloneAsBefore(): void {
		$this->mayUpdate(may: true);
		$this->objects->expects($this->once())->method('restoreObject')->with(self::ROOT);

		$data = $this->controller(withCascade: false)->restore(self::ROOT)->getData();

		self::assertTrue($data['success']);
		self::assertArrayNotHasKey('restored', $data);
	}//end testWithoutTheServiceTheObjectIsRestoredAloneAsBefore()

	public function testThePreviewReturnsThePlanWithoutWriting(): void {
		$this->mayUpdate(may: true);
		$this->cascade->method('preview')->willReturn(self::plan());
		$this->cascade->expects($this->never())->method('restore');
		$this->objects->expects($this->never())->method('restoreObject');

		$response = $this->controller()->restorePreview(self::ROOT);

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertCount(2, $data['restore']);
		self::assertSame('lead', $data['relink'][0]['property']);
		self::assertSame('quote-2', $data['changed'][0]['uuid']);
		self::assertSame(['count' => 0, 'schemas' => []], $data['forbidden']);
		self::assertSame([], $this->recorded);
	}//end testThePreviewReturnsThePlanWithoutWriting()

	public function testThePreviewRefusesALiveObject(): void {
		$live = new ObjectEntity();
		$live->setUuid('live');
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('find')->willReturn($live);
		$this->objects = $objects;
		$this->mayUpdate(may: true);

		self::assertSame(400, $this->controller()->restorePreview('live')->getStatus());
	}//end testThePreviewRefusesALiveObject()

	public function testThePreviewRefusesACallerWithoutTheRight(): void {
		$this->mayUpdate(may: false);
		$this->cascade->expects($this->never())->method('preview');

		self::assertSame(403, $this->controller()->restorePreview(self::ROOT)->getStatus());
	}//end testThePreviewRefusesACallerWithoutTheRight()

	public function testThePreviewOverTheCapAnswers409(): void {
		$this->mayUpdate(may: true);
		$this->cascade->method('preview')->willThrowException(CascadeRestoreRefusedException::tooLarge(cap: 5000));

		self::assertSame(409, $this->controller()->restorePreview(self::ROOT)->getStatus());
	}//end testThePreviewOverTheCapAnswers409()

	public function testABulkRestoreRestoresEachRootWithItsCascade(): void {
		$this->mayUpdate(may: true);
		$this->params['ids'] = ['lead-a', 'lead-b'];
		$this->objects->method('findMultipleAcrossAllMagicTables')->willReturn([self::deleted(uuid: 'lead-a'), self::deleted(uuid: 'lead-b')]);
		$roots = [];
		$this->cascade->method('restore')->willReturnCallback(
			static function (ObjectEntity $root, bool $cascade) use (&$roots): CascadeRestoreResult {
				$roots[] = [$root->getUuid(), $cascade];

				return new CascadeRestoreResult(dependants: [], relinked: 0, plan: new CascadeRestorePlan(root: (string)$root->getUuid(), cascadeKnown: false, items: []));
			}
		);

		$data = $this->controller()->restoreMultiple()->getData();

		self::assertSame(2, $data['restored']);
		self::assertSame([['lead-a', true], ['lead-b', true]], $roots);
	}//end testABulkRestoreRestoresEachRootWithItsCascade()
}//end class
