<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Tests\Unit\Controller\Support\SchemasControllerFactory;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Files\Service\UploadService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception as DBException;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/*
 * Unit tests for SchemasController
 *
 * @package Unit\Controller
 */

class SchemasControllerTest extends TestCase {

	private const PLAIN_RULE = ['group' => 'aanbod-beheerder', 'match' => ['provider' => '$organisation']];

	private SchemasController $controller;

	private IRequest&MockObject $request;

	private IAppConfig&MockObject $config;

	private SchemaMapper&MockObject $schemaMapper;

	private MagicMapper&MockObject $objectMapper;
	private RegisterMapper&MockObject $registerMapper;

	private UploadService&MockObject $uploadService;

	private AuditTrailMapper&MockObject $auditTrailMapper;

	private OrganisationService&MockObject $organisationService;

	private SchemaCacheHandler&MockObject $schemaCacheService;

	private FacetResultCacheClearer&MockObject $facetCacheSvc;

	private SchemaService&MockObject $schemaService;

	private LoggerInterface&MockObject $logger;

	private SchemaVersioningService&MockObject $schemaVersioningService;

	private \Psr\Container\ContainerInterface&MockObject $container;

	private ?\OCA\OpenRegister\Service\Form\FormDependentsCheck $formCheck = null;

	private \OCA\OpenRegister\Service\SchemaDeletionService&MockObject $schemaDeletionService;

	/**
	 * The user the mocked session resolves to. SchemasController resolves the
	 * session + group manager lazily via the container, so the container's
	 * get() returns mocks driven by this property. Defaults to an admin so
	 * write and authenticated-read tests pass; anonymous-read tests set it null.
	 */
	private ?\OCP\IUser $currentUser = null;

	/**
	 * Whether the signed-in user of the mocked session is an administrator.
	 */
	private bool $currentUserIsAdmin = true;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->uploadService = $this->createMock(UploadService::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->schemaCacheService = $this->createMock(SchemaCacheHandler::class);
		$this->facetCacheSvc = $this->createMock(FacetResultCacheClearer::class);
		$this->schemaService = $this->createMock(SchemaService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->schemaVersioningService = $this->createMock(SchemaVersioningService::class);

		// SchemasController resolves IUserSession + IGroupManager lazily via the
		// container (isCurrentUserAdmin / checkSchemaManagePermission / the
		// read-visibility guard). Default to an authenticated admin; anonymous-read
		// tests set $this->currentUser = null.
		$this->currentUser = $this->createMock(\OCP\IUser::class);
		$this->currentUser->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(\OCP\IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(fn () => $this->currentUser);
		$groupManager = $this->createMock(\OCP\IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn () => $this->currentUser !== null && $this->currentUserIsAdmin === true);
		$groupManager->method('getUserGroupIds')->willReturn(['admin']);

		// destroy() also resolves SchemaDeletionService lazily (cascade + reclaim of an
		// empty magic table on the zero-object path).
		$this->schemaDeletionService = $this->createMock(
			\OCA\OpenRegister\Service\SchemaDeletionService::class
		);
		$schemaDeletionService = $this->schemaDeletionService;

		$this->container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function ($id) use ($userSession, $groupManager, $schemaDeletionService) {
				if ($id === \OCP\IUserSession::class) {
					return $userSession;
				}

				if ($id === \OCP\IGroupManager::class) {
					return $groupManager;
				}

				if ($id === \OCA\OpenRegister\Service\SchemaTeardownInterface::class) {
					return $schemaDeletionService;
				}

				if ($id === \OCA\OpenRegister\Service\Form\FormDependentsCheckInterface::class) {
					return $this->formCheck;
				}

				return null;
			}
		);

		$this->controller = SchemasControllerFactory::create(
			'openregister',
			$this->request,
			$this->config,
			$this->schemaMapper,
			// Slot 5. `$registerMapper` was inserted HERE rather than appended,
			// which shifted every later argument: without it the MagicMapper mock
			// lands on the RegisterMapper parameter and the TypeError names the
			// production constructor, not this line.
			$this->registerMapper,
			$this->objectMapper,
			$this->uploadService,
			$this->auditTrailMapper,
			$this->organisationService,
			$this->schemaCacheService,
			$this->facetCacheSvc,
			$this->schemaService,
			$this->logger,
			$this->container,
			$this->schemaVersioningService
		);
	}//end setUp()

	public function testIndexReturnsSchemas(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);

		$result = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(200, $result->getStatus());
	}//end testIndexReturnsSchemas()

	public function testIndexWithPagination(): void {
		$this->request->method('getParams')->willReturn(
			[
				'_limit' => '5',
				'_offset' => '10',
			]
		);
		$this->schemaMapper->method('findAll')->willReturn([]);

		$result = $this->controller->index();

		$this->assertSame(200, $result->getStatus());
	}//end testIndexWithPagination()

