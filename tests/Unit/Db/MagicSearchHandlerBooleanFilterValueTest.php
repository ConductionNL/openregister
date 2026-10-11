<?php

/**
 * Regression: a PHP boolean filter value returned no rows.
 *
 * Every object-filter builder turned the value into SQL text with a string cast
 * or a string-typed parameter. PHP casts `false` to `''` and `true` to `'1'`.
 * PostgreSQL refuses `boolean = ''` (SQLSTATE 22P02), the mapper logs and
 * swallows that, and the caller gets zero rows: dossiq's work queue, which asks
 * for `isDraft => false`, came back empty on 2026-10-10 while `'false'` returned
 * six cases.
 *
 * These tests drive the three builders that read object filters (the
 * QueryBuilder path, the raw-SQL UNION path and the facet path) and assert that
 * a boolean value reaches the condition in a form every supported database reads
 * as the boolean, the same form its string spelling reaches.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/boolean-filter-values/specs/zoeken-filteren/spec.md#requirement-a-boolean-filter-value-filters-like-its-string-form
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCA\OpenRegister\Support\FilterParams;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * A boolean filter value reaches every object-filter builder as its string form.
 */
class MagicSearchHandlerBooleanFilterValueTest extends TestCase {

	/**
	 * Schema properties used by every case.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const PROPERTIES = [
		'isDraft'  => ['type' => 'boolean'],
		'priority' => ['type' => 'integer'],
		'label'    => ['type' => 'string'],
	];

	private MagicSearchHandler $handler;

	/**
	 * WHERE fragments captured from a QueryBuilder double.
	 *
	 * @var string[]
	 */
	private array $captured = [];

	protected function setUp(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')
			->willReturn($this->createMock(PostgreSQL120Platform::class));
		$logger = $this->createMock(LoggerInterface::class);

		$this->handler = new MagicSearchHandler(
			db: $db,
			logger: $logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);

		$this->captured = [];
	}//end setUp()

	/**
	 * Cases: filter bag, expected QueryBuilder fragment, expected raw SQL fragment.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string,2:string}>
	 */
	public static function booleanFilterProvider(): array {
		return [
			'bool false on a boolean column'      => [['isDraft' => false], 'eq(t.is_draft,0)', '"is_draft" = \'0\''],
			'string false on a boolean column'    => [['isDraft' => 'false'], 'eq(t.is_draft,0)', '"is_draft" = \'0\''],
			'bool true on a boolean column'       => [['isDraft' => true], 'eq(t.is_draft,1)', '"is_draft" = \'1\''],
			'string TRUE on a boolean column'     => [['isDraft' => 'TRUE'], 'eq(t.is_draft,1)', '"is_draft" = \'1\''],
			'bool list on a boolean column'       => [['isDraft' => [false, true]], 'in(t.is_draft,0|1)', '"is_draft" IN (\'0\', \'1\')'],
			'ne true on a boolean column'         => [['isDraft' => ['ne' => true]], 'neq(t.is_draft,1)', '"is_draft" <> \'1\''],
			'in false on a boolean column'        => [['isDraft' => ['in' => [false]]], 'in(t.is_draft,0)', '"is_draft" IN (\'0\')'],
			'bool true on an integer column'      => [['priority' => true], 'eq(t.priority,1)', '"priority" = \'1\''],
			'bool false on a string column'       => [['label' => false], 'eq(t.label,false)', '"label" = \'false\''],
		];
	}//end booleanFilterProvider()

	/**
	 * The QueryBuilder path binds the boolean in its comparable form.
	 *
	 * @param array<string,mixed> $filters  The object filters.
	 * @param string              $expected The expected QueryBuilder fragment.
	 *
	 * @return void
	 */
	#[DataProvider('booleanFilterProvider')]
	public function testQueryBuilderPath(array $filters, string $expected): void {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyObjectFilters');
		$method->setAccessible(true);
		$method->invoke($this->handler, $this->makeQueryBuilder(), $filters, $this->makeSchema());

		$this->assertSame([$expected], $this->captured);
	}//end testQueryBuilderPath()

