<?php

/**
 * The trash shows a caller only what they could read (openregister#4078).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\DeletedController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizer;
use OCA\OpenRegister\Service\Deletion\DeletionServiceBundle;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every trash read asks for the caller's read scope, and serves only that.
 *
 * Before openregister#4078 a signed-in user with no rights at all listed every
 * soft-deleted object of every register, read the instance-wide deleted count
 * and read any destruction record, because index(), statistics() and
 * destructionRecord() asked nothing about the caller. The authorizer here is
 * the REAL one; only its collaborators are doubles.
 */
class DeletedControllerReadScopeTest extends TestCase {

	private DeletedController $controller;

	private IRequest&MockObject $request;

	private MagicMapper&MockObject $objectMapper;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private AuditTrailMapper&MockObject $auditTrails;

	private RenderObject&MockObject $renderObject;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn([]);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->auditTrails = $this->createMock(AuditTrailMapper::class);
		$this->renderObject = $this->createMock(RenderObject::class);

		$deletion = new DeletionServiceBundle(
			$this->createMock(DeletionWindowService::class),
			$this->createMock(DestroyRightService::class),
			$this->createMock(DestructionScopeService::class),
			$this->createMock(DestructionRecorder::class),
			$this->createMock(RetentionClockService::class),
		);

		$authorizer = new DeletedObjectAuthorizer(
			$this->createMock(SchemaMapper::class),
			$this->userSession,
			$this->groupManager,
			$this->createMock(PermissionHandler::class),
		);

