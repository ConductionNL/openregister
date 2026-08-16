<?php

/**
 * One rejected schema must not detach the register from every schema before it.
 *
 * Locks the defect found while diagnosing why softwarecatalog's E2E job ran ZERO
 * tests for two days: `importFromJson()`'s Pass-1 loop blanks `$this->schemasMap`
 * before every `importSchema()` call and restores it on the SUCCESS path only.
 * When a schema is rejected the `catch` leaves the map empty, so every schema
 * imported BEFORE the failure is evicted from it. The register pass then resolves
 * its schema slugs against that map, finds nothing, and creates the register
 * WITHOUT those links — a fully detached register from a single bad schema.
 *
 * The fingerprint is arithmetic, which is what makes it provable: in
 * softwarecatalog 2 rejected schemas produced 18 missing register<->schema links,
 * and the survivors were exactly the schemas declared AFTER the last failing one.
 * These tests assert that arithmetic, with the boundary at the last failure.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Exception;
use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for the schemasMap save/restore around importSchema().
 */
class ImportHandlerSchemaMapRestoreTest extends TestCase {

	/**
	 * Total schemas the fixture configuration declares.
	 *
	 * @var integer
	 */
	private const TOTAL_SCHEMAS = 20;

	/**
	 * 1-based positions of the two schemas the mapper rejects.
	 *
	 * Mirrors softwarecatalog: two bad `objectDescriptionField` values, the
	 * later of which sits near the end of the declaration order so that the
	 * amplification is unmistakable.
	 *
	 * @var integer[]
	 */
	private const FAILING_POSITIONS = [7, 18];

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private MagicMapper&MockObject $objectEntityMapper;

	private ConfigurationMapper&MockObject $configurationMapper;

	private MappingMapper&MockObject $mappingMapper;

	private Client&MockObject $client;

	private IAppConfig&MockObject $appConfig;

	private LoggerInterface&MockObject $logger;

	private UploadHandler&MockObject $uploadHandler;

	private ObjectService&MockObject $objectService;

	private ImportHandler $handler;

	/**
	 * Schema slugs the register was actually linked to, captured from the
	 * register the mapper was asked to persist.
	 *
	 * @var string[]
	 */
	private array $linkedSchemaIds = [];

	/**
	 * Next schema id to hand out, so every created schema has a distinct id.
	 *
	 * @var integer
	 */
	private int $nextSchemaId = 100;

	/**
	 * Schema id => slug, so a captured register id list can be read as slugs.
	 *
	 * @var array<int, string>
	 */
	private array $slugForSchemaId = [];

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->objectEntityMapper = $this->createMock(MagicMapper::class);
		$this->configurationMapper = $this->createMock(ConfigurationMapper::class);
		$this->mappingMapper = $this->createMock(MappingMapper::class);
		$this->client = $this->createMock(Client::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->uploadHandler = $this->createMock(UploadHandler::class);
		$this->objectService = $this->createMock(ObjectService::class);

		// No previously-imported version -> never skip on the version check.
		$this->appConfig->method('getValueString')->willReturn('');
		$this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
		$this->registerMapper->method('getSlugToIdMap')->willReturn([]);
		$this->mappingMapper->method('getSlugToIdMap')->willReturn([]);

		// Nothing exists yet: a fresh instance importing an app's configuration.
		$this->registerMapper->method('find')
			->willThrowException(new DoesNotExistException('not found'));
		$this->schemaMapper->method('find')
			->willThrowException(new DoesNotExistException('not found'));
		$this->schemaMapper->method('findBySlugInIds')->willReturn(null);
		$this->schemaMapper->method('findByApplicationAndSlug')->willReturn(null);

		// The rejection mechanism is the REAL one: SchemaMapper::createFromArray()
		// runs validateConfigurationFields(), which throws when
		// `configuration.objectDescriptionField` names a property the schema does
		// not declare. This callback mirrors that rule and its exact message.
		$this->schemaMapper->method('createFromArray')
			->willReturnCallback(function (array $data): Schema {
				$this->assertConfigurationFields($data);
				return $this->makeSchema($data['slug']);
			});
		$this->schemaMapper->method('updateFromArray')
			->willReturnCallback(function (int $id, array $data): Schema {
				$this->assertConfigurationFields($data);
				return $this->makeSchema($data['slug'], $id);
			});
		$this->schemaMapper->method('update')->willReturnArgument(0);

		// Capture the resolved schema id list the register is created with.
		$this->registerMapper->method('createFromArray')
			->willReturnCallback(function (array $data): Register {
				$this->linkedSchemaIds = ($data['schemas'] ?? []);
				$register = new Register();
				$register->hydrate(['slug' => $data['slug'], 'version' => '1.0.0']);
				$register->setId(1);
				return $register;
			});
		$this->registerMapper->method('update')->willReturnArgument(0);

		$this->handler = new ImportHandler(
			schemaMapper:        $this->schemaMapper,
			registerMapper:      $this->registerMapper,
			objectEntityMapper:  $this->objectEntityMapper,
			configurationMapper: $this->configurationMapper,
			mappingMapper:       $this->mappingMapper,
			client:              $this->client,
			appConfig:           $this->appConfig,
			logger:              $this->logger,
			appDataPath:         '/tmp',
			uploadHandler:       $this->uploadHandler,
			objectService:       $this->objectService
		);
	}//end setUp()

