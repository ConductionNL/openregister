<?php

/**
 * Unit tests for register-scoped schema resolution on GET /api/schemas/{id}.
 *
 * Naming a register via `?register=` makes it a BOUNDARY: the {id} route
 * parameter (numeric id, uuid, or slug) resolves only among the schemas that
 * register carries, an identifier the register does not carry is refused with a
 * diagnosis instead of falling back to global resolution, and a register that
 * does not resolve is itself a 404 — never a silent widening back to the whole
 * instance. Measured on the shared dev instance 2026-08-21: three schemas
 * carried the slug `timeEntry` and hrmq's form dialog was served another app's
 * schema (id 161 instead of hrmq's 9466), which is exactly the read these tests
 * pin as refused. A caller that names no register keeps global resolution
 * byte-identical (the control case).
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

class SchemasControllerShowRegisterScopeTest extends TestCase {

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
	 * Stub the two getParam() reads show() performs.
	 *
	 * @param string|null $register The `?register=` value, or null for absent.
	 *
	 * @return void
	 */
	private function withRegisterParam(?string $register): void {
		$this->request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) use ($register) {
				if ($key === 'register') {
					return $register;
				}

				return $default;
			}
		);
	}//end withRegisterParam()

	/**
	 * Build a persisted-looking schema.
	 *
	 * @param int    $id   The schema id.
	 * @param string $slug The schema slug.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWithId(int $id, string $slug = 'timeEntry'): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle('TimeEntry');

		return $schema;
	}//end schemaWithId()

	/**
	 * Build a register carrying the given schema ids.
	 *
	 * @param int   $id        The register id.
	 * @param array $schemaIds The schema ids it carries.
	 *
	 * @return Register The register.
	 */
	private function registerWith(int $id, array $schemaIds): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug('hrmq');
		$register->setSchemas($schemaIds);

		return $register;
	}//end registerWith()

	/**
	 * Scoped hit: a slug resolves among the named register's schemas only.
	 *
	 * The global resolver must never run — on the live instance it is the call
	 * that returned another app's id-161 schema for hrmq's `timeEntry`.
	 *
	 * @return void
	 */
	public function testScopedSlugResolvesWithinTheNamedRegisterOnly(): void {
		$this->withRegisterParam('hrmq');
		$this->registerMapper->method('find')->willReturn($this->registerWith(id: 12, schemaIds: [9466, 9467]));

		$this->schemaMapper->expects($this->once())
			->method('findInIds')
			->with('timeEntry', [9466, 9467])
			->willReturn($this->schemaWithId(id: 9466));
		$this->schemaMapper->expects($this->never())->method('find');
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);

		$response = $this->controller->show('timeEntry');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(9466, $response->getData()['id']);
	}//end testScopedSlugResolvesWithinTheNamedRegisterOnly()

	/**
	 * Scoped miss: a slug carried elsewhere on the instance but not by the named
	 * register is refused with the boundary diagnosis, not resolved globally.
	 *
	 * @return void
	 */
	public function testSlugCarriedElsewhereButNotByTheRegisterIsRefused(): void {
		$this->withRegisterParam('hrmq');
		$this->registerMapper->method('find')->willReturn($this->registerWith(id: 12, schemaIds: [7, 8]));

		$this->schemaMapper->method('findInIds')->willReturn(null);
		$this->schemaMapper->method('countBySlug')->willReturn(3);
		$this->schemaMapper->expects($this->never())->method('find');

		$response = $this->controller->show('timeEntry');

		$this->assertSame(404, $response->getStatus());
		$error = $response->getData()['error'];
		$this->assertStringContainsString('is not carried by register "hrmq" (id 12)', $error);
		$this->assertStringContainsString('3 schema(s) elsewhere', $error);
		$this->assertStringContainsString('naming a register makes it a boundary', $error);
		$this->assertStringContainsString('occ openregister:registers:relink-schemas', $error);
	}//end testSlugCarriedElsewhereButNotByTheRegisterIsRefused()

	/**
	 * An unknown register is a 404 naming the register — never a silent fallback
	 * to global resolution, which would serve a schema from outside the boundary
	 * the caller explicitly named.
	 *
	 * @return void
	 */
	public function testUnknownRegisterIsRefusedInsteadOfFallingBackGlobally(): void {
		$this->withRegisterParam('no-such-register');
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('nope'));

		$this->schemaMapper->expects($this->never())->method('find');
		$this->schemaMapper->expects($this->never())->method('findInIds');

		$response = $this->controller->show('timeEntry');

		$this->assertSame(404, $response->getStatus());
		$error = $response->getData()['error'];
		$this->assertStringContainsString("Register not found: 'no-such-register'", $error);
		$this->assertStringContainsString('naming a register makes it a boundary', $error);
	}//end testUnknownRegisterIsRefusedInsteadOfFallingBackGlobally()

	/**
	 * Control: a caller that names no register keeps global resolution.
	 *
	 * This is the compatibility half of the contract — old clients that never
	 * send `?register=` observe the exact pre-change behaviour.
	 *
	 * @return void
	 */
	public function testNoRegisterParamKeepsGlobalResolution(): void {
		$this->withRegisterParam(null);

		$this->schemaMapper->expects($this->once())
			->method('find')
			->willReturn($this->schemaWithId(id: 161));
		$this->schemaMapper->expects($this->never())->method('findInIds');
		$this->schemaMapper->method('findAll')->willReturn([]);
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);
		$this->registerMapper->expects($this->never())->method('find');

		$response = $this->controller->show('timeEntry');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(161, $response->getData()['id']);
	}//end testNoRegisterParamKeepsGlobalResolution()

	/**
	 * A numeric id combined with `?register=` resolves within the register.
	 *
	 * @return void
	 */
	public function testNumericIdResolvesWithinTheRegister(): void {
		$this->withRegisterParam('hrmq');
		$this->registerMapper->method('find')->willReturn($this->registerWith(id: 12, schemaIds: [9466]));

		$this->schemaMapper->expects($this->once())
			->method('findInIds')
			->with('9466', [9466])
			->willReturn($this->schemaWithId(id: 9466));
		$this->schemaMapper->expects($this->never())->method('find');
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);

		$response = $this->controller->show('9466');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(9466, $response->getData()['id']);
	}//end testNumericIdResolvesWithinTheRegister()

	/**
	 * A numeric id the register does not LIST still resolves.
	 *
	 * The boundary is about ambiguity: a slug can name a different schema in
	 * every register, a numeric id cannot. Refusing an unlisted id protects
	 * nothing and punishes a caller whose register carries a stale `schemas`
	 * array — which is precisely how it 404'd object writes addressed by id.
	 *
	 * @return void
	 */
	public function testNumericIdResolvesEvenWhenTheRegisterDoesNotListIt(): void {
		$this->withRegisterParam('hrmq');
		$this->registerMapper->method('find')->willReturn($this->registerWith(id: 12, schemaIds: [9466]));

		$this->schemaMapper->method('findInIds')->willReturn(null);
		$this->schemaMapper->method('countBySlug')->willReturn(0);
		$this->schemaMapper->expects($this->once())
			->method('find')
			->willReturn($this->schemaWithId(id: 161, slug: 'persoon'));

		$response = $this->controller->show('161');

		$this->assertSame(200, $response->getStatus());
	}//end testNumericIdResolvesEvenWhenTheRegisterDoesNotListIt()
}//end class
