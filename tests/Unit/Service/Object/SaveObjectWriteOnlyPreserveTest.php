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
use Twig\Loader\ArrayLoader;

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
	private SaveObject $handler;
	private SchemaMapper $schemaMapper;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		// A REAL PropertyRbacHandler: the point of this test is the collaboration.
		$propertyRbacHandler = new PropertyRbacHandler(
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ConditionMatcher::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->handler = new SaveObject(
			$this->createMock(MagicMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(MetadataHydrationHandler::class),
			$this->createMock(FilePropertyHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler::class),
			$this->createMock(IUserSession::class),
			$this->createMock(AuditTrailMapper::class),
			$this->schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$propertyRbacHandler,
			$this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\TranslationProjectionService::class),
			$this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\OpenRegister\Service\TmloService::class),
			$this->createMock(\OCA\OpenRegister\Service\File\FolderManagementHandler::class),
			new ArrayLoader()
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
			[$existing, $schema, $data, [], null, null]
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
