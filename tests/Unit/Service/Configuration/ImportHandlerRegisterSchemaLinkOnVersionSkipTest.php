<?php

/**
 * Unit tests for WOO-563: a register whose own version is not newer must still
 * adopt the schema ids the same import just created.
 *
 * The bug this pins: `ImportHandler::importRegister()` returned on the version
 * gate before attaching `$data['schemas']`, so schemas created by the schema
 * pass stayed orphaned. `computeRegisterScopedSchemaIds()` then built the next
 * import's candidate set from that stale list, `findBySlugInIds()` could not
 * see the app's own row, and the register-scoped resolution branch created a
 * TWIN schema instead of updating in place — once per import, forever. One
 * opencatalogi install reached 92 schemas with 25 slugs duplicated and
 * page/menu/glossary present three times over, each copy identical but for its
 * uuid.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

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
use ReflectionClass;

/**
 * The fake persistence layer keeps the SAME object identities across several
 * `importFromJson()` calls, which is the only way to observe behaviour that
 * only appears on a RE-import. Stateless mocks cannot express it.
 */
class ImportHandlerRegisterSchemaLinkOnVersionSkipTest extends TestCase {

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	/** @var RegisterMapper&MockObject */
	private RegisterMapper $registerMapper;

	private ImportHandler $handler;

	/** @var array<int, Schema> id => Schema, the fake schemas "table". */
	private array $schemaStore = [];

	/** @var array<string, Register> slug(lower) => Register, the fake registers "table". */
	private array $registerStore = [];

	private int $nextSchemaId = 100;

	private int $nextRegisterId = 200;

	/** Number of RegisterMapper::update() calls, to prove a no-op stays a no-op. */
	private int $registerUpdateCalls = 0;

	/**
	 * Wire an ImportHandler over in-memory schema/register "tables" that
	 * behave like the queries this code path relies on.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);

		$objectEntityMapper = $this->createMock(MagicMapper::class);
		$configurationMapper = $this->createMock(ConfigurationMapper::class);
		$mappingMapper = $this->createMock(MappingMapper::class);
		$client = $this->createMock(Client::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);
		$uploadHandler = $this->createMock(UploadHandler::class);
		$objectService = $this->createMock(ObjectService::class);

		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('setValueString')->willReturn(true);
		$mappingMapper->method('getSlugToIdMap')->willReturn([]);

		// --- SchemaMapper fake -------------------------------------------------

		$this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

		$this->schemaMapper->method('findBySlugInIds')->willReturnCallback(
			function (string $slug, array $schemaIds): ?Schema {
				foreach ($schemaIds as $id) {
					$candidate = ($this->schemaStore[$id] ?? null);
					if ($candidate !== null && strtolower((string)$candidate->getSlug()) === strtolower($slug)) {
						return $candidate;
					}
				}

				return null;
			}
		);

		$this->schemaMapper->method('findByApplicationAndSlug')->willReturnCallback(
			function (string $slug, string $application): ?Schema {
				foreach ($this->schemaStore as $candidate) {
					if (strtolower((string)$candidate->getSlug()) === strtolower($slug)
						&& (string)$candidate->getApplication() === $application
					) {
						return $candidate;
					}
				}

				return null;
			}
		);

		$this->schemaMapper->method('find')->willReturnCallback(
			function (string $id): Schema {
				foreach ($this->schemaStore as $candidate) {
					if (strtolower((string)$candidate->getSlug()) === strtolower($id)) {
						return $candidate;
					}
				}

				throw new DoesNotExistException('schema not found: ' . $id);
			}
		);

		$this->schemaMapper->method('createFromArray')->willReturnCallback(
			function (array $data): Schema {
				$id = $this->nextSchemaId++;
				$schema = new Schema();
				$schema->setSlug($data['slug']);
				$schema->setTitle($data['title'] ?? $data['slug']);
				$schema->setVersion($data['version'] ?? '1.0.0');
				$schema->setProperties($data['properties'] ?? []);
				$this->setEntityId($schema, $id);
				$this->schemaStore[$id] = $schema;
				return $schema;
			}
		);

		$this->schemaMapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $data): Schema {
				$schema = $this->schemaStore[$id];
				if (isset($data['version']) === true) {
					$schema->setVersion($data['version']);
				}

				if (isset($data['properties']) === true) {
					$schema->setProperties($data['properties']);
				}

				return $schema;
			}
		);

		$this->schemaMapper->method('update')->willReturnArgument(0);

		// --- RegisterMapper fake -----------------------------------------------

		$this->registerMapper->method('find')->willReturnCallback(
			function (string $id): Register {
				$existing = ($this->registerStore[strtolower($id)] ?? null);
				if ($existing !== null) {
					return $existing;
				}

				throw new DoesNotExistException('register not found: ' . $id);
			}
		);

		$this->registerMapper->method('createFromArray')->willReturnCallback(
			function (array $data): Register {
				$id = $this->nextRegisterId++;
				$register = new Register();
				$register->setSlug($data['slug']);
				$register->setVersion($data['version'] ?? '1.0.0');
				$register->setSchemas($data['schemas'] ?? []);
				$this->setEntityId($register, $id);
				$this->registerStore[strtolower($data['slug'])] = $register;
				return $register;
			}
		);

		$this->registerMapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $data): Register {
				foreach ($this->registerStore as $register) {
					if ($register->getId() === $id) {
						if (isset($data['schemas']) === true) {
							$register->setSchemas($data['schemas']);
						}

						if (isset($data['version']) === true) {
							$register->setVersion($data['version']);
						}

						return $register;
					}
				}

				throw new DoesNotExistException('register id not found: ' . $id);
			}
		);

		$this->registerMapper->method('update')->willReturnCallback(
			function (Register $register): Register {
				$this->registerUpdateCalls++;
				return $register;
			}
		);

		$this->handler = new ImportHandler(
			schemaMapper:        $this->schemaMapper,
			registerMapper:      $this->registerMapper,
			objectEntityMapper:  $objectEntityMapper,
			configurationMapper: $configurationMapper,
			mappingMapper:       $mappingMapper,
			client:              $client,
			appConfig:           $appConfig,
			logger:              $logger,
			appDataPath:         '/tmp',
			uploadHandler:       $uploadHandler,
			objectService:       $objectService
		);

	}//end setUp()

	/**
	 * Set the integer id on an Entity instance via reflection.
	 *
	 * @param object $entity The entity to stamp.
	 * @param int    $id     The id to set.
	 */
	private function setEntityId(object $entity, int $id): void {
		$ref = new ReflectionClass($entity);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($entity, $id);

	}//end setEntityId()

