<?php

/**
 * Tests for boolean operators and wildcards in the `_search` term.
 *
 * `_search` used to reach SQL as one substring pattern on both the QueryBuilder
 * path and the raw-SQL UNION path, so `dakkapel AND NOT geweigerd` was searched
 * for as that literal run of characters and found nothing. This locks the new
 * grammar on BOTH paths, because a change applied to one of them only is the
 * shape of bug openregister#3611 already cost us once: two sibling code paths
 * answering the same question differently, each confidently.
 *
 * The regression half matters as much as the feature half. A term carrying no
 * operator, bracket, quote or wildcard must produce exactly the SQL it produced
 * before, or every saved search in the fleet changes meaning at once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\SearchTermSyntaxException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Support\FilterParams;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Locks the boolean grammar of `_search` on both search paths.
 */
class MagicSearchHandlerBooleanTermTest extends TestCase {

	private IDBConnection&MockObject $db;

	private LoggerInterface&MockObject $logger;

	private MagicSearchHandler $handler;

	/**
	 * WHERE fragments captured from the QueryBuilder path.
	 *
	 * @var string[]
	 */
	private array $captured = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->db->method('getDatabasePlatform')
			->willReturn($this->createMock(PostgreSQL120Platform::class));

		$this->handler = new MagicSearchHandler(
			db: $this->db,
			logger: $this->logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($this->logger)
		);

