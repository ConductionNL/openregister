<?php

/**
 * A seed is never written into another app's schema its register does not list.
 *
 * Measured on the Rotterdam stack: opencatalogi dropped its own `organization`
 * schema but kept a `default-org` seed under register `publication`. The
 * import resolved the slug globally, found stackiq's `organization` schema,
 * and wrote the seed into a `publication` x stackiq-`organization` table,
 * which failed NOT NULL on stackiq's required fields. These tests drive the
 * real importFromJson() with mapper doubles that answer the global lookup the
 * way the instance did.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/seed-schema-must-belong-to-its-register/specs/data-import-export/spec.md#requirement-a-seed-is-never-written-into-another-apps-schema-its-register-does-not-list
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
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

class ImportHandlerForeignSeedSchemaTest extends TestCase {

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private ObjectService&MockObject $objectService;

	private ImportHandler $handler;

	/** @var array<string, Schema> Schemas that already exist on the instance, by slug. */
	private array $existingSchemas = [];

	/** @var array<string, Register> Registers that already exist on the instance, by slug. */
	private array $existingRegisters = [];

	/** @var array<int, array> Every object handed to saveObject(). */
	private array $saved = [];

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$mappingMapper = $this->createMock(MappingMapper::class);

		$appConfig->method('getValueString')->willReturn('');
		$this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
		$this->registerMapper->method('getSlugToIdMap')->willReturn([]);
		$mappingMapper->method('getSlugToIdMap')->willReturn([]);

		// The GLOBAL lookup: answers any slug that exists on the instance,
		// whichever app owns it. That is the behaviour the guard has to survive.
		$this->schemaMapper->method('find')->willReturnCallback(
			function ($id) {
				if (isset($this->existingSchemas[(string)$id]) === true) {
					return $this->existingSchemas[(string)$id];
				}

				throw new DoesNotExistException('not found');
			}
		);
		$this->schemaMapper->method('createFromArray')
			->willReturnCallback(fn (array $d) => $this->makeSchema(10, $d['slug'], 'opencatalogi'));
		$this->schemaMapper->method('update')->willReturnArgument(0);

		$this->registerMapper->method('find')->willReturnCallback(
			function ($id) {
				if (isset($this->existingRegisters[(string)$id]) === true) {
					return $this->existingRegisters[(string)$id];
				}

				throw new DoesNotExistException('not found');
			}
		);
		$this->registerMapper->method('createFromArray')->willReturnCallback(
			fn (array $d) => $this->makeRegister(23, $d['slug'], ($d['schemas'] ?? []), 'opencatalogi')
		);
		$this->registerMapper->method('update')->willReturnArgument(0);

		$this->objectService->method('searchObjects')->willReturn([]);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('uuid-' . ($object['@self']['slug'] ?? 'x'));
				return $entity;
			}
		);

		$this->handler = new ImportHandler(
			schemaMapper:        $this->schemaMapper,
			registerMapper:      $this->registerMapper,
			objectEntityMapper:  $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper:       $mappingMapper,
			client:              $this->createMock(Client::class),
			appConfig:           $appConfig,
			logger:              $this->createMock(LoggerInterface::class),
			appDataPath:         '/tmp',
			uploadHandler:       $this->createMock(UploadHandler::class),
			objectService:       $this->objectService
		);
	}//end setUp()

	/**
	 * The measured defect: opencatalogi's stale `organization` seed resolves to
	 * stackiq's schema, which register `publication` does not list. It is
	 * skipped; the sibling seed of opencatalogi's own schema is still saved.
	 */
	public function testAStaleSeedIsNotWrittenIntoAnotherAppsSchema(): void {
		$this->existingSchemas['organization'] = $this->makeSchema(33, 'organization', 'stackiq');

		$result = $this->import(
			[
				['@self' => ['register' => 'publication', 'schema' => 'catalog', 'slug' => 'main'], 'title' => 'Main'],
				['@self' => ['register' => 'publication', 'schema' => 'organization', 'slug' => 'default-org'], 'name' => 'Default'],
			]
		);

		$this->assertSame(['main'], $this->savedSlugs(), 'only the seed of the register\'s own schema is written');
		$this->assertSame(1, $result['skipped']['objects']);
	}//end testAStaleSeedIsNotWrittenIntoAnotherAppsSchema()

	/**
	 * The importing app's own schema is accepted even when this pass has not
	 * linked it to the register yet (a first install over several fragments).
	 */
	public function testTheAppsOwnSchemaIsAcceptedBeforeItIsLinked(): void {
		$this->existingSchemas['page'] = $this->makeSchema(41, 'page', 'opencatalogi');

		$this->import([['@self' => ['register' => 'publication', 'schema' => 'page', 'slug' => 'home'], 'title' => 'Home']]);

		$this->assertSame(['home'], $this->savedSlugs());
	}//end testTheAppsOwnSchemaIsAcceptedBeforeItIsLinked()

	/**
	 * A schema with no owning application (openregister's shared ones) is
	 * accepted: nothing says it belongs to anyone else.
	 */
	public function testAnOwnerlessSchemaIsAccepted(): void {
		$this->existingSchemas['nc-organisation'] = $this->makeSchema(8, 'nc-organisation', null);

		$this->import([['@self' => ['register' => 'publication', 'schema' => 'nc-organisation', 'slug' => 'gemeente'], 'name' => 'Gemeente']]);

		$this->assertSame(['gemeente'], $this->savedSlugs());
	}//end testAnOwnerlessSchemaIsAccepted()

	/**
	 * An app seeding into ANOTHER app's register, with a schema that register
	 * lists, is a legitimate cross-app seed and is written.
	 */
	public function testACrossAppSeedIntoARegisterThatListsTheSchemaIsAccepted(): void {
		$this->existingSchemas['organization'] = $this->makeSchema(33, 'organization', 'stackiq');
		$this->existingRegisters['stackiq'] = $this->makeRegister(20, 'stackiq', [28, 33], 'stackiq');

		$this->import([['@self' => ['register' => 'stackiq', 'schema' => 'organization', 'slug' => 'conduction'], 'name' => 'Conduction', 'type' => 'Leverancier']]);

		$this->assertSame(['conduction'], $this->savedSlugs());
	}//end testACrossAppSeedIntoARegisterThatListsTheSchemaIsAccepted()

	/**
	 * Import opencatalogi's register `publication` (own schema `catalog`) with the given seeds.
	 *
	 * @param array<int, array> $objects The seed objects.
	 *
	 * @return array The import result.
	 */
	private function import(array $objects): array {
		$data = [
			'appId' => 'opencatalogi',
			'version' => '9.9.9',
			'components' => [
				'schemas' => [
					'catalog' => ['slug' => 'catalog', 'title' => 'Catalog', 'properties' => []],
				],
				'registers' => [
					'publication' => ['slug' => 'publication', 'version' => '1.0.0', 'schemas' => ['catalog']],
				],
				'objects' => $objects,
			],
		];

		return $this->handler->importFromJson(
			data: $data,
			configuration: new Configuration(),
			appId: 'opencatalogi',
			version: '9.9.9'
		);
	}//end import()

	/**
	 * @return array<int, string> The slugs saveObject() received, in order.
	 */
	private function savedSlugs(): array {
		return array_map(static fn (array $o): string => (string)($o['@self']['slug'] ?? ''), $this->saved);
	}//end savedSlugs()

	private function makeRegister(int $id, string $slug, array $schemas, ?string $application): Register {
		$register = new Register();
		$register->hydrate(['slug' => $slug, 'version' => '1.0.0']);
		$register->setSchemas($schemas);
		$register->setApplication($application);
		$register->setId($id);
		return $register;
	}//end makeRegister()

	private function makeSchema(int $id, string $slug, ?string $application): Schema {
		$schema = new Schema();
		$schema->hydrate(['slug' => $slug, 'title' => $slug, 'version' => '1.0.0', 'properties' => []]);
		$schema->setApplication($application);
		$schema->setId($id);
		return $schema;
	}//end makeSchema()
}//end class