	public function testShowReturnsSchema(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

		$this->request->method('getParam')->willReturn([]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$result = $this->controller->show(1);

		$this->assertSame(200, $result->getStatus());
	}//end testShowReturnsSchema()

	public function testShowAnonymousUnpublishedSchemaReturns401(): void {
		// Anonymous (no user) + schema without public read authorization → 401 (RBAC visibility guard).
		// Use a real Schema entity so getAuthorization() (a __call magic) works without mocking.
		$this->currentUser = null;
		$schema = $this->createRealSchema(1, 'Private');
		// No authorization set → getAuthorization() returns null → isPublicReadable = false.

		$this->request->method('getParam')->willReturn([]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$result = $this->controller->show(1);

		$this->assertSame(401, $result->getStatus());
	}//end testShowAnonymousUnpublishedSchemaReturns401()

	public function testShowAnonymousPublishedSchemaReturns200(): void {
		// Anonymous + schema with public read authorization → 200.
		// Use a real Schema entity so getAuthorization() (a __call magic) works without mocking.
		$this->currentUser = null;
		$schema = $this->createRealSchema(1, 'Public');
		$schema->setAuthorization(['read' => ['public']]);
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);

		$this->request->method('getParam')->willReturn([]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$result = $this->controller->show(1);

		$this->assertSame(200, $result->getStatus());
	}//end testShowAnonymousPublishedSchemaReturns200()

	public function testShowAnonymousSchemaWithAMarkedPublicRuleReturns200(): void {
		// `{"group": "public"}` without match reads like `"public"`, also when marked.
		$this->currentUser = null;
		$schema = $this->createRealSchema(1, 'Public');
		$schema->setAuthorization(['read' => [['group' => 'public', 'crossOrganisation' => true]]]);
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);

		$this->request->method('getParam')->willReturn([]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->assertSame(200, $this->controller->show(1)->getStatus());
	}//end testShowAnonymousSchemaWithAMarkedPublicRuleReturns200()

	public function testShowAnonymousSchemaWithOnlyAConditionalPublicRuleReturns401(): void {
		$this->currentUser = null;
		$schema = $this->createRealSchema(1, 'Conditional');
		$schema->setAuthorization(['read' => [['group' => 'public', 'match' => ['status' => 'open'], 'crossOrganisation' => true]]]);

		$this->request->method('getParam')->willReturn([]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->assertSame(401, $this->controller->show(1)->getStatus());
	}//end testShowAnonymousSchemaWithOnlyAConditionalPublicRuleReturns401()

	public function testIndexAnonymousFiltersUnpublishedSchemas(): void {
		// Anonymous list returns only schemas whose authorization grants public read.
		// Use real Schema entities so getAuthorization() (a __call magic) works without mocking.
		$this->currentUser = null;
		$published = $this->createRealSchema(1, 'Published');
		$published->setAuthorization(['read' => ['public']]);

		$unpublished = $this->createRealSchema(2, 'Unpublished');
		// No authorization set → getAuthorization() returns null → isPublicReadable = false.

		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->method('findAll')->willReturn([$published, $unpublished]);
		$this->schemaMapper->method('findAllExtendedBy')->willReturn([]);

		$result = $this->controller->index();
		$data = $result->getData();

		$this->assertSame(200, $result->getStatus());
		$ids = array_map(fn ($s) => $s['id'], $data['results']);
		$this->assertContains(1, $ids);
		$this->assertNotContains(2, $ids);
	}//end testIndexAnonymousFiltersUnpublishedSchemas()

	public function testCreateReturnsCreatedSchema(): void {
		$schema = $this->createRealSchema(1, 'New Schema');

		$this->request->method('getParams')->willReturn(['title' => 'New Schema']);
		$this->schemaMapper->method('createFromArray')->willReturn($schema);

		$result = $this->controller->create();

		$this->assertSame(201, $result->getStatus());
	}//end testCreateReturnsCreatedSchema()

	public function testCreateRemovesInternalParams(): void {
		$schema = $this->createRealSchema(1, 'Test');

		$this->request->method('getParams')->willReturn(
			[
				'_route' => 'test',
				'id' => 5,
				'title' => 'Test',
			]
		);
		$this->schemaMapper->expects($this->once())
			->method('createFromArray')
			->with(
				$this->callback(
					function ($data) {
						return !isset($data['_route']) && !isset($data['id']) && isset($data['title']);
					}
				)
			)
			->willReturn($schema);

		$this->controller->create();
	}//end testCreateRemovesInternalParams()

	public function testCreateReturns500OnException(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Test']);
		$this->schemaMapper->method('createFromArray')
			->willThrowException(new Exception('DB error'));

		$result = $this->controller->create();

		$this->assertSame(500, $result->getStatus());
	}//end testCreateReturns500OnException()

	private function createRealSchema(int $id = 1, string $title = 'Test'): Schema {
		$schema = new Schema();
		$ref = new \ReflectionClass($schema);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($schema, $id);
		$schema->setTitle($title);
		return $schema;
	}//end createRealSchema()

	/**
	 * A schema manager who is not an administrator may not let a rule cross organisations.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function testAManagerWhoIsNotAnAdminCannotAddACrossOrganisationMarker(): void {
		$this->currentUserIsAdmin = false;
		$schema = $this->managedSchema(read: [self::PLAIN_RULE]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->request->method('getParams')->willReturn(['authorization' => ['manage' => ['admin'], 'read' => [self::PLAIN_RULE + ['crossOrganisation' => true]]]]);
		$this->schemaMapper->expects($this->never())->method('updateFromArray');

		$result = $this->controller->update(1);

		$this->assertSame(403, $result->getStatus());
		$this->assertStringContainsString('crossOrganisation', (string)json_encode($result->getData()));
	}//end testAManagerWhoIsNotAnAdminCannotAddACrossOrganisationMarker()

	/**
	 * A schema manager may change the other rules and keep a marker an administrator set.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function testAManagerMayChangeOtherRulesAndKeepAMarker(): void {
		$this->currentUserIsAdmin = false;
		$marked = self::PLAIN_RULE + ['crossOrganisation' => true];
		$schema = $this->managedSchema(read: [$marked]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->request->method('getParams')->willReturn(['authorization' => ['manage' => ['admin'], 'read' => ['hr', $marked]]]);
		$this->schemaMapper->method('updateFromArray')->willReturn($schema);

		$this->assertSame(200, $this->controller->update(1)->getStatus());
	}//end testAManagerMayChangeOtherRulesAndKeepAMarker()

	/**
	 * An administrator may add the marker.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function testAnAdministratorMayAddACrossOrganisationMarker(): void {
		$schema = $this->managedSchema(read: [self::PLAIN_RULE]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->request->method('getParams')->willReturn(['authorization' => ['manage' => ['admin'], 'read' => [self::PLAIN_RULE + ['crossOrganisation' => true]]]]);
		$this->schemaMapper->method('updateFromArray')->willReturn($schema);

		$this->assertSame(200, $this->controller->update(1)->getStatus());
	}//end testAnAdministratorMayAddACrossOrganisationMarker()

	/**
	 * An upload over an existing schema is held to the same rule.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function testAnUploadByAManagerWhoIsNotAnAdminCannotAddTheMarker(): void {
		$this->currentUserIsAdmin = false;
		$schema = $this->managedSchema(read: [self::PLAIN_RULE]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->request->method('getParams')->willReturn([]);
		$this->uploadService->method('getUploadedJson')->willReturn(
			['title' => 'Existing', 'authorization' => ['manage' => ['admin'], 'read' => [['group' => 'public', 'crossOrganisation' => true]]]]
		);
		$this->schemaMapper->expects($this->never())->method('update');

		$this->assertSame(403, $this->controller->uploadUpdate(1)->getStatus());
	}//end testAnUploadByAManagerWhoIsNotAnAdminCannotAddTheMarker()

	/**
	 * A marker sent as JSON text is judged as the block the entity would store.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function testAMarkerSentAsJsonTextIsRefused(): void {
		$this->currentUserIsAdmin = false;
		$schema = $this->managedSchema(read: [self::PLAIN_RULE]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->request->method('getParams')->willReturn(
			['authorization' => (string)json_encode(['manage' => ['admin'], 'read' => [self::PLAIN_RULE + ['crossOrganisation' => true]]])]
		);
		$this->schemaMapper->expects($this->never())->method('updateFromArray');

		$this->assertSame(403, $this->controller->update(1)->getStatus());
	}//end testAMarkerSentAsJsonTextIsRefused()

	/**
	 * An external grant sent as JSON text meets the same refusal as the array form.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function testAnExternalGrantSentAsJsonTextIsRefused(): void {
		$schema = $this->managedSchema(read: [self::PLAIN_RULE]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->request->method('getParams')->willReturn(
			['authorization' => (string)json_encode(['manage' => ['admin'], 'read' => [['group' => 'ghost', 'external' => true]]])]
		);
		$this->schemaMapper->expects($this->never())->method('updateFromArray');

		$this->assertSame(400, $this->controller->update(1)->getStatus());
	}//end testAnExternalGrantSentAsJsonTextIsRefused()

	/**
	 * Publishing a draft that holds a marker as JSON text is refused, and the draft stays.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function testPublishingADraftWithAMarkerAsJsonTextIsRefused(): void {
		$this->currentUserIsAdmin = false;
		$schema = $this->managedSchema(read: [self::PLAIN_RULE]);
		$schema->setDraft(['authorization' => (string)json_encode(['manage' => ['admin'], 'read' => [self::PLAIN_RULE + ['crossOrganisation' => true]]])]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->expects($this->never())->method('updateFromArray');

		$this->assertSame(403, $this->controller->publishDraft(1)->getStatus());
		$this->assertNotNull($schema->getDraft());
	}//end testPublishingADraftWithAMarkerAsJsonTextIsRefused()

	/**
	 * Other spellings of a guarded key reach nothing through an upload.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function testAnUploadCannotStoreAGuardedFieldUnderAnotherSpelling(): void {
		$this->currentUserIsAdmin = false;
		$schema = $this->managedSchema(read: [self::PLAIN_RULE]);
		$schema->setOrganisation('own-org');
		$schema->setOwner('alice');
		$authorization = $schema->getAuthorization();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->request->method('getParams')->willReturn([]);
		$this->uploadService->method('getUploadedJson')->willReturn(
			[
				'title' => 'Existing',
				'Authorization' => ['read' => [['group' => 'public', 'crossOrganisation' => true]]],
				'Organisation' => 'other-org',
				'Owner' => 'mallory',
			]
		);
		$this->schemaMapper->method('update')->willReturnArgument(0);

		$this->assertSame(200, $this->controller->uploadUpdate(1)->getStatus());
		$this->assertSame($authorization, $schema->getAuthorization());
		$this->assertSame('own-org', $schema->getOrganisation());
		$this->assertSame('alice', $schema->getOwner());
	}//end testAnUploadCannotStoreAGuardedFieldUnderAnotherSpelling()

	/**
	 * An upload over an existing schema cannot move it, whichever user sends it.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function testAnUploadCannotMoveASchemaToAnotherOrganisationOrOwner(): void {
		$schema = $this->managedSchema(read: [self::PLAIN_RULE]);
		$schema->setOrganisation('own-org');
		$schema->setOwner('alice');
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->request->method('getParams')->willReturn([]);
		$this->uploadService->method('getUploadedJson')->willReturn(
			['title' => 'Existing', 'id' => 99, 'organisation' => 'other-org', 'owner' => 'mallory', 'created' => '2000-01-01T00:00:00+00:00']
		);
		$this->schemaMapper->method('update')->willReturnArgument(0);

		$this->assertSame(200, $this->controller->uploadUpdate(1)->getStatus());
		$this->assertSame(1, $schema->getId());
		$this->assertSame('own-org', $schema->getOrganisation());
		$this->assertSame('alice', $schema->getOwner());
		$this->assertNotSame('2000-01-01', $schema->getCreated()?->format('Y-m-d'));
	}//end testAnUploadCannotMoveASchemaToAnotherOrganisationOrOwner()

	/**
	 * A new schema uploaded by an administrator keeps the organisation the document names.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function testANewUploadKeepsItsOrganisation(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->uploadService->method('getUploadedJson')->willReturn(['title' => 'New', 'organisation' => 'named-org']);
		$saved = null;
		$this->schemaMapper->method('insert')->willReturnCallback(
			function (Schema $schema) use (&$saved): Schema {
				$schema->setId(7);
				$saved = $schema;

				return $schema;
			}
		);

		$this->assertSame(200, $this->controller->upload()->getStatus());
		$this->assertSame('named-org', $saved?->getOrganisation());
	}//end testANewUploadKeepsItsOrganisation()

	/**
	 * An upload meets the external-grant refusal too.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function testAnUploadWithAnExternalGrantIsRefused(): void {
		$schema = $this->managedSchema(read: [self::PLAIN_RULE]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->request->method('getParams')->willReturn([]);
		$this->uploadService->method('getUploadedJson')->willReturn(
			['title' => 'Existing', 'authorization' => ['read' => [['group' => 'ghost', 'external' => true]]]]
		);
		$this->schemaMapper->expects($this->never())->method('update');

		$this->assertSame(400, $this->controller->uploadUpdate(1)->getStatus());
	}//end testAnUploadWithAnExternalGrantIsRefused()

	/**
	 * An upload meets the external-grant refusal in the JSON text form too.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function testAnUploadWithAnExternalGrantAsJsonTextIsRefused(): void {
		$schema = $this->managedSchema(read: [self::PLAIN_RULE]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->request->method('getParams')->willReturn([]);
		$this->uploadService->method('getUploadedJson')->willReturn(
			['title' => 'Existing', 'authorization' => (string)json_encode(['read' => [['group' => 'ghost', 'external' => true]]])]
		);
		$this->schemaMapper->expects($this->never())->method('update');

		$this->assertSame(400, $this->controller->uploadUpdate(1)->getStatus());
	}//end testAnUploadWithAnExternalGrantAsJsonTextIsRefused()

	/**
	 * A schema whose `manage` rule names the signed-in user's group.
	 *
	 * @param array<int, mixed> $read The read rules.
	 *
	 * @return Schema
	 */
	private function managedSchema(array $read): Schema {
		$schema = $this->createRealSchema(1, 'Existing');
		$schema->setAuthorization(['manage' => ['admin'], 'read' => $read]);

		return $schema;
	}//end managedSchema()

	public function testUpdateReturnsUpdatedSchema(): void {
		$schema = $this->createRealSchema(1, 'Updated');

		$this->request->method('getParams')->willReturn(['title' => 'Updated']);
		$this->schemaMapper->method('updateFromArray')->willReturn($schema);

		$result = $this->controller->update(1);

		$this->assertSame(200, $result->getStatus());
	}//end testUpdateReturnsUpdatedSchema()

	/**
	 * Decision 179: an owning app set to refuse blocks a schema change that breaks its published form.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function testUpdateIsRefusedWhenAnOwningAppRefusesTheBreak(): void {
		$schema = $this->createRealSchema(1, 'Ticket');
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->request->method('getParams')->willReturn(['required' => ['title', 'priority']]);
		$this->schemaVersioningService->method('classify')->willReturn(new \OCA\OpenRegister\Service\Schema\SchemaChangeSet([], 'none', 'none'));
		$assessment = ['refused' => true, 'schema' => 'ticket', 'affected' => [['id' => 'form-a']]];
		$check = $this->createMock(\OCA\OpenRegister\Service\Form\FormDependentsCheck::class);
		$check->expects($this->once())->method('assess')
			->with($this->callback(static fn (Schema $proposed): bool => $proposed->getRequired() === ['title', 'priority'] && $proposed !== $schema))
			->willReturn($assessment);
		$check->method('apply')->willReturn([['id' => 'form-a', 'outcome' => 'refused']]);
		$this->formCheck = $check;
		$this->schemaMapper->expects($this->never())->method('updateFromArray');

		$result = $this->controller->update(1);

		$this->assertSame(409, $result->getStatus());
		$this->assertSame([['id' => 'form-a', 'outcome' => 'refused']], $result->getData()['affectedForms']);
	}//end testUpdateIsRefusedWhenAnOwningAppRefusesTheBreak()

	/**
	 * Decision 179: a save that breaks a published form goes ahead, and the response lists the unpublished form.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function testUpdateListsTheFormsItUnpublished(): void {
		$schema = $this->createRealSchema(1, 'Ticket');
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->method('updateFromArray')->willReturn($schema);
		$this->request->method('getParams')->willReturn(['required' => ['title', 'priority']]);
		$this->schemaVersioningService->method('classify')->willReturn(new \OCA\OpenRegister\Service\Schema\SchemaChangeSet([], 'none', 'none'));
		$check = $this->createMock(\OCA\OpenRegister\Service\Form\FormDependentsCheck::class);
		$check->method('assess')->willReturn(['refused' => false, 'schema' => 'ticket', 'affected' => [['id' => 'form-a']]]);
		$check->expects($this->once())->method('apply')->willReturn([['id' => 'form-a', 'outcome' => 'unpublished']]);
		$this->formCheck = $check;

		$result = $this->controller->update(1);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame('Ticket', $result->getData()['title']);
		$this->assertSame([['id' => 'form-a', 'outcome' => 'unpublished']], $result->getData()['affectedForms']);
	}//end testUpdateListsTheFormsItUnpublished()

	/**
	 * A dependent-form check that cannot be built is logged, not read as "no forms affected".
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function testAFailingDependentFormCheckIsLogged(): void {
		$schema = $this->createRealSchema(1, 'Ticket');
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->method('updateFromArray')->willReturn($schema);
		$this->request->method('getParams')->willReturn(['required' => ['title', 'priority']]);
		$this->schemaVersioningService->method('classify')->willReturn(new \OCA\OpenRegister\Service\Schema\SchemaChangeSet([], 'none', 'none'));
		$this->formCheck = null;
		$this->logger->expects($this->atLeastOnce())->method('warning')
			->with($this->stringContains('dependent-form check could not be built'), $this->anything());

		$result = $this->controller->update(1);

		$this->assertSame(200, $result->getStatus());
		$this->assertArrayNotHasKey('affectedForms', $result->getData() instanceof Schema ? [] : (array)$result->getData());
	}//end testAFailingDependentFormCheckIsLogged()

	/**
	 * Sign in a schema manager who is no administrator, for a schema with the given flag.
	 *
	 * @param array<string, mixed> $configuration The stored configuration.
	 *
	 * @return Schema
	 */
	private function asNonAdminManager(array $configuration = []): Schema {
		$schema = $this->createRealSchema(1, 'Ticket');
		$schema->setAuthorization(['manage' => ['admin']]);
		$schema->setConfiguration($configuration);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->method('updateFromArray')->willReturn($schema);
		$this->schemaMapper->method('update')->willReturn($schema);
		$this->currentUserIsAdmin = false;

		return $schema;
	}//end asNonAdminManager()

	/**
	 * The ways a body can set the flag.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function orphanCleanupBodies(): array {
		return [
			'array' => [['orphanCleanup' => true]],
			'json text' => ['{"orphanCleanup":true}'],
		];
	}//end orphanCleanupBodies()

	/**
	 * @dataProvider orphanCleanupBodies
	 *
	 * @param mixed $configuration The body's configuration.
	 */
	public function testANonAdminManagerMayNotSetOrphanCleanupOnUpdate(mixed $configuration): void {
		$this->asNonAdminManager();
		$this->request->method('getParams')->willReturn(['configuration' => $configuration]);
		$this->schemaMapper->expects($this->never())->method('updateFromArray');

		$this->assertSame(403, $this->controller->update(1)->getStatus());
	}

	public function testANonAdminManagerMayNotSetOrphanCleanupOnPatch(): void {
		$this->asNonAdminManager();
		$this->request->method('getParams')->willReturn(['configuration' => ['orphanCleanup' => true]]);
		$this->schemaMapper->expects($this->never())->method('updateFromArray');

		$this->assertSame(403, $this->controller->patch(1)->getStatus());
	}

	public function testANonAdminManagerMayNotSetOrphanCleanupOnUpload(): void {
		$this->asNonAdminManager();
		$this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Ticket', 'configuration' => ['orphanCleanup' => true]]);
		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->expects($this->never())->method('update');

		$this->assertSame(403, $this->controller->uploadUpdate(1)->getStatus());
	}

	public function testANonAdminManagerMayNotClearOrphanCleanupExplicitly(): void {
		$this->asNonAdminManager(configuration: ['orphanCleanup' => true]);
		$this->request->method('getParams')->willReturn(['configuration' => ['orphanCleanup' => false]]);

		$this->assertSame(403, $this->controller->update(1)->getStatus());
	}

	public function testANonAdminManagerMaySendTheStoredValueBack(): void {
		$this->asNonAdminManager(configuration: ['orphanCleanup' => true]);
		$this->request->method('getParams')->willReturn(['configuration' => ['orphanCleanup' => true, 'allowFiles' => true]]);

		$this->assertSame(200, $this->controller->update(1)->getStatus());
	}

	public function testANonAdminManagerMayUpdateWithoutTheFlag(): void {
		$this->asNonAdminManager();
		$this->request->method('getParams')->willReturn(['configuration' => ['allowFiles' => true]]);

		$this->assertSame(200, $this->controller->update(1)->getStatus());
	}

	public function testAnAdministratorMaySetOrphanCleanup(): void {
		$schema = $this->createRealSchema(1, 'Ticket');
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->method('updateFromArray')->willReturn($schema);
		$this->request->method('getParams')->willReturn(['configuration' => ['orphanCleanup' => true]]);

		$this->assertSame(200, $this->controller->update(1)->getStatus());
	}

	public function testPatchDelegatesToUpdate(): void {
		$schema = $this->createRealSchema(1, 'Patched');

		$this->request->method('getParams')->willReturn(['title' => 'Patched']);
		$this->schemaMapper->method('updateFromArray')->willReturn($schema);

		$result = $this->controller->patch(1);

		$this->assertSame(200, $result->getStatus());
	}//end testPatchDelegatesToUpdate()

	public function testDestroyReturnsEmptyOnSuccess(): void {
		$schema = $this->createRealSchema(1, 'Test');
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectMapper->method('getStatistics')->willReturn(['total' => 0]);

		$result = $this->controller->destroy(1);

		$this->assertSame(200, $result->getStatus());
	}//end testDestroyReturnsEmptyOnSuccess()

	public function testDestroyReturns500WhenNotFound(): void {
		// SchemasController::destroy() catches \Exception (not DoesNotExistException
		// specifically), so DoesNotExistException results in 500 not 404.
		$this->schemaMapper->method('find')
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->controller->destroy(999);

		$this->assertSame(500, $result->getStatus());
	}//end testDestroyReturns500WhenNotFound()

	public function testDownloadReturnsSchema(): void {
		$schema = $this->createRealSchema(1, 'Downloadable');
		$this->schemaMapper->method('find')->willReturn($schema);

		$result = $this->controller->download(1);

		$this->assertSame(200, $result->getStatus());
	}//end testDownloadReturnsSchema()

	public function testDownloadReturns404WhenNotFound(): void {
		$this->schemaMapper->method('find')
			->willThrowException(new Exception('Not found'));

		$result = $this->controller->download(999);

		$this->assertSame(404, $result->getStatus());
		$this->assertSame('Schema not found', $result->getData()['error']);
	}//end testDownloadReturns404WhenNotFound()

	public function testRelatedReturnsRelationships(): void {
		$schema1 = $this->createRealSchema(1, 'Schema A');
		$schema1->setProperties(['field1' => ['type' => 'string']]);

		$this->schemaMapper->method('getRelated')->willReturn([]);
		$this->schemaMapper->method('find')->willReturn($schema1);
		$this->schemaMapper->method('findAll')->willReturn([$schema1]);
		$this->schemaMapper->method('hasReferenceToSchema')->willReturn(false);

		$result = $this->controller->related(1);

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('incoming', $data);
		$this->assertArrayHasKey('outgoing', $data);
		$this->assertArrayHasKey('total', $data);
	}//end testRelatedReturnsRelationships()

	public function testRelatedReturns404WhenSchemaNotFound(): void {
		$this->schemaMapper->method('getRelated')
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->controller->related(999);

		$this->assertSame(404, $result->getStatus());
	}//end testRelatedReturns404WhenSchemaNotFound()

	public function testRelatedReturns500OnGenericException(): void {
		$this->schemaMapper->method('getRelated')
			->willThrowException(new Exception('DB error'));

		$result = $this->controller->related(1);

		$this->assertSame(500, $result->getStatus());
	}//end testRelatedReturns500OnGenericException()

	public function testStatsReturnsSchemaStatistics(): void {
		$schema = $this->createRealSchema(1, 'Stats Schema');
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->objectMapper->method('getStatistics')->willReturn(
			[
				'total' => 50,
				'invalid' => 3,
				'deleted' => 5,
				'published' => 42,
				'locked' => 1,
				'size' => 10000,
			]
		);

		$this->auditTrailMapper->method('getStatistics')->willReturn(
			[
				'total' => 100,
			]
		);

		$this->schemaMapper->method('getRegisterCountPerSchema')->willReturn(
			[
				1 => 3,
			]
		);

		$result = $this->controller->stats(1);

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertSame(50, $data['objectCount']);
		$this->assertArrayHasKey('objects', $data);
		$this->assertArrayHasKey('logs', $data);
	}//end testStatsReturnsSchemaStatistics()

	public function testStatsReturns404WhenSchemaNotFound(): void {
		$this->schemaMapper->method('find')
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->controller->stats(999);

		$this->assertSame(404, $result->getStatus());
	}//end testStatsReturns404WhenSchemaNotFound()

	public function testExploreReturnsExplorationResults(): void {
		$this->schemaService->method('exploreSchemaProperties')->willReturn(
			[
				'newProperties' => ['field1' => ['type' => 'string']],
				'objectsScanned' => 100,
			]
		);

		$result = $this->controller->explore(1);

		$this->assertSame(200, $result->getStatus());
	}//end testExploreReturnsExplorationResults()

	public function testExploreReturns500OnException(): void {
		$this->schemaService->method('exploreSchemaProperties')
			->willThrowException(new Exception('Explore failed'));

		$result = $this->controller->explore(1);

		$this->assertSame(500, $result->getStatus());
	}//end testExploreReturns500OnException()

	public function testUpdateFromExplorationReturns400WhenNoProperties(): void {
		$this->request->method('getParam')->willReturn([]);

		$result = $this->controller->updateFromExploration(1);

		$this->assertSame(400, $result->getStatus());
	}//end testUpdateFromExplorationReturns400WhenNoProperties()

	public function testUpdateFromExplorationSuccess(): void {
		$this->request->method('getParam')
			->willReturnMap(
				[
					['properties', [], ['field1' => ['type' => 'string']]],
				]
			);

		$updatedSchema = $this->createRealSchema(1, 'Updated');
		$this->schemaService->method('updateSchemaFromExploration')->willReturn($updatedSchema);
		// clearSchemaCache() returns void, no need to mock return value
		$result = $this->controller->updateFromExploration(1);

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
	}//end testUpdateFromExplorationSuccess()

	public function testUpdateFromExplorationReturns500OnException(): void {
		$this->request->method('getParam')
			->willReturnMap(
				[
					['properties', [], ['field1' => ['type' => 'string']]],
				]
			);

		$this->schemaService->method('updateSchemaFromExploration')
			->willThrowException(new Exception('Update error'));

		$result = $this->controller->updateFromExploration(1);

		$this->assertSame(500, $result->getStatus());
	}//end testUpdateFromExplorationReturns500OnException()

	// NOTE: publish()/depublish() endpoint tests removed — the
	// SchemasController publish/depublish endpoints were retired for
	// security (commit 29b1de3af, H2). Publication state is now derived
	// server-side from the schema's published/depublished timestamps;
	// there is no controller method to drive directly.

	public function testUpdateRemovesImmutableFields(): void {
		$schema = $this->createRealSchema(1, 'Updated');

		$this->request->method('getParams')->willReturn(
			[
				'id' => 1,
				'organisation' => 'org1',
				'owner' => 'user1',
				'created' => '2024-01-01',
				'title' => 'Updated',
			]
		);
		$this->schemaMapper->expects($this->once())
			->method('updateFromArray')
			->with(
				$this->equalTo(1),
				$this->callback(
					function ($data) {
						return !isset($data['id'])
						&& !isset($data['organisation'])
						&& !isset($data['owner'])
						&& !isset($data['created'])
						&& isset($data['title']);
					}
				)
			)
			->willReturn($schema);

		$this->controller->update(1);
	}//end testUpdateRemovesImmutableFields()

	public function testUpdateReturns500OnException(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Updated']);
		$this->schemaMapper->method('updateFromArray')
			->willThrowException(new Exception('DB error'));

		$result = $this->controller->update(1);

		$this->assertSame(500, $result->getStatus());
	}//end testUpdateReturns500OnException()

	// ── index() branch coverage ──
	public function testIndexWithPageBasedPagination(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

		$this->request->method('getParams')->willReturn(
			[
				'_limit' => '10',
				'_page' => '3',
			]
		);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		$this->schemaMapper->method('findAllExtendedBy')->willReturn([]);

		$result = $this->controller->index();

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('results', $data);
	}//end testIndexWithPageBasedPagination()

	public function testIndexWithExtendStats(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

		$this->request->method('getParams')->willReturn(
			[
				'_extend' => '@self.stats',
			]
		);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		$this->schemaMapper->method('findAllExtendedBy')->willReturn([]);
		$this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([1 => 2]);
		$this->objectMapper->method('getStatisticsGroupedBySchema')->willReturn(
			[
				1 => ['total' => 10, 'size' => 500, 'invalid' => 1, 'deleted' => 0, 'locked' => 0, 'published' => 9],
			]
		);
		$this->auditTrailMapper->method('getStatisticsGroupedBySchema')->willReturn(
			[
				1 => ['total' => 20, 'size' => 100],
			]
		);

		$result = $this->controller->index();

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('stats', $data['results'][0]);
		$this->assertSame(10, $data['results'][0]['stats']['objects']['total']);
		$this->assertSame(2, $data['results'][0]['stats']['registers']);
	}//end testIndexWithExtendStats()

	public function testIndexWithExtendStatsDefaultsForMissingSchema(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('jsonSerialize')->willReturn(['id' => 99, 'title' => 'Orphan']);

		$this->request->method('getParams')->willReturn(
			[
				'_extend' => ['@self.stats'],
			]
		);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		$this->schemaMapper->method('findAllExtendedBy')->willReturn([]);
		$this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([]);
		$this->objectMapper->method('getStatisticsGroupedBySchema')->willReturn([]);
		$this->auditTrailMapper->method('getStatisticsGroupedBySchema')->willReturn([]);

		$result = $this->controller->index();

		$this->assertSame(200, $result->getStatus());
		$stats = $result->getData()['results'][0]['stats'];
		$this->assertSame(0, $stats['objects']['total']);
		$this->assertSame(0, $stats['registers']);
	}//end testIndexWithExtendStatsDefaultsForMissingSchema()

	public function testIndexWithFilters(): void {
		$this->request->method('getParams')->willReturn(
			[
				'filters' => ['title' => 'Test'],
			]
		);
		$this->schemaMapper->method('findAll')->willReturn([]);
		$this->schemaMapper->method('findAllExtendedBy')->willReturn([]);

		$result = $this->controller->index();

		$this->assertSame(200, $result->getStatus());
	}//end testIndexWithFilters()

	public function testIndexExtendedByPopulated(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Base']);

		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		$this->schemaMapper->method('findAllExtendedBy')->willReturn(
			[
				1 => ['uuid-child-1', 'uuid-child-2'],
			]
		);

		$result = $this->controller->index();

		$data = $result->getData();
		$this->assertSame(['uuid-child-1', 'uuid-child-2'], $data['results'][0]['@self']['extendedBy']);
	}//end testIndexExtendedByPopulated()

	// ── show() branch coverage ──
	public function testShowReturns404OnDoesNotExistException(): void {
		$this->request->method('getParam')->willReturn([]);
		$this->schemaMapper->method('find')
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->controller->show(999);

		$this->assertSame(404, $result->getStatus());
		$this->assertSame('Schema not found', $result->getData()['error']);
	}//end testShowReturns404OnDoesNotExistException()

	public function testShowReturns404OnValidationException(): void {
		$this->request->method('getParam')->willReturn([]);
		$this->schemaMapper->method('find')
			->willThrowException(new ValidationException('Schema not found'));

		$result = $this->controller->show(999);

		$this->assertSame(404, $result->getStatus());
	}//end testShowReturns404OnValidationException()

	public function testShowReturns500OnGenericException(): void {
		$this->request->method('getParam')->willReturn([]);
		$this->schemaMapper->method('find')
			->willThrowException(new Exception('Unexpected error'));

		$result = $this->controller->show(1);

		$this->assertSame(500, $result->getStatus());
		// SEC-CTRL-7: internal exception detail must not leak; generic envelope only.
		$this->assertSame('Internal server error', $result->getData()['error']);
	}//end testShowReturns500OnGenericException()

	public function testShowWithExtendStats(): void {
		$schema = $this->createRealSchema(1, 'Stats Schema');

		$this->request->method('getParam')
			->willReturnMap(
				[
					['_extend', [], ['@self.stats']],
				]
			);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);
		$this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([1 => 5]);
		$this->objectMapper->method('getStatistics')->willReturn(
			[
				'total' => 25,
				'invalid' => 0,
				'deleted' => 0,
				'published' => 25,
				'locked' => 0,
				'size' => 5000,
			]
		);
		$this->auditTrailMapper->method('getStatistics')->willReturn(['total' => 50, 'size' => 200]);

		$result = $this->controller->show(1);

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('stats', $data);
		$this->assertSame(5, $data['stats']['registers']);
	}//end testShowWithExtendStats()

	public function testShowWithAllOfAddsPropertyMetadata(): void {
		$schema = $this->createRealSchema(1, 'Composed');
		$schema->setAllOf([['$ref' => '#/schemas/2']]);

		$this->request->method('getParam')->willReturn([]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);
		$this->schemaMapper->method('getPropertySourceMetadata')->willReturn(
			[
				'field1' => ['source' => 'native'],
			]
		);

		$result = $this->controller->show(1);

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('propertyMetadata', $data['@self']);
	}//end testShowWithAllOfAddsPropertyMetadata()

	public function testShowWithExtendAsString(): void {
		$schema = $this->createRealSchema(1, 'Test');

		$this->request->method('getParam')
			->willReturnMap(
				[
					['_extend', [], '@self.stats'],
				]
			);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);
		$this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([]);
		$this->objectMapper->method('getStatistics')->willReturn(
			[
				'total' => 0,
				'invalid' => 0,
				'deleted' => 0,
				'published' => 0,
				'locked' => 0,
				'size' => 0,
			]
		);
		$this->auditTrailMapper->method('getStatistics')->willReturn(['total' => 0, 'size' => 0]);

		$result = $this->controller->show(1);

		$this->assertSame(200, $result->getStatus());
		$this->assertArrayHasKey('stats', $result->getData());
	}//end testShowWithExtendAsString()

	// ── create() branch coverage ──
	public function testCreateReturnsErrorOnDBException(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Test']);
		$this->schemaMapper->method('createFromArray')
			->willThrowException(new DBException('Duplicate entry x for key schemas_organisation_slug_unique'));

		$result = $this->controller->create();

		$this->assertSame(409, $result->getStatus());
	}//end testCreateReturnsErrorOnDBException()

	public function testCreateReturnsErrorOnDatabaseConstraintException(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Test']);
		$this->schemaMapper->method('createFromArray')
			->willThrowException(new DatabaseConstraintException('Constraint error', 0, 409));

		$result = $this->controller->create();

		$this->assertSame(409, $result->getStatus());
		$this->assertSame('Constraint error', $result->getData()['error']);
	}//end testCreateReturnsErrorOnDatabaseConstraintException()

	public function testCreateReturns400OnValidationError(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Test']);
		$this->schemaMapper->method('createFromArray')
			->willThrowException(new Exception('Invalid field value'));

		$result = $this->controller->create();

		$this->assertSame(400, $result->getStatus());
		$this->assertStringContainsString('Invalid', $result->getData()['error']);
	}//end testCreateReturns400OnValidationError()

	public function testCreateReturns400OnMustBeError(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Test']);
		$this->schemaMapper->method('createFromArray')
			->willThrowException(new Exception('Field must be a string'));

		$result = $this->controller->create();

		$this->assertSame(400, $result->getStatus());
	}//end testCreateReturns400OnMustBeError()

	public function testCreateReturns400OnRequiredError(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Test']);
		$this->schemaMapper->method('createFromArray')
			->willThrowException(new Exception('Title is required'));

		$result = $this->controller->create();

		$this->assertSame(400, $result->getStatus());
	}//end testCreateReturns400OnRequiredError()

	public function testCreateReturns400OnFormatError(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Test']);
		$this->schemaMapper->method('createFromArray')
			->willThrowException(new Exception('Invalid format for date'));

		$result = $this->controller->create();

		$this->assertSame(400, $result->getStatus());
	}//end testCreateReturns400OnFormatError()

	public function testCreateReturns400OnPropertyAtError(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Test']);
		$this->schemaMapper->method('createFromArray')
			->willThrowException(new Exception('Property at /name is invalid'));

		$result = $this->controller->create();

		$this->assertSame(400, $result->getStatus());
	}//end testCreateReturns400OnPropertyAtError()

	public function testCreateReturns400OnAuthorizationError(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Test']);
		$this->schemaMapper->method('createFromArray')
			->willThrowException(new Exception('authorization group invalid'));

		$result = $this->controller->create();

		$this->assertSame(400, $result->getStatus());
	}//end testCreateReturns400OnAuthorizationError()

	public function testCreateReturns409OnConstraintError(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Test']);
		$this->schemaMapper->method('createFromArray')
			->willThrowException(new Exception('unique constraint violated'));

		$result = $this->controller->create();

		$this->assertSame(409, $result->getStatus());
	}//end testCreateReturns409OnConstraintError()

	public function testCreateReturns409OnDuplicateError(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Test']);
		$this->schemaMapper->method('createFromArray')
			->willThrowException(new Exception('duplicate key value'));

		$result = $this->controller->create();

		$this->assertSame(409, $result->getStatus());
	}//end testCreateReturns409OnDuplicateError()

	// ── update() branch coverage ──
	public function testUpdateInvalidatesCaches(): void {
		$schema = $this->createRealSchema(1, 'Updated');

		$this->request->method('getParams')->willReturn(['title' => 'Updated']);
		$this->schemaMapper->method('updateFromArray')->willReturn($schema);

		// runtime-schema-api: update() now routes schema-cache cleanup through the
		// canonical invalidate() entry point (which itself covers the legacy
		// invalidateForSchemaChange cleanup plus the request-scoped mapper cache).
		$this->schemaCacheService->expects($this->once())
			->method('invalidate')
			->with(1);
		$this->facetCacheSvc->expects($this->once())->method('clearAll');

		$result = $this->controller->update(1);

		$this->assertSame(200, $result->getStatus());
	}//end testUpdateInvalidatesCaches()

	public function testUpdateReturnsErrorOnDBException(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Updated']);
		$this->schemaMapper->method('updateFromArray')
			->willThrowException(new DBException('Duplicate entry x for key unique'));

		$result = $this->controller->update(1);

		$this->assertSame(409, $result->getStatus());
	}//end testUpdateReturnsErrorOnDBException()

	public function testUpdateReturnsErrorOnDatabaseConstraintException(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Updated']);
		$this->schemaMapper->method('updateFromArray')
			->willThrowException(new DatabaseConstraintException('Constraint', 0, 409));

		$result = $this->controller->update(1);

		$this->assertSame(409, $result->getStatus());
	}//end testUpdateReturnsErrorOnDatabaseConstraintException()

	public function testUpdateReturns400OnValidationError(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Updated']);
		$this->schemaMapper->method('updateFromArray')
			->willThrowException(new Exception('Invalid field'));

		$result = $this->controller->update(1);

		$this->assertSame(400, $result->getStatus());
	}//end testUpdateReturns400OnValidationError()

	public function testUpdateReturns409OnConstraintError(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Updated']);
		$this->schemaMapper->method('updateFromArray')
			->willThrowException(new Exception('duplicate key'));

		$result = $this->controller->update(1);

		$this->assertSame(409, $result->getStatus());
	}//end testUpdateReturns409OnConstraintError()

	public function testUpdateRemovesUnderscoreParams(): void {
		$schema = $this->createRealSchema(1, 'Updated');

		$this->request->method('getParams')->willReturn(
			[
				'_route' => 'test',
				'_limit' => '10',
				'title' => 'Updated',
			]
		);
		$this->schemaMapper->expects($this->once())
			->method('updateFromArray')
			->with(
				$this->equalTo(1),
				$this->callback(
					function ($data) {
						return !isset($data['_route'])
						&& !isset($data['_limit'])
						&& isset($data['title']);
					}
				)
			)
			->willReturn($schema);

		$this->controller->update(1);
	}//end testUpdateRemovesUnderscoreParams()

	// ── destroy() branch coverage ──
	public function testDestroyInvalidatesCaches(): void {
		$schema = $this->createRealSchema(1, 'Deletable');
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectMapper->method('getStatistics')->willReturn(['total' => 0]);

		// runtime-schema-api: destroy() now routes schema-cache cleanup through the
		// canonical invalidate() entry point.
		$this->schemaCacheService->expects($this->once())
			->method('invalidate')
			->with(1);
		$this->facetCacheSvc->expects($this->once())->method('clearAll');

		$result = $this->controller->destroy(1);

		$this->assertSame(200, $result->getStatus());
	}//end testDestroyInvalidatesCaches()

	public function testDestroyReturns409OnValidationException(): void {
		$this->schemaMapper->method('find')
			->willThrowException(new ValidationException('Objects still attached'));

		$result = $this->controller->destroy(1);

		$this->assertSame(409, $result->getStatus());
		$this->assertStringContainsString('Objects still attached', $result->getData()['error']);
	}//end testDestroyReturns409OnValidationException()

	// ── stats() branch coverage ──
	public function testStatsReturns500OnGenericException(): void {
		$this->schemaMapper->method('find')
			->willThrowException(new Exception('Database connection lost'));

		$result = $this->controller->stats(1);

		$this->assertSame(500, $result->getStatus());
		// SEC-CTRL-7: internal exception detail must not leak; generic envelope only.
		$this->assertSame('Internal server error', $result->getData()['error']);
	}//end testStatsReturns500OnGenericException()

	// NOTE: publish()/depublish() branch-coverage tests removed — endpoints
	// retired for security (commit 29b1de3af, H2).

	// ── upload() / uploadUpdate() coverage ──
	public function testUploadUpdateDelegatesToUpload(): void {
		$schema = $this->createRealSchema(1, 'Existing');

		$this->schemaMapper->method('find')->willReturn($schema);
		$this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Updated']);
		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->method('update')->willReturn($schema);

		$result = $this->controller->uploadUpdate(1);

		$this->assertSame(200, $result->getStatus());
	}//end testUploadUpdateDelegatesToUpload()

	public function testUploadNewSchemaWithoutId(): void {
		$schema = $this->createRealSchema(1, 'New Schema');

		$this->uploadService->method('getUploadedJson')->willReturn(['title' => 'New Schema']);
		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->method('insert')->willReturn($schema);
		$this->schemaMapper->method('update')->willReturn($schema);
		$this->organisationService->method('getOrganisationForNewEntity')->willReturn('org-uuid');

		$result = $this->controller->upload(null);

		$this->assertSame(200, $result->getStatus());
	}//end testUploadNewSchemaWithoutId()

	public function testUploadReturnsErrorResponseFromUploadService(): void {
		$errorResponse = new JSONResponse(['error' => 'Invalid JSON'], 400);

		$this->uploadService->method('getUploadedJson')->willReturn($errorResponse);
		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->upload(null);

		$this->assertSame(400, $result->getStatus());
	}//end testUploadReturnsErrorResponseFromUploadService()

	public function testUploadNewSchemaWithEmptyTitle(): void {
		$schema = $this->createRealSchema(1, 'New Schema');

		$this->uploadService->method('getUploadedJson')->willReturn(['title' => '']);
		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->method('insert')->willReturn($schema);
		$this->schemaMapper->method('update')->willReturn($schema);
		$this->organisationService->method('getOrganisationForNewEntity')->willReturn('org-uuid');

		$result = $this->controller->upload(null);

		$this->assertSame(200, $result->getStatus());
	}//end testUploadNewSchemaWithEmptyTitle()

	public function testUploadExistingSchemaById(): void {
		$schema = $this->createRealSchema(5, 'Existing');

		$this->schemaMapper->method('find')->willReturn($schema);
		$this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Updated via upload']);
		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->method('update')->willReturn($schema);

		$result = $this->controller->upload(5);

		$this->assertSame(200, $result->getStatus());
	}//end testUploadExistingSchemaById()

	public function testUploadReturns500OnGenericException(): void {
		$this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->method('insert')
			->willThrowException(new Exception('Unexpected insert error'));

		$result = $this->controller->upload(null);

		$this->assertSame(500, $result->getStatus());
	}//end testUploadReturns500OnGenericException()

	public function testUploadReturns400OnValidationException(): void {
		$this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->method('insert')
			->willThrowException(new Exception('Invalid property value'));

		$result = $this->controller->upload(null);

		$this->assertSame(400, $result->getStatus());
	}//end testUploadReturns400OnValidationException()

	public function testUploadReturns409OnConstraintException(): void {
		$this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->method('insert')
			->willThrowException(new Exception('duplicate key'));

		$result = $this->controller->upload(null);

		$this->assertSame(409, $result->getStatus());
	}//end testUploadReturns409OnConstraintException()

	public function testUploadReturnsErrorOnDBException(): void {
		$this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->method('insert')
			->willThrowException(new DBException('Duplicate entry x for key unique'));

		$result = $this->controller->upload(null);

		$this->assertSame(409, $result->getStatus());
	}//end testUploadReturnsErrorOnDBException()

	public function testUploadReturnsErrorOnDatabaseConstraintException(): void {
		$this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->method('insert')
			->willThrowException(new DatabaseConstraintException('Constraint', 0, 409));

		$result = $this->controller->upload(null);

		$this->assertSame(409, $result->getStatus());
	}//end testUploadReturnsErrorOnDatabaseConstraintException()

	public function testUploadNewSchemaWithOrganisationAlreadySet(): void {
		$schema = $this->createRealSchema(1, 'New Schema');
		$schema->setOrganisation('existing-org-uuid');

		$this->uploadService->method('getUploadedJson')->willReturn(['title' => 'New']);
		$this->request->method('getParams')->willReturn([]);
		$this->schemaMapper->method('insert')->willReturn($schema);

		// update should NOT be called for organisation assignment since org is already set
		$this->schemaCacheService->expects($this->once())
			->method('invalidateForSchemaChange')
			->with(1, 'create');

		$result = $this->controller->upload(null);

		$this->assertSame(200, $result->getStatus());
	}//end testUploadNewSchemaWithOrganisationAlreadySet()

	// ── related() additional coverage ──
	public function testRelatedWithOutgoingReferences(): void {
		$schema1 = $this->createRealSchema(1, 'Source');
		$schema1->setProperties(
			[
				'ref_field' => ['$ref' => '#/schemas/2'],
			]
		);

		$schema2 = $this->createRealSchema(2, 'Target');
		$schema2->setUuid('target-uuid');
		$schema2->setSlug('target-slug');

		$this->schemaMapper->method('getRelated')->willReturn([]);
		$this->schemaMapper->method('find')->willReturn($schema1);
		$this->schemaMapper->method('findAll')->willReturn([$schema1, $schema2]);
		$this->schemaMapper->method('hasReferenceToSchema')
			->willReturnCallback(
				function ($properties, $target) {
					return (string)$target->getId() === '2';
				}
			);

		$result = $this->controller->related(1);

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertCount(1, $data['outgoing']);
		$this->assertSame(1, $data['total']);
	}//end testRelatedWithOutgoingReferences()

	// ---------------------------------------------------------------------
	// Metadata-read bypass policy — auth-system requirement
	// "Schema and register METADATA-READ lookups MUST bypass multi-tenancy;
	// metadata WRITE lookups MUST enforce it".
	//
	// The five read-path methods below MUST pass `_multitenancy: false` to
	// SchemaMapper::find / findAll. The three mutation-path methods after
	// them MUST keep the safe default (`_multitenancy: true`).
	//
	// openspec/changes/aggregation-runner-multitenancy-policy/specs/auth-system/spec.md
	// ---------------------------------------------------------------------

	/**
	 * SchemaMapper::find positional signature is
	 * (id, _extend=[], _rbac=true, _multitenancy=true).
	 * These matchers pin the 4th arg (_multitenancy).
	 */
	private function readBypassWithMatchers(string|int $id): array {
		return [
			$this->equalTo($id),
			$this->anything(),
			$this->anything(),
			$this->isFalse(),
		];
	}//end readBypassWithMatchers()

	private function mutationDefaultWithMatchers(string|int $id): array {
		return [
			$this->equalTo($id),
			$this->anything(),
			$this->anything(),
			$this->isTrue(),
		];
	}//end mutationDefaultWithMatchers()

	public function testDownloadPassesMultitenancyFalseToFind(): void {
		$schema = $this->createRealSchema(1, 'Downloadable');
		$this->schemaMapper->expects($this->once())
			->method('find')
			->with(...$this->readBypassWithMatchers(1))
			->willReturn($schema);

		$this->controller->download(1);
	}//end testDownloadPassesMultitenancyFalseToFind()

	public function testRelatedPassesMultitenancyFalseToFindAndFindAll(): void {
		$target = $this->createRealSchema(1, 'Target');
		$target->setProperties([]);
		$this->schemaMapper->method('getRelated')->willReturn([]);
		$this->schemaMapper->expects($this->once())
			->method('find')
			->with(...$this->readBypassWithMatchers(1))
			->willReturn($target);
		// SchemaMapper::findAll signature:
		// (limit, offset, filters, searchConditions, searchParams, _extend,
		//  _rbac, _multitenancy).
		// _multitenancy is the 8th positional arg.
		$this->schemaMapper->expects($this->once())
			->method('findAll')
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->isFalse()
			)
			->willReturn([]);

		$this->controller->related(1);
	}//end testRelatedPassesMultitenancyFalseToFindAndFindAll()

