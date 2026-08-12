<?php

/**
 * Admin LIST/search reads MUST NOT return writeOnly secrets (openregister#460).
 *
 * These tests drive the REAL controller list path with a REAL RenderObject and a REAL
 * PropertyRbacHandler, and assert on the serialized JSONResponse — deliberately.
 *
 * openregister#389 established that writeOnly is a hard render-boundary rule that strips
 * unconditionally, admins included, and hardened `RenderObject::renderEntity()`. Its
 * sibling `redactWriteOnlyFromRows()` — the cheap list/search path — kept the pre-#389
 * bypass for another two releases. The reason nobody noticed: every existing test either
 * exercised the helper directly (passing `_rbac: true`, the one value that did strip), or
 * mocked RenderObject out of the controller entirely. Both styles are blind to the actual
 * defect, which only exists in the composition: `ObjectsController` derives
 * `$rbac = ($isAdmin === false)`, so an ADMIN list arrives at the helper with
 * `_rbac: false` and got every secret in cleartext.
 *
 * So these tests assert the property that actually matters — "the bytes an admin receives
 * contain no secret" — through the same call the HTTP router makes. A mutation that
 * restores the early-return in `redactWriteOnlyFromRows()` MUST fail here.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 */
class ObjectsControllerWriteOnlyListLeakTest extends TestCase {
	private const SECRET_TOP = 'SECRET_APIKEY_MUST_NOT_LEAK';
	private const SECRET_NESTED = 'SECRET_CLIENT_SECRET_MUST_NOT_LEAK';

