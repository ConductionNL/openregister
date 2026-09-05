<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\BulkController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\BatchOperationStatus;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BulkControllerTest extends TestCase {
	private BulkController $controller;
	private IRequest&MockObject $request;
	private ObjectService&MockObject $objectService;
	private RegisterMapper&MockObject $registerMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new BulkController(
			'openregister',
			$this->request,
			$this->objectService,
			$this->registerMapper,
			$this->schemaMapper,
			$this->userSession,
			$this->groupManager
		);
	}

	/**
	 * Helper: stub the user session as an admin user so manage-permission gates pass.
	 */
	private function stubAdminUser(string $userId = 'admin'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($userId)->willReturn(true);
	}

	/**
	 * Helper: stub the schemaMapper to return a default schema entity so the
	 * manage-permission gate on deleteSchema/deleteSchemaObjects has something
	 * to inspect (admin bypass still does the work).
	 */
	private function stubSchemaLookup(?int $schemaId = null): void {
		$schema = new Schema();
		if ($schemaId !== null) {
			$ref = new \ReflectionClass($schema);
			$prop = $ref->getProperty('id');
			$prop->setAccessible(true);
			$prop->setValue($schema, $schemaId);
		}
		$this->schemaMapper->method('find')->willReturn($schema);
	}

	/**
	 * Helper: stub the registerMapper to return a default register entity so
	 * the manage-permission gate on deleteRegister has something to inspect.
	 */
	private function stubRegisterLookup(?int $registerId = null): void {
		$register = new Register();
		if ($registerId !== null) {
			$ref = new \ReflectionClass($register);
			$prop = $ref->getProperty('id');
			$prop->setAccessible(true);
			$prop->setValue($register, $registerId);
		}
		$this->registerMapper->method('find')->willReturn($register);
	}

	/**
	 * Helper to set up objectService for resolveRegisterSchemaIds success.
	 */
	private function setupResolveSuccess(int $registerId = 1, int $schemaId = 2): void {
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn($registerId);
		$this->objectService->method('getSchema')->willReturn($schemaId);
	}

	// ========================================================================
	// delete() tests
	// ========================================================================

	public function testDeleteMissingUuids(): void {
		$this->setupResolveSuccess();
		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->delete('1', '2');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('uuids', $data['error']);
	}

	public function testDeleteEmptyUuidsArray(): void {
		$this->setupResolveSuccess();
		$this->request->method('getParams')->willReturn(['uuids' => []]);

		$result = $this->controller->delete('1', '2');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
	}

	public function testDeleteUuidsNotArray(): void {
		$this->setupResolveSuccess();
		$this->request->method('getParams')->willReturn(['uuids' => 'not-an-array']);

		$result = $this->controller->delete('1', '2');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
	}

	/**
	 * A batch that refused a row does not answer `success: true`.
	 *
	 * This assertion used to read the other way round, and that is the shape the
	 * live defect wore: a 200 saying `success: true, requested_count: 1,
	 * deleted_count: 0` over an object still sitting in its table. A row this
	 * endpoint refused is a row the caller otherwise believes it deleted, so
	 * `success` reports the shortfall — the same rule the bulk save path states
	 * in writeBatch(). `testDeleteAllSuccessful()` below is the control: nothing
	 * skipped still answers true.
	 */
	public function testDeleteReportsTheShortfallWhenARowIsRefused(): void {
		$this->setupResolveSuccess();
		$this->objectService->method('deleteObjects')->willReturn([
			'deleted_uuids' => ['uuid1', 'uuid2'],
			'skipped_uuids' => ['uuid3'],
			'skipped_reasons' => ['uuid3' => ['error' => 'SCHEMA_ARCHIVAL_IMMUTABLE']],
			'cascade_count' => 0,
		]);

		$this->request->method('getParams')->willReturn([
			'uuids' => ['uuid1', 'uuid2', 'uuid3'],
		]);

		$result = $this->controller->delete('1', '2');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals(2, $data['deleted_count']);
		$this->assertEquals(['uuid1', 'uuid2'], $data['deleted_uuids']);
		$this->assertEquals(3, $data['requested_count']);
		$this->assertEquals(1, $data['skipped_count']);
		$this->assertEquals(['uuid3'], $data['skipped_uuids']);
		$this->assertEquals(0, $data['cascade_count']);
		$this->assertEquals(2, $data['total_affected']);
		$this->assertStringContainsString('1 of 3 objects refused', $data['message']);
		$this->assertSame(
			'SCHEMA_ARCHIVAL_IMMUTABLE',
			$data['skipped_reasons']['uuid3']['error'],
			'the caller is told WHY the row survived, not just that it did'
		);
	}

	public function testDeleteAllSuccessful(): void {
		$this->setupResolveSuccess();
		$this->objectService->method('deleteObjects')->willReturn([
			'deleted_uuids' => ['uuid1', 'uuid2'],
			'skipped_uuids' => [],
			'cascade_count' => 0,
		]);

		$this->request->method('getParams')->willReturn([
			'uuids' => ['uuid1', 'uuid2'],
		]);

		$result = $this->controller->delete('1', '2');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(0, $data['skipped_count']);
	}

	public function testDeleteException(): void {
		$this->setupResolveSuccess();
		$this->objectService->method('deleteObjects')
			->willThrowException(new \Exception('Delete error'));

		$this->request->method('getParams')->willReturn([
			'uuids' => ['uuid1'],
		]);

		$result = $this->controller->delete('1', '2');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Delete error', $data['error']);
		$this->assertStringContainsString('Bulk delete operation failed', $data['error']);
	}

	public function testDeleteRegisterNotFound(): void {
		$this->objectService->method('setRegister')
			->willThrowException(new DoesNotExistException('not found'));

		$this->request->method('getParams')->willReturn([
			'uuids' => ['uuid1'],
		]);

		$result = $this->controller->delete('nonexistent', '2');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Register not found', $data['error']);
	}

	public function testDeleteSchemaNotFound(): void {
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')
			->willThrowException(new DoesNotExistException('not found'));

		$this->request->method('getParams')->willReturn([
			'uuids' => ['uuid1'],
		]);

		$result = $this->controller->delete('1', 'nonexistent');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Schema not found', $data['error']);
	}

	// publish/depublish tests removed — endpoints deprecated per deprecate-published-metadata spec

	// ========================================================================
	// save() tests
	// ========================================================================

	public function testSaveSuccess(): void {
		// AUTHORIZATION (wave-11 WF1): caller must be admin or have manage-permission.
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->objectService->method('saveObjects')->willReturn([
			'statistics' => ['saved' => 2, 'updated' => 1],
		]);

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1'], ['name' => 'obj2']],
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(3, $data['saved_count']);
		$this->assertEquals(2, $data['requested_count']);
		$this->assertEquals('Bulk save operation completed successfully', $data['message']);
	}

	public function testSaveForbiddenWithoutManagePermission(): void {
		// AUTHORIZATION (wave-11 WF1): non-admin without manage-permission gets 403.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('regular-user');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);

		$schema = new \OCA\OpenRegister\Db\Schema();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->setupResolveSuccess();

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1']],
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_FORBIDDEN, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('permission', $data['error']);
	}

	public function testSaveMissingObjects(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('objects', $data['error']);
	}

	public function testSaveEmptyObjectsArray(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->request->method('getParams')->willReturn(['objects' => []]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
	}

	public function testSaveObjectsNotArray(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->request->method('getParams')->willReturn(['objects' => 'not-an-array']);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
	}

	public function testSaveRegisterNotFound(): void {
		$this->objectService->method('setRegister')
			->willThrowException(new DoesNotExistException('not found'));

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1']],
		]);

		$result = $this->controller->save('nonexistent', '2');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Register not found', $data['error']);
	}

	public function testSaveSchemaNotFound(): void {
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')
			->willThrowException(new DoesNotExistException('not found'));

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1']],
		]);

		$result = $this->controller->save('1', 'nonexistent');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Schema not found', $data['error']);
	}

	public function testSaveException(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->objectService->method('saveObjects')
			->willThrowException(new \Exception('Save error'));

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1']],
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Bulk save operation failed', $data['error']);
		$this->assertStringContainsString('Save error', $data['error']);
	}

	public function testSaveMixedSchemaMode(): void {
		// When schema resolves to 0, it means mixed-schema mode
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('getSchema')->willReturn(0);
		$this->objectService->expects($this->once())
			->method('saveObjects')
			->with(
				$this->equalTo([['name' => 'obj1']]),
				$this->equalTo(1),
				$this->isNull(),  // schema should be null for mixed mode
				$this->equalTo(true),
				$this->equalTo(true),
				$this->equalTo(true),
				$this->equalTo(false)
			)
			->willReturn([
				'statistics' => ['saved' => 1, 'updated' => 0],
			]);

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1']],
		]);

		$result = $this->controller->save('1', '0');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(1, $data['saved_count']);
	}

	public function testSaveWithStatisticsMissingSavedKey(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		// Return statistics without 'saved' key
		$this->objectService->method('saveObjects')->willReturn([
			'statistics' => ['updated' => 3],
		]);

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1']],
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(3, $data['saved_count']); // 0 + 3
	}

	public function testSaveWithStatisticsMissingUpdatedKey(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		// Return statistics without 'updated' key
		$this->objectService->method('saveObjects')->willReturn([
			'statistics' => ['saved' => 5],
		]);

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1']],
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(5, $data['saved_count']); // 5 + 0
	}

	public function testSaveWithEmptyStatistics(): void {
		// Issue #2778: statistics that account for NOTHING while the caller
		// submitted a row is a total loss, not a success. `saved_count` still
		// reports 0 (existing behaviour), but the response no longer calls it
		// a 200/success — it was previously asserted to be exactly that.
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->objectService->method('saveObjects')->willReturn([
			'statistics' => [],
		]);

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1']],
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(0, $data['saved_count']);
		$this->assertFalse($data['success']);
		$this->assertEquals(1, $data['failed_count']);
		$this->assertEquals('UnaccountedObject', $data['failures'][0]['type']);
	}

	// ========================================================================
	// save() partial-write reporting — issue #2778
	// ========================================================================

	/**
	 * The real defect, in the shape it was found in.
	 *
	 * A Jira export row carried `2025-08-15T16:42:42.922+0200` — an RFC-822
	 * offset, which fails JSON-Schema `date-time` (RFC3339 wants `+02:00`).
	 * The validator produced a precise message and the response threw it away,
	 * answering `success: true` with a smaller `saved_count`.
	 */
	public function testSaveRejectedObjectFailsTheResponseAndCarriesTheValidationMessage(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();

		$validationMessage = 'Schema validation failed: resolutiondate: The data must match the '
			. "'date-time' format (got \"2025-08-15T16:42:42.922+0200\")";

		$this->objectService->method('saveObjects')->willReturn([
			'statistics' => ['saved' => 1, 'updated' => 0, 'invalid' => 1, 'errors' => 1],
			'invalid' => [
				[
					'object' => [
						'@self' => ['id' => 'b2f0d0f1-0000-4000-8000-000000000002'],
						'resolutiondate' => '2025-08-15T16:42:42.922+0200',
					],
					'error' => $validationMessage,
					'index' => 1,
					'type' => 'BulkSafeguardException',
				],
			],
			'errors' => [
				['error' => $validationMessage, 'type' => 'BulkSafeguardException', 'index' => 1],
			],
		]);

		$this->request->method('getParams')->willReturn([
			'objects' => [
				['summary' => 'ok row'],
				['resolutiondate' => '2025-08-15T16:42:42.922+0200'],
			],
		]);

		$result = $this->controller->save('1', '2');

		// A partial write is not a success, and the status says so too.
		$this->assertEquals(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);

		// The counts still reconcile: 1 written, 1 rejected, 2 requested.
		$this->assertEquals(1, $data['saved_count']);
		$this->assertEquals(1, $data['failed_count']);
		$this->assertEquals(2, $data['requested_count']);

		// The rejected object names itself AND says why.
		$this->assertCount(1, $data['failures']);
		$failure = $data['failures'][0];
		$this->assertEquals(1, $failure['index']);
		$this->assertEquals('b2f0d0f1-0000-4000-8000-000000000002', $failure['uuid']);
		$this->assertStringContainsString('date-time', $failure['error']);
		$this->assertStringContainsString('2025-08-15T16:42:42.922+0200', $failure['error']);
		$this->assertStringContainsString('resolutiondate', $failure['error']);

		// And the message points at the list rather than claiming success.
		$this->assertStringContainsString('rejected', $data['message']);
	}

	/**
	 * Must-PASS control: the fix must not turn every batch into a failure.
	 */
	public function testSaveAllValidBatchStillReportsSuccess(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->objectService->method('saveObjects')->willReturn([
			'statistics' => ['saved' => 2, 'updated' => 1, 'invalid' => 0, 'errors' => 0],
			'invalid' => [],
			'errors' => [],
		]);

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1'], ['name' => 'obj2'], ['name' => 'obj3']],
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(3, $data['saved_count']);
		$this->assertEquals(3, $data['requested_count']);
		$this->assertEquals(0, $data['failed_count']);
		$this->assertSame([], $data['failures']);
		$this->assertEquals('Bulk save operation completed successfully', $data['message']);
	}

	/**
	 * An unchanged row is written work the caller already has — deduplication
	 * must not be mistaken for loss, or every re-import would report a failure.
	 */
	public function testSaveUnchangedObjectsDoNotCountAsLoss(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->objectService->method('saveObjects')->willReturn([
			'statistics' => ['saved' => 1, 'updated' => 0, 'unchanged' => 2],
			'invalid' => [],
			'errors' => [],
		]);

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1'], ['name' => 'obj2'], ['name' => 'obj3']],
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(0, $data['failed_count']);
		// saved_count keeps its existing meaning: created + updated only.
		$this->assertEquals(1, $data['saved_count']);
	}

	/**
	 * The migration batch, to scale: 31 of 58 stored and NOT ONE recorded
	 * reason. The shortfall must still be reported rather than rounded away
	 * because no per-object error happened to be captured.
	 */
	public function testSaveShortfallWithoutRecordedReasonsIsStillReported(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->objectService->method('saveObjects')->willReturn([
			'statistics' => ['saved' => 31, 'updated' => 0],
			'invalid' => [],
			'errors' => [],
		]);

		$this->request->method('getParams')->willReturn([
			'objects' => array_fill(0, 58, ['name' => 'issue']),
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals(31, $data['saved_count']);
		$this->assertEquals(58, $data['requested_count']);
		$this->assertEquals(27, $data['failed_count']);
		$this->assertCount(1, $data['failures']);
		$this->assertEquals('UnaccountedObject', $data['failures'][0]['type']);
		$this->assertStringContainsString('27 object(s) were not written', $data['failures'][0]['error']);
	}

	/**
	 * A batch-level error that belongs to no row is quoted into the synthetic
	 * failure, so the caller does not have to go to the server log for it.
	 */
	public function testSaveShortfallQuotesBatchLevelErrors(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->objectService->method('saveObjects')->willReturn([
			'statistics' => ['saved' => 0, 'updated' => 0],
			'invalid' => [],
			'errors' => [
				[
					'error' => 'No objects were successfully prepared for bulk save',
					'type' => 'NoObjectsPreparedException',
				],
			],
		]);

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1'], ['name' => 'obj2']],
		]);

		$result = $this->controller->save('1', '2');

		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals(2, $data['failed_count']);
		$this->assertStringContainsString(
			'No objects were successfully prepared',
			$data['failures'][0]['error']
		);
	}

	/**
	 * `partial: true` is the opt-in for best-effort semantics: it relaxes the
	 * HTTP status so a client library does not raise, and it does NOT relax
	 * `success` — that would rebuild the exact shape #2778 is about.
	 */
	public function testSavePartialOptInKeepsOkStatusButSuccessStaysFalse(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->objectService->method('saveObjects')->willReturn([
			'statistics' => ['saved' => 1, 'updated' => 0],
			'invalid' => [
				[
					'object' => ['uuid' => 'row-2'],
					'error' => 'Schema validation failed: due: format date-time',
					'index' => 1,
					'type' => 'BulkSafeguardException',
				],
			],
			'errors' => [],
		]);

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1'], ['name' => 'obj2']],
			'partial' => true,
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertTrue($data['partial']);
		$this->assertEquals(1, $data['failed_count']);
		$this->assertEquals('row-2', $data['failures'][0]['uuid']);
	}

	/**
	 * The streaming path already derived `success` from its failed count, but
	 * it reported the detail under its own key layout. It now answers with the
	 * same `failed_count` / `failures` contract as the default path.
	 */
	public function testSaveStreamingReportsPerRowFailures(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();

		$status = new BatchOperationStatus();
		$status->start();
		$status->recordCreated('created-uuid');
		$status->recordFailed(
			'failed-uuid',
			"resolutiondate: The data must match the 'date-time' format",
			\OCA\OpenRegister\Exception\ValidationException::class,
			1
		);
		$status->complete();

		$this->objectService->method('saveObjectsStreaming')->willReturn($status);

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1'], ['name' => 'obj2']],
			'stream' => true,
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals(1, $data['saved_count']);
		$this->assertEquals(1, $data['failed_count']);
		$this->assertEquals(2, $data['requested_count']);
		$this->assertEquals(1, $data['failures'][0]['index']);
		$this->assertEquals('failed-uuid', $data['failures'][0]['uuid']);
		$this->assertStringContainsString('date-time', $data['failures'][0]['error']);
	}

	/**
	 * Must-PASS control for the streaming path.
	 */
	public function testSaveStreamingAllValidStillReportsSuccess(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();

		$status = new BatchOperationStatus();
		$status->start();
		$status->recordCreated('uuid-1');
		$status->recordUpdated('uuid-2');
		$status->complete();

		$this->objectService->method('saveObjectsStreaming')->willReturn($status);

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1'], ['name' => 'obj2']],
			'stream' => true,
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(2, $data['saved_count']);
		$this->assertEquals(0, $data['failed_count']);
		$this->assertSame([], $data['failures']);
	}

	/**
	 * THE PAYLOAD A REAL SERVER RETURNS.
	 *
	 * Captured verbatim from a live OpenRegister instance running `development`,
	 * by replaying the Newman suite's own request
	 * (`Bulk Operations Tests / Bulk 1: Save Multiple Objects`): two objects
	 * submitted against a schema whose `name` property is required, both refused
	 * because the bulk path strips `name` out of the business payload (#2781).
	 *
	 * The instance answered HTTP 200, `success: true`, `saved_count: 0` — and
	 * both objects were lost. The exact shape #2778 is about, sitting inside the
	 * repo's own API suite, green.
	 *
	 * Two details here are what the hand-written mocks above got wrong, and are
	 * why this fixture exists: the real `invalid[]` entries come from
	 * `enforceChunkGuards()`, so they carry NO `index`, and their `object` is the
	 * TRANSFORMED row — uuid at the top level, business data nested under
	 * `object`. The failure list must still identify each object from that shape.
	 */
	public function testRealServerRejectionPayloadIsReportedAsAFailure(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();

		$missingName = 'The required property (name) is missing. Please provide a value for '
			. 'this property or set it to null if allowed.';

		$this->objectService->method('saveObjects')->willReturn([
			'saved' => [],
			'updated' => [],
			'unchanged' => [],
			'invalid' => [
				[
					'object' => [
						'register' => 4894,
						'schema' => 9478,
						'uuid' => '7aeab5c2-f323-4e2f-a5b8-8141a9b264d0',
						'id' => '7aeab5c2-f323-4e2f-a5b8-8141a9b264d0',
						'owner' => 'admin',
						'organisation' => '286a9152-4b09-4714-9115-fabbbad342d0',
						'name' => 'Updated Bulk 1',
						'relations' => [],
						'object' => ['age' => 26],
					],
					'error' => $missingName,
					'type' => 'ValidationException',
				],
				[
					'object' => [
						'register' => 4894,
						'schema' => 9478,
						'uuid' => 'f2350c89-ecaa-4f4c-adbf-0eba2e49ae3e',
						'id' => 'f2350c89-ecaa-4f4c-adbf-0eba2e49ae3e',
						'owner' => 'admin',
						'organisation' => '286a9152-4b09-4714-9115-fabbbad342d0',
						'name' => 'Updated Bulk 2',
						'relations' => [],
						'object' => ['age' => 31],
					],
					'error' => $missingName,
					'type' => 'ValidationException',
				],
			],
			'errors' => [],
			'statistics' => [
				'totalProcessed' => 2,
				'saved' => 0,
				'updated' => 0,
				'unchanged' => 0,
				'invalid' => 2,
				'errors' => 2,
				'processingTimeMs' => 0,
			],
		]);

		$this->request->method('getParams')->willReturn([
			'objects' => [
				['uuid' => 'u1', 'name' => 'Updated Bulk 1', 'age' => 26],
				['uuid' => 'u2', 'name' => 'Updated Bulk 2', 'age' => 31],
			],
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals(0, $data['saved_count']);
		$this->assertEquals(2, $data['failed_count']);
		$this->assertEquals(2, $data['requested_count']);

		// Both objects are named — by uuid, since a chunk-guard rejection carries
		// no index — and each carries the validator's own words.
		$this->assertCount(2, $data['failures']);
		$this->assertEquals('7aeab5c2-f323-4e2f-a5b8-8141a9b264d0', $data['failures'][0]['uuid']);
		$this->assertEquals('f2350c89-ecaa-4f4c-adbf-0eba2e49ae3e', $data['failures'][1]['uuid']);
		$this->assertNull($data['failures'][0]['index']);
		$this->assertEquals('ValidationException', $data['failures'][0]['type']);
		$this->assertStringContainsString('required property (name) is missing', $data['failures'][0]['error']);

		// No synthetic entry: every lost object is explained by a real rejection.
		$this->assertNotContains('UnaccountedObject', array_column($data['failures'], 'type'));
	}

	/**
	 * The same live instance, same endpoint, a batch with NO metadata-name
	 * collision: two updates addressed by `@self.id` came back `updated: 2` /
	 * `invalid: 0`. Pinned here as the real-payload must-PASS control — the
	 * accounting has to call that 200 and `success: true`, or the fix would be
	 * trading a silent loss for a false alarm on every ordinary bulk write.
	 */
	public function testRealServerSuccessPayloadStillReportsSuccess(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();

		$this->objectService->method('saveObjects')->willReturn([
			'saved' => [],
			'updated' => [['id' => 'a'], ['id' => 'b']],
			'unchanged' => [],
			'invalid' => [],
			'errors' => [],
			'statistics' => [
				'totalProcessed' => 2,
				'saved' => 0,
				'updated' => 2,
				'unchanged' => 0,
				'invalid' => 0,
				'errors' => 0,
				'processingTimeMs' => 0,
			],
		]);

		$this->request->method('getParams')->willReturn([
			'objects' => [
				['@self' => ['id' => 'a'], 'title' => 'T1 updated', 'age' => 26],
				['@self' => ['id' => 'b'], 'title' => 'T2 updated', 'age' => 31],
			],
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(2, $data['saved_count']);
		$this->assertEquals(2, $data['requested_count']);
		$this->assertEquals(0, $data['failed_count']);
		$this->assertSame([], $data['failures']);
	}

	/**
	 * A streaming batch that simply stops short — rows consumed but never
	 * classified — is a loss with no recorded reason, and must not read as a
	 * success either.
	 */
	public function testSaveStreamingSilentShortfallIsReported(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();

		$status = new BatchOperationStatus();
		$status->start();
		$status->recordCreated('uuid-1');
		$status->complete();

		$this->objectService->method('saveObjectsStreaming')->willReturn($status);

		$this->request->method('getParams')->willReturn([
			'objects' => [['name' => 'obj1'], ['name' => 'obj2'], ['name' => 'obj3']],
			'stream' => true,
		]);

		$result = $this->controller->save('1', '2');

		$this->assertEquals(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals(2, $data['failed_count']);
		$this->assertEquals('UnaccountedObject', $data['failures'][0]['type']);
	}

	// publishSchema() tests removed — deprecated per deprecate-published-metadata spec

	// ========================================================================
	// deleteSchema() tests
	// ========================================================================

	public function testDeleteSchemaInvalidId(): void {
		$result = $this->controller->deleteSchema('1', 'abc');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Invalid schema ID', $data['error']);
	}

	public function testDeleteSchemaUnauthorizedRejected(): void {
		// Wave-3 C5 regression guard: non-admin without manage-permission
		// cannot mass-delete schema objects.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
		$this->stubSchemaLookup(2);
		$this->objectService->expects($this->never())->method('deleteObjectsBySchema');

		$result = $this->controller->deleteSchema('1', '2');

		$this->assertEquals(Http::STATUS_FORBIDDEN, $result->getStatus());
	}

	public function testDeleteSchemaSuccess(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('deleteObjectsBySchema')->willReturn([
			'deleted_count' => 3,
			'deleted_uuids' => ['u1', 'u2', 'u3'],
			'schema_id' => 2,
		]);

		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->deleteSchema('1', '2');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(3, $data['deleted_count']);
		$this->assertEquals(['u1', 'u2', 'u3'], $data['deleted_uuids']);
		$this->assertEquals(2, $data['schema_id']);
		$this->assertFalse($data['hard_delete']);
		$this->assertEquals('Schema objects deletion completed successfully', $data['message']);
	}

	public function testDeleteSchemaWithHardDelete(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(3);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('deleteObjectsBySchema')->willReturn([
			'deleted_count' => 2,
			'deleted_uuids' => ['u1', 'u2'],
			'schema_id' => 3,
		]);

		$this->request->method('getParams')->willReturn(['hardDelete' => true]);

		$result = $this->controller->deleteSchema('1', '3');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['hard_delete']);
	}

	public function testDeleteSchemaException(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('deleteObjectsBySchema')
			->willThrowException(new \Exception('Schema delete error'));

		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->deleteSchema('1', '2');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Schema objects deletion failed', $data['error']);
		$this->assertStringContainsString('Schema delete error', $data['error']);
	}

	// ========================================================================
	// deleteSchemaObjects() tests
	// ========================================================================

	public function testDeleteSchemaObjectsUnauthorizedRejected(): void {
		// Wave-3 C5 regression guard.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
		$this->setupResolveSuccess();
		$this->stubSchemaLookup(2);
		$this->objectService->expects($this->never())->method('deleteObjectsBySchema');

		$result = $this->controller->deleteSchemaObjects('1', '2');

		$this->assertEquals(Http::STATUS_FORBIDDEN, $result->getStatus());
	}

	public function testDeleteSchemaObjectsSuccess(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->objectService->method('deleteObjectsBySchema')->willReturn([
			'deleted_count' => 4,
			'deleted_uuids' => ['u1', 'u2', 'u3', 'u4'],
			'schema_id' => 2,
		]);

		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->deleteSchemaObjects('1', '2');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(4, $data['deleted_count']);
		$this->assertEquals(['u1', 'u2', 'u3', 'u4'], $data['deleted_uuids']);
		$this->assertEquals(1, $data['register_id']);
		$this->assertEquals(2, $data['schema_id']);
		$this->assertFalse($data['hard_delete']);
		$this->assertEquals('Objects deletion completed successfully', $data['message']);
	}

	public function testDeleteSchemaObjectsWithHardDelete(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->objectService->method('deleteObjectsBySchema')->willReturn([
			'deleted_count' => 1,
			'deleted_uuids' => ['u1'],
			'schema_id' => 2,
		]);

		$this->request->method('getParams')->willReturn(['hardDelete' => true]);

		$result = $this->controller->deleteSchemaObjects('1', '2');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['hard_delete']);
	}

	public function testDeleteSchemaObjectsRegisterNotFound(): void {
		$this->objectService->method('setRegister')
			->willThrowException(new DoesNotExistException('not found'));

		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->deleteSchemaObjects('nonexistent', '2');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Register not found', $data['error']);
	}

	public function testDeleteSchemaObjectsSchemaNotFound(): void {
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')
			->willThrowException(new DoesNotExistException('not found'));

		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->deleteSchemaObjects('1', 'nonexistent');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Schema not found', $data['error']);
	}

	public function testDeleteSchemaObjectsException(): void {
		$this->stubAdminUser();
		$this->stubSchemaLookup(2);
		$this->setupResolveSuccess();
		$this->objectService->method('deleteObjectsBySchema')
			->willThrowException(new \Exception('Delete objects error'));

		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->deleteSchemaObjects('1', '2');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Objects deletion failed', $data['error']);
		$this->assertStringContainsString('Delete objects error', $data['error']);
	}

	// ========================================================================
	// deleteRegister() tests
	// ========================================================================

	public function testDeleteRegisterInvalidId(): void {
		$result = $this->controller->deleteRegister('abc');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Invalid register ID', $data['error']);
	}

	public function testDeleteRegisterUnauthorizedRejected(): void {
		// Wave-3 C5 regression guard.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
		$this->stubRegisterLookup(1);
		$this->objectService->expects($this->never())->method('deleteObjectsByRegister');

		$result = $this->controller->deleteRegister('1');

		$this->assertEquals(Http::STATUS_FORBIDDEN, $result->getStatus());
	}

	public function testDeleteRegisterSuccess(): void {
		$this->stubAdminUser();
		$this->stubRegisterLookup(1);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('deleteObjectsByRegister')->willReturn([
			'deleted_count' => 3,
			'deleted_uuids' => ['u1', 'u2', 'u3'],
			'register_id' => 1,
		]);

		$result = $this->controller->deleteRegister('1');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(3, $data['deleted_count']);
		$this->assertEquals(['u1', 'u2', 'u3'], $data['deleted_uuids']);
		$this->assertEquals(1, $data['register_id']);
		$this->assertEquals('Register objects deletion completed successfully', $data['message']);
	}

	public function testDeleteRegisterException(): void {
		$this->stubAdminUser();
		$this->stubRegisterLookup(1);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('deleteObjectsByRegister')
			->willThrowException(new \Exception('Register delete error'));

		$result = $this->controller->deleteRegister('1');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Register objects deletion failed', $data['error']);
		$this->assertStringContainsString('Register delete error', $data['error']);
	}

	// ========================================================================
	// runSchemaValidation() tests
	// ========================================================================

	public function testValidateSchemaInvalidId(): void {
		$result = $this->controller->runSchemaValidation('abc');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Invalid schema ID', $data['error']);
	}

	public function testValidateSchemaSuccess(): void {
		$validationResult = ['valid' => 10, 'invalid' => 2, 'errors' => []];
		$this->objectService->method('validateObjectsBySchema')
			->willReturn($validationResult);

		$result = $this->controller->runSchemaValidation('1');

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals(10, $data['valid']);
		$this->assertEquals(2, $data['invalid']);
	}

	public function testValidateSchemaException(): void {
		$this->objectService->method('validateObjectsBySchema')
			->willThrowException(new \Exception('Validation error'));

		$result = $this->controller->runSchemaValidation('1');

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
		$data = $result->getData();
		$this->assertStringContainsString('Schema validation failed', $data['error']);
		$this->assertStringContainsString('Validation error', $data['error']);
	}

	// ========================================================================
	// Additional edge case tests
	// ========================================================================

	public function testDeleteReturnsJsonResponse(): void {
		$this->setupResolveSuccess();
		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->delete('1', '2');

		$this->assertInstanceOf(JSONResponse::class, $result);
	}

	public function testSaveReturnsJsonResponse(): void {
		$this->setupResolveSuccess();
		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->save('1', '2');

		$this->assertInstanceOf(JSONResponse::class, $result);
	}
}