	public function testStatsPassesMultitenancyFalseToFind(): void {
		$schema = $this->createRealSchema(1, 'StatsSchema');
		$this->schemaMapper->expects($this->once())
			->method('find')
			->with(...$this->readBypassWithMatchers(1))
			->willReturn($schema);
		// stats() does follow-up work that may throw on missing fixtures;
		// the assertion on the find() mock fires before that, which is all
		// we care about for the policy lock-in.
		try {
			$this->controller->stats(1);
		} catch (\Throwable $ignored) {
			// Intentional — see comment above.
		}
	}//end testStatsPassesMultitenancyFalseToFind()

	// NOTE: publish()/depublish() multitenancy tests removed — endpoints
	// retired for security (commit 29b1de3af, H2).

	public function testUpdatePassesMultitenancyDefaultTrueToFind(): void {
		$schema = $this->createRealSchema(1, 'Updatable');
		$this->request->method('getParam')->willReturn(null);
		$this->request->method('getParams')->willReturn(['id' => 1]);
		$this->schemaMapper->expects($this->once())
			->method('find')
			->with(...$this->mutationDefaultWithMatchers(1))
			->willReturn($schema);

		try {
			$this->controller->update(1);
		} catch (\Throwable $ignored) {
			// see testStatsPassesMultitenancyFalseToFind — assertion on find()
			// happens before the mutation logic that needs richer fixtures.
		}
	}//end testUpdatePassesMultitenancyDefaultTrueToFind()

