<?php

/**
 * Regression: ordering by a translatable property gave two sorted runs.
 *
 * Seen on cloud.conduction.nl on 8 October 2026 in dossiq's case types:
 * ordered by title ascending, the list read College-besluit to Toezichtzaak
 * Milieu and then Cultuursubsidie to Woo-verzoek. A translatable property is
 * stored as a language map (`{"nl":"Woo-verzoek"}`) on rows saved since
 * translations arrived and as a plain string on older rows. `applySorting()`
 * ordered by the raw column, so every plain row sorted before every map row.
 *
 * These tests run the ORDER BY that `applySorting()` builds against a real
 * SQLite engine holding mixed rows, so they fail on the old bare-column order.
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
 * Since 9 October 2026 the same tests also lock that text orders without
 * regard to case (cloud check: "Leverancier IBAN-wijziging" sorted before
 * "Leverancier accreditatie").
 *
 * @spec openspec/changes/order-filters-and-notification-links/specs/register-i18n/spec.md#requirement-ordering-by-a-translatable-property-must-follow-the-value-a-person-sees
 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/register-i18n/spec.md#requirement-ordering-by-a-text-property-must-ignore-case
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Runs the translatable ORDER BY against SQLite with mixed rows.
 */
class MagicSearchHandlerTranslatableSortTest extends TestCase {

