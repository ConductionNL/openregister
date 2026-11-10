<?php

/**
 * Golden tests for SaveObjects' mixed-schema object preparation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins prepareObjectsForBulkSave for rows that name their own schema: schema loading, identity, metadata and relations.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationRun
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationScanner
 */
class SaveObjectsMixedSchemaPreparationGoldenTest extends TestCase {

	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	private const PARENT = '0b9d2f5e-6c1a-4f43-9b8e-3d2f1a0c9e77';

	/** @var list<mixed> */
	private array $loads = [];

	protected function setUp(): void {
		parent::setUp();
		$ref = new ReflectionClass(SaveObjects::class);
		foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $prop) {
			$ref->getProperty($prop)->setValue(null, []);
		}

		$this->loads = [];
	}//end setUp()

	/**
	 * Prepare the objects; schemas 4 and 5 exist.
	 *
	 * @param array $objects The raw objects.
	 *
	 * @return array [prepared, schema cache ids, invalid]
	 */
	private function prepare(array $objects): array {
		$schemas = [];
		foreach ([4 => 'thing', 5 => 'other'] as $id => $slug) {
			$schema = new Schema();
			$schema->setId($id);
			$schema->setSlug($slug);
			$schema->setProperties(['title' => ['type' => 'string'], 'parent' => ['type' => 'string', 'format' => 'uuid']]);
			$schemas[$id] = $schema;
		}

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id) use ($schemas) {
				$this->loads[] = $id;
				if (isset($schemas[$id]) === false) {
					throw new Exception('no schema ' . var_export($id, true));
				}

				return $schemas[$id];
			}
		);
		$save = $this->createMock(SaveObject::class);
		$save->method('hydrateObjectMetadata')->willReturnCallback(
			static function (ObjectEntity $entity, Schema $schema): void {
				$entity->setName($schema->getSlug() . ':' . ($entity->getObject()['title'] ?? '?'));
			}
		);

		$handler = new SaveObjects(
			stores: new BulkSaveStores(objectEntityMapper: $this->createMock(MagicMapper::class), schemaMapper: $schemaMapper, registerMapper: $this->createMock(RegisterMapper::class), saveHandler: $save),
			session: new BulkSaveSession(userSession: $this->createMock(IUserSession::class), organisationService: $this->createMock(OrganisationService::class)),
			reporting: new BulkSaveReporting(logger: $this->createMock(LoggerInterface::class)),
			checks: new BulkRowChecks()
		);

		$method = new ReflectionMethod(SaveObjects::class, 'prepareObjectsForBulkSave');
		[$prepared, $cache, $invalid] = $method->invoke($handler, $objects);
		return [$prepared, array_keys($cache), $invalid];
	}//end prepare()

	public function testSchemasLoadOncePerIdInFirstSeenOrderAndIdentitiesAreKeptOrGenerated(): void {
		[$prepared, $cacheIds, $invalid] = $this->prepare(
			[
				['@self' => ['schema' => 5, 'id' => 'given'], 'title' => 'One', 'parent' => self::PARENT],
				['@self' => ['schema' => '4'], 'title' => 'Two'],
				['@self' => ['schema' => 4, 'id' => '  '], 'title' => 'Three'],
				['@self' => ['schema' => 5, 'id' => '0'], 'title' => 'Four'],
				['@self' => ['schema' => 5, 'id' => 9], 'title' => 'Five'],
			]
		);

		$this->assertSame([5, '4'], $this->loads);
		$this->assertSame([5, 4], $cacheIds);
		$this->assertSame([], $invalid);
		$this->assertCount(5, $prepared);

		$this->assertSame('given', $prepared[0]['@self']['id']);
		$this->assertSame(9, $prepared[4]['@self']['id']);
		foreach ([1, 2, 3] as $index) {
			$this->assertMatchesRegularExpression(self::UUID_PATTERN, $prepared[$index]['@self']['id']);
		}

		$this->assertNotSame($prepared[1]['@self']['id'], $prepared[2]['@self']['id']);
		$this->assertNotSame($prepared[2]['@self']['id'], $prepared[3]['@self']['id']);

		foreach ([1, 2, 3] as $index) {
			$this->assertSame(['@self.id' => $prepared[$index]['@self']['id']], $prepared[$index]['@self']['relations']);
			$prepared[$index]['@self']['id'] = 'generated';
			$prepared[$index]['@self']['relations']['@self.id'] = 'generated';
		}

		$this->assertSame($this->expected(), $prepared);
	}//end testSchemasLoadOncePerIdInFirstSeenOrderAndIdentitiesAreKeptOrGenerated()

	/**
	 * The prepared objects with each generated id replaced by "generated".
	 *
	 * @return array
	 */
	private function expected(): array {
		$generated = static fn ($schema, string $name, string $title): array => [
			'@self' => ['schema' => $schema, 'id' => 'generated', 'name' => $name, 'relations' => ['@self.id' => 'generated']],
			'title' => $title,
			'name' => $name,
		];

		return [
			[
				'@self' => ['schema' => 5, 'id' => 'given', 'name' => 'other:One', 'relations' => ['parent' => self::PARENT]],
				'title' => 'One',
				'parent' => self::PARENT,
				'name' => 'other:One',
			],
			$generated('4', 'thing:Two', 'Two'),
			$generated(4, 'thing:Three', 'Three'),
			$generated(5, 'other:Four', 'Four'),
			['@self' => ['schema' => 5, 'id' => 9, 'name' => 'other:Five', 'relations' => []], 'title' => 'Five', 'name' => 'other:Five'],
		];
	}//end expected()

	public function testARowWithSchemaFalseIsLoadedAsSchemaZeroAndRefused(): void {
		try {
			$this->prepare([['@self' => ['schema' => 4], 'title' => 'ok'], ['@self' => ['schema' => false], 'title' => 'false']]);
			$this->fail('expected the schema load to fail');
		} catch (Exception $e) {
			$this->assertSame('no schema 0', $e->getMessage());
		}

		$this->assertSame([4, 0], $this->loads);
	}//end testARowWithSchemaFalseIsLoadedAsSchemaZeroAndRefused()

	public function testARowWhoseSchemaIsNotLoadedIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Schema  not found in cache during preparation');
		$this->prepare([['@self' => ['schema' => 4], 'title' => 'ok'], ['title' => 'no schema']]);
	}//end testARowWhoseSchemaIsNotLoadedIsRefused()

	public function testNoObjectsPrepareNothing(): void {
		$method = new ReflectionMethod(SaveObjects::class, 'prepareObjectsForBulkSave');
		$handler = (new ReflectionClass(SaveObjects::class))->newInstanceWithoutConstructor();
		$this->assertSame([[], []], $method->invoke($handler, []));
	}//end testNoObjectsPrepareNothing()
}//end class
