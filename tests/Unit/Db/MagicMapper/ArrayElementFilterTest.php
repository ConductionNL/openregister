<?php

/**
 * A filter can match one element of an array property: dossiq's case list
 * filters a case type's fields on the answers a case carries in `properties[]`
 * (`{ propertyDefinition, name, value }`), through
 * `_element[properties][propertyDefinition]=…&_element[properties][value][gte]=…`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-match-one-element-of-an-array-property
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\ArrayElementFilter;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\EncryptedFieldFilterException;
use OCA\OpenRegister\Exception\RelatedRowFilterNotSupportedException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * `_element` blocks: parsing, SQL on both platforms, and the handler's guards.
 */
class ArrayElementFilterTest extends TestCase {

	/**
	 * The case shape the filter is written for.
	 *
	 * @var array<string,mixed>
	 */
	private const PROPERTIES = [
		'title'      => ['type' => 'string'],
		'properties' => ['type' => 'array', 'items' => ['type' => 'object']],
		'secrets'    => ['type' => 'array', 'x-openregister-encrypted' => true],
	];

	/**
	 * Predicates the handler added.
	 *
	 * @var string[]
	 */
	private array $captured = [];

	/**
	 * Bind a value as `:value`.
	 *
	 * @return callable
	 */
	private static function bind(): callable {
		return static fn (string $value): string => ':'.$value;
	}//end bind()

	/**
	 * One unnumbered block is one element; numbered blocks are one element each.
	 *
	 * @return void
	 */
	public function testBlocksAreReadPerElement(): void {
		$filter = new ArrayElementFilter();
		$this->assertSame(expected: [], actual: $filter->blocks(query: ['title' => 'x']));
		$this->assertSame(
			expected: [['root' => 'properties', 'fields' => ['propertyDefinition' => 'pd-7', 'value' => ['gte' => '100']]]],
			actual: $filter->blocks(query: ['_element' => ['properties' => ['propertyDefinition' => 'pd-7', 'value' => ['gte' => '100']]]])
		);
		$this->assertCount(
			expectedCount: 2,
			haystack: $filter->blocks(query: ['_element' => ['properties' => [['propertyDefinition' => 'pd-7'], ['propertyDefinition' => 'pd-8']]]])
		);
	}//end testBlocksAreReadPerElement()

	/**
	 * A block that names no fields refuses rather than matching everything.
	 *
	 * @return void
	 */
	public function testAnEmptyBlockRefuses(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		(new ArrayElementFilter())->blocks(query: ['_element' => ['properties' => []]]);
	}//end testAnEmptyBlockRefuses()

	/**
	 * PostgreSQL: one EXISTS over json_array_elements, every field on the same element.
	 *
	 * @return void
	 */
	public function testPostgresMatchesOneElementOnEveryField(): void {
		$sql = (new ArrayElementFilter())->condition(
			column: 't.properties',
			fields: ['propertyDefinition' => 'pd-7', 'value' => ['in' => ['a', 'b']]],
			bind: self::bind(),
			isPostgres: true
		);

		$this->assertStringStartsWith(
			prefix: 'EXISTS (SELECT 1 FROM json_array_elements(CASE WHEN json_typeof(t.properties::json)',
			string: (string)$sql
		);
		$this->assertStringContainsString(needle: "(cn_el.e #>> '{propertyDefinition}') = :pd-7 AND ", haystack: (string)$sql);
		$this->assertStringContainsString(needle: "(cn_el.e #>> '{value}') IN (:a, :b)", haystack: (string)$sql);
	}//end testPostgresMatchesOneElementOnEveryField()

	/**
	 * MariaDB/MySQL: the same through JSON_TABLE.
	 *
	 * @return void
	 */
	public function testMysqlReadsTheElementsThroughJsonTable(): void {
		$sql = (new ArrayElementFilter())->condition(
			column: 't.properties',
			fields: ['propertyDefinition' => 'pd-7'],
			bind: self::bind(),
			isPostgres: false
		);

		$this->assertStringContainsString(needle: "JSON_TABLE(CASE WHEN JSON_TYPE(t.properties) = 'ARRAY'", haystack: (string)$sql);
		$this->assertStringContainsString(needle: "JSON_UNQUOTE(JSON_EXTRACT(cn_el.e, '$.propertyDefinition')) = :pd-7", haystack: (string)$sql);
	}//end testMysqlReadsTheElementsThroughJsonTable()

