<?php

/**
 * A filter can name a field inside an object property: `dunning.currentStage=2`.
 *
 * Before this, an object property's filter value was read as a list of UUIDs
 * (applyJsonObjectFilter), and a dotted key was an unknown property that added
 * `1 = 0`. Either way `?dunning.currentStage=2` answered an empty page, which
 * looks exactly like "no invoice is in a reminder round".
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-name-a-field-inside-an-object-property
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\NestedPathFilter;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\EncryptedFieldFilterException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Nested-path filters on the QueryBuilder path, the raw UNION path and in the helper.
 */
class MagicSearchHandlerNestedPathFilterTest extends TestCase {

	/**
	 * The ARInvoice shape the filters are written for.
	 *
	 * @var array<string,mixed>
	 */
	private const PROPERTIES = [
		'invoiceNumber' => ['type' => 'string'],
		'dunning'       => [
			'type'       => 'object',
			'properties' => [
				'currentStage'    => ['type' => 'integer'],
				'paused'          => ['type' => 'boolean'],
				'nextDunningDate' => ['type' => 'string'],
			],
		],
		'customer'      => ['type' => 'object'],
	];

	/**
	 * WHERE fragments captured from the QueryBuilder path.
	 *
	 * @var string[]
	 */
	private array $captured = [];