	/**
	 * Transcription of SchemaMapper::validateConfigurationFields() for the
	 * simple (non-template, non-pipe) case the fixture uses.
	 *
	 * @param array $data The schema payload handed to the mapper.
	 *
	 * @throws Exception When objectDescriptionField names an absent property.
	 *
	 * @return void
	 */
	private function assertConfigurationFields(array $data): void {
		$field = ($data['configuration']['objectDescriptionField'] ?? '');
		if ($field === '') {
			return;
		}

		$propertyKeys = array_keys($data['properties'] ?? []);
		if (in_array($field, $propertyKeys, true) === false) {
			throw new Exception(
				"The value for objectDescriptionField ('{$field}') does not exist as a property in the schema."
			);
		}
	}//end assertConfigurationFields()

	/**
	 * Build a hydrated Schema entity, remembering its id -> slug mapping.
	 *
	 * @param string       $slug The schema slug.
	 * @param integer|null $id   An explicit id, or null to allocate one.
	 *
	 * @return Schema
	 */
	private function makeSchema(string $slug, ?int $id = null): Schema {
		if ($id === null) {
			$id = $this->nextSchemaId;
			$this->nextSchemaId++;
		}

		$schema = new Schema();
		$schema->hydrate(['slug' => $slug, 'title' => $slug, 'version' => '1.0.0', 'properties' => []]);
		$schema->setId($id);
		$this->slugForSchemaId[$id] = $slug;
		return $schema;
	}//end makeSchema()

	/**
	 * The fixture: 20 schemas in declaration order, two of which carry an
	 * `objectDescriptionField` naming a property they do not declare, plus one
	 * register declaring all 20 slugs.
	 *
	 * @return array
	 */
	private function configuration(): array {
		$schemas = [];
		$slugs = [];
		for ($position = 1; $position <= self::TOTAL_SCHEMAS; $position++) {
			$slug = sprintf('schema%02d', $position);
			$slugs[] = $slug;
			$schemas[$slug] = [
				'slug' => $slug,
				'title' => 'Schema ' . $position,
				'version' => '1.0.0',
				'properties' => ['name' => ['type' => 'string']],
			];

			if (in_array($position, self::FAILING_POSITIONS, true) === true) {
				// `summary` is not among this schema's properties — the exact
				// shape that rejected softwarecatalog's `view` and `bioMeasure`.
				$schemas[$slug]['configuration'] = ['objectDescriptionField' => 'summary'];
			}
		}

		return [
			'appId' => 'softwarecatalog',
			'version' => '9.9.9',
			'components' => [
				'schemas' => $schemas,
				'registers' => [
					'catalog' => ['slug' => 'catalog', 'version' => '1.0.0', 'schemas' => $slugs],
				],
			],
		];
	}//end configuration()

	/**
	 * Slugs of the schemas the register ended up linked to.
	 *
	 * @return string[]
	 */
	private function linkedSlugs(): array {
		$slugs = [];
		foreach ($this->linkedSchemaIds as $id) {
			$slugs[] = ($this->slugForSchemaId[(int)$id] ?? ('unknown-' . $id));
		}

		sort($slugs);
		return $slugs;
	}//end linkedSlugs()

	/**
	 * THE ARITHMETIC. 2 rejected schemas must cost exactly 2 register links —
	 * not 18.
	 *
	 * Before the fix the register is linked to `schema19` + `schema20` alone:
	 * the survivors are exactly the schemas declared after the LAST failure, and
	 * the 16 perfectly-valid schemas declared before it are collateral.
	 *
	 * @return void
	 */
	public function testTwoRejectedSchemasDetachOnlyThemselvesFromTheRegister(): void {
		$config = new Configuration();
		$result = $this->handler->importFromJson(
			data: $this->configuration(),
			configuration: $config,
			appId: 'softwarecatalog',
			version: '9.9.9'
		);

		$rejectedCount = count(self::FAILING_POSITIONS);
		$expectedLinks = (self::TOTAL_SCHEMAS - $rejectedCount);

		$this->assertCount(
			$expectedLinks,
			$this->linkedSchemaIds,
			sprintf(
				'%d rejected schemas must cost %d register links, not %d — every schema declared '
				. 'before the last failure was evicted from schemasMap by the unrestored catch.',
				$rejectedCount,
				$rejectedCount,
				(self::TOTAL_SCHEMAS - count($this->linkedSchemaIds))
			)
		);

		// And name them, so a right count for a wrong reason still fails.
		$expectedSlugs = [];
		for ($position = 1; $position <= self::TOTAL_SCHEMAS; $position++) {
			if (in_array($position, self::FAILING_POSITIONS, true) === true) {
				continue;
			}

			$expectedSlugs[] = sprintf('schema%02d', $position);
		}

		sort($expectedSlugs);
		$this->assertSame($expectedSlugs, $this->linkedSlugs(), 'exactly the valid schemas must be linked');

		// The schemas the import reports as created must match the same set.
		$this->assertCount($expectedLinks, $result['schemas'], 'created-schema list must exclude only the rejected two');
	}//end testTwoRejectedSchemasDetachOnlyThemselvesFromTheRegister()