	/**
	 * Build a minimal Configuration entity.
	 *
	 * @param int    $id  The configuration id.
	 * @param string $app The owning app id.
	 *
	 * @return Configuration The entity.
	 */
	private function makeConfiguration(int $id, string $app = 'opencatalogi'): Configuration {
		$config = new Configuration();
		$config->setApp($app);
		$config->setRegisters([]);
		$config->setSchemas([]);
		$config->setObjects([]);
		$this->setEntityId($config, $id);
		return $config;
	}//end makeConfiguration()

	/**
	 * Build a one-register payload declaring the given schema slugs.
	 *
	 * The register version is passed separately from the schema version so a
	 * test can hold the register at a version the gate will skip while the
	 * schema set grows — exactly what a `register.d` fragment does.
	 *
	 * @param string        $registerSlug    The register slug.
	 * @param string        $registerVersion The register's declared version.
	 * @param array<string> $schemaSlugs     The schema slugs the register declares.
	 * @param string        $schemaVersion   The version every schema is declared at.
	 *
	 * @return array The import payload.
	 */
	private function makePayload(
		string $registerSlug,
		string $registerVersion,
		array $schemaSlugs,
		string $schemaVersion = '1.0.0',
	): array {
		$schemas = [];
		foreach ($schemaSlugs as $schemaSlug) {
			$schemas[$schemaSlug] = [
				'slug' => $schemaSlug,
				'title' => ucfirst($schemaSlug),
				'version' => $schemaVersion,
				'properties' => ['name' => ['type' => 'string', 'title' => 'Name']],
			];
		}

		return [
			'appId' => 'opencatalogi',
			'version' => '1.0.0',
			'components' => [
				'schemas' => $schemas,
				'registers' => [
					$registerSlug => [
						'slug' => $registerSlug,
						'title' => ucfirst($registerSlug),
						'version' => $registerVersion,
						'schemas' => $schemaSlugs,
					],
				],
			],
		];

	}//end makePayload()

	/**
	 * Run one import as app `opencatalogi`.
	 *
	 * @param array $payload The payload to import.
	 *
	 * @return array The import result.
	 */
	private function import(array $payload): array {
		return $this->handler->importFromJson(
			data:          $payload,
			configuration: $this->makeConfiguration(1),
			owner:         'opencatalogi',
			appId:         'opencatalogi',
			version:       '1.0.0'
		);

	}//end import()

