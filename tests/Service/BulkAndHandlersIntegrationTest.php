<?php

/**
 * Integration tests for MagicMapper bulk save, MetadataHydrationHandler, and FilePropertyHandler
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for bulk operations and save-object handlers.
 *
 * Tests MagicMapper's bulk save into a register+schema table (real DB),
 * MetadataHydrationHandler (metadata extraction, twig templates, slugs),
 * and FilePropertyHandler (file detection, validation, parsing paths).
 *
 * @group DB
 */
class BulkAndHandlersIntegrationTest extends TestCase {
	private MetadataHydrationHandler $metadataHandler;
	private FilePropertyHandler $fileHandler;
	private RegisterMapper $registerMapper;
	private SchemaMapper $schemaMapper;
	private MagicMapper $objectMapper;
	private \OCP\IDBConnection $db;
	private ?Register $testRegister = null;
	private ?Schema $testSchema = null;

	/** @var string[] UUIDs of objects created via bulk ops for cleanup */
	private array $createdUuids = [];

	protected function setUp(): void {
		parent::setUp();
		$this->metadataHandler = \OC::$server->get(MetadataHydrationHandler::class);
		$this->fileHandler = \OC::$server->get(FilePropertyHandler::class);
		$this->registerMapper = \OC::$server->get(RegisterMapper::class);
		$this->schemaMapper = \OC::$server->get(SchemaMapper::class);
		$this->objectMapper = \OC::$server->get(MagicMapper::class);
		$this->db = \OC::$server->get(\OCP\IDBConnection::class);

		$this->createTestRegisterAndSchema();
	}

	protected function tearDown(): void {
		// Clean up objects created by bulk operations via direct DB.
		if (!empty($this->createdUuids)) {
			$placeholders = implode(',', array_fill(0, count($this->createdUuids), '?'));
			$sql = "DELETE FROM oc_openregister_objects WHERE uuid IN ($placeholders)";
			$stmt = $this->db->prepare($sql);
			$stmt->execute(array_values($this->createdUuids));
		}

		if ($this->testSchema !== null) {
			try {
				$this->schemaMapper->delete($this->testSchema);
			} catch (\Exception $e) {
				// Ignore.
			}
		}

		if ($this->testRegister !== null) {
			try {
				$this->registerMapper->delete($this->testRegister);
			} catch (\Exception $e) {
				// Ignore.
			}
		}

		parent::tearDown();
	}

	private function createTestRegisterAndSchema(): void {
		$register = new Register();
		$register->setTitle('phpunit-bulk-' . uniqid());
		$register->setDescription('Test register for bulk/handler tests');
		$register->setUuid(Uuid::v4()->toRfc4122());
		$register->setSlug('phpunit-bulk-' . uniqid());
		$register->setSchemas([]);
		$this->testRegister = $this->registerMapper->insert($register);

		$schema = new Schema();
		$schema->setTitle('phpunit-bulk-schema-' . uniqid());
		$schema->setDescription('Test schema for bulk/handler tests');
		$schema->setUuid(Uuid::v4()->toRfc4122());
		$schema->setSlug('phpunit-bulk-schema-' . uniqid());
		$schema->setProperties([
			'naam' => ['type' => 'string', 'title' => 'Naam'],
			'beschrijving' => ['type' => 'string', 'title' => 'Description'],
			'title' => ['type' => 'string', 'title' => 'Title'],
			'summary' => ['type' => 'string', 'title' => 'Summary'],
			'photo' => ['type' => 'file', 'title' => 'Photo'],
			'attachments' => [
				'type' => 'array',
				'title' => 'Attachments',
				'items' => ['type' => 'file'],
			],
			'website' => ['type' => 'string', 'title' => 'Website'],
		]);
		$this->testSchema = $this->schemaMapper->insert($schema);
	}

	/**
	 * Helper: build a raw insert object array in the format expected by bulk ops.
	 */
	private function buildInsertObject(array $objectData, ?string $uuid = null): array {
		$uuid = $uuid ?? Uuid::v4()->toRfc4122();
		$this->createdUuids[] = $uuid;

		return [
			'uuid' => $uuid,
			'register' => (string)$this->testRegister->getId(),
			'schema' => (string)$this->testSchema->getId(),
			'object' => $objectData,
		];
	}