	/**
	 * The boundary is the LAST failure, which is what identifies the mechanism
	 * rather than merely detecting a wrong number. With a single rejection at
	 * position 7, the buggy code links 13 schemas (positions 8..20); the correct
	 * code links 19.
	 *
	 * @return void
	 */
	public function testASingleRejectionDoesNotEvictTheSchemasDeclaredBeforeIt(): void {
		$data = $this->configuration();
		// Drop the second failure, keeping only the one at position 7.
		unset($data['components']['schemas']['schema18']['configuration']);

		$config = new Configuration();
		$this->handler->importFromJson(
			data: $data,
			configuration: $config,
			appId: 'softwarecatalog',
			version: '9.9.9'
		);

		$this->assertCount(
			(self::TOTAL_SCHEMAS - 1),
			$this->linkedSchemaIds,
			'one rejected schema must cost exactly one register link'
		);
		$this->assertContains(
			'schema01',
			$this->linkedSlugs(),
			'a schema imported BEFORE the failure must still be linked — this is the eviction'
		);
	}//end testASingleRejectionDoesNotEvictTheSchemasDeclaredBeforeIt()

	/**
	 * A rejected schema must be VISIBLE to the operator in the import result.
	 *
	 * The silent partial import is the actual defect: softwarecatalog's two bad
	 * schemas were only discovered three layers downstream, in a seed step, via
	 * empty `*_schema` app-config keys. `skipped.registers`, `skipped.objects`,
	 * `skipped.mappings` and `skipped.seedObjects` all existed; `skipped.schemas`
	 * did not, so the one failure that amplifies was the one nothing counted.
	 *
	 * @return void
	 */
	public function testRejectedSchemasAreReportedInTheImportResult(): void {
		$config = new Configuration();
		$result = $this->handler->importFromJson(
			data: $this->configuration(),
			configuration: $config,
			appId: 'softwarecatalog',
			version: '9.9.9'
		);

		$this->assertArrayHasKey('schemas', $result['skipped'], 'the result must count skipped schemas at all');
		$this->assertSame(
			count(self::FAILING_POSITIONS),
			$result['skipped']['schemas'],
			'both rejected schemas must be counted as skipped'
		);

		$this->assertArrayHasKey('failed', $result, 'the result must name what it could not import');
		$this->assertArrayHasKey('schemas', $result['failed']);
		$this->assertSame(
			['schema07', 'schema18'],
			array_column($result['failed']['schemas'], 'slug'),
			'the failed list must name the rejected schemas in declaration order'
		);
		$this->assertStringContainsString(
			"objectDescriptionField ('summary')",
			(string)($result['failed']['schemas'][0]['error'] ?? ''),
			'the operator must get the mapper\'s own reason, not just a count'
		);
	}//end testRejectedSchemasAreReportedInTheImportResult()

	/**
	 * Positive control: with no rejected schema, all 20 link and nothing is
	 * reported as skipped or failed. Without this, a fix that linked everything
	 * unconditionally would pass the tests above.
	 *
	 * @return void
	 */
	public function testACleanImportLinksEverySchemaAndReportsNoFailures(): void {
		$data = $this->configuration();
		foreach (self::FAILING_POSITIONS as $position) {
			unset($data['components']['schemas'][sprintf('schema%02d', $position)]['configuration']);
		}

		$config = new Configuration();
		$result = $this->handler->importFromJson(
			data: $data,
			configuration: $config,
			appId: 'softwarecatalog',
			version: '9.9.9'
		);

		$this->assertCount(self::TOTAL_SCHEMAS, $this->linkedSchemaIds, 'a clean import links every schema');
		$this->assertSame(0, $result['skipped']['schemas']);
		$this->assertSame([], $result['failed']['schemas']);
	}//end testACleanImportLinksEverySchemaAndReportsNoFailures()
}//end class
