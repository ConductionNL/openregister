<?php

/**
 * Live-database proof that a PHP boolean filter value filters like its string form.
 *
 * Before the fix a filter such as `['isDraft' => false]` reached PostgreSQL as
 * `is_draft = ''`, which PostgreSQL refuses (SQLSTATE 22P02). The mapper logged
 * and swallowed the error and the caller got zero rows. Unit tests can only show
 * the SQL text; only a real database shows the rows, so this runs on the list
 * path, the count path and the cross-table (UNION) path.
 *
 * It also pins the decision that a missing value is neither true nor false.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/boolean-filter-values/specs/zoeken-filteren/spec.md#requirement-a-boolean-filter-value-filters-like-its-string-form
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class BooleanFilterIntegrationTest extends TestCase {

	/**
	 * Query flags that keep RBAC and tenancy out of the way: this test is about values.
	 *
	 * @var array<string,bool>
	 */
	private const OPEN = ['_rbac' => false, '_multitenancy' => false];

	private MagicMapper $mapper;

	private RegisterMapper $registerMapper;

	private SchemaMapper $schemaMapper;

	private Register $register;

	/**
	 * Two schemas, so the cross-table path has two arms.
	 *
	 * @var Schema[]
	 */
	private array $schemas = [];

	/**
	 * @var string[]
	 */
	private array $createdTables = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = \OC::$server->get(MagicMapper::class);
		$this->registerMapper = \OC::$server->get(RegisterMapper::class);
		$this->schemaMapper = \OC::$server->get(SchemaMapper::class);

		$this->register = $this->registerMapper->createFromArray(
			[
				'title' => 'PHPUnit boolean-filter Register ' . uniqid(),
				'description' => 'Register for boolean filter value tests',
			]
		);

		foreach (['a', 'b'] as $suffix) {
			$schema = $this->schemaMapper->createFromArray(
				[
					'title' => 'PHPUnit boolean-filter Schema ' . $suffix . ' ' . uniqid(),
					'properties' => [
						'name' => ['type' => 'string', 'maxLength' => 255],
						'isDraft' => ['type' => 'boolean'],
					],
				]
			);
			$this->mapper->ensureTableForRegisterSchema($this->register, $schema);
			$this->createdTables[] = 'oc_' . $this->mapper->getTableNameForRegisterSchema($this->register, $schema);
			$this->schemas[] = $schema;

			// Per schema: two false, one true, one without a value.
			$this->insert($schema, ['name' => $suffix . '-false-1', 'isDraft' => false]);
			$this->insert($schema, ['name' => $suffix . '-false-2', 'isDraft' => false]);
			$this->insert($schema, ['name' => $suffix . '-true', 'isDraft' => true]);
			$this->insert($schema, ['name' => $suffix . '-unset']);
		}
	}//end setUp()

	protected function tearDown(): void {
		$db = \OC::$server->get(IDBConnection::class);

		foreach ($this->createdTables as $tableName) {
			try {
				$db->prepare("DROP TABLE IF EXISTS $tableName")->execute();
			} catch (\Exception $e) {
				// Table may not exist.
			}
		}

		foreach ($this->schemas as $schema) {
			$qb = $db->getQueryBuilder();
			$qb->delete('openregister_schemas')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}

		$qb = $db->getQueryBuilder();
		$qb->delete('openregister_registers')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($this->register->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		parent::tearDown();
	}//end tearDown()

	/**
	 * Filter, expected names in schema a.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string[]}>
	 */
	public static function filterProvider(): array {
		$false = ['a-false-1', 'a-false-2'];

		return [
			'bool false'     => [['isDraft' => false], $false],
			'string false'   => [['isDraft' => 'false'], $false],
			'bool true'      => [['isDraft' => true], ['a-true']],
			'string true'    => [['isDraft' => 'true'], ['a-true']],
			'ne bool true'   => [['isDraft' => ['ne' => true]], $false],
			'in bool false'  => [['isDraft' => ['in' => [false]]], $false],
			'list of bools'  => [['isDraft' => [false, true]], ['a-false-1', 'a-false-2', 'a-true']],
		];
	}//end filterProvider()

	/**
	 * The single-table list returns the rows holding the value, never the unset row.
	 *
	 * @param array<string,mixed> $filter   The property filter.
	 * @param string[]            $expected The names expected in schema a.
	 *
	 * @dataProvider filterProvider
	 *
	 * @return void
	 */
	public function testListPath(array $filter, array $expected): void {
		$rows = $this->mapper->searchObjectsInRegisterSchemaTable($filter + self::OPEN, $this->register, $this->schemas[0]);

		$this->assertSame($expected, $this->names($rows));
	}//end testListPath()

	/**
	 * The count agrees with the list.
	 *
	 * @param array<string,mixed> $filter   The property filter.
	 * @param string[]            $expected The names expected in schema a.
	 *
	 * @dataProvider filterProvider
	 *
	 * @return void
	 */
	public function testCountPath(array $filter, array $expected): void {
		$count = $this->mapper->countObjectsInRegisterSchemaTable($filter + self::OPEN, $this->register, $this->schemas[0]);

		$this->assertSame(count($expected), $count);
	}//end testCountPath()

	/**
	 * The cross-table path returns the same rows from both arms.
	 *
	 * @param array<string,mixed> $filter   The property filter.
	 * @param string[]            $expected The names expected in schema a.
	 *
	 * @dataProvider filterProvider
	 *
	 * @return void
	 */
	public function testCrossTablePath(array $filter, array $expected): void {
		$pairs = [];
		foreach ($this->schemas as $schema) {
			$pairs[] = ['register' => $this->register, 'schema' => $schema];
		}

		$rows = $this->mapper->searchAcrossMultipleTables($filter + self::OPEN + ['_limit' => 100], $pairs);

		$both = array_merge($expected, str_replace('a-', 'b-', $expected));
		sort($both);
		$this->assertSame($both, $this->names($rows));
	}//end testCrossTablePath()

	/**
	 * Collect and sort the `name` of every returned object.
	 *
	 * @param array<int,mixed> $rows The returned objects.
	 *
	 * @return string[] The sorted names.
	 */
	private function names(array $rows): array {
		$names = [];
		foreach ($rows as $row) {
			$data = (array)$row;
			if ($row instanceof ObjectEntity) {
				$data = $row->getObject();
			}

			$names[] = (string)($data['name'] ?? '');
		}

		sort($names);
		return $names;
	}//end names()

	/**
	 * Insert one fixture object.
	 *
	 * @param Schema              $schema The schema.
	 * @param array<string,mixed> $data   The object body.
	 *
	 * @return void
	 */
	private function insert(Schema $schema, array $data): void {
		$entity = new ObjectEntity();
		$entity->setUuid(Uuid::v4()->toRfc4122());
		$entity->setRegister((string)$this->register->getId());
		$entity->setSchema((string)$schema->getId());
		$entity->setObject($data);
		$entity->setOwner('boolean-filter-fixture');

		$this->mapper->insertObjectEntity($entity, $this->register, $schema, false);
	}//end insert()
}//end class