	public function testDestroyPassesMultitenancyDefaultTrueToFind(): void {
		$schema = $this->createRealSchema(1, 'Deletable');
		$this->request->method('getParam')->willReturn(null);
		$this->schemaMapper->expects($this->once())
			->method('find')
			->with(...$this->mutationDefaultWithMatchers(1))
			->willReturn($schema);

		try {
			$this->controller->destroy(1);
		} catch (\Throwable $ignored) {
			// see testStatsPassesMultitenancyFalseToFind
		}
	}//end testDestroyPassesMultitenancyDefaultTrueToFind()

	public function testUploadPassesMultitenancyDefaultTrueToFind(): void {
		$schema = $this->createRealSchema(1, 'Uploadable');
		$this->request->method('getParam')->willReturn(null);
		$this->request->method('getParams')->willReturn(['id' => 1]);
		$this->schemaMapper->expects($this->once())
			->method('find')
			->with(...$this->mutationDefaultWithMatchers(1))
			->willReturn($schema);
		$this->uploadService->method('getUploadedJson')->willReturn(['title' => 'X']);

		try {
			$this->controller->upload(1);
		} catch (\Throwable $ignored) {
			// see testStatsPassesMultitenancyFalseToFind
		}
	}//end testUploadPassesMultitenancyDefaultTrueToFind()

