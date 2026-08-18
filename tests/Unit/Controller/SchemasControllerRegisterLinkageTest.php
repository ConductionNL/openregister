<?php

/**
 * Unit tests for the register linkage established by POST /api/schemas?register=.
 *
 * Since register-scoped slug resolution landed, a register's `schemas` list is a
 * BOUNDARY: a slug it does not carry is refused with SchemaNotInRegisterException
 * and the request 404s. These tests pin the other half of that contract — that the
 * list is actually MAINTAINED by the endpoint that creates a schema inside a
 * register. Without it the boundary is enforced against a list nothing keeps up to
 * date, which is the state in which a write addressed by numeric id lands and the
 * matching slug read cannot find it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchemasControllerRegisterLinkageTest extends TestCase {

	private IRequest $request;

	private SchemaMapper $schemaMapper;

	private RegisterMapper $registerMapper;

	private SchemasController $controller;

	protected function setUp(): void {
		$this->request        = $this->createMock(IRequest::class);
		$this->schemaMapper   = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);

		$userSession = $this->createMock(\OCP\IUserSession::class);
		$user        = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(\OCP\IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['admin']);
		$groupManager->method('isAdmin')->willReturn(true);

		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function ($id) use ($userSession, $groupManager) {
				if ($id === \OCP\IUserSession::class) {
					return $userSession;
				}

				if ($id === \OCP\IGroupManager::class) {
					return $groupManager;
				}

				return null;
			}
		);

		$this->controller = new SchemasController(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(MagicMapper::class),
			$this->createMock(UploadService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(SchemaCacheHandler::class),
			$this->createMock(FacetCacheHandler::class),
			$this->createMock(SchemaService::class),
			$this->createMock(LoggerInterface::class),
			$container,
			$this->createMock(SchemaVersioningService::class)
		);
	}//end setUp()

	/**
	 * Build a persisted-looking schema.
	 *
	 * @param int $id The schema id.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWithId(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('person-schema');

		return $schema;
	}//end schemaWithId()

	/**
	 * Build a register carrying the given schema ids.
	 *
	 * @param int   $id        The register id.
	 * @param array $schemaIds The schema ids it already carries.
	 *
	 * @return Register The register.
	 */
	private function registerWith(int $id, array $schemaIds): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug('test-register');
		$register->setSchemas($schemaIds);

		return $register;
	}//end registerWith()

	/**
	 * A schema created with ?register= is recorded in that register's schemas list.
	 *
	 * This is the assertion that fails on the pre-fix controller: it created the
	 * schema, answered 201, and never touched the register — so every later
	 * register-scoped read of the new slug was refused.
	 *
	 * @return void
	 */
	public function testCreateWithRegisterParamRecordsTheLinkage(): void {
		$register = $this->registerWith(id: 15, schemaIds: [7]);

		$this->request->method('getParams')->willReturn(
			['title' => 'Person', 'register' => '15']
		);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('createFromArray')->willReturn($this->schemaWithId(id: 18));

		$saved = null;
		$this->registerMapper->expects($this->once())
			->method('update')
			->willReturnCallback(
				function (Register $entity) use (&$saved) {
					$saved = $entity;
					return $entity;
				}
			);

		$response = $this->controller->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertInstanceOf(Register::class, $saved);
		$this->assertSame([7, 18], $saved->getSchemas());
	}//end testCreateWithRegisterParamRecordsTheLinkage()

	/**
	 * The register identifier is consumed, never hydrated onto the schema.
	 *
	 * A schema row has no register column; the linkage lives on the register. If
	 * `register` reached createFromArray() the entity would silently swallow it and
	 * the caller would have no way to tell a recorded linkage from a dropped one.
	 *
	 * @return void
	 */
	public function testRegisterParamIsNotHydratedOntoTheSchema(): void {
		$register = $this->registerWith(id: 15, schemaIds: []);

		$this->request->method('getParams')->willReturn(
			['title' => 'Person', 'register' => '15']
		);
		$this->registerMapper->method('find')->willReturn($register);
		$this->registerMapper->method('update')->willReturnArgument(0);

		$seen = null;
		$this->schemaMapper->method('createFromArray')->willReturnCallback(
			function (array $object) use (&$seen) {
				$seen = $object;
				return $this->schemaWithId(id: 18);
			}
		);

		$this->controller->create();

		$this->assertIsArray($seen);
		$this->assertArrayNotHasKey('register', $seen);
	}//end testRegisterParamIsNotHydratedOntoTheSchema()

	/**
	 * Linking is idempotent — a register that already carries the id is not rewritten.
	 *
	 * @return void
	 */
	public function testAlreadyLinkedRegisterIsNotUpdated(): void {
		$register = $this->registerWith(id: 15, schemaIds: [18]);

		$this->request->method('getParams')->willReturn(
			['title' => 'Person', 'register' => '15']
		);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('createFromArray')->willReturn($this->schemaWithId(id: 18));

		$this->registerMapper->expects($this->never())->method('update');

		$this->assertSame(201, $this->controller->create()->getStatus());
	}//end testAlreadyLinkedRegisterIsNotUpdated()

	/**
	 * A register-less create is untouched: no lookup, no register write.
	 *
	 * @return void
	 */
	public function testCreateWithoutRegisterParamTouchesNoRegister(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Person']);
		$this->schemaMapper->method('createFromArray')->willReturn($this->schemaWithId(id: 18));

		$this->registerMapper->expects($this->never())->method('find');
		$this->registerMapper->expects($this->never())->method('update');

		$this->assertSame(201, $this->controller->create()->getStatus());
	}//end testCreateWithoutRegisterParamTouchesNoRegister()

	/**
	 * A register that does not resolve is refused BEFORE the schema is written.
	 *
	 * Creating a free-floating schema and answering 201 is what made the old
	 * behaviour invisible — the caller believed it had a schema in that register.
	 *
	 * @return void
	 */
	public function testUnresolvableRegisterRefusesTheCreate(): void {
		$this->request->method('getParams')->willReturn(
			['title' => 'Person', 'register' => 'no-such-register']
		);
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		$this->schemaMapper->expects($this->never())->method('createFromArray');

		$response = $this->controller->create();

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('no-such-register', $response->getData()['error']);
	}//end testUnresolvableRegisterRefusesTheCreate()

	/**
	 * A refused linkage does not turn a successful schema write into an error.
	 *
	 * RegisterMapper::update() enforces RBAC and the cross-tenant organisation
	 * check. When it refuses, the schema itself still exists, so answering 500
	 * would hide a successful write behind an error.
	 *
	 * @return void
	 */
	public function testRefusedLinkageStillReturns201(): void {
		$register = $this->registerWith(id: 15, schemaIds: []);

		$this->request->method('getParams')->willReturn(
			['title' => 'Person', 'register' => '15']
		);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('createFromArray')->willReturn($this->schemaWithId(id: 18));
		$this->registerMapper->method('update')
			->willThrowException(new \Exception('Cross-tenant access denied'));

		$this->assertSame(201, $this->controller->create()->getStatus());
	}//end testRefusedLinkageStillReturns201()
}//end class
