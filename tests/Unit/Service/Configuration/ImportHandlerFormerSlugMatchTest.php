<?php

/**
 * Unit tests for the register import matching a renamed schema by its former slug.
 *
 * An app update still ships the slug the administrator renamed away from. The
 * import must update the renamed schema, keep its new slug, and create no
 * second schema under the old one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * In-memory schema and register tables, so the same rows survive two imports.
 *
 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-former-slug-keeps-answering-and-says-where-to-go
 */
class ImportHandlerFormerSlugMatchTest extends TestCase {

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	private ImportHandler $handler;

	/** @var array<int, Schema> id => Schema. */
	private array $schemaStore = [];

	/** @var array<string, Register> slug => Register. */
	private array $registerStore = [];

	private int $nextSchemaId = 100;

	private int $nextRegisterId = 200;

	/** @var list<array> The data each schema update was called with. */
	private array $updates = [];

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('setValueString')->willReturn(true);
		$mappingMapper = $this->createMock(MappingMapper::class);
		$mappingMapper->method('getSlugToIdMap')->willReturn([]);

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
		$this->schemaMapper->method('findMultiple')->willReturnCallback(
			fn (array $ids): array => array_values(array_filter(array_map(fn ($id) => ($this->schemaStore[$id] ?? null), $ids)))
		);
		$this->schemaMapper->method('find')->willReturnCallback(
			function (string $id): Schema {
				throw new DoesNotExistException('schema not found: ' . $id);
			}
		);
		$this->schemaMapper->method('createFromArray')->willReturnCallback(
			function (array $data): Schema {
				$id = $this->nextSchemaId++;
				$schema = new Schema();
				$schema->setSlug($data['slug']);
				$schema->setVersion($data['version'] ?? '1.0.0');
				$this->setEntityId($schema, $id);
				$this->schemaStore[$id] = $schema;
				return $schema;
			}
		);
		$this->schemaMapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $data): Schema {
				$this->updates[] = $data;
				$schema = $this->schemaStore[$id];
				if (isset($data['slug']) === true) {
					$schema->setSlug($data['slug']);
				}

				if (isset($data['version']) === true) {
					$schema->setVersion($data['version']);
				}

				return $schema;
			}
		);
		$this->schemaMapper->method('update')->willReturnArgument(0);

		$registerMapper->method('find')->willReturnCallback(
			function (string $id): Register {
				return ($this->registerStore[strtolower($id)] ?? throw new DoesNotExistException('register not found: ' . $id));
			}
		);
		$registerMapper->method('createFromArray')->willReturnCallback(
			function (array $data): Register {
				$register = new Register();
				$register->setSlug($data['slug']);
				$register->setVersion($data['version'] ?? '1.0.0');
				$register->setSchemas($data['schemas'] ?? []);
				$this->setEntityId($register, $this->nextRegisterId++);
				$this->registerStore[strtolower($data['slug'])] = $register;
				return $register;
			}
		);
		$registerMapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $data): Register {
				foreach ($this->registerStore as $register) {
					if ($register->getId() === $id) {
						if (isset($data['schemas']) === true) {
							$register->setSchemas($data['schemas']);
						}

						return $register;
					}
				}

				throw new DoesNotExistException('register id not found: ' . $id);
			}
		);
		$registerMapper->method('update')->willReturnArgument(0);

		$this->handler = new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $registerMapper,
				schemas: $this->schemaMapper,
				tables: $this->createMock(MagicMapper::class)
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $mappingMapper,
				appConfig: $appConfig
			),
			schemaSteps: new \OCA\OpenRegister\Service\Configuration\SchemaImportSteps(),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);
	}//end setUp()

	private function setEntityId(object $entity, int $id): void {
		$prop = (new ReflectionClass($entity))->getProperty('id');
		$prop->setValue($entity, $id);
	}//end setEntityId()

	private function configuration(): Configuration {
		$config = new Configuration();
		$config->setApp('meldingen-app');
		$config->setRegisters([]);
		$config->setSchemas([]);
		$config->setObjects([]);
		$this->setEntityId($config, 1);
		return $config;
	}//end configuration()

	/**
	 * The app's register descriptor, shipping its schemas under these slugs.
	 *
	 * @param string       $version The descriptor version.
	 * @param list<string> $slugs   The schema slugs it ships.
	 *
	 * @return array
	 */
	private function descriptor(string $version, array $slugs = ['melding']): array {
		$schemas = [];
		foreach ($slugs as $slug) {
			$schemas[$slug] = ['slug' => $slug, 'title' => ucfirst($slug), 'version' => $version];
		}

		return [
			'appId' => 'meldingen-app',
			'version' => $version,
			'components' => [
				'schemas' => $schemas,
				'registers' => [
					'meldingen-register' => ['slug' => 'meldingen-register', 'title' => 'Meldingen', 'version' => $version, 'schemas' => $slugs],
				],
			],
		];
	}//end descriptor()

	/**
	 * Rename the stored schema as the renameSchema migration does.
	 *
	 * @param Schema $schema The schema.
	 * @param string $to     The new slug.
	 *
	 * @return void
	 */
	private function rename(Schema $schema, string $to): void {
		$schema->setFormerSlugs([(string)$schema->getSlug()]);
		$schema->setSlug($to);
	}//end rename()

	public function testAnAppUpdateWithTheOldSlugUpdatesTheRenamedSchema(): void {
		$first = $this->handler->importFromJson(data: $this->descriptor('1.0.0'), configuration: $this->configuration(), version: '1.0.0');
		$schema = $first['schemas'][0];
		$this->rename(schema: $schema, to: 'signaal');

		$second = $this->handler->importFromJson(data: $this->descriptor('1.1.0'), configuration: $this->configuration(), version: '1.1.0');

		$this->assertCount(1, $this->schemaStore, 'no second schema is created under the old slug');
		$this->assertSame($schema, $second['schemas'][0]);
		$this->assertSame('signaal', $schema->getSlug(), 'the rename stands');
		$this->assertSame(['melding'], $schema->getFormerSlugs());
		$this->assertSame('1.1.0', $schema->getVersion());
		$this->assertSame([$schema->getId()], $this->registerStore['meldingen-register']->getSchemas());
		$this->assertNotEmpty($this->updates);
		$this->assertSame('signaal', $this->updates[array_key_last($this->updates)]['slug']);
	}//end testAnAppUpdateWithTheOldSlugUpdatesTheRenamedSchema()

	public function testACurrentSlugInTheRegisterWinsOverAFormerOne(): void {
		$first = $this->handler->importFromJson(data: $this->descriptor('1.0.0', ['melding', 'notitie']), configuration: $this->configuration(), version: '1.0.0');
		[$melding, $notitie] = $first['schemas'];
		// `notitie` once answered to `melding` too; the current `melding` must still win.
		$notitie->setFormerSlugs(['melding']);

		$second = $this->handler->importFromJson(data: $this->descriptor('1.1.0', ['melding', 'notitie']), configuration: $this->configuration(), version: '1.1.0');

		$this->assertCount(2, $this->schemaStore);
		$this->assertSame($melding, $second['schemas'][0]);
		$this->assertSame('melding', $melding->getSlug());
		$this->assertSame('1.1.0', $melding->getVersion());
	}//end testACurrentSlugInTheRegisterWinsOverAFormerOne()

	public function testWithoutARegisterTheAppsRenamedSchemaIsMatched(): void {
		$signaal = new Schema();
		$signaal->setSlug('signaal');
		$signaal->setFormerSlugs(['melding']);
		$signaal->setApplication('meldingen-app');
		$this->setEntityId($signaal, 7);
		$this->schemaMapper->method('findByApplicationAndSlug')->willReturn(null);
		$this->schemaMapper->expects($this->once())->method('findAll')
			->with(null, null, ['application' => 'meldingen-app'], [], [], [], false, false)
			->willReturn([$signaal]);

		$method = (new ReflectionClass($this->handler))->getMethod('resolveSchemaByApplication');

		$this->assertSame($signaal, $method->invoke($this->handler, ['slug' => 'melding'], 'meldingen-app'));
	}//end testWithoutARegisterTheAppsRenamedSchemaIsMatched()

	public function testAnUnknownSlugIsStillCreated(): void {
		$this->handler->importFromJson(data: $this->descriptor('1.0.0'), configuration: $this->configuration(), version: '1.0.0');

		$this->handler->importFromJson(data: $this->descriptor('1.1.0', ['melding', 'bijlage']), configuration: $this->configuration(), version: '1.1.0');

		$this->assertCount(2, $this->schemaStore);
		$slugs = array_map(static fn (Schema $schema): string => (string)$schema->getSlug(), array_values($this->schemaStore));
		$this->assertSame(['melding', 'bijlage'], $slugs);
	}//end testAnUnknownSlugIsStillCreated()
}//end class
