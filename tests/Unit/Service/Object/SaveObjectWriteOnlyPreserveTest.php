<?php

declare(strict_types=1);

/**
 * SaveObject save-side write-only preservation wiring tests (openregister#463).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Tests\Support\BuildsStateFieldRuleResolver;

/**
 * Proves the save-side preserve rule is actually WIRED INTO the update path, not merely
 * implemented next to it.
 *
 * PropertyRbacHandlerWriteOnlyPreserveTest pins the rule's behaviour in isolation. This
 * one pins the thing that isolation cannot: that SaveObject::prepareObjectForUpdate()
 * invokes it, with a REAL PropertyRbacHandler, at the one point in the sequence where it
 * works — after prepareObjectData (so an encrypted stored value is not double-encrypted)
 * and before fillMissingSchemaPropertiesWithNull (which materialises every absent property
 * as null, after which an omitted secret and a deliberately-cleared one are byte-identical).
 *
 * A correct implementation placed one line later is a silent no-op that these assertions
 * catch and the isolated tests would not.
 */
class SaveObjectWriteOnlyPreserveTest extends TestCase {
	use BuildsStateFieldRuleResolver;

	private SaveObject $handler;
	private SchemaMapper $schemaMapper;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		// A REAL PropertyRbacHandler: the point of this test is the collaboration.
		$propertyRbacHandler = new PropertyRbacHandler(caller: new \OCA\OpenRegister\Service\PropertyRbacCaller($this->createMock(IUserSession::class), $this->createMock(IGroupManager::class)), rules: new \OCA\OpenRegister\Service\PropertyRbacRules($this->createMock(ConditionMatcher::class), self::stateFieldRuleResolver($this->createMock(IUserSession::class), $this->createMock(IGroupManager::class))), logger: $this->createMock(LoggerInterface::class));

