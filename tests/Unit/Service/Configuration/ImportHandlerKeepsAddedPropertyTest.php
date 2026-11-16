<?php

/**
 * A property an administrator adds to an app-shipped schema survives the
 * app's next import.
 *
 * Runs the real ImportHandler, SchemaImportSteps and ShippedConfigurationGuard
 * over an in-memory schema table and baseline store: the app's first import
 * creates the schema and records what it shipped, an administrator adds a
 * property, the app's update imports again, and the schema read back still
 * has the added property next to the shipped ones (with the shipped change
 * applied). Only the storage boundaries are doubled.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportedSchemaLookup;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\SchemaImportOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use OCA\OpenRegister\Service\ShippedBaseline\GuardedDescriptorMerge;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineStore;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Import, add a property, import the update, read it back.
 */
class ImportHandlerKeepsAddedPropertyTest extends TestCase {

	/**
	 * The one stored schema, null until the first import creates it.
	 *
	 * @var Schema|null
	 */
	private ?Schema $stored = null;

	/**
	 * Recorded baselines by subject.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $baselines = [];

	/**
	 * A schema table holding one schema.
	 *
	 * @return SchemaMapper
	 */
	private function schemas(): SchemaMapper {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findByApplicationAndSlug')->willReturnCallback(fn (): ?Schema => $this->stored);
		$mapper->method('find')->willReturnCallback(
			function (): Schema {
				if ($this->stored === null) {
					throw new DoesNotExistException('none');
				}

				return $this->stored;
			}
		);
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('createFromArray')->willReturnCallback(
			function (array $object): Schema {
				$schema = new Schema();
				(new ReflectionProperty($schema, 'id'))->setValue($schema, 7);
				$this->stored = $this->write(schema: $schema, object: $object);
				return $this->stored;
			}
		);
		$mapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $object): Schema {
				$this->stored = $this->write(schema: $this->stored, object: $object);
				return $this->stored;
			}
		);

		return $mapper;
	}//end schemas()

	/**
	 * Write the columns this test reads onto a schema.
	 *
	 * @param Schema               $schema The schema row.
	 * @param array<string, mixed> $object The definition written.
	 *
	 * @return Schema
	 */
	private function write(Schema $schema, array $object): Schema {
		$schema->setSlug((string)($object['slug'] ?? $schema->getSlug()));
		$schema->setTitle((string)($object['title'] ?? $schema->getTitle()));
		$schema->setVersion((string)($object['version'] ?? $schema->getVersion()));
		if (array_key_exists('properties', $object) === true) {
			$schema->setProperties($object['properties']);
		}

		if (array_key_exists('required', $object) === true) {
			$schema->setRequired($object['required']);
		}

		return $schema;
	}//end write()

	/**
	 * The real guard over an in-memory baseline store.
	 *
	 * @return ShippedConfigurationGuard
	 */
	private function guard(): ShippedConfigurationGuard {
		$store = $this->createMock(ShippedBaselineStore::class);
		$store->method('schemaSubject')->willReturnCallback(static fn (string $slug): string => 'schema:' . $slug);
		$store->method('read')->willReturnCallback(fn (string $subject): ?array => ($this->baselines[$subject] ?? null));
		$store->method('record')->willReturnCallback(
			function (string $subject, ShippedDefinition $shipped): bool {
				$this->baselines[$subject] = ['definition' => $shipped->definition, 'app' => $shipped->app, 'appVersion' => $shipped->appVersion, 'recordedAt' => '2026-11-14T00:00:00+00:00'];
				return true;
			}
		);

		$parts = new DescriptorParts();
		$comparator = new DivergenceComparator(parts: $parts);
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('insertAuditTrails')->willReturnArgument(0);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new ShippedConfigurationGuard(
			baselines: $store,
			merge: new GuardedDescriptorMerge(parts: $parts, comparator: $comparator),
			comparator: $comparator,
			audit: $audit,
			session: $session,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end guard()

	/**
	 * The import handler with the real schema steps and guard.
	 *
	 * @return ImportHandler
	 */
	private function handler(): ImportHandler {
		return new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $this->createMock(RegisterMapper::class),
				schemas: $this->schemas(),
				tables: $this->createMock(MagicMapper::class)
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $this->createMock(IAppConfig::class)
			),
			schemaSteps: new \OCA\OpenRegister\Service\Configuration\SchemaImportSteps(
				shippedGuard: $this->guard()
			),
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);
	}//end handler()

	/**
	 * What the app ships for its employee schema, at a version.
	 *
	 * @param string $version   The app version.
	 * @param string $nameTitle The title the app gives the name property.
	 *
	 * @return array<string, mixed>
	 */
	private function shipped(string $version, string $nameTitle): array {
		return [
			'slug' => 'employee',
			'title' => 'Employee',
			'version' => $version,
			'properties' => [
				'name' => ['type' => 'string', 'title' => $nameTitle],
				'email' => ['type' => 'string', 'format' => 'email'],
			],
			'required' => ['name'],
		];
	}//end shipped()

	/**
	 * Import a definition the way an app's install or update does.
	 *
	 * @param ImportHandler        $handler    The handler.
	 * @param array<string, mixed> $definition The shipped definition.
	 * @param string               $version    The app version.
	 *
	 * @return void
	 */
	private function import(ImportHandler $handler, array $definition, string $version): void {
		$handler->importSchema(
			definition: new ImportedSchemaLookup(data: $definition, options: new SchemaImportOptions(appId: 'hrapp', version: $version)),
			slugsAndIdsMap: []
		);
	}//end import()

	/**
	 * The app update keeps the property an administrator added, and still
	 * lands its own change.
	 *
	 * @return void
	 */
	public function testAnAppUpdateKeepsThePropertyAnAdministratorAdded(): void {
		$handler = $this->handler();
		$this->import(handler: $handler, definition: $this->shipped(version: '1.0.0', nameTitle: 'Name'), version: '1.0.0');
		$this->assertNotNull($this->stored, 'the first import created the schema');
		$this->assertArrayHasKey('schema:employee', $this->baselines, 'and recorded what the app shipped');

		// An administrator adds their own property in the schema screens.
		$properties = $this->stored->getProperties();
		$properties['rijbewijs'] = ['type' => 'string', 'title' => 'Rijbewijs'];
		$this->stored->setProperties($properties);

		$this->import(handler: $handler, definition: $this->shipped(version: '1.1.0', nameTitle: 'Full name'), version: '1.1.0');

		$read = $this->stored->getProperties();
		$this->assertEquals(['type' => 'string', 'title' => 'Rijbewijs'], ($read['rijbewijs'] ?? null), 'the added property is still there');
		$this->assertSame('Full name', $read['name']['title'], 'the shipped change landed');
		$this->assertArrayHasKey('email', $read);
	}//end testAnAppUpdateKeepsThePropertyAnAdministratorAdded()

	/**
	 * A re-import of the same version keeps the added property too.
	 *
	 * @return void
	 */
	public function testReimportingTheSameVersionKeepsTheAddedProperty(): void {
		$handler = $this->handler();
		$this->import(handler: $handler, definition: $this->shipped(version: '1.0.0', nameTitle: 'Name'), version: '1.0.0');
		$properties = $this->stored->getProperties();
		$properties['rijbewijs'] = ['type' => 'string'];
		$this->stored->setProperties($properties);

		$this->import(handler: $handler, definition: $this->shipped(version: '1.0.0', nameTitle: 'Name'), version: '1.0.0');

		$this->assertArrayHasKey('rijbewijs', $this->stored->getProperties());
	}//end testReimportingTheSameVersionKeepsTheAddedProperty()
}//end class