	/**
	 * The raw-SQL UNION path quotes the boolean in its comparable form.
	 *
	 * @param array<string,mixed> $filters  The object filters.
	 * @param string              $_qb      The QueryBuilder fragment (unused here).
	 * @param string              $expected The expected raw SQL fragment.
	 *
	 * @return void
	 */
	#[DataProvider('booleanFilterProvider')]
	public function testRawSqlPath(array $filters, string $_qb, string $expected): void {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildObjectFilterConditionsSql');
		$method->setAccessible(true);
		$conditions = $method->invoke($this->handler, $filters, $this->makeSchema(), $this->makeConnection(), true);

		$this->assertSame([$expected], $conditions);
	}//end testRawSqlPath()

	/**
	 * The facet path filters its counts the same way.
	 *
	 * @param array<string,mixed> $filters  The object filters.
	 * @param string              $expected The expected QueryBuilder fragment.
	 *
	 * @return void
	 */
	#[DataProvider('booleanFilterProvider')]
	public function testFacetPath(array $filters, string $expected): void {
		// The facet path has no operator bags: an `ne`/`in` bag is a plain IN there.
		if (is_array(reset($filters)) === true && array_is_list(reset($filters)) === false) {
			$this->markTestSkipped('the facet path reads an operator bag as a value list');
		}

		$db = $this->createMock(IDBConnection::class);
		$facets = new MagicFacetHandler(db: $db, logger: $this->createMock(LoggerInterface::class));

		// Pretend every column exists, so the lookup never queries the database.
		$cache = new ReflectionProperty(MagicFacetHandler::class, 'columnCache');
		$cache->setAccessible(true);
		$cache->setValue($facets, ['oc_openregister_table_1_1' => ['is_draft' => true, 'priority' => true, 'label' => true]]);

		$method = new ReflectionMethod(MagicFacetHandler::class, 'applyObjectFieldFilters');
		$method->setAccessible(true);
		$method->invoke($facets, $this->makeQueryBuilder(), $filters, 'openregister_table_1_1', $this->makeSchema());

		$this->assertSame([str_replace('t.', '', $expected)], $this->captured);
	}//end testFacetPath()

	/**
	 * `isnull` and `like` keep their own value rules.
	 *
	 * @return void
	 */
	public function testIsNullAndLikeValuesAreLeftAlone(): void {
		$value = FilterParams::comparableValue(value: ['isnull' => true, 'like' => 'tru', 'ne' => false], propertyType: 'boolean');

		$this->assertSame(['isnull' => true, 'like' => 'tru', 'ne' => '0'], $value);
	}//end testIsNullAndLikeValuesAreLeftAlone()

	/**
	 * A schema double with the shared properties.
	 *
	 * @return Schema The schema double.
	 */
	private function makeSchema(): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(self::PROPERTIES);
		return $schema;
	}//end makeSchema()

	/**
	 * A connection double whose quote() wraps values in single quotes.
	 *
	 * @return object The connection double.
	 */
	private function makeConnection(): object {
		$conn = $this->createMock(IDBConnection::class);
		$conn->method('quote')->willReturnCallback(static fn ($v) => "'{$v}'");
		return $conn;
	}//end makeConnection()

	/**
	 * A QueryBuilder double that renders each predicate as text, values as PHP's string cast.
	 *
	 * @return IQueryBuilder The query-builder double.
	 */
	private function makeQueryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'in', 'notIn'] as $operator) {
			$expr->method($operator)->willReturnCallback(
				static function (string $column, $value) use ($operator): string {
					if (is_array($value) === true) {
						$value = implode('|', array_map(static fn ($v): string => (string)$v, $value));
					}

					return "{$operator}({$column},{$value})";
				}
			);
		}

		$expr->method('isNull')->willReturnCallback(static fn (string $c): string => "isNull({$c})");
		$expr->method('isNotNull')->willReturnCallback(static fn (string $c): string => "isNotNull({$c})");

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		// A bound parameter reaches the database as PHP's string cast of the value.
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value) => is_array($value) === true ? $value : (string)$value
		);
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->captured[] = (string)$predicate;
				}

				return $qb;
			}
		);

		return $qb;
	}//end makeQueryBuilder()
}//end class