	/**
	 * Build a controller whose semantic-type resolver is REAL, wired over
	 * mocked mappers.
	 *
	 * {@see \OCA\OpenRegister\Service\SemanticTypeResolver} is `final`, so it
	 * cannot be doubled; the resolution behaviour is driven through the schema
	 * enumeration and the implemented-type markers instead.
	 *
	 * @param array<int,\OCA\OpenRegister\Db\Schema> $schemas The installed schemas.
	 * @param array<int,string> $implementedTypes The semantic types every schema implements.
	 * @param array<int,\OCA\OpenRegister\Db\Register> $registers The installed registers.
	 *
	 * @return SchemasController
	 */
	private function controllerWithSemanticResolver(
		array $schemas,
		array $implementedTypes,
		array $registers = [],
	): SchemasController {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn($schemas);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn($registers);

		$jsonLdContext = $this->createMock(\OCA\OpenRegister\Service\JsonLd\JsonLdContextService::class);
		$jsonLdContext->method('getImplementedTypes')->willReturn($implementedTypes);

		$resolver = new \OCA\OpenRegister\Service\SemanticTypeResolver(
			new SchemaCatalog($registerMapper, $schemaMapper, $this->createMock(MagicMapperInterface::class)),
			$jsonLdContext,
			$this->logger,
			null
		);

		return SchemasControllerFactory::create(
			'openregister',
			$this->request,
			$this->config,
			$schemaMapper,
			$registerMapper,
			$this->objectMapper,
			$this->uploadService,
			$this->auditTrailMapper,
			$this->organisationService,
			$this->schemaCacheService,
			$this->facetCacheSvc,
			$this->schemaService,
			$this->logger,
			$this->container,
			$this->schemaVersioningService,
			null,
			null,
			$resolver
		);
	}//end controllerWithSemanticResolver()