	// -----------------------------------------------------------------------
	// Bulk save: MagicMapper (was ObjectHandlers\OptimizedBulkOperations)
	//
	// OptimizedBulkOperations was deleted in 12927d356 (2026-03-13) with the blob
	// `openregister_objects` table it wrote to. Twenty-eight tests stood here.
	// Twenty-three of them drove its PRIVATE helpers through reflection
	// (unifyObjectFormats, extractColumnValue, mapObjectColumnsToDatabase,
	// getJsonColumns, convertDateTimeToMySQLFormat, createEntityFromData) or read
	// the blob table's columns back with raw SQL, including `published`, a column
	// the same commit retired. That is an implementation nobody ships any more,
	// so those tests are gone rather than translated.
	//
	// The five below are the ones that asserted BEHAVIOUR: save many objects in
	// one call, get their identifiers back, read them out again, and update
	// rather than duplicate on a second save of the same uuid. They now drive
	// MagicMapper::saveObjectsToRegisterSchemaTable(), which is where that
	// behaviour lives.
	// -----------------------------------------------------------------------

	/**
	 * Save one object in a bulk call and get its uuid back.
	 *
	 * @return void
	 */
	public function testBulkSaveSingleObject(): void {
		$uuid = Uuid::v4()->toRfc4122();
		$saved = $this->objectMapper->saveObjectsToRegisterSchemaTable(
			[['uuid' => $uuid, 'naam' => 'Bulk Test 1']],
			$this->testRegister,
			$this->testSchema
		);

		$this->assertSame([$uuid], $saved);
	}//end testBulkSaveSingleObject()

	/**
	 * Every object of a bulk call is saved, not just the first.
	 *
	 * @return void
	 */
	public function testBulkSaveMultipleObjects(): void {
		$objects = [];
		$uuids = [];
		for ($i = 0; $i < 5; $i++) {
			$uuid = Uuid::v4()->toRfc4122();
			$uuids[] = $uuid;
			$objects[] = ['uuid' => $uuid, 'naam' => "Bulk Multi $i"];
		}

		$saved = $this->objectMapper->saveObjectsToRegisterSchemaTable(
			$objects,
			$this->testRegister,
			$this->testSchema
		);

		$this->assertCount(5, $saved);
		$this->assertSame($uuids, $saved);
	}//end testBulkSaveMultipleObjects()

	/**
	 * An empty bulk call saves nothing and says so.
	 *
	 * @return void
	 */
	public function testBulkSaveEmptyArrayReturnsEmpty(): void {
		$saved = $this->objectMapper->saveObjectsToRegisterSchemaTable(
			[],
			$this->testRegister,
			$this->testSchema
		);

		$this->assertSame([], $saved);
	}//end testBulkSaveEmptyArrayReturnsEmpty()

	/**
	 * What a bulk call saved is what comes back out.
	 *
	 * The old version read the blob table with raw SQL. The magic table is one
	 * table per register+schema, so this reads the object back through the
	 * mapper instead of naming a table this test has no business knowing.
	 *
	 * @return void
	 */
	public function testBulkSavedObjectIsReadableAgain(): void {
		$uuid = Uuid::v4()->toRfc4122();
		$this->objectMapper->saveObjectsToRegisterSchemaTable(
			[['uuid' => $uuid, 'naam' => 'DB Verify Test', 'title' => 'Check DB']],
			$this->testRegister,
			$this->testSchema
		);

		$stored = $this->objectMapper->findInRegisterSchemaTable(
			identifier: $uuid,
			register: $this->testRegister,
			schema: $this->testSchema,
			_rbac: false,
			_multitenancy: false
		);

		$data = $stored->getObject();
		$this->assertSame('DB Verify Test', $data['naam']);
		$this->assertSame('Check DB', $data['title']);
	}//end testBulkSavedObjectIsReadableAgain()

	/**
	 * A second save of the same uuid updates the row, it does not add one.
	 *
	 * @return void
	 */
	public function testBulkSaveMixedInsertAndUpdate(): void {
		$existing = Uuid::v4()->toRfc4122();
		$this->objectMapper->saveObjectsToRegisterSchemaTable(
			[['uuid' => $existing, 'naam' => 'First Object']],
			$this->testRegister,
			$this->testSchema
		);

		$fresh = Uuid::v4()->toRfc4122();
		$saved = $this->objectMapper->saveObjectsToRegisterSchemaTable(
			[
				['uuid' => $fresh, 'naam' => 'New Object'],
				['uuid' => $existing, 'naam' => 'Updated First Object'],
			],
			$this->testRegister,
			$this->testSchema
		);

		$this->assertSame([$fresh, $existing], $saved);
		$this->assertCount(
			2,
			$this->objectMapper->findAllInRegisterSchemaTable(register: $this->testRegister, schema: $this->testSchema),
			'the update MUST land on the existing row, not beside it'
		);

		$updated = $this->objectMapper->findInRegisterSchemaTable(
			identifier: $existing,
			register: $this->testRegister,
			schema: $this->testSchema,
			_rbac: false,
			_multitenancy: false
		);
		$this->assertSame('Updated First Object', $updated->getObject()['naam']);
	}//end testBulkSaveMixedInsertAndUpdate()