	/**
	 * THE FIX. A register that already exists at the SAME version still adopts
	 * the schema ids this import created, instead of returning from the
	 * version gate and leaving them orphaned.
	 */
	public function testVersionSkippedRegisterAdoptsSchemasCreatedByThisImport(): void {
		// First import: register created at 1.0.0 with `publication` only.
		$this->import($this->makePayload('publication', '1.0.0', ['publication']));

		$register = $this->registerStore['publication'];
		$publicationId = $this->schemaStore[array_key_first($this->schemaStore)]->getId();
		$this->assertSame([$publicationId], $register->getSchemas());

		// Second import: the register version is UNCHANGED (the gate will skip
		// it) but a register.d fragment added `page`.
		$result = $this->import($this->makePayload('publication', '1.0.0', ['publication', 'page']));

		$pageId = null;
		foreach ($this->schemaStore as $id => $schema) {
			if ($schema->getSlug() === 'page') {
				$pageId = $id;
			}
		}

		$this->assertNotNull($pageId, 'the import created a `page` schema');
		$this->assertContains(
			$pageId,
			$this->registerStore['publication']->getSchemas(),
			'the version-skipped register must still link the schema this import created'
		);
		$this->assertContains($publicationId, $this->registerStore['publication']->getSchemas());
		$this->assertCount(2, $result['schemas']);

	}//end testVersionSkippedRegisterAdoptsSchemasCreatedByThisImport()

	/**
	 * THE REPRODUCTION (WOO-563). Importing the SAME payload three times, with
	 * the register held at one version the whole way, must leave exactly one
	 * schema row per slug. Before the fix the second and third runs each
	 * forked a twin — same slug, same owner, same version, new uuid — which is
	 * how page/menu/glossary ended up on the instance three times over.
	 */
	public function testRepeatedImportAtTheSameRegisterVersionDoesNotForkTwins(): void {
		$payloadSeed = $this->makePayload('publication', '1.0.0', ['publication']);
		$payloadFull = $this->makePayload('publication', '1.0.0', ['publication', 'page', 'menu', 'glossary']);

		$this->import($payloadSeed);
		$this->import($payloadFull);
		$this->import($payloadFull);
		$this->import($payloadFull);

		$bySlug = [];
		foreach ($this->schemaStore as $schema) {
			$bySlug[(string)$schema->getSlug()][] = $schema->getId();
		}

		foreach (['publication', 'page', 'menu', 'glossary'] as $slug) {
			$this->assertArrayHasKey($slug, $bySlug);
			$this->assertCount(
				1,
				$bySlug[$slug],
				sprintf('slug `%s` forked into %d rows', $slug, count($bySlug[$slug] ?? []))
			);
		}

		$this->assertCount(4, $this->schemaStore, 'four slugs must mean four schema rows');
		$this->assertCount(4, $this->registerStore['publication']->getSchemas());

	}//end testRepeatedImportAtTheSameRegisterVersionDoesNotForkTwins()

	/**
	 * The link is a UNION, never a replace: a schema the register already owns
	 * but this import does not mention stays linked (#2935).
	 */
	public function testVersionSkippedImportNeverDropsAnExistingLink(): void {
		$this->import($this->makePayload('publication', '1.0.0', ['publication']));

		// A schema linked by something other than this configuration.
		$foreign = new Schema();
		$foreign->setSlug('legacy-thing');
		$foreign->setVersion('1.0.0');
		$this->setEntityId($foreign, 900);
		$this->schemaStore[900] = $foreign;

		$register = $this->registerStore['publication'];
		$register->setSchemas(array_merge($register->getSchemas(), [900]));

		// Re-import at the same register version, WITHOUT mentioning 900.
		$this->import($this->makePayload('publication', '1.0.0', ['publication', 'page']));

		$this->assertContains(
			900,
			$this->registerStore['publication']->getSchemas(),
			'a link this import could not see must be left alone, not dropped'
		);

	}//end testVersionSkippedImportNeverDropsAnExistingLink()

	/**
	 * A genuinely unchanged re-import must not write the register row: the
	 * link step persists only when the union actually adds an id.
	 */
	public function testUnchangedReimportDoesNotWriteTheRegister(): void {
		$payload = $this->makePayload('publication', '1.0.0', ['publication']);
		$this->import($payload);

		$callsAfterFirstImport = $this->registerUpdateCalls;
		$this->import($payload);

		$this->assertSame(
			$callsAfterFirstImport,
			$this->registerUpdateCalls,
			'an import that changes nothing must not write the register row'
		);

	}//end testUnchangedReimportDoesNotWriteTheRegister()

}//end class