	/**
	 * The handler on a PostgreSQL connection double.
	 *
	 * @param bool $mayReadProperties Whether the caller may read the root property of a nested path.
	 *
	 * @return MagicSearchHandler
	 */
	private function handler(bool $mayReadProperties = true): MagicSearchHandler {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQL120Platform::class));
		$logger = $this->createMock(LoggerInterface::class);
		$related = $this->createMock(RelatedRowQueryApplier::class);
		$related->method('mayReadProperty')->willReturn($mayReadProperties);

		return new MagicSearchHandler(
			db: $db,
			logger: $logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			relatedRows: $related
		);
	}//end handler()

	/**
	 * A QueryBuilder double whose named parameters are the bare value and whose andWhere records the predicate.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'in', 'notIn'] as $operator) {
			$expr->method($operator)->willReturnCallback(
				static function (string $column, $value) use ($operator): string {
					if (is_array($value) === true) {
						$value = implode('|', $value);
					}

					return "{$operator}({$column},{$value})";
				}
			);
		}

		$expr->method('isNull')->willReturnCallback(static fn (string $c): string => "isNull({$c})");
		$expr->method('isNotNull')->willReturnCallback(static fn (string $c): string => "isNotNull({$c})");

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => is_array($value) ? $value : ":{$value}");
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->captured[] = (string)$predicate;
				}

				return $qb;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * Run applyObjectFilters() and return what it added.
	 *
	 * @param array<string,mixed> $filters    Object filters.
	 * @param array<string,mixed> $properties Schema properties.
	 *
	 * @return string[]
	 */
	private function applyObject(array $filters, array $properties = self::PROPERTIES): array {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);
		$this->captured = [];

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyObjectFilters');
		$method->invoke($this->handler(), $this->queryBuilder(), $filters, $schema);

		return $this->captured;
	}//end applyObject()

	/**
	 * Run the raw-SQL object builder and return its conditions.
	 *
	 * @param array<string,mixed> $query Search query.
	 *
	 * @return string[]
	 */
	private function rawObject(array $query): array {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(self::PROPERTIES);
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($v) => "'{$v}'");

		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildObjectFilterConditionsSql');
		return $method->invoke($this->handler(), $query, $schema, $connection, true);
	}//end rawObject()

	/**
	 * A path into an object property the caller may not read matches nothing, on both paths.
	 *
	 * A filter on `dunning.currentStage` is an oracle on `dunning`; the rule that
	 * withholds the property from a response has to withhold it from a filter.
	 *
	 * @return void
	 */
	public function testAPathIntoAnUnreadablePropertyMatchesNothing(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(self::PROPERTIES);
		$this->captured = [];

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyObjectFilters');
		$method->invoke($this->handler(mayReadProperties: false), $this->queryBuilder(), ['dunning.currentStage' => 2], $schema);
		$this->assertSame(['1 = 0'], $this->captured);

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($v) => "'{$v}'");
		$raw = new ReflectionMethod(MagicSearchHandler::class, 'buildObjectFilterConditionsSql');
		$this->assertSame(
			['1=0'],
			$raw->invoke($this->handler(mayReadProperties: false), ['dunning' => ['currentStage' => 2]], $schema, $connection, true)
		);
	}//end testAPathIntoAnUnreadablePropertyMatchesNothing()

	/**
	 * The dotted key and the nested map the request layer rebuilds read the same path.
	 *
	 * @return void
	 */
	public function testTheDottedAndTheNestedSpellingReadTheSamePath(): void {
		$dotted = $this->applyObject(['dunning.currentStage' => 2]);
		$nested = $this->applyObject(['dunning' => ['currentStage' => 2]]);

		$this->assertSame(["(t.dunning #>> '{currentStage}') = :2"], $dotted);
		$this->assertSame($dotted, $nested);
	}//end testTheDottedAndTheNestedSpellingReadTheSamePath()

	/**
	 * Operators apply inside the path: a numeric bound compares as a number, isnull asks for presence.
	 *
	 * @return void
	 */
	public function testOperatorsApplyInsideThePath(): void {
		$captured = $this->applyObject(['dunning' => ['currentStage' => ['gte' => '1', 'isnull' => 'false']]]);

		$this->assertCount(2, $captured);
		$this->assertStringContainsString("CAST((t.dunning #>> '{currentStage}') AS NUMERIC)", $captured[0]);
		$this->assertStringEndsWith('>= :1', $captured[0]);
		$this->assertSame("(t.dunning #>> '{currentStage}') IS NOT NULL", $captured[1]);
	}//end testOperatorsApplyInsideThePath()

	/**
	 * A boolean compares in its JSON spelling, and a list is an IN over the text forms.
	 *
	 * @return void
	 */
	public function testABooleanAndAListCompareAsJsonText(): void {
		$this->assertSame(["(t.dunning #>> '{paused}') = :true"], $this->applyObject(['dunning.paused' => true]));
		$this->assertSame(
			["(t.dunning #>> '{currentStage}') IN (:1, :2)"],
			$this->applyObject(['dunning.currentStage' => ['1', '2']])
		);
	}//end testABooleanAndAListCompareAsJsonText()

	/**
	 * A filter on an object property that is not a path map keeps its old meaning.
	 *
	 * @return void
	 */
	public function testAnObjectPropertysOwnFilterIsUnchanged(): void {
		$captured = $this->applyObject(['customer' => 'a1b2c3d4-0000-4000-8000-000000000001']);
		$this->assertCount(1, $captured);
		$this->assertStringContainsString('t.customer::text', $captured[0]);
	}//end testAnObjectPropertysOwnFilterIsUnchanged()

	/**
	 * A dot on a property that is not an object, or a segment that is not a plain name, matches nothing.
	 *
	 * @return void
	 */
	public function testAPathThatCannotBeReadMatchesNothing(): void {
		$this->assertSame(['1 = 0'], $this->applyObject(['invoiceNumber.x' => 'a']));
		$this->assertSame(['1 = 0'], $this->applyObject(["dunning.current'Stage" => 'a']));
		$this->assertNull((new NestedPathFilter())->expression(column: 't.dunning', path: ["a'}') OR 1=1 --"], isPostgres: true));
	}//end testAPathThatCannotBeReadMatchesNothing()

	/**
	 * An encrypted object property is refused as its column filter is.
	 *
	 * @return void
	 */
	public function testAPathIntoAnEncryptedPropertyIsRefused(): void {
		$properties = self::PROPERTIES;
		$properties['dunning']['x-openregister-encrypted'] = true;

		$this->expectException(EncryptedFieldFilterException::class);
		$this->applyObject(['dunning.currentStage' => 2], $properties);
	}//end testAPathIntoAnEncryptedPropertyIsRefused()

	/**
	 * The raw UNION path writes the same condition with a quoted literal.
	 *
	 * @return void
	 */
	public function testTheRawUnionPathAgrees(): void {
		$this->assertSame(['("dunning" #>> \'{currentStage}\') = \'2\''], $this->rawObject(['dunning' => ['currentStage' => 2]]));
		$this->assertSame(['("dunning" #>> \'{paused}\') = \'true\''], $this->rawObject(['dunning.paused' => 'true']));
	}//end testTheRawUnionPathAgrees()

	/**
	 * MariaDB and MySQL read the path with JSON_EXTRACT.
	 *
	 * @return void
	 */
	public function testMariaDbReadsThePathWithJsonExtract(): void {
		$expression = (new NestedPathFilter())->expression(column: 't.dunning', path: ['currentStage'], isPostgres: false);

		$this->assertSame("JSON_UNQUOTE(JSON_EXTRACT(t.dunning, '$.currentStage'))", $expression);
		$conditions = (new NestedPathFilter())->conditions(
			expression: $expression,
			value: ['lt' => 3, 'ne' => '1', 'like' => 'Her'],
			bind: static fn (string $v): string => "'{$v}'",
			isPostgres: false
		);
		$this->assertSame(
			[
				"CAST(JSON_UNQUOTE(JSON_EXTRACT(t.dunning, '$.currentStage')) AS DECIMAL(30,10)) < '3'",
				"JSON_UNQUOTE(JSON_EXTRACT(t.dunning, '$.currentStage')) <> '1'",
				"LOWER(JSON_UNQUOTE(JSON_EXTRACT(t.dunning, '$.currentStage'))) LIKE '%her%'",
			],
			$conditions
		);
	}//end testMariaDbReadsThePathWithJsonExtract()

	/**
	 * Deeper paths and several fields of one object split into one filter each.
	 *
	 * @return void
	 */
	public function testSplitCollectsEveryLeaf(): void {
		[$plain, $nested] = (new NestedPathFilter())->split(
			filters: [
				'invoiceNumber' => 'VF-1',
				'dunning'       => ['currentStage' => 2, 'paused' => 'false'],
				'customer.address.city' => 'Zuiddrecht',
			],
			properties: self::PROPERTIES
		);

		$this->assertSame(['invoiceNumber' => 'VF-1'], $plain);
		$this->assertSame(
			[
				['root' => 'dunning', 'path' => ['currentStage'], 'value' => 2],
				['root' => 'dunning', 'path' => ['paused'], 'value' => 'false'],
				['root' => 'customer', 'path' => ['address', 'city'], 'value' => 'Zuiddrecht'],
			],
			$nested
		);
	}//end testSplitCollectsEveryLeaf()
	/**
	 * A segment with a trailing newline is not a plain name and is refused.
	 *
	 * `$` matches before a final newline, so `a\n` passed the segment check and
	 * reached the JSON path literal, where MariaDB rejects it with a 500.
	 *
	 * @return void
	 */
	public function testASegmentWithATrailingNewlineIsRefused(): void {
		$filter = new NestedPathFilter();

		foreach (["a\n", "currentStage\n", "\n", "a\r\n"] as $segment) {
			$this->assertNull($filter->expression(column: 't.dunning', path: [$segment], isPostgres: true), json_encode($segment));
			$this->assertNull($filter->expression(column: 't.dunning', path: ['ok', $segment], isPostgres: false), json_encode($segment));
		}

		$this->assertNotNull($filter->expression(column: 't.dunning', path: ['currentStage'], isPostgres: true));
		$this->assertSame(['1 = 0'], $this->applyObject(["dunning.current\nStage" => 'a']));
		$this->assertSame(['1 = 0'], $this->applyObject(['dunning' => ["currentStage\n" => 'a']]));
	}//end testASegmentWithATrailingNewlineIsRefused()
}//end class