	// -----------------------------------------------------------------------
	// MetadataHydrationHandler tests
	// -----------------------------------------------------------------------

	public function testGetValueFromPathSimpleField(): void {
		$data = ['name' => 'Test Name', 'count' => 42];
		$result = $this->metadataHandler->getValueFromPath($data, 'name');
		$this->assertEquals('Test Name', $result);
	}

	public function testGetValueFromPathNestedField(): void {
		$data = ['contact' => ['email' => 'test@example.com', 'phone' => '123']];
		$result = $this->metadataHandler->getValueFromPath($data, 'contact.email');
		$this->assertEquals('test@example.com', $result);
	}

	public function testGetValueFromPathMissingField(): void {
		$data = ['name' => 'Test'];
		$result = $this->metadataHandler->getValueFromPath($data, 'nonexistent');
		$this->assertNull($result);
	}

	public function testGetValueFromPathDeepNested(): void {
		$data = ['a' => ['b' => ['c' => 'deep']]];
		$result = $this->metadataHandler->getValueFromPath($data, 'a.b.c');
		$this->assertEquals('deep', $result);
	}

	public function testGetValueFromPathNumericValue(): void {
		$data = ['count' => 42];
		$result = $this->metadataHandler->getValueFromPath($data, 'count');
		$this->assertEquals('42', $result); // Casts to string.
	}

	public function testExtractMetadataValueSimplePath(): void {
		$data = ['title' => 'Hello World'];
		$result = $this->metadataHandler->extractMetadataValue($data, 'title');
		$this->assertEquals('Hello World', $result);
	}

	public function testExtractMetadataValueFallbackChain(): void {
		$data = ['ggm_naam' => 'Fallback Name'];
		$result = $this->metadataHandler->extractMetadataValue($data, 'name | ggm_naam | identifier');
		$this->assertEquals('Fallback Name', $result);
	}

	public function testExtractMetadataValueFallbackChainFirstMatch(): void {
		$data = ['name' => 'First', 'ggm_naam' => 'Second'];
		$result = $this->metadataHandler->extractMetadataValue($data, 'name | ggm_naam');
		$this->assertEquals('First', $result);
	}

	public function testExtractMetadataValueFallbackChainAllEmpty(): void {
		$data = ['other' => 'value'];
		$result = $this->metadataHandler->extractMetadataValue($data, 'name | ggm_naam | identifier');
		$this->assertNull($result);
	}

	public function testExtractMetadataValueTwigTemplate(): void {
		$data = ['voornaam' => 'Jan', 'achternaam' => 'de Vries'];
		$result = $this->metadataHandler->extractMetadataValue($data, '{{ voornaam }} {{ achternaam }}');
		$this->assertEquals('Jan de Vries', $result);
	}

	public function testExtractMetadataValueTwigTemplateMissingField(): void {
		$data = ['voornaam' => 'Jan'];
		$result = $this->metadataHandler->extractMetadataValue($data, '{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}');
		$this->assertEquals('Jan', $result);
	}

	public function testExtractMetadataValueTwigTemplateAllMissing(): void {
		$data = ['other' => 'value'];
		$result = $this->metadataHandler->extractMetadataValue($data, '{{ voornaam }} {{ achternaam }}');
		$this->assertNull($result);
	}

	public function testProcessTwigLikeTemplateWithFallbacks(): void {
		$data = ['ggm_naam' => 'Alt Name', 'type' => 'gemeente'];
		$result = $this->metadataHandler->processTwigLikeTemplate(
			$data,
			'{{ name | ggm_naam }} - {{ type }}'
		);
		$this->assertEquals('Alt Name - gemeente', $result);
	}

	public function testProcessTwigLikeTemplateNoMatches(): void {
		$data = ['x' => 'y'];
		$result = $this->metadataHandler->processTwigLikeTemplate($data, 'no-template-here');
		$this->assertNull($result);
	}