		$this->handler = new SaveObject(
			services: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices(
				persistence: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence(
					mappers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers(
						catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->schemaMapper, tables: $this->createMock(MagicMapper::class)),
						unifiedObjectMapper: $this->createMock(MagicMapper::class)
					),
					records: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords(
						auditTrailMapper: $this->createMock(AuditTrailMapper::class),
						settingsService: $this->createMock(SettingsService::class),
						tmloService: $this->createMock(\OCA\OpenRegister\Service\TmloService::class)
					),
					runtime: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime(
						cacheHandler: $this->createMock(CacheHandler::class)
					)
				),
				handlers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectHandlers(
					properties: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPropertyHandlers(
						metaHydrationHandler: $this->createMock(MetadataHydrationHandler::class),
						filePropertyHandler: $this->createMock(FilePropertyHandler::class),
						linkedEntityHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler::class),
						computedFieldHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class)
					),
					values: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectValueHandlers(
						translationHandler: $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class),
						translationProjectionService: $this->createMock(\OCA\OpenRegister\Service\TranslationProjectionService::class),
						translationStatusService: $this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class)
					)
				),
				platform: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPlatform(
					userSession: $this->createMock(IUserSession::class),
					urlGenerator: $this->createMock(IURLGenerator::class),
					logger: $this->createMock(LoggerInterface::class)
				),
				tenancy: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy(
					organisationService: $this->createMock(OrganisationService::class)
				)
			),
			propertyRbacHandler: $propertyRbacHandler,
			permissionHandler: $this->createMock(PermissionHandler::class),
			folderManagementHandler: $this->createMock(\OCA\OpenRegister\Service\File\FolderManagementHandler::class)
		);
	}

	/**
	 * A Source-shaped schema: a top-level secret and a nested one under an untyped
	 * `configuration` object that also holds ordinary operator-editable settings.
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
				'apiToken' => ['type' => 'string', 'writeOnly' => true],
				'configuration' => ['type' => 'object'],
			]
		);
		$schema->setConfiguration(
			[
				Schema::WRITEONLY_PATHS_ANNOTATION => [
					'configuration.authentication.client_secret',
				],
			]
		);

		$this->schemaMapper->method('find')->willReturn($schema);

		return $schema;
	}

	private function storedEntity(Schema $schema, array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		$entity->setSchema($schema->getId());
		$entity->setRegister(65);
		$entity->setObject($object);

		return $entity;
	}

	private function prepareUpdate(Schema $schema, ObjectEntity $existing, array $data): array {
		$method = new ReflectionMethod(SaveObject::class, 'prepareObjectForUpdate');
		$method->setAccessible(true);

		/** @var ObjectEntity $prepared */
		$prepared = $method->invokeArgs(
			$this->handler,
			[$existing, $schema, new \OCA\OpenRegister\Service\Object\SaveObject\ObjectPayload($data, []), null]
		);

		return $prepared->getObject();
	}

	/**
	 * The load-bearing case, driven through the real update path.
	 *
	 * Remove the restoreWriteOnlyValues() call from prepareObjectForUpdate and this
	 * assertion fails with apiToken nulled — that is the mutation test for the fix.
	 */
	public function testOmittedTopLevelSecretSurvivesThePreparedUpdate(): void {
		$schema = $this->sourceSchema();
		$existing = $this->storedEntity($schema, ['name' => 'prod', 'apiToken' => 's3cr3t']);

		$result = $this->prepareUpdate($schema, $existing, ['name' => 'prod-renamed']);

		$this->assertSame('s3cr3t', $result['apiToken'], 'The PUT null-fill must not destroy an omitted secret.');
		$this->assertSame('prod-renamed', $result['name']);
	}

	public function testOmittedNestedSecretSurvivesThePreparedUpdate(): void {
		$schema = $this->sourceSchema();
		$existing = $this->storedEntity(
			$schema,
			[
				'name' => 'src',
				'configuration' => [
					'endpoint' => 'https://old.example.gov',
					'authentication' => ['username' => 'svc', 'client_secret' => 's3cr3t'],
				],
			]
		);

		$result = $this->prepareUpdate(
			$schema,
			$existing,
			[
				'name' => 'src',
				'configuration' => [
					'endpoint' => 'https://new.example.gov',
					'authentication' => ['username' => 'svc'],
				],
			]
		);

		$this->assertSame('s3cr3t', $result['configuration']['authentication']['client_secret']);
		$this->assertSame(
			'https://new.example.gov',
			$result['configuration']['endpoint'],
			'A sibling edit under configuration must survive the preserve.'
		);
	}

	public function testNewSecretStillOverwritesThroughThePreparedUpdate(): void {
		$schema = $this->sourceSchema();
		$existing = $this->storedEntity($schema, ['name' => 'prod', 'apiToken' => 'old-secret']);

		$result = $this->prepareUpdate($schema, $existing, ['name' => 'prod', 'apiToken' => 'rotated']);

		$this->assertSame('rotated', $result['apiToken'], 'Secrets must remain settable through the save path.');
	}

	/**
	 * Pins the ordering against fillMissingSchemaPropertiesWithNull specifically: an
	 * explicit null must reach the mapper as null, exactly as before this fix.
	 */
	public function testExplicitNullStillClearsThroughThePreparedUpdate(): void {
		$schema = $this->sourceSchema();
		$existing = $this->storedEntity($schema, ['name' => 'prod', 'apiToken' => 's3cr3t']);

		$result = $this->prepareUpdate($schema, $existing, ['name' => 'prod', 'apiToken' => null]);

		$this->assertArrayHasKey('apiToken', $result);
		$this->assertNull($result['apiToken'], 'An explicit null must still clear the secret.');
	}

	/**
	 * A non-write-only property omitted from the payload must still be nulled — the
	 * preserve rule must not accidentally turn PUT into PATCH for ordinary fields.
	 */
	public function testOrdinaryOmittedPropertyIsStillNulledByPutSemantics(): void {
		$schema = $this->sourceSchema();
		$existing = $this->storedEntity($schema, ['name' => 'prod', 'apiToken' => 's3cr3t']);

		$result = $this->prepareUpdate($schema, $existing, ['apiToken' => 'kept']);

		$this->assertArrayHasKey('name', $result);
		$this->assertNull($result['name'], 'PUT semantics must still null an omitted ordinary property.');
	}
}