	private IRequest&MockObject $request;
	private IAppConfig&MockObject $config;
	private IAppManager&MockObject $appManager;
	private ContainerInterface&MockObject $container;
	private RegisterMapper&MockObject $registerMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private AuditTrailMapper&MockObject $auditTrailMapper;
	private ObjectService&MockObject $objectService;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * A real `source` Schema with a top-level writeOnly property and a declared nested
	 * write-only dot-path (openregister#459).
	 *
	 * @return Schema
	 */
	private function sourceSchema(): Schema {
		$schema = new Schema();
		$ref = new ReflectionClass($schema);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($schema, 213);

		$schema->setSlug('source');
		$schema->setProperties(
			[
				'name' => ['type' => 'string'],
				'apiKey' => ['type' => 'string', 'writeOnly' => true],
				'configuration' => ['type' => 'object'],
			]
		);
		$schema->setConfiguration(
			['x-openregister-writeonly-paths' => ['configuration.authentication.client_secret']]
		);

		return $schema;
	}

	/**
	 * A source row carrying a top-level secret, a nested secret, and the `@self.relations`
	 * mirror copies of both (openregister#429).
	 *
	 * @return ObjectEntity
	 */
	private function sourceEntity(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('src-1');
		$entity->setRegister('1');
		$entity->setSchema('213');
		$entity->setObject(
			[
				'name' => 'BRP HaalCentraal',
				'apiKey' => self::SECRET_TOP,
				'configuration' => [
					'endpoint' => 'https://api.example.gov',
					'authentication' => [
						'client_id' => 'public-client-id',
						'client_secret' => self::SECRET_NESTED,
					],
				],
			]
		);
		$entity->setRelations(
			[
				'apiKey' => self::SECRET_TOP,
				'configuration.authentication.client_secret' => self::SECRET_NESTED,
				'name' => 'BRP HaalCentraal',
			]
		);

		return $entity;
	}

	/**
	 * A REAL RenderObject wired to resolve our source schema, with a REAL
	 * PropertyRbacHandler. writeOnly stripping is a pure function of the schema's flags, so
	 * no session/groups wiring is needed for the behaviour under test.
	 *
	 * @return RenderObject
	 */
	private function realRenderObject(): RenderObject {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($this->sourceSchema());

		$propertyRbacHandler = new PropertyRbacHandler(
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class),
			$this->createMock(LoggerInterface::class)
		);

		return new RenderObject(
			$this->createMock(\OCA\OpenRegister\Db\FileMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(RegisterMapper::class),
			$schemaMapper,
			$this->createMock(\OCP\SystemTag\ISystemTagManager::class),
			$this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class),
			$propertyRbacHandler,
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\OpenRegister\Service\FileService::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\LinkedEntityEnricher::class),
			$this->createMock(\OCA\OpenRegister\Service\Calculation\CalculationEvaluator::class),
			$this->createMock(\OCA\OpenRegister\Service\UrnService::class),
			$this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
			$this->createMock(\OCA\OpenRegister\Db\TranslationMapper::class),
			$this->createMock(\OCA\OpenRegister\Service\LanguageService::class)
		);
	}

	/**
	 * Drive ObjectsController::index() as the given user on the magic-mapper cheap list
	 * path, with a REAL RenderObject in the container, and return the JSON response data.
	 *
	 * @param bool $asAdmin Whether the caller is an admin (=> the controller derives
	 *                      `_rbac: false`, which is the #460 trigger).
	 *
	 * @return array The JSONResponse payload.
	 */
	private function listAs(bool $asAdmin): array {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($asAdmin === true ? ['admin'] : ['users']);

		$register = $this->getMockBuilder(Register::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize', 'isMagicMappingEnabledForSchema', 'getConfiguration'])
			->addMethods(['getId', 'getSlug'])
			->getMock();
		$register->method('getId')->willReturn(1);
		$register->method('getSlug')->willReturn('integrations');
		$register->method('jsonSerialize')->willReturn(['id' => 1, 'slug' => 'integrations']);
		$register->method('isMagicMappingEnabledForSchema')->willReturn(true);
		$register->method('getConfiguration')->willReturn(
			['enableMagicMapping' => true, 'magicMappingSchemas' => ['213', 'source']]
		);

		$schemaEntity = $this->getMockBuilder(Schema::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->addMethods(['getId', 'getSlug'])
			->getMock();
		$schemaEntity->method('getId')->willReturn(213);
		$schemaEntity->method('getSlug')->willReturn('source');
		$schemaEntity->method('jsonSerialize')->willReturn(['id' => 213, 'slug' => 'source']);

		$this->objectService->method('getCurrentRegisterEntity')->willReturn($register);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schemaEntity);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($schemaEntity);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('getSchema')->willReturn(213);
		// No _extend/_fields/_filter/_unset => the CHEAP path, which is the #460 path.
		$this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20, '_offset' => 0]);

		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getRequestUri')->willReturn('/api/objects/1/213');

		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([$this->sourceEntity()]);
		$magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(1);
		$magicMapper->method('getIgnoredFilters')->willReturn([]);
		\OC::$server->registerService(MagicMapper::class, fn () => $magicMapper);

		// The REAL RenderObject — the whole point of this test. Mocking it here is what
		// let #460 hide behind a green suite.
		$renderObject = $this->realRenderObject();
		\OC::$server->registerService(RenderObject::class, fn () => $renderObject);

		$controller = new ObjectsController(
			'openregister',
			$this->request,
			$this->config,
			$this->appManager,
			$this->container,
			$this->registerMapper,
			$this->schemaMapper,
			$this->auditTrailMapper,
			$this->objectService,
			$this->userSession,
			$this->groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->logger
		);

		$response = $controller->index('1', '213', $this->objectService);
		$this->assertSame(200, $response->getStatus());

		return $response->getData();
	}

	/**
	 * THE load-bearing test: an ADMIN list response contains no writeOnly secret.
	 *
	 * The admin is the case that leaked, because admin => `_rbac: false` => the old
	 * early-return. Asserting on the encoded response (not just the array keys) also
	 * catches a secret surfacing anywhere else in the payload — e.g. `@self.relations`.
	 *
	 * @return void
	 */
	public function testAdminListResponseDoesNotContainWriteOnlySecret(): void {
		$data = $this->listAs(asAdmin: true);

		$encoded = json_encode($data);
		$this->assertStringNotContainsString(
			self::SECRET_TOP,
			$encoded,
			'An admin LIST response leaked a writeOnly secret in cleartext (openregister#460).'
		);

		$row = $data['results'][0];
		$body = ($row instanceof ObjectEntity) === true ? $row->jsonSerialize() : $row;
		$this->assertArrayNotHasKey('apiKey', $body['object'] ?? $body);

		// Non-secret data still comes back — the strip is a scalpel, not a blanket.
		$this->assertStringContainsString('BRP HaalCentraal', $encoded);
	}

	/**
	 * The same for a NESTED writeOnly dot-path (openregister#459) on the list path: the
	 * nested mechanism exists because a secret inside an untyped `object` has no property
	 * to hang `writeOnly: true` on — it is not a weaker rule, so it rides the same boundary.
	 *
	 * @return void
	 */
	public function testAdminListResponseDoesNotContainNestedWriteOnlyPath(): void {
		$data = $this->listAs(asAdmin: true);
		$encoded = json_encode($data);

		$this->assertStringNotContainsString(
			self::SECRET_NESTED,
			$encoded,
			'An admin LIST response leaked a NESTED writeOnly path in cleartext (#459/#460).'
		);

		// The non-secret siblings under the same parent survive: the strip removes the
		// declared path and its sub-tree, never the whole parent object.
		$row = $data['results'][0];
		$body = ($row instanceof ObjectEntity) === true ? $row->jsonSerialize() : $row;
		$body = ($body['object'] ?? $body);

		$this->assertSame('https://api.example.gov', $body['configuration']['endpoint']);
		$this->assertSame('public-client-id', $body['configuration']['authentication']['client_id']);
		$this->assertArrayNotHasKey('client_secret', $body['configuration']['authentication']);
	}

	/**
	 * The `@self.relations` search-index mirror (openregister#429) does not leak on the
	 * list path either — it is a separate copy of the value and needs its own strip.
	 *
	 * @return void
	 */
	public function testAdminListResponseRelationsMirrorDoesNotLeak(): void {
		$data = $this->listAs(asAdmin: true);
		$encoded = json_encode($data);

		$this->assertStringNotContainsString('MUST_NOT_LEAK', $encoded);

		$row = $data['results'][0];
		$serialized = ($row instanceof ObjectEntity) === true ? $row->jsonSerialize() : $row;
		$relations = $serialized['@self']['relations'] ?? ($serialized['relations'] ?? []);
		$this->assertArrayNotHasKey('apiKey', $relations);
		$this->assertArrayNotHasKey('configuration.authentication.client_secret', $relations);
	}

	/**
	 * A NON-admin list response must not leak either. This case already passed before #460
	 * (non-admin => `_rbac: true` => the old code stripped); it is pinned so a future
	 * refactor cannot fix the admin case by breaking this one.
	 *
	 * @return void
	 */
	public function testNonAdminListResponseDoesNotContainWriteOnlySecret(): void {
		$encoded = json_encode($this->listAs(asAdmin: false));

		$this->assertStringNotContainsString(self::SECRET_TOP, $encoded);
		$this->assertStringNotContainsString(self::SECRET_NESTED, $encoded);
	}
}