	public function testProcessFieldWithFallbacksEmptyFields(): void {
		$data = ['a' => 'val'];
		$result = $this->metadataHandler->processFieldWithFallbacks($data, ' | | a');
		$this->assertEquals('val', $result);
	}

	public function testProcessMapFilter(): void {
		$data = ['richting' => 'AnaarB'];
		$result = $this->metadataHandler->processMapFilter(
			$data,
			'richting',
			'AnaarB=arrow-right, BnaarA=arrow-left, bi-directioneel=arrows'
		);
		$this->assertEquals('arrow-right', $result);
	}

	public function testProcessMapFilterNoMatch(): void {
		$data = ['richting' => 'Unknown'];
		$result = $this->metadataHandler->processMapFilter(
			$data,
			'richting',
			'AnaarB=arrow-right, BnaarA=arrow-left'
		);
		// Falls back to the raw field value.
		$this->assertEquals('Unknown', $result);
	}

	public function testProcessMapFilterEmptyField(): void {
		$data = [];
		$result = $this->metadataHandler->processMapFilter(
			$data,
			'richting',
			'AnaarB=arrow-right, BnaarA=arrow-left'
		);
		// Defaults to first mapped value when field is empty.
		$this->assertEquals('arrow-right', $result);
	}

	public function testProcessIfFilledFilterFieldFilled(): void {
		$data = ['voorziening' => 'some-value'];
		$result = $this->metadataHandler->processIfFilledFilter($data, 'voorziening', 'extern, intern');
		$this->assertEquals('extern', $result);
	}

	public function testProcessIfFilledFilterFieldEmpty(): void {
		$data = [];
		$result = $this->metadataHandler->processIfFilledFilter($data, 'voorziening', 'extern, intern');
		$this->assertEquals('intern', $result);
	}

	public function testProcessIfFilledFilterFieldEmptyString(): void {
		$data = ['voorziening' => ''];
		$result = $this->metadataHandler->processIfFilledFilter($data, 'voorziening', 'extern, intern');
		$this->assertEquals('intern', $result);
	}

	public function testProcessIfFilledFilterSingleValue(): void {
		$data = [];
		$result = $this->metadataHandler->processIfFilledFilter($data, 'field', 'onlyvalue');
		$this->assertEquals('onlyvalue', $result);
	}

	public function testProcessTwigLikeTemplateWithMapFilter(): void {
		$data = ['richting' => 'AnaarB', 'name' => 'Route 1'];
		$result = $this->metadataHandler->processTwigLikeTemplate(
			$data,
			'{{ name }} {{ richting | map: AnaarB=>, BnaarA=< }}'
		);
		$this->assertEquals('Route 1 >', $result);
	}

	public function testProcessTwigLikeTemplateWithIfFilledFilter(): void {
		$data = ['extern' => 'ja', 'name' => 'Service'];
		$result = $this->metadataHandler->processTwigLikeTemplate(
			$data,
			'{{ name }} ({{ extern | ifFilled: extern, intern }})'
		);
		$this->assertEquals('Service (extern)', $result);
	}

	public function testProcessTwigLikeTemplateWithArrayValueConverted(): void {
		// When a twig template references an array field with no schemaProperties,
		// resolveRelationValue returns null for non-string values.
		// Provide schemaProperties to skip resolveRelationValue for non-relation fields.
		$data = ['tags' => ['a', 'b', 'c']];
		$result = $this->metadataHandler->processTwigLikeTemplate(
			$data,
			'{{ tags }}',
			['tags' => ['type' => 'array', 'items' => ['type' => 'string']]]
		);
		// With schemaProperties provided but 'tags' not a relation, resolveRelationValue
		// returns null for arrays. The template replaces it with empty string.
		// This is expected behavior — arrays are not meaningful as template values
		// unless they are relation fields.
		$this->assertNull($result);
	}

	public function testCreateSlug(): void {
		$this->assertEquals('hello-world', $this->metadataHandler->createSlug('Hello World'));
		$this->assertEquals('test-name', $this->metadataHandler->createSlug('Test_Name'));
		$this->assertEquals('special-chars', $this->metadataHandler->createSlug('Special !@#$% Chars'));
		$this->assertEquals('multiple-hyphens', $this->metadataHandler->createSlug('Multiple---Hyphens'));
		$this->assertEquals('trimmed', $this->metadataHandler->createSlug('--trimmed--'));
	}

