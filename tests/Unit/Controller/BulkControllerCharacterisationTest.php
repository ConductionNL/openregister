<?php

/**
 * Pins the BulkController outcomes the other bulk tests leave open.
 *
 * Written against the controller before its route actions were split into
 * small units, so the split can be shown to keep every refusal, status code
 * and message: the unique-constraint 422 on save, the not-found answers of the
 * id-only table deletes, the exact error prefixes of the generic failure
 * handlers, and the "a row" wording of an atomic rollback that names no index.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\OpenRegister\Controller\BulkController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\BulkControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of BulkController's remaining branches.
 */
class BulkControllerCharacterisationTest extends TestCase {

	private IRequest&MockObject $request;

	private ObjectService&MockObject $objectService;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private IDBConnection&MockObject $db;

	private BulkController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->db = $this->createMock(IDBConnection::class);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('getSchema')->willReturn(2);

		$this->controller = BulkControllerFactory::create(
			'openregister',
			$this->request,
			$this->objectService,
			$this->registerMapper,
			$this->schemaMapper,
			$this->userSession,
			$this->groupManager,
			$this->db
		);
	}

	/**
	 * Make the caller an administrator.
	 *
	 * @return void
	 */
	private function asAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
	}

	/**
	 * A schema with the given id.
	 *
	 * @param int $id The id.
	 *
	 * @return Schema
	 */
	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);

		return $schema;
	}

	public function testSaveMapsAUniqueConstraintViolationTo422(): void {
		$this->asAdmin();
		$this->schemaMapper->method('find')->willReturn($this->schema(2));
		$this->request->method('getParams')->willReturn(['objects' => [['name' => 'a']]]);
		$violation = $this->createMock(UniqueConstraintViolationException::class);
		$this->objectService->method('saveObjects')->willThrowException($violation);

		$response = $this->controller->save('1', '2');

		$this->assertSame(422, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('uuid_conflict', $data['error_code']);
		$this->assertSame(
			'One or more objects contain a UUID or unique field that conflicts with an existing record.',
			$data['error']
		);
		$this->assertArrayHasKey('detail', $data);
	}

	public function testSaveGenericFailureCarriesItsPrefix(): void {
		$this->asAdmin();
		$this->schemaMapper->method('find')->willReturn($this->schema(2));
		$this->request->method('getParams')->willReturn(['objects' => [['name' => 'a']]]);
		$this->objectService->method('saveObjects')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->save('1', '2');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Bulk save operation failed: boom'], $response->getData());
	}

	public function testAnAtomicRollbackWithoutAnIndexNamesARow(): void {
		$this->asAdmin();
		$this->schemaMapper->method('find')->willReturn($this->schema(2));
		$this->request->method('getParams')->willReturn(['objects' => [['name' => 'a']], 'atomic' => 'true']);
		$this->objectService->method('saveObjects')->willReturn(
			['statistics' => ['saved' => 0, 'invalid' => 1], 'invalid' => [['error' => 'bad']]]
		);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$response = $this->controller->save('1', '2');

		$this->assertSame(422, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['atomic']);
		$this->assertSame(0, $data['saved_count']);
		$this->assertSame(1, $data['requested_count']);
		$this->assertStringStartsWith('Atomic batch rolled back: ', $data['message']);
		$this->assertStringEndsWith('No row was written.', $data['message']);
	}

	public function testDeleteSchemaAnswers404ForAnUnknownSchema(): void {
		$this->asAdmin();
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('nope'));
		$this->objectService->expects($this->never())->method('deleteObjectsOfTable');

		$response = $this->controller->deleteSchema('1', '9');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Schema not found'], $response->getData());
	}

	public function testDeleteSchemaObjectsAnswers404ForAnUnknownSchema(): void {
		$this->asAdmin();
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('nope'));
		$this->objectService->expects($this->never())->method('deleteObjectsOfTable');

		$response = $this->controller->deleteSchemaObjects('1', '9');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Schema not found'], $response->getData());
	}

	public function testDeleteRegisterAnswers404ForAnUnknownRegister(): void {
		$this->asAdmin();
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('nope'));
		$this->objectService->expects($this->never())->method('deleteObjectsByRegister');

		$response = $this->controller->deleteRegister('7');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Register not found'], $response->getData());
	}

	public function testTheTableDeletesKeepTheirFailurePrefixes(): void {
		$this->asAdmin();
		$this->schemaMapper->method('find')->willReturn($this->schema(2));
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->request->method('getParams')->willReturn([]);
		$this->objectService->method('deleteObjectsOfTable')->willThrowException(new \RuntimeException('x'));
		$this->objectService->method('deleteObjectsByRegister')->willThrowException(new \RuntimeException('y'));

		$schema = $this->controller->deleteSchema('1', '2');
		$objects = $this->controller->deleteSchemaObjects('1', '2');
		$register = $this->controller->deleteRegister('1');

		$this->assertSame(500, $schema->getStatus());
		$this->assertSame(['error' => 'Schema objects deletion failed: x'], $schema->getData());
		$this->assertSame(500, $objects->getStatus());
		$this->assertSame(['error' => 'Objects deletion failed: x'], $objects->getData());
		$this->assertSame(500, $register->getStatus());
		$this->assertSame(['error' => 'Register objects deletion failed: y'], $register->getData());
	}

	public function testAnArchivalRefusalIsA403OnEveryTableDelete(): void {
		$this->asAdmin();
		$this->schemaMapper->method('find')->willReturn($this->schema(2));
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->request->method('getParams')->willReturn([]);
		$refusal = new ArchivalImmutableException('kept');
		$this->objectService->method('deleteObjectsOfTable')->willThrowException($refusal);
		$this->objectService->method('deleteObjectsByRegister')->willThrowException($refusal);

		$this->assertSame(403, $this->controller->deleteSchema('1', '2')->getStatus());
		$this->assertSame(403, $this->controller->deleteSchemaObjects('1', '2')->getStatus());
		$this->assertSame(403, $this->controller->deleteRegister('1')->getStatus());
	}

	public function testANonAdministratorIsRefusedBeforeAnyLookup(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->schemaMapper->expects($this->never())->method('find');
		$this->registerMapper->expects($this->never())->method('find');

		$this->assertSame(403, $this->controller->deleteSchema('1', '2')->getStatus());
		$this->assertSame(403, $this->controller->deleteSchemaObjects('1', '2')->getStatus());
		$this->assertSame(403, $this->controller->deleteRegister('1')->getStatus());
	}
}
