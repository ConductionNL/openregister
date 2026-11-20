<?php

/**
 * ImportHandlerSecondPassTest: pass 2 writes a schema only when resolving its references changed what pass 1 wrote.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords;
use OCA\OpenRegister\Service\Configuration\ImportedSchemaLookup;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\SchemaImportOptions;
use OCA\OpenRegister\Service\Configuration\SchemaImportSteps;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @covers \OCA\OpenRegister\Service\Configuration\FirstPassWrites
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-the-second-schema-pass-must-write-only-what-resolving-references-changed
 */
class ImportHandlerSecondPassTest extends TestCase {

	/**
	 * The stored schemas, by id.
	 *
	 * @var array<int, Schema>
	 */
	private array $stored = [];

	/**
	 * Every write to the schema store, as `create slug` or `update slug`, in order.
	 *
	 * @var list<string>
	 */
	private array $writes = [];

	/**
	 * An import handler over a schema store that starts empty and remembers what it is given.
	 *
	 * @return ImportHandler
	 */
	private function handler(): ImportHandler {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('getSlugToIdMap')->willReturn([]);
		$schemas->method('find')->willThrowException(new DoesNotExistException('not found'));
		$schemas->method('findByApplicationAndSlug')->willReturn(null);
		$schemas->method('findBySlugInIds')->willReturnCallback(
			function (string $slug, array $ids): ?Schema {
				foreach ($ids as $id) {
					if (isset($this->stored[(int) $id]) === true && $this->stored[(int) $id]->getSlug() === $slug) {
						return $this->stored[(int) $id];
					}
				}

				return null;
			}
		);
		$schemas->method('createFromArray')->willReturnCallback(
			function (array $data): Schema {
				$this->writes[] = 'create ' . $data['slug'];
				return $this->store(id: (count($this->stored) + 100), data: $data);
			}
		);
		$schemas->method('updateFromArray')->willReturnCallback(
			function (int $id, array $data): Schema {
				$this->writes[] = 'update ' . $data['slug'];
				return $this->store(id: $id, data: $data);
			}
		);
		$schemas->method('update')->willReturnArgument(0);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('getSlugToIdMap')->willReturn([]);
		$registers->method('find')->willThrowException(new DoesNotExistException('not found'));
		$registers->method('createFromArray')->willReturnCallback(
			static function (array $data): Register {
				$register = new Register();
				$register->hydrate(['slug' => $data['slug'], 'version' => '1.0.0']);
				$register->setId(1);
				return $register;
			}
		);
		$registers->method('update')->willReturnArgument(0);

		$mappings = $this->createMock(MappingMapper::class);
		$mappings->method('getSlugToIdMap')->willReturn([]);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new ImportHandler(
			catalog: new SchemaCatalog(registers: $registers, schemas: $schemas, tables: $this->createMock(MagicMapper::class)),
			records: new ConfigurationImportRecords(configurations: $this->createMock(ConfigurationMapper::class), mappings: $mappings, appConfig: $appConfig),
			schemaSteps: new SchemaImportSteps(),
			logger: new NullLogger(),
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);
	}//end handler()

	/**
	 * Keep a schema as the store would.
	 *
	 * @param int   $id   The schema id.
	 * @param array $data The schema data.
	 *
	 * @return Schema
	 */
	private function store(int $id, array $data): Schema {
		$schema = new Schema();
		$schema->hydrate(['slug' => $data['slug'], 'title' => $data['title'], 'version' => '1.0.0', 'properties' => ($data['properties'] ?? [])]);
		$schema->setId($id);
		$this->stored[$id] = $schema;

		return $schema;
	}//end store()

	/**
	 * A configuration where `order` references `customer`, which is declared after it.
	 *
	 * @return array
	 */
	private static function configuration(): array {
		$plain = ['type' => 'string'];

		return [
			'appId' => 'shop',
			'version' => '1.0.0',
			'components' => [
				'schemas' => [
					'product' => ['slug' => 'product', 'title' => 'Product', 'version' => '1.0.0', 'properties' => ['name' => $plain]],
					'order' => ['slug' => 'order', 'title' => 'Order', 'version' => '1.0.0', 'properties' => ['customer' => ['type' => 'object', '$ref' => 'customer']]],
					'customer' => ['slug' => 'customer', 'title' => 'Customer', 'version' => '1.0.0', 'properties' => ['name' => $plain]],
				],
				'registers' => [
					'shop' => ['slug' => 'shop', 'version' => '1.0.0', 'schemas' => ['product', 'order', 'customer']],
				],
			],
		];
	}//end configuration()

	public function testOnlyTheSchemaWhoseReferenceWasResolvedIsWrittenAgain(): void {
		$this->handler()->importFromJson(data: self::configuration(), configuration: new Configuration(), appId: 'shop', version: '1.0.0', force: true);

		$this->assertSame(['create product', 'create order', 'create customer', 'update order'], $this->writes);
	}//end testOnlyTheSchemaWhoseReferenceWasResolvedIsWrittenAgain()

	public function testTheResolvedReferenceIsWhatEndsUpStored(): void {
		$this->handler()->importFromJson(data: self::configuration(), configuration: new Configuration(), appId: 'shop', version: '1.0.0', force: true);

		$bySlug = [];
		foreach ($this->stored as $schema) {
			$bySlug[$schema->getSlug()] = $schema;
		}

		$this->assertSame($bySlug['customer']->getId(), $bySlug['order']->getProperties()['customer']['$ref']);
	}//end testTheResolvedReferenceIsWhatEndsUpStored()

	public function testAnImportOfOneSchemaOutsideARunAlwaysWrites(): void {
		$handler = $this->handler();
		$handler->importFromJson(data: self::configuration(), configuration: new Configuration(), appId: 'shop', version: '1.0.0', force: true);
		$this->writes = [];
		$product = self::configuration()['components']['schemas']['product'];
		$ids = array_keys($this->stored);

		// What pass 1 remembered must not outlive the import that ran it.
		$handler->importSchema(
			new ImportedSchemaLookup(data: $product, registerSchemaIds: $ids, options: new SchemaImportOptions(appId: 'shop', version: '1.0.0', force: true)),
			[]
		);

		$this->assertSame(['update product'], $this->writes);
	}//end testAnImportOfOneSchemaOutsideARunAlwaysWrites()
}//end class