	/**
	 * A field name that could leave the JSON path writes no SQL.
	 *
	 * @return void
	 */
	public function testAFieldNameOutsideAPlainNameWritesNothing(): void {
		$sql = (new ArrayElementFilter())->condition(column: 't.properties', fields: ["value') OR 1=1 --" => 'x'], bind: self::bind(), isPostgres: true);
		$this->assertNull(actual: $sql);
	}//end testAFieldNameOutsideAPlainNameWritesNothing()

	/**
	 * The handler narrows a list by a block on a readable array property.
	 *
	 * @return void
	 */
	public function testTheListIsNarrowedByABlock(): void {
		$captured = $this->apply(query: ['_element' => ['properties' => ['propertyDefinition' => 'pd-7', 'value' => 'ja']]]);

		$this->assertCount(expectedCount: 1, haystack: $captured);
		$this->assertStringContainsString(needle: "(cn_el.e #>> '{value}') = :ja", haystack: $captured[0]);
	}//end testTheListIsNarrowedByABlock()

	/**
	 * A property that is not an array, or one the caller may not read, matches nothing.
	 *
	 * @return void
	 */
	public function testANonArrayOrUnreadablePropertyMatchesNothing(): void {
		$this->assertSame(expected: ['1 = 0'], actual: $this->apply(query: ['_element' => ['title' => ['x' => 'y']]]));
		$this->assertSame(expected: ['1 = 0'], actual: $this->apply(query: ['_element' => ['properties' => ['value' => 'ja']]], mayRead: false));
	}//end testANonArrayOrUnreadablePropertyMatchesNothing()

	/**
	 * An encrypted array property refuses: its column holds ciphertext.
	 *
	 * @return void
	 */
	public function testAnEncryptedPropertyRefuses(): void {
		$this->expectException(exception: EncryptedFieldFilterException::class);
		$this->apply(query: ['_element' => ['secrets' => ['value' => 'x']]]);
	}//end testAnEncryptedPropertyRefuses()

	/**
	 * The raw UNION path refuses `_element` instead of answering the unfiltered set.
	 *
	 * @return void
	 */
	public function testTheUnionPathRefuses(): void {
		$this->expectException(exception: RelatedRowFilterNotSupportedException::class);
		$this->handler(mayRead: true, postgres: true)->refuseRelatedRowFilters(query: ['_element' => ['properties' => ['value' => 'x']]]);
	}//end testTheUnionPathRefuses()

	/**
	 * Run applyArrayElementFilters() and return what it added.
	 *
	 * @param array<string,mixed> $query   The request query.
	 * @param bool                $mayRead Whether the caller may read the property.
	 *
	 * @return string[]
	 */
	private function apply(array $query, bool $mayRead=true): array {
		$schema = $this->createMock(originalClassName: Schema::class);
		$schema->method('getProperties')->willReturn(self::PROPERTIES);
		$this->captured = [];

		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => ':'.$value);
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->captured[] = (string)$predicate;
				}

				return $qb;
			}
		);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyArrayElementFilters');
		$method->invoke($this->handler(mayRead: $mayRead, postgres: true), $qb, $query, $schema);

		return $this->captured;
	}//end apply()

	/**
	 * The handler on a connection double.
	 *
	 * @param bool $mayRead  Whether the caller may read the property.
	 * @param bool $postgres Whether the platform is PostgreSQL.
	 *
	 * @return MagicSearchHandler
	 */
	private function handler(bool $mayRead, bool $postgres): MagicSearchHandler {
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$platform = $this->createMock(originalClassName: PostgreSQL120Platform::class);
		if ($postgres === false) {
			$platform = $this->createMock(originalClassName: MySQL80Platform::class);
		}

		$db->method('getDatabasePlatform')->willReturn($platform);
		$logger  = $this->createMock(originalClassName: LoggerInterface::class);
		$related = $this->createMock(originalClassName: RelatedRowQueryApplier::class);
		$related->method('mayReadProperty')->willReturn($mayRead);

		return new MagicSearchHandler(
			db: $db,
			logger: $logger,
			rbacHandler: $this->createMock(originalClassName: MagicRbacHandler::class),
			organizationHandler: $this->createMock(originalClassName: MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer(logger: $logger),
			relatedRows: $related
		);
	}//end handler()
}//end class