	public function testCreateSlugFromValue(): void {
		$this->assertEquals('hello-world', $this->metadataHandler->createSlugFromValue('Hello World'));
		$this->assertNull($this->metadataHandler->createSlugFromValue(''));
		$this->assertNull($this->metadataHandler->createSlugFromValue('   '));
	}

	public function testGenerateSlugFromSlugFromConfig(): void {
		$schema = new Schema();
		$schema->setTitle('Test Schema');
		$schema->setProperties([
			'_slugFrom' => 'title',
			'title' => ['type' => 'string'],
		]);

		$data = ['title' => 'My Article Title'];
		$result = $this->metadataHandler->generateSlug($data, $schema);
		$this->assertEquals('my-article-title', $result);
	}

	public function testGenerateSlugFromTitleFieldConfig(): void {
		$schema = new Schema();
		$schema->setTitle('Test Schema');
		$schema->setProperties([
			'_titleField' => 'label',
			'label' => ['type' => 'string'],
		]);

		$data = ['label' => 'Custom Label'];
		$result = $this->metadataHandler->generateSlug($data, $schema);
		$this->assertEquals('custom-label', $result);
	}

	public function testGenerateSlugFromCommonFields(): void {
		$schema = new Schema();
		$schema->setTitle('Test Schema');
		$schema->setProperties([]);

		$data = ['name' => 'Common Name'];
		$result = $this->metadataHandler->generateSlug($data, $schema);
		$this->assertEquals('common-name', $result);
	}

	public function testGenerateSlugFallbackToSchemaName(): void {
		$schema = new Schema();
		$schema->setTitle('My Schema Title');
		$schema->setProperties([]);

		$data = ['randomField' => 'value'];
		$result = $this->metadataHandler->generateSlug($data, $schema);
		$this->assertEquals('my-schema-title', $result);
	}

	public function testHydrateObjectMetadataWithConfiguredFields(): void {
		$entity = new ObjectEntity();
		$entity->setObject([
			'naam' => 'Test Entity Name',
			'beschrijvingLang' => 'A long description',
			'beschrijvingKort' => 'Short summary',
		]);

		$schema = new Schema();
		$schema->setProperties([]);
		$schema->setConfiguration([]);

		$this->metadataHandler->hydrateObjectMetadata($entity, $schema);

		$this->assertEquals('Test Entity Name', $entity->getName());
		$this->assertEquals('A long description', $entity->getDescription());
		$this->assertEquals('Short summary', $entity->getSummary());
	}

	public function testHydrateObjectMetadataWithCustomNameField(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['custom_title' => 'Custom Title Value']);

		$schema = new Schema();
		$schema->setProperties([]);
		$schema->setConfiguration(['objectNameField' => 'custom_title']);