	/**
	 * A URI an installed schema implements resolves to that schema plus its
	 * owning register, so a consuming app can build a picker over it.
	 *
	 * @return void
	 */
	public function testResolveByImplementsReturnsTheProviderSchemaAndRegister(): void {
		$schema = new \OCA\OpenRegister\Db\Schema();
		$schema->setId(12);
		$schema->setSlug('organisatie');

		$register = new \OCA\OpenRegister\Db\Register();
		$register->setId(3);
		$register->setSlug('basisregister');
		$register->setSchemas([12]);

		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return (['uri' => 'https://schema.org/Organization'][$key] ?? $default);
			}
		);

		$controller = $this->controllerWithSemanticResolver(
			[$schema],
			['https://schema.org/Organization'],
			[$register]
		);

		$response = $controller->resolveByImplements();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['resolved']);
		$this->assertSame(12, $data['schema']);
		$this->assertSame('organisatie', $data['schemaSlug']);
		$this->assertSame(3, $data['register']);
		$this->assertSame('basisregister', $data['registerSlug']);
	}//end testResolveByImplementsReturnsTheProviderSchemaAndRegister()

	/**
	 * No installed schema implements the type: `{resolved:false}` with HTTP
	 * 200 — never a 500 — so a consuming form degrades to a disabled field.
	 *
	 * @return void
	 */
	public function testResolveByImplementsDegradesToUnresolvedWithHttp200(): void {
		$schema = new \OCA\OpenRegister\Db\Schema();
		$schema->setId(12);
		$schema->setSlug('organisatie');

		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return (['uri' => 'https://schema.org/Person'][$key] ?? $default);
			}
		);

		$controller = $this->controllerWithSemanticResolver(
			[$schema],
			['https://schema.org/Organization']
		);

		$response = $controller->resolveByImplements();

		$this->assertSame(200, $response->getStatus());
		$this->assertFalse($response->getData()['resolved']);
		$this->assertArrayNotHasKey('schema', $response->getData());
	}//end testResolveByImplementsDegradesToUnresolvedWithHttp200()

	/**
	 * An absent `uri` short-circuits to `{resolved:false}` without enumerating
	 * a single schema.
	 *
	 * @return void
	 */
	public function testResolveByImplementsWithoutAUriIsUnresolved(): void {
		$this->request->method('getParam')->willReturn(null);

		$controller = $this->controllerWithSemanticResolver([], []);

		$response = $controller->resolveByImplements();

		$this->assertSame(200, $response->getStatus());
		$this->assertFalse($response->getData()['resolved']);
	}//end testResolveByImplementsWithoutAUriIsUnresolved()
	/**
	 * An unsupported match operator is refused with 400 naming the operator, not a bare 500 (#4162).
	 *
	 * The exception is the real one: the mapper double hydrates a real Schema,
	 * which is where the save path validates the authorization block.
	 */
	public function testCreateRefusesAnUnsupportedMatchOperatorWith400NamingIt(): void {
		$payload = [
			'title' => 'LP b3 bad',
			'properties' => ['title' => ['type' => 'string']],
			'authorization' => ['read' => [['group' => 'authenticated', 'match' => ['title' => ['$regex' => '^a']]]]],
		];
		$this->request->method('getParams')->willReturn($payload);
		$this->schemaMapper->method('createFromArray')->willReturnCallback(
			static fn (array $data) => (new \OCA\OpenRegister\Db\Schema())->hydrate($data)
		);

		$result = $this->controller->create();

		$this->assertSame(400, $result->getStatus());
		$this->assertStringContainsString("unsupported operator '\$regex'", $result->getData()['error']);
	}//end testCreateRefusesAnUnsupportedMatchOperatorWith400NamingIt()

	/**
	 * The same refusal on an update, which PATCH routes to (#4162).
	 */
	public function testUpdateRefusesANonListInOperandWith400NamingIt(): void {
		$this->request->method('getParams')->willReturn(
			['authorization' => ['read' => [['group' => 'authenticated', 'match' => ['status' => ['$in' => 'open']]]]]]
		);
		$this->schemaMapper->method('updateFromArray')->willReturnCallback(
			static fn (int $id, array $data) => (new \OCA\OpenRegister\Db\Schema())->hydrate($data)
		);

		$result = $this->controller->update(1);

		$this->assertSame(400, $result->getStatus());
		$this->assertStringContainsString("'\$in' operand", $result->getData()['error']);
	}//end testUpdateRefusesANonListInOperandWith400NamingIt()

	/**
	 * Index with `@self.stats` for the current user, with audit counts on the mapper.
	 *
	 * @return array The response body.
	 */
	private function indexWithLogStats(): array {
		$schema = $this->createMock(Schema::class);
		$schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

		$this->request->method('getParams')->willReturn(['_extend' => '@self.stats']);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		$this->schemaMapper->method('findAllExtendedBy')->willReturn([]);
		$this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([1 => 2]);
		$this->objectMapper->method('getStatisticsGroupedBySchema')->willReturn([1 => ['total' => 10]]);

		return $this->controller->index()->getData();
	}//end indexWithLogStats()

	public function testANonAdminListingSchemasIsNotGivenTheTrailCount(): void {
		$this->currentUserIsAdmin = false;
		$this->auditTrailMapper->expects($this->never())->method('getStatisticsGroupedBySchema');

		$data = $this->indexWithLogStats();

		$this->assertSame(['total' => 0, 'size' => 0], $data['results'][0]['stats']['logs']);
		$this->assertSame(10, $data['results'][0]['stats']['objects']['total']);
	}//end testANonAdminListingSchemasIsNotGivenTheTrailCount()

	public function testAnAnonymousCallerListingSchemasIsNotGivenTheTrailCount(): void {
		$this->currentUser = null;
		$this->auditTrailMapper->expects($this->never())->method('getStatisticsGroupedBySchema');
		$this->schemaMapper->method('findAll')->willReturn([]);
		$this->request->method('getParams')->willReturn(['_extend' => '@self.stats']);

		$this->assertSame(200, $this->controller->index()->getStatus());
	}//end testAnAnonymousCallerListingSchemasIsNotGivenTheTrailCount()

	public function testAnAdminListingSchemasIsGivenTheTrailCount(): void {
		$this->auditTrailMapper->expects($this->once())->method('getStatisticsGroupedBySchema')
			->with([1])->willReturn([1 => ['total' => 20, 'size' => 100]]);

		$data = $this->indexWithLogStats();

		$this->assertSame(['total' => 20, 'size' => 100], $data['results'][0]['stats']['logs']);
	}//end testAnAdminListingSchemasIsGivenTheTrailCount()

	/**
	 * Show with `@self.stats` for the current user.
	 *
	 * @return array The response body.
	 */
	private function showWithLogStats(): array {
		$this->request->method('getParam')->willReturnMap([['_extend', [], ['@self.stats']]]);
		$this->schemaMapper->method('find')->willReturn($this->createRealSchema(1, 'Stats Schema'));
		$this->schemaMapper->method('findExtendedBy')->willReturn([]);
		$this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([1 => 5]);
		$this->objectMapper->method('getStatistics')->willReturn(['total' => 25]);

		return $this->controller->show(1)->getData();
	}//end showWithLogStats()

	public function testANonAdminReadingASchemaIsNotGivenTheTrailCount(): void {
		$this->currentUserIsAdmin = false;
		$this->auditTrailMapper->expects($this->never())->method('getStatistics');

		$data = $this->showWithLogStats();

		$this->assertSame(['total' => 0, 'size' => 0], $data['stats']['logs']);
		$this->assertSame(['total' => 25], $data['stats']['objects']);
	}//end testANonAdminReadingASchemaIsNotGivenTheTrailCount()

	public function testAnAdminReadingASchemaIsGivenTheTrailCount(): void {
		$this->auditTrailMapper->expects($this->once())->method('getStatistics')
			->with(null, 1)->willReturn(['total' => 50, 'size' => 200]);

		$data = $this->showWithLogStats();

		$this->assertSame(['total' => 50, 'size' => 200], $data['stats']['logs']);
	}//end testAnAdminReadingASchemaIsGivenTheTrailCount()

	public function testANonAdminAskingForSchemaStatsIsNotGivenTheTrailCount(): void {
		$this->currentUserIsAdmin = false;
		$this->auditTrailMapper->expects($this->never())->method('getStatistics');
		$this->schemaMapper->method('find')->willReturn($this->createRealSchema(1, 'Stats Schema'));
		$this->objectMapper->method('getStatistics')->willReturn(['total' => 7]);

		$data = $this->controller->stats(1)->getData();

		$this->assertSame(['total' => 0, 'size' => 0], $data['logs']);
		$this->assertSame(7, $data['objectCount']);
	}//end testANonAdminAskingForSchemaStatsIsNotGivenTheTrailCount()

	public function testAnAdminAskingForSchemaStatsIsGivenTheTrailCount(): void {
		$this->auditTrailMapper->expects($this->once())->method('getStatistics')
			->with(null, 1)->willReturn(['total' => 9, 'size' => 90]);
		$this->schemaMapper->method('find')->willReturn($this->createRealSchema(1, 'Stats Schema'));
		$this->objectMapper->method('getStatistics')->willReturn(['total' => 7]);

		$data = $this->controller->stats(1)->getData();

		$this->assertSame(['total' => 9, 'size' => 90], $data['logs']);
	}//end testAnAdminAskingForSchemaStatsIsGivenTheTrailCount()
}//end class