		$this->captured = [];
	}//end setUp()

	/**
	 * A schema double carrying one searchable string property.
	 *
	 * @return Schema The schema double.
	 */
	private function makeSchema(?array $properties = null): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties ?? ['omschrijving' => ['type' => 'string']]);

		return $schema;
	}//end makeSchema()

	/**
	 * A connection double whose quote() wraps values in single quotes.
	 *
	 * @return object The connection double.
	 */
	private function makeConnection(): object {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($v) => "'{$v}'");

		return $connection;
	}//end makeConnection()

	/**
	 * Run the raw-SQL search builder, the one the UNION path uses.
	 *
	 * @param string $search The search term.
	 *
	 * @return string The generated SQL condition.
	 */
	private function unionSql(string $search, ?array $properties = null): string {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildSearchConditionSql');
		$method->setAccessible(true);

		return (string)$method->invoke(
			$this->handler,
			$search,
			$this->makeSchema(properties: $properties),
			[],
			$this->makeConnection(),
			true,
			null
		);
	}//end unionSql()

	/**
	 * A QueryBuilder double that renders each predicate as readable text.
	 *
	 * @return IQueryBuilder The query-builder double.
	 */
	private function makeQueryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$composite = $this->createMock(ICompositeExpression::class);
		$composite->method('add')->willReturnCallback(
			function (mixed $part) use ($composite): ICompositeExpression {
				$this->captured[] = (string)$part;
				return $composite;
			}
		);
		$composite->method('count')->willReturn(1);
		$expr->method('orX')->willReturn($composite);
		$expr->method('like')->willReturnCallback(
			static fn ($column, $value): string => "like({$column},{$value})"
		);
		$expr->method('isNull')->willReturnCallback(static fn (string $c): string => "isNull({$c})");
		$expr->method('isNotNull')->willReturnCallback(static fn (string $c): string => "isNotNull({$c})");
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

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createFunction')->willReturnCallback(static fn (string $sql): string => $sql);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => (string)$value);
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					// The composite double cannot stringify; its parts were already
					// captured by add(), so record only that it was applied.
					if (is_object($predicate) === true && ($predicate instanceof \Stringable) === false) {
						$this->captured[] = 'composite';
						continue;
					}

					$this->captured[] = (string)$predicate;
				}

				return $qb;
			}
		);

		return $qb;
	}//end makeQueryBuilder()

	/**
	 * Run the QueryBuilder search path and return what it added to the WHERE.
	 *
	 * @param string $search The search term.
	 *
	 * @return string The captured predicate.
	 */
	private function queryBuilderSql(string $search, ?array $properties = null): string {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyFullTextSearch');
		$method->setAccessible(true);
		$method->invoke(
			$this->handler,
			$this->makeQueryBuilder(),
			$search,
			$this->makeSchema(properties: $properties),
			false
		);

		return implode(' ', $this->captured);
	}//end queryBuilderSql()

	/**
	 * Regression: a term with no operator produces the substring match it has
	 * always produced, through the untouched expression-builder code.
	 *
	 * @return void
	 */
	public function testAnOperatorFreeTermKeepsItsCurrentMeaningOnTheUnionPath(): void {
		$sql = $this->unionSql('dakkapel geweigerd');

		$this->assertStringContainsString("ILIKE '%dakkapel geweigerd%'", $sql);
		$this->assertStringNotContainsString(' AND ', $sql);
		$this->assertStringNotContainsString('NOT ', $sql);
		$this->assertStringNotContainsString('COALESCE', $sql);
	}//end testAnOperatorFreeTermKeepsItsCurrentMeaningOnTheUnionPath()

	/**
	 * Regression, same term, on the QueryBuilder path.
	 *
	 * @return void
	 */
	public function testAnOperatorFreeTermKeepsItsCurrentMeaningOnTheQueryBuilderPath(): void {
		$sql = $this->queryBuilderSql('dakkapel geweigerd');

		$this->assertStringContainsString('composite', $sql);
		$this->assertStringContainsString('like(LOWER(t.', $sql);
		$this->assertStringContainsString('%dakkapel geweigerd%', $sql);
		$this->assertStringNotContainsString('NOT ', $sql);
	}//end testAnOperatorFreeTermKeepsItsCurrentMeaningOnTheQueryBuilderPath()

	/**
	 * The spec scenario, on the UNION path: `NOT` excludes rather than searching
	 * for the word "NOT".
	 *
	 * @return void
	 */
	public function testNotExcludesOnTheUnionPath(): void {
		$sql = $this->unionSql('dakkapel AND NOT geweigerd');

		$this->assertStringContainsString("ILIKE '%dakkapel%'", $sql);
		$this->assertStringContainsString('NOT (', $sql);
		$this->assertStringContainsString("ILIKE '%geweigerd%'", $sql);
		$this->assertStringContainsString(' AND ', $sql);
	}//end testNotExcludesOnTheUnionPath()

	/**
	 * The same term on the QueryBuilder path, so the two cannot disagree.
	 *
	 * @return void
	 */
	public function testNotExcludesOnTheQueryBuilderPath(): void {
		$sql = $this->queryBuilderSql('dakkapel AND NOT geweigerd');

		$this->assertStringContainsString('%dakkapel%', $sql);
		$this->assertStringContainsString('NOT (', $sql);
		$this->assertStringContainsString('%geweigerd%', $sql);
	}//end testNotExcludesOnTheQueryBuilderPath()

	/**
	 * A negated term must be null-safe. LIKE against a NULL column is NULL, and
	 * NOT NULL is NULL rather than TRUE, so without COALESCE a record with no
	 * description would silently drop out of `NOT geweigerd`.
	 *
	 * @return void
	 */
	public function testANegatedTermIsNullSafeOnBothPaths(): void {
		$this->assertStringContainsString('COALESCE', $this->unionSql('NOT geweigerd'));
		$this->assertStringContainsString('COALESCE', $this->queryBuilderSql('NOT geweigerd'));
	}//end testANegatedTermIsNullSafeOnBothPaths()

	/**
	 * A trailing wildcard anchors the start of the match rather than being
	 * searched for as a literal asterisk.
	 *
	 * @return void
	 */
	public function testATrailingWildcardAnchorsTheStartOnBothPaths(): void {
		$this->assertStringContainsString("ILIKE 'vergunning%'", $this->unionSql('vergunning*'));
		$this->assertStringNotContainsString('vergunning*', $this->unionSql('vergunning*'));

		$this->captured = [];
		$this->assertStringContainsString("LIKE vergunning%", $this->queryBuilderSql('vergunning*'));
	}//end testATrailingWildcardAnchorsTheStartOnBothPaths()

	/**
	 * Brackets group, and the grouping survives into the SQL.
	 *
	 * @return void
	 */
	public function testBracketsGroupOnTheUnionPath(): void {
		$sql = $this->unionSql('dakkapel AND (geweigerd OR verleend)');

		$this->assertStringContainsString(' OR ', $sql);
		$this->assertStringContainsString(' AND ', $sql);
		$this->assertStringContainsString("ILIKE '%verleend%'", $sql);
	}//end testBracketsGroupOnTheUnionPath()

	/**
	 * A malformed term is refused rather than compiled, on both paths. Silently
	 * compiling it as a literal returns zero rows, which looks exactly like a
	 * search that found nothing.
	 *
	 * @return void
	 */
	public function testAMalformedTermIsRefusedOnTheUnionPath(): void {
		$this->expectException(SearchTermSyntaxException::class);
		$this->unionSql('dakkapel AND (geweigerd');
	}//end testAMalformedTermIsRefusedOnTheUnionPath()

	/**
	 * @return void
	 */
	public function testAMalformedTermIsRefusedOnTheQueryBuilderPath(): void {
		$this->expectException(SearchTermSyntaxException::class);
		$this->queryBuilderSql('dakkapel AND (geweigerd');
	}//end testAMalformedTermIsRefusedOnTheQueryBuilderPath()

	/**
	 * The missing-value bucket is selectable through the object-filter grammar
	 * this endpoint already speaks, and BOTH spellings of it mean one thing.
	 *
	 * This is the half of openregister#3611 that is already closed and must stay
	 * closed. `SearchQueryHandler::buildSearchQuery()` lifts `filter[x]` into a
	 * bare `x` before the mapper sees it, and its underscore reconstruction turns
	 * `?resultType_isnull=true` into the same nested bag. So the consumer may
	 * write either spelling and gets the same rows, and nothing here widens that.
	 *
	 * @return void
	 */
	public function testTheMissingBucketFilterMeansTheSameInBothSpellings(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(['resultType' => ['type' => 'string']]);

		// What both spellings become by the time the mapper reads them.
		$nested = ['resultType' => ['isnull' => 'true']];

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyObjectFilters');
		$method->setAccessible(true);
		$method->invoke($this->handler, $this->makeQueryBuilder(), $nested, $schema);

		$this->assertContains(
			'isNull(t.result_type)',
			$this->captured,
			'The missing bucket is selected by the isnull operator on the property.'
		);

		// The bracket spelling lifts to exactly that bag, and leaves no `filter`
		// key behind to be read as a property nothing declares.
		$lifted = FilterParams::liftBracketFilter(
			params: ['filter' => ['resultType' => ['isnull' => 'true']]]
		);
		$this->assertSame($nested, $lifted);
		$this->assertArrayNotHasKey('filter', $lifted);
	}//end testTheMissingBucketFilterMeansTheSameInBothSpellings()

	/**
	 * The spec scenario: an identifier column that declares `exact` does not
	 * answer to half an identifier.
	 *
	 * The declaration has to beat the term, not merely sit beside it. If the
	 * column kept its `%term%` comparison the search would still return the
	 * object, and the declaration would look exactly like it was working.
	 *
	 * @return void
	 */
	public function testAnExactPropertyComparesTheWholeValue(): void {
		$properties = ['zaaknummer' => ['type' => 'string', 'matchType' => 'exact']];

		$sql = $this->unionSql('Z-2026 AND geweigerd', $properties);
		$this->assertStringContainsString("COALESCE(\"zaaknummer\"::text, '') ILIKE 'z-2026'", $sql);
		$this->assertStringNotContainsString("COALESCE(\"zaaknummer\"::text, '') ILIKE '%z-2026%'", $sql);

		// The metadata columns keep the substring pattern, because `_name` and
		// its siblings declare nothing. The declaration governs the column that
		// carries it, not the whole scan.
		$this->assertStringContainsString("COALESCE(_name::text, '') ILIKE '%z-2026%'", $sql);

		$this->captured = [];
		$qbSql = $this->queryBuilderSql('Z-2026 AND geweigerd', $properties);
		$this->assertStringContainsString('LOWER(COALESCE(t."zaaknummer", \'\')) LIKE z-2026', $qbSql);
	}//end testAnExactPropertyComparesTheWholeValue()

	/**
	 * A property declaring `prefix` anchors the start of the value, whatever
	 * the caller typed around the term.
	 *
	 * @return void
	 */
	public function testAPrefixPropertyAnchorsTheStart(): void {
		$properties = ['kenmerk' => ['type' => 'string', 'matchType' => 'prefix']];

		$sql = $this->unionSql('zaak AND open', $properties);
		$this->assertStringContainsString("COALESCE(\"kenmerk\"::text, '') ILIKE 'zaak%'", $sql);
	}//end testAPrefixPropertyAnchorsTheStart()

	/**
	 * A property declaring `range` is a pair of bounds, not a term, so the
	 * free-text scan leaves its column out entirely.
	 *
	 * @return void
	 */
	public function testARangePropertyIsNotScannedForATerm(): void {
		$properties = [
			'omschrijving' => ['type' => 'string'],
			'bedrag' => ['type' => 'string', 'matchType' => 'range'],
		];

		$sql = $this->unionSql('dakkapel AND open', $properties);
		$this->assertStringContainsString('omschrijving', $sql);
		$this->assertStringNotContainsString('bedrag', $sql);
	}//end testARangePropertyIsNotScannedForATerm()

	/**
	 * A property declaring `fuzzy` is compared by similarity rather than by
	 * LIKE, on the platform that has pg_trgm.
	 *
	 * @return void
	 */
	public function testAFuzzyPropertyUsesSimilarity(): void {
		$properties = ['naam' => ['type' => 'string', 'matchType' => 'fuzzy']];

		$sql = $this->unionSql('jansen AND open', $properties);
		$this->assertStringContainsString('similarity(', $sql);
		$this->assertStringContainsString("'jansen'", $sql);
	}//end testAFuzzyPropertyUsesSimilarity()

	/**
	 * A declaration can pull a column into the scan that the old rule left out.
	 * That is the point of declaring one, and it is also the only way a
	 * declaration is allowed to widen the scan.
	 *
	 * @return void
	 */
	public function testADeclarationCanPullANonStringColumnIn(): void {
		$properties = ['jaar' => ['type' => 'integer', 'matchType' => 'exact']];

		$sql = $this->unionSql('2026 AND open', $properties);
		$this->assertStringContainsString('jaar', $sql);
	}//end testADeclarationCanPullANonStringColumnIn()

	/**
	 * The regression, at the SQL level: a schema whose properties declare
	 * nothing produces the same scan it produced before the match types
	 * existed. A date-formatted string stays out, a plain string stays in, and
	 * both keep the two sided substring pattern.
	 *
	 * @return void
	 */
	public function testAnUndeclaredSchemaScansExactlyWhatItScannedBefore(): void {
		$properties = [
			'omschrijving' => ['type' => 'string'],
			'startdatum' => ['type' => 'string', 'format' => 'date'],
			'aantal' => ['type' => 'integer'],
			'spoed' => ['type' => 'boolean'],
		];

		$sql = $this->unionSql('dakkapel geweigerd', $properties);

		$this->assertStringContainsString("ILIKE '%dakkapel geweigerd%'", $sql);
		$this->assertStringContainsString('omschrijving', $sql);

		// `spoed` is the one that catches a participation rule quietly widened
		// to the auto-detected type: a boolean auto-detects as `exact`, and
		// `exact` is not `range`, so a rule phrased that way would pull a column
		// into the scan that no search has ever read.
		foreach (['startdatum', 'aantal', 'spoed'] as $column) {
			$this->assertStringNotContainsString(
				$column,
				$sql,
				"An undeclared {$column} was not scanned before this change."
			);
		}
	}//end testAnUndeclaredSchemaScansExactlyWhatItScannedBefore()

}//end class