	/**
	 * Every addOrderBy() call, rendered as [sort expression, direction].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $orderBy = [];

	private PDO $pdo;

	protected function setUp(): void {
		if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
			$this->markTestSkipped('pdo_sqlite is not available.');
		}

		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->exec('CREATE TABLE objects (title TEXT, other TEXT)');
		$this->orderBy = [];
	}//end setUp()

	/**
	 * Build the handler on a connection that reports the given platform.
	 *
	 * @param object               $platform        The Doctrine platform the connection reports.
	 * @param LanguageService|null $languageService The request's language state, if any.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function makeHandler(object $platform, ?LanguageService $languageService = null): MagicSearchHandler {
		$logger = $this->createMock(LoggerInterface::class);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);

		$arguments = [
			'db' => $db,
			'logger' => $logger,
			'rbacHandler' => $this->createMock(MagicRbacHandler::class),
			'organizationHandler' => $this->createMock(MagicOrganizationHandler::class),
			'schemaTypeConverter' => new SchemaTypeConverter(),
			'dateTimeNormalizer' => new DateTimeNormalizer($logger),
			'relatedRows' => $this->createMock(RelatedRowQueryApplier::class),
		];
		// Passed only when a test needs it, so the ordering tests also run
		// against the handler as it was before the language service arrived.
		if ($languageService !== null) {
			$arguments['languageService'] = $languageService;
		}

		return new MagicSearchHandler(...$arguments);
	}//end makeHandler()

	/**
	 * A QueryBuilder double that quotes like SQLite and records ORDER BY.
	 *
	 * @return IQueryBuilder The query-builder double.
	 */
	private function makeQueryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getColumnName')->willReturnCallback(
			static fn (string $column, string $alias = ''): string => ($alias === '' ? '' : "\"{$alias}\".") . "\"{$column}\""
		);
		$qb->method('createFunction')->willReturnCallback(
			function (string $call): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn($call);
				return $function;
			}
		);
		$qb->method('addOrderBy')->willReturnCallback(
			function ($sort, $direction = null) use ($qb) {
				$this->orderBy[] = [(string) $sort, (string) $direction];
				return $qb;
			}
		);

		return $qb;
	}//end makeQueryBuilder()

	/**
	 * Insert rows, sort them with the ORDER BY applySorting() builds, return the titles shown.
	 *
	 * @param array<int, string>   $stored     The raw column values.
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param string               $direction  asc or desc.
	 * @param array<int, string>   $languages  The language chain.
	 *
	 * @return array<int, string> The stored values in the order the database returned them.
	 */
	private function sortRows(array $stored, array $properties, string $direction, array $languages = ['nl']): array {
		$insert = $this->pdo->prepare('INSERT INTO objects (title) VALUES (?)');
		foreach ($stored as $value) {
			$insert->execute([$value]);
		}

		$schema = new Schema();
		$schema->setProperties($properties);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applySorting');
		$method->invoke(
			$this->makeHandler(new SqlitePlatform()),
			$this->makeQueryBuilder(),
			['title' => $direction],
			$schema,
			null,
			null,
			$languages
		);

		$this->assertCount(1, $this->orderBy);
		[$expression, $sqlDirection] = $this->orderBy[0];
		// The double quotes the double emits name the alias `t`; SQLite reads them as identifiers.
		$sql = 'SELECT title FROM objects AS t ORDER BY ' . $expression . ' ' . $sqlDirection;

		return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
	}//end sortRows()

	public function testMixedPlainAndLanguageMapTitlesSortAsOneList(): void {
		$result = $this->sortRows(
			stored: [
				'{"nl":"Woo-verzoek"}',
				'Toezichtzaak Milieu',
				'{"nl":"Cultuursubsidie 2026"}',
				'College-besluit',
				'{"nl":"Omgevingsvergunning"}',
				'Handhavingszaak',
			],
			properties: ['title' => ['type' => 'string', 'translatable' => true]],
			direction: 'asc'
		);

		$this->assertSame(
			[
				'College-besluit',
				'{"nl":"Cultuursubsidie 2026"}',
				'Handhavingszaak',
				'{"nl":"Omgevingsvergunning"}',
				'Toezichtzaak Milieu',
				'{"nl":"Woo-verzoek"}',
			],
			$result
		);
	}//end testMixedPlainAndLanguageMapTitlesSortAsOneList()

	public function testMixedTitlesSortDescendingAsOneList(): void {
		$result = $this->sortRows(
			stored: ['Ticket', '{"nl":"Woo-verzoek"}', 'Mandaatbesluit', '{"nl":"Evenementenvergunning"}'],
			properties: ['title' => ['type' => 'string', 'translatable' => true]],
			direction: 'desc'
		);

		$this->assertSame(
			['{"nl":"Woo-verzoek"}', 'Ticket', 'Mandaatbesluit', '{"nl":"Evenementenvergunning"}'],
			$result
		);
	}//end testMixedTitlesSortDescendingAsOneList()

	public function testTheFirstLanguageOfTheChainDecides(): void {
		$result = $this->sortRows(
			stored: ['{"nl":"Aanvraag","en":"Zoning"}', '{"nl":"Zienswijze","en":"Appeal"}'],
			properties: ['title' => ['type' => 'string', 'translatable' => true]],
			direction: 'asc',
			languages: ['en', 'nl']
		);

		$this->assertSame(['{"nl":"Zienswijze","en":"Appeal"}', '{"nl":"Aanvraag","en":"Zoning"}'], $result);
	}//end testTheFirstLanguageOfTheChainDecides()

	public function testAMapWithoutAChainLanguageSortsByItsFirstValue(): void {
		$result = $this->sortRows(
			stored: ['{"de":"Zulassung"}', 'Melding', '{"fr":"Avis"}'],
			properties: ['title' => ['type' => 'string', 'translatable' => true]],
			direction: 'asc'
		);

		$this->assertSame(['{"fr":"Avis"}', 'Melding', '{"de":"Zulassung"}'], $result);
	}//end testAMapWithoutAChainLanguageSortsByItsFirstValue()

	public function testAPlainTextPropertySortsWithoutRegardToCase(): void {
		// Cloud check, 9 October 2026: "Leverancier IBAN-wijziging" sorted
		// before "Leverancier accreditatie" because capitals sort first.
		$result = $this->sortRows(
			stored: ['Leverancier IBAN-wijziging', 'leverancier zorg', 'Leverancier accreditatie', 'Aanvraag'],
			properties: ['title' => ['type' => 'string']],
			direction: 'asc'
		);

		$this->assertSame(
			['Aanvraag', 'Leverancier accreditatie', 'Leverancier IBAN-wijziging', 'leverancier zorg'],
			$result
		);
	}//end testAPlainTextPropertySortsWithoutRegardToCase()

	public function testATranslatablePropertySortsWithoutRegardToCase(): void {
		$result = $this->sortRows(
			stored: ['{"nl":"Leverancier IBAN-wijziging"}', 'Leverancier accreditatie', '{"nl":"leverancier zorg"}'],
			properties: ['title' => ['type' => 'string', 'translatable' => true]],
			direction: 'desc'
		);

		$this->assertSame(
			['{"nl":"leverancier zorg"}', '{"nl":"Leverancier IBAN-wijziging"}', 'Leverancier accreditatie'],
			$result
		);
	}//end testATranslatablePropertySortsWithoutRegardToCase()

	public function testNameMetadataSortsWithoutRegardToCaseAndDatesKeepTheirColumn(): void {
		$schema = new Schema();
		$schema->setProperties(['title' => ['type' => 'string']]);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applySorting');
		$method->invoke(
			$this->makeHandler(new SqlitePlatform()),
			$this->makeQueryBuilder(),
			['@self.name' => 'asc', '_summary' => 'desc', '_created' => 'desc', '@self.updated' => 'asc'],
			$schema,
			null,
			null,
			['nl']
		);

		$this->assertSame(
			[
				['LOWER("t"."_name")', 'ASC'],
				['LOWER("t"."_summary")', 'DESC'],
				['t._created', 'DESC'],
				['t._updated', 'ASC'],
			],
			$this->orderBy
		);
	}//end testNameMetadataSortsWithoutRegardToCaseAndDatesKeepTheirColumn()

	public function testANumberPropertyKeepsItsBareColumn(): void {
		$schema = new Schema();
		$schema->setProperties(['amount' => ['type' => 'number'], 'when' => ['type' => 'string', 'format' => 'date']]);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applySorting');
		$method->invoke($this->makeHandler(new SqlitePlatform()), $this->makeQueryBuilder(), ['amount' => 'asc', 'when' => 'asc'], $schema, null, null, ['nl']);

		$this->assertSame([['t.amount', 'ASC'], ['t.when', 'ASC']], $this->orderBy);
	}//end testANumberPropertyKeepsItsBareColumn()

	public function testPostgresLowersTextAfterACastToText(): void {
		// PostgreSQL has no LOWER() for json or timestamp, and a table keeps
		// its column type when a property changes type.
		$schema = new Schema();
		$schema->setProperties(['title' => ['type' => 'string']]);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applySorting');
		$method->invoke($this->makeHandler(new PostgreSQLPlatform()), $this->makeQueryBuilder(), ['title' => 'asc'], $schema, null, null, ['nl']);

		$this->assertSame([['LOWER(CAST("t"."title" AS TEXT))', 'ASC']], $this->orderBy);
	}//end testPostgresLowersTextAfterACastToText()

	public function testPostgresExpressionNeverCastsToJson(): void {
		// A plain value that starts with a brace must not fail the query, so the
		// PostgreSQL branch extracts with a regular expression, never ::jsonb.
		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildTranslatableSortSql');
		$sql = $method->invoke($this->makeHandler(new PostgreSQLPlatform()), '"t"."title"', ['nl', "x'); DROP TABLE t; --"]);

		$this->assertStringNotContainsStringIgnoringCase('jsonb', $sql);
		$this->assertStringContainsString("substring(CAST(\"t\".\"title\" AS TEXT) from '\"nl\"", $sql);
		// Language codes are reduced to [a-z0-9-] before they enter the SQL.
		$this->assertStringNotContainsString('DROP', $sql);
		$this->assertStringNotContainsString("x')", $sql);
	}//end testPostgresExpressionNeverCastsToJson()

	public function testTheChainFollowsTheAcceptedLanguageTheRegisterOffers(): void {
		$languageService = new LanguageService();
		$languageService->setAcceptedLanguages(['en-GB', 'de']);

		$register = new Register();
		$register->setLanguages(['nl', 'en']);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'sortLanguageChain');
		$chain = $method->invoke($this->makeHandler(new SqlitePlatform(), $languageService), $register);

		$this->assertSame(['en', 'nl'], $chain);
		// Reading the chain must not flag a fallback on the response.
		$this->assertFalse($languageService->isFallbackUsed());
	}//end testTheChainFollowsTheAcceptedLanguageTheRegisterOffers()
}//end class
