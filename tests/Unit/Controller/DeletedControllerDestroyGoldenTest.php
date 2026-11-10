<?php

/**
 * DeletedController destroy golden test.
 *
 * Pins the destruction record's context (the published window and whether it
 * was waived), the refusal while the window is open, and the exact messages of
 * the bulk restore and the bulk purge.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use DateTimeImmutable;
use OCA\OpenRegister\Controller\DeletedController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizer;
use OCA\OpenRegister\Service\Deletion\DeletionRetention;
use OCA\OpenRegister\Service\Deletion\DeletionServiceBundle;
use OCA\OpenRegister\Service\Deletion\DeletionWindow;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\Deletion\DestructionServices;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use OCA\OpenRegister\Tests\Unit\Controller\Support\DeletedControllerFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DeletedControllerDestroyGoldenTest extends TestCase {

	private IRequest&MockObject $request;

	private MagicMapper&MockObject $objectMapper;

	private DeletionWindowService&MockObject $windows;

	private DestructionScopeService&MockObject $scopes;

	private DestructionRecorder&MockObject $recorder;

	private DeletedController $controller;

	/** @var list<string> */
	private array $deleted = [];

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->windows = $this->createMock(DeletionWindowService::class);
		$this->scopes = $this->createMock(DestructionScopeService::class);
		$this->recorder = $this->createMock(DestructionRecorder::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('recordmanager-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn(new Schema());

		$this->scopes->method('destroy')->willReturn(['scope' => [], 'destroyed' => [], 'total' => 0, 'failed' => []]);
		$this->objectMapper->method('delete')->willReturnCallback(
			function (ObjectEntity $entity): ObjectEntity {
				$this->deleted[] = (string)$entity->getUuid();

				return $entity;
			}
		);

		$this->controller = DeletedControllerFactory::create(
			'openregister',
			$this->request,
			$this->objectMapper,
			$this->createMock(RegisterMapper::class),
			$session,
			$this->createMock(AuditTrailMapper::class),
			new DeletionServiceBundle(
				$this->windows,
				$this->createMock(DestroyRightService::class),
				$this->createMock(RetentionClockService::class),
				new DestructionServices($this->scopes, $this->recorder),
			),
			new DeletedObjectAuthorizer($schemas, new CallerContext($session, $groups), $this->createMock(PermissionHandler::class)),
			$this->createMock(\OCA\OpenRegister\Service\Object\RenderObject::class)
		);
	}//end setUp()

	private function trashed(string $uuid): ObjectEntity {
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
			new DeletionRetention(30, DeletionWindow::SOURCE_INSTANCE),
			new DateTimeImmutable('2026-03-19T09:00:00+00:00')
		);
	}//end window()

	/**
	 * Destroy one trashed object and answer the record's rule and context.
	 *
	 * @return array{status: int, recorded: array<string, mixed>}
	 */
	private function destroyWith(bool $force, int $daysRemaining): array {
		$this->objectMapper->method('find')->willReturn($this->trashed('zaak-1'));
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'force') ? $force : $default
		);
		$this->windows->method('windowOf')->willReturn($this->window($daysRemaining));
		$recorded = [];
		$this->recorder->method('record')->willReturnCallback(
			static function (ObjectEntity $object, array $scope, string $rule, array $context) use (&$recorded): array {
				$recorded = ['uuid' => $object->getUuid(), 'rule' => $rule, 'context' => $context];

				return ['destroyedBy' => 'recordmanager-1'];
			}
		);

		$response = $this->controller->destroy('zaak-1');

		return ['status' => $response->getStatus(), 'recorded' => $recorded];
	}//end destroyWith()

	public function testAWaivedOpenWindowIsDestroyedAndTheWaiverRecorded(): void {
		$outcome = $this->destroyWith(force: true, daysRemaining: 12);

		self::assertSame(200, $outcome['status']);
		self::assertSame(['zaak-1'], $this->deleted);
		self::assertSame('destroy-right-granted', $outcome['recorded']['rule']);
		self::assertSame($this->window(12)->toArray(), $outcome['recorded']['context']['deletionWindow']);
		self::assertTrue($outcome['recorded']['context']['windowWaived']);
	}//end testAWaivedOpenWindowIsDestroyedAndTheWaiverRecorded()

	public function testALapsedWindowIsDestroyedWithoutAWaiver(): void {
		$outcome = $this->destroyWith(force: false, daysRemaining: 0);

		self::assertSame(200, $outcome['status']);
		self::assertSame(['zaak-1'], $this->deleted);
		self::assertFalse($outcome['recorded']['context']['windowWaived']);
	}//end testALapsedWindowIsDestroyedWithoutAWaiver()

	public function testAnOpenWindowWithoutAWaiverRefusesAndDestroysNothing(): void {
		$outcome = $this->destroyWith(force: false, daysRemaining: 3);

		self::assertSame(409, $outcome['status']);
		self::assertSame([], $this->deleted);
		self::assertSame([], $outcome['recorded']);
	}//end testAnOpenWindowWithoutAWaiverRefusesAndDestroysNothing()

	public function testTheBulkMessagesNameTheCountsAndTheMissing(): void {
		$trashed = $this->trashed('uuid-1');
		$slim = new ObjectEntity();
		$slim->setUuid('uuid-1');
		$slim->setSchema('2');
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'ids') ? ['uuid-1', 'uuid-missing'] : $default
		);
		$this->objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([$slim]);
		$this->objectMapper->method('find')->willReturn($trashed);
		$this->objectMapper->method('restoreObject')->willReturn($trashed);

		self::assertSame('Restored 1 objects, 1 failed (1 not found)', $this->controller->restoreMultiple()->getData()['message']);
		self::assertSame('Permanently deleted 1 objects, 1 failed (1 not found)', $this->controller->destroyMultiple()->getData()['message']);
	}//end testTheBulkMessagesNameTheCountsAndTheMissing()
}//end class