		$this->controller = new DeletedController(
			'openregister',
			$this->request,
			$this->objectMapper,
			$this->createMock(RegisterMapper::class),
			$this->userSession,
			$this->auditTrails,
			$deletion,
			$authorizer,
			$this->renderObject
		);
	}//end setUp()

	/**
	 * Sign in a user.
	 *
	 * @param string $uid     The user id.
	 * @param bool   $isAdmin Whether the user is an administrator.
	 *
	 * @return void
	 */
	private function signIn(string $uid, bool $isAdmin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($isAdmin);
	}//end signIn()

	/**
	 * A non-admin's listing and its total are both asked for in the caller's read scope.
	 *
	 * @return void
	 */
	public function testNonAdminListingIsNarrowedToTheCallersReadScope(): void {
		$this->signIn(uid: 'burger', isAdmin: false);
		[$listArgs, $countArgs] = $this->captureScanArguments();

		$response = $this->controller->index();

		$this->assertSame(200, $response->getStatus());
		// limit, offset, _rbac, _multitenancy.
		$this->assertSame([20, null, true, true], $listArgs->args, 'The listing must be asked for in the caller\'s read scope.');
		$this->assertSame([true, true], $countArgs->args, 'The total must count only the caller\'s read scope.');
	}//end testNonAdminListingIsNarrowedToTheCallersReadScope()

	/**
	 * An administrator's listing is not narrowed, exactly as the object list is not.
	 *
	 * @return void
	 */
	public function testAdminListingIsNotNarrowed(): void {
		$this->signIn(uid: 'admin', isAdmin: true);
		[$listArgs, $countArgs] = $this->captureScanArguments();

		$this->assertSame(200, $this->controller->index()->getStatus());
		$this->assertSame([20, null, false, false], $listArgs->args);
		$this->assertSame([false, false], $countArgs->args);
	}//end testAdminListingIsNotNarrowed()

	/**
	 * Trashed rows go through the render boundary before they are served.
	 *
	 * @return void
	 */
	public function testListedRowsAreRedactedBeforeTheyAreServed(): void {
		$this->signIn(uid: 'burger', isAdmin: false);

		$object = new ObjectEntity();
		$object->setUuid('trashed-1');
		$object->setObject(['name' => 'visible', 'apiKey' => 'secret']);

		$this->objectMapper->method('findDeletedAcrossAllMagicTables')->willReturn([$object]);
		$this->objectMapper->method('countDeletedAcrossAllMagicTables')->willReturn(1);

		$this->renderObject->expects($this->once())
			->method('redactWriteOnlyFromRows')
			->willReturnCallback(
				static function (array &$rows, bool $_rbac = true): void {
					foreach ($rows as $row) {
						$data = $row->getObject();
						unset($data['apiKey']);
						$row->setObject($data);
					}
				}
			);

		$response = $this->controller->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertStringNotContainsString('secret', (string)json_encode($response->getData()));
	}//end testListedRowsAreRedactedBeforeTheyAreServed()

	/**
	 * The deleted count a non-admin reads is their own reach, not the instance's.
	 *
	 * @return void
	 */
	public function testNonAdminStatisticsCountOnlyTheCallersReadScope(): void {
		$this->signIn(uid: 'burger', isAdmin: false);
		[, $countArgs] = $this->captureScanArguments();

		$response = $this->controller->statistics();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([true, true], $countArgs->args, 'The deleted count must be the caller\'s own reach.');
	}//end testNonAdminStatisticsCountOnlyTheCallersReadScope()

	/**
	 * A destruction record is not served to a signed-in user it does not concern.
	 *
	 * @return void
	 */
	public function testNonAdminCannotReadSomeoneElsesDestructionRecord(): void {
		$this->signIn(uid: 'burger', isAdmin: false);

		$this->auditTrails->method('findForObjectByAction')->willReturn([$this->destructionRecord(actor: 'recordmanager')]);

		$response = $this->controller->destructionRecord('destroyed-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(0, $response->getData()['total']);
		$this->assertSame([], $response->getData()['results']);
	}//end testNonAdminCannotReadSomeoneElsesDestructionRecord()

	/**
	 * The record manager who destroyed an object reads the record afterwards (REQ-DWD-002).
	 *
	 * @return void
	 */
	public function testTheActorReadsTheirOwnDestructionRecord(): void {
		$this->signIn(uid: 'recordmanager', isAdmin: false);

		$this->auditTrails->method('findForObjectByAction')->willReturn([$this->destructionRecord(actor: 'recordmanager')]);

		$response = $this->controller->destructionRecord('destroyed-1');

		$this->assertSame(1, $response->getData()['total']);
	}//end testTheActorReadsTheirOwnDestructionRecord()

	/**
	 * An administrator reads every destruction record.
	 *
	 * @return void
	 */
	public function testAdminReadsEveryDestructionRecord(): void {
		$this->signIn(uid: 'admin', isAdmin: true);

		$this->auditTrails->method('findForObjectByAction')->willReturn([$this->destructionRecord(actor: 'recordmanager')]);

		$this->assertSame(1, $this->controller->destructionRecord('destroyed-1')->getData()['total']);
	}//end testAdminReadsEveryDestructionRecord()

	/**
	 * A preview of an object the caller may not read answers 404, not 500.
	 *
	 * @return void
	 */
	public function testPreviewOfAnUnreadableObjectIsNotFound(): void {
		$this->signIn(uid: 'burger', isAdmin: false);

		$this->objectMapper->method('find')->willThrowException(new DoesNotExistException('not found'));

		$this->assertSame(404, $this->controller->destructionPreview('trashed-1')->getStatus());
	}//end testPreviewOfAnUnreadableObjectIsNotFound()

	/**
	 * Record the arguments the two cross-table scans are called with.
	 *
	 * @return array{0: \stdClass, 1: \stdClass} The list and count call arguments, filled in when called.
	 */
	private function captureScanArguments(): array {
		$listArgs = new \stdClass();
		$listArgs->args = null;
		$countArgs = new \stdClass();
		$countArgs->args = null;

		$this->objectMapper->method('findDeletedAcrossAllMagicTables')->willReturnCallback(
			static function (...$args) use ($listArgs): array {
				$listArgs->args = $args;
				return [];
			}
		);
		$this->objectMapper->method('countDeletedAcrossAllMagicTables')->willReturnCallback(
			static function (...$args) use ($countArgs): int {
				$countArgs->args = $args;
				return 0;
			}
		);

		return [$listArgs, $countArgs];
	}//end captureScanArguments()

	/**
	 * A destruction record naming its actor.
	 *
	 * @param string $actor The user who destroyed the object.
	 *
	 * @return AuditTrail
	 */
	private function destructionRecord(string $actor): AuditTrail {
		$record = new AuditTrail();
		$record->setObjectUuid('destroyed-1');
		$record->setAction('object.destroyed');
		$record->setUser($actor);
		$record->setSchema(7);
		$record->setRegister(3);

		return $record;
	}//end destructionRecord()
}//end class