		$this->metadataHandler->hydrateObjectMetadata($entity, $schema);
		$this->assertEquals('Custom Title Value', $entity->getName());
	}

	public function testHydrateObjectMetadataWithSlugField(): void {
		// Note: Schema::setConfiguration() validates keys via a whitelist.
		// 'objectSlugField' is not in the validated keys, so we set configuration
		// directly via reflection to test the slug hydration path.
		$entity = new ObjectEntity();
		$entity->setObject(['naam' => 'Test', 'slug_source' => 'My Custom Slug']);

		$schema = new Schema();
		$schema->setProperties([]);

		// Set configuration directly to bypass validation that drops unknown keys.
		$refProp = new \ReflectionProperty(Schema::class, 'configuration');
		$refProp->setAccessible(true);
		$refProp->setValue($schema, ['objectSlugField' => 'slug_source']);

		$this->metadataHandler->hydrateObjectMetadata($entity, $schema);
		$this->assertEquals('my-custom-slug', $entity->getSlug());
	}

	public function testHydrateObjectMetadataWithDescriptionField(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['naam' => 'Test', 'bio' => 'My biography']);

		$schema = new Schema();
		$schema->setProperties([]);
		$schema->setConfiguration(['objectDescriptionField' => 'bio']);

		$this->metadataHandler->hydrateObjectMetadata($entity, $schema);
		$this->assertEquals('My biography', $entity->getDescription());
	}

	public function testHydrateObjectMetadataWithSummaryField(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['naam' => 'Test', 'intro' => 'Short intro']);

		$schema = new Schema();
		$schema->setProperties([]);
		$schema->setConfiguration(['objectSummaryField' => 'intro']);

		$this->metadataHandler->hydrateObjectMetadata($entity, $schema);
		$this->assertEquals('Short intro', $entity->getSummary());
	}

	public function testHydrateObjectMetadataFallbackFields(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['title' => 'Title Value', 'description' => 'Desc Value']);

		$schema = new Schema();
		$schema->setProperties([]);
		$schema->setConfiguration([]);

		$this->metadataHandler->hydrateObjectMetadata($entity, $schema);
		$this->assertEquals('Title Value', $entity->getName());
		$this->assertEquals('Desc Value', $entity->getDescription());
	}

	public function testHydrateObjectMetadataNestedObjectKey(): void {
		// When object data has a nested 'object' key that is an array.
		$entity = new ObjectEntity();
		$entity->setObject([
			'object' => [
				'naam' => 'Nested Name',
				'description' => 'Nested Desc',
			],
		]);

		$schema = new Schema();
		$schema->setProperties([]);
		$schema->setConfiguration([]);

		$this->metadataHandler->hydrateObjectMetadata($entity, $schema);
		$this->assertEquals('Nested Name', $entity->getName());
	}

	// -----------------------------------------------------------------------
	// FilePropertyHandler tests
	// -----------------------------------------------------------------------

	public function testIsFilePropertyDataUri(): void {
		$this->assertTrue(
			$this->fileHandler->isFileProperty('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB')
		);
	}

	public function testIsFilePropertyUrlWithFileExtension(): void {
		$this->assertTrue(
			$this->fileHandler->isFileProperty('https://example.com/files/document.pdf')
		);
	}

	public function testIsFilePropertyUrlWithoutExtension(): void {
		$this->assertFalse(
			$this->fileHandler->isFileProperty('https://example.com/page')
		);
	}

	public function testIsFilePropertyBase64Long(): void {
		// Generate a valid base64 string > 100 chars.
		$longBase64 = base64_encode(str_repeat('A', 200));
		$this->assertTrue($this->fileHandler->isFileProperty($longBase64));
	}

	public function testIsFilePropertyPlainStringNotFile(): void {
		$this->assertFalse($this->fileHandler->isFileProperty('just a normal string'));
	}

	public function testIsFilePropertyNullValue(): void {
		$this->assertFalse($this->fileHandler->isFileProperty(null));
	}

	public function testIsFilePropertyIntValue(): void {
		$this->assertFalse($this->fileHandler->isFileProperty(42));
	}

	public function testIsFilePropertySchemaBasedFileType(): void {
		$schema = new Schema();
		$schema->setProperties([
			'photo' => ['type' => 'file'],
		]);

		$this->assertTrue($this->fileHandler->isFileProperty('any-value', $schema, 'photo'));
	}

	public function testIsFilePropertySchemaBasedArrayFileType(): void {
		$schema = new Schema();
		$schema->setProperties([
			'photos' => [
				'type' => 'array',
				'items' => ['type' => 'file'],
			],
		]);

		$this->assertTrue($this->fileHandler->isFileProperty('any-value', $schema, 'photos'));
	}

	public function testIsFilePropertySchemaBasedNonFileType(): void {
		$schema = new Schema();
		$schema->setProperties([
			'name' => ['type' => 'string'],
		]);

		$this->assertFalse($this->fileHandler->isFileProperty('any-value', $schema, 'name'));
	}

	public function testIsFilePropertySchemaBasedMissingProperty(): void {
		$schema = new Schema();
		$schema->setProperties([
			'name' => ['type' => 'string'],
		]);

		$this->assertFalse($this->fileHandler->isFileProperty('any-value', $schema, 'nonexistent'));
	}

	public function testIsFilePropertyFileObject(): void {
		$fileObj = ['id' => 123, 'title' => 'test.pdf', 'path' => '/files/test.pdf'];
		$this->assertTrue($this->fileHandler->isFileProperty($fileObj));
	}

	public function testIsFilePropertyArrayOfDataUris(): void {
		$arr = ['data:image/png;base64,abc123'];
		$this->assertTrue($this->fileHandler->isFileProperty($arr));
	}

	public function testIsFilePropertyArrayOfUrls(): void {
		$arr = ['https://example.com/files/doc.pdf'];
		$this->assertTrue($this->fileHandler->isFileProperty($arr));
	}

	public function testIsFilePropertyArrayOfBase64(): void {
		$longBase64 = base64_encode(str_repeat('B', 200));
		$arr = [$longBase64];
		$this->assertTrue($this->fileHandler->isFileProperty($arr));
	}

	public function testIsFilePropertyArrayOfFileObjects(): void {
		$arr = [['id' => 1, 'title' => 'file1.pdf', 'path' => '/a']];
		$this->assertTrue($this->fileHandler->isFileProperty($arr));
	}

	public function testIsFileObjectValid(): void {
		$this->assertTrue($this->fileHandler->isFileObject([
			'id' => 1, 'title' => 'test.pdf', 'size' => 1024,
		]));
	}

	public function testIsFileObjectMissingId(): void {
		$this->assertFalse($this->fileHandler->isFileObject([
			'title' => 'test.pdf', 'size' => 1024,
		]));
	}

	public function testIsFileObjectMissingTitleAndPath(): void {
		$this->assertFalse($this->fileHandler->isFileObject([
			'id' => 1, 'size' => 1024,
		]));
	}

	public function testIsFileObjectWithPath(): void {
		$this->assertTrue($this->fileHandler->isFileObject([
			'id' => 1, 'path' => '/files/doc.pdf', 'extension' => 'pdf',
		]));
	}

	public function testParseFileDataFromDataUri(): void {
		$content = 'Hello World';
		$b64 = base64_encode($content);
		$dataUri = "data:text/plain;base64,$b64";

		$result = $this->fileHandler->parseFileData($dataUri);
		$this->assertEquals($content, $result['content']);
		$this->assertEquals('text/plain', $result['mimeType']);
		$this->assertEquals('txt', $result['extension']);
		$this->assertEquals(strlen($content), $result['size']);
	}

	public function testParseFileDataFromBase64(): void {
		$content = 'Test content for base64';
		$b64 = base64_encode($content);

		$result = $this->fileHandler->parseFileData($b64);
		$this->assertEquals($content, $result['content']);
		$this->assertNotEmpty($result['mimeType']);
		$this->assertEquals(strlen($content), $result['size']);
	}

	public function testParseFileDataInvalidBase64Throws(): void {
		$this->expectException(\Exception::class);
		$this->fileHandler->parseFileData('not-valid-base64!!!');
	}

	public function testParseFileDataInvalidDataUriThrows(): void {
		$this->expectException(\Exception::class);
		$this->fileHandler->parseFileData('data:invalid-format-no-base64');
	}

	public function testValidateFileAgainstConfigPassesWithNoRestrictions(): void {
		$fileData = [
			'content' => 'test content',
			'mimeType' => 'text/plain',
			'extension' => 'txt',
			'size' => 12,
		];

		// Should not throw.
		$this->fileHandler->validateFileAgainstConfig($fileData, ['type' => 'file'], 'testProp');
		$this->assertTrue(true); // Passed without exception.
	}

	public function testValidateFileAgainstConfigBlocksMimeType(): void {
		$fileData = [
			'content' => 'test',
			'mimeType' => 'application/pdf',
			'extension' => 'pdf',
			'size' => 4,
		];

		$config = [
			'type' => 'file',
			'allowedTypes' => ['image/png', 'image/jpeg'],
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('invalid type');
		$this->fileHandler->validateFileAgainstConfig($fileData, $config, 'photo');
	}

	public function testValidateFileAgainstConfigBlocksOversize(): void {
		$fileData = [
			'content' => str_repeat('x', 1000),
			'mimeType' => 'text/plain',
			'extension' => 'txt',
			'size' => 1000,
		];

		$config = [
			'type' => 'file',
			'maxSize' => 500,
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('exceeds maximum size');
		$this->fileHandler->validateFileAgainstConfig($fileData, $config, 'doc');
	}

	public function testValidateFileAgainstConfigWithIndex(): void {
		$fileData = [
			'content' => str_repeat('x', 1000),
			'mimeType' => 'text/plain',
			'extension' => 'txt',
			'size' => 1000,
		];

		$config = ['type' => 'file', 'maxSize' => 500];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('testProp[2]');
		$this->fileHandler->validateFileAgainstConfig($fileData, $config, 'testProp', 2);
	}

	public function testBlockExecutableFilesByExtension(): void {
		$fileData = [
			'content' => 'harmless content',
			'mimeType' => 'application/octet-stream',
			'filename' => 'malware.exe',
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('executable file');
		$this->fileHandler->blockExecutableFiles($fileData, 'File at test');
	}

	public function testBlockExecutableFilesByMagicBytes(): void {
		$fileData = [
			'content' => "<?php echo 'hacked'; ?>",
			'mimeType' => 'text/plain',
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('PHP');
		$this->fileHandler->blockExecutableFiles($fileData, 'File at test');
	}

	public function testBlockExecutableFilesByMimeType(): void {
		$fileData = [
			'content' => 'binary data',
			'mimeType' => 'application/x-executable',
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('executable MIME type');
		$this->fileHandler->blockExecutableFiles($fileData, 'File at test');
	}

	public function testBlockExecutableFilesSafeFilePassesThrough(): void {
		$fileData = [
			'content' => 'just a text file',
			'mimeType' => 'text/plain',
			'filename' => 'readme.txt',
		];

		// Should not throw.
		$this->fileHandler->blockExecutableFiles($fileData, 'File at test');
		$this->assertTrue(true);
	}

	public function testBlockExecutableFilesShellScript(): void {
		$fileData = [
			'content' => "#!/bin/bash\nrm -rf /",
			'mimeType' => 'text/plain',
		];

		$this->expectException(\Exception::class);
		$this->fileHandler->blockExecutableFiles($fileData, 'File at test');
	}

	public function testBlockExecutableFilesElfBinary(): void {
		$fileData = [
			'content' => "\x7FELF" . str_repeat("\x00", 100),
			'mimeType' => 'application/octet-stream',
		];

		$this->expectException(\Exception::class);
		$this->fileHandler->blockExecutableFiles($fileData, 'File at test');
	}

	public function testBlockExecutableFilesShebangInContent(): void {
		$fileData = [
			'content' => "#!/usr/bin/python\nimport os\nos.system('rm -rf /')",
			'mimeType' => 'text/plain',
		];

		$this->expectException(\Exception::class);
		$this->fileHandler->blockExecutableFiles($fileData, 'File at test');
	}

	public function testProcessUploadedFilesSkipsErrors(): void {
		$uploaded = [
			'broken' => [
				'name' => 'test.txt',
				'type' => 'text/plain',
				'tmp_name' => '/tmp/nonexistent',
				'error' => UPLOAD_ERR_NO_FILE,
				'size' => 0,
			],
		];

		$data = ['existing' => 'value'];
		$result = $this->fileHandler->processUploadedFiles($uploaded, $data);
		// Should keep existing data and skip the broken upload.
		$this->assertEquals('value', $result['existing']);
		$this->assertArrayNotHasKey('broken', $result);
	}

	public function testIsFilePropertyUrlWithImageExtension(): void {
		$this->assertTrue(
			$this->fileHandler->isFileProperty('https://cdn.example.com/photo.jpg')
		);
	}

	public function testIsFilePropertyUrlWithDocxExtension(): void {
		$this->assertTrue(
			$this->fileHandler->isFileProperty('https://example.com/report.docx')
		);
	}

	public function testIsFilePropertyArrayWithUrlInside(): void {
		$arr = ['https://example.com/image.png'];
		$this->assertTrue($this->fileHandler->isFileProperty($arr));
	}

	public function testIsFilePropertyArrayWithNonFiles(): void {
		$arr = ['just a string', 'another string'];
		$this->assertFalse($this->fileHandler->isFileProperty($arr));
	}

	public function testBlockExecutableFilesPhpTag(): void {
		$fileData = [
			'content' => "Some text\n<?= 'injected' ?>",
			'mimeType' => 'text/html',
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('PHP');
		$this->fileHandler->blockExecutableFiles($fileData, 'File at test');
	}

	public function testValidateFileAgainstConfigAllowsCorrectMimeType(): void {
		$fileData = [
			'content' => 'png data',
			'mimeType' => 'image/png',
			'extension' => 'png',
			'size' => 8,
		];

		$config = [
			'type' => 'file',
			'allowedTypes' => ['image/png', 'image/jpeg'],
		];

		$this->fileHandler->validateFileAgainstConfig($fileData, $config, 'photo');
		$this->assertTrue(true);
	}

	public function testValidateFileAgainstConfigAllowsWithinSizeLimit(): void {
		$fileData = [
			'content' => str_repeat('x', 100),
			'mimeType' => 'text/plain',
			'extension' => 'txt',
			'size' => 100,
		];

		$config = ['type' => 'file', 'maxSize' => 500];

		$this->fileHandler->validateFileAgainstConfig($fileData, $config, 'doc');
		$this->assertTrue(true);
	}
}
