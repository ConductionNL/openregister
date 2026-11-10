<?php

/**
 * Characterisation: how MagicSearchHandler reads row values and picks column references.
 *
 * Pins, branch by branch, convertRowValue(), rowMetadataValues(),
 * getSchemaColumnMaps(), buildFilterColumnRef() and sortLanguageChain(), so
 * splitting them into named steps cannot change a value read back from a
 * magic table, a cast in a filter, or the order of a translated sort.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use DateTime;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\TypedColumnRef;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\LanguageServiceInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden row values, column references and language chains.
 */
class MagicSearchHandlerRowValuesGoldenTest extends TestCase {

	/**
	 * The default timezone before the test, restored afterwards.
	 *
	 * @var string
	 */
	private string $timezone = 'UTC';

	protected function setUp(): void {
		$this->timezone = date_default_timezone_get();
		date_default_timezone_set('UTC');
	}//end setUp()

	protected function tearDown(): void {
		date_default_timezone_set($this->timezone);
	}//end tearDown()

	/**
	 * A handler, with a language service when one is given.
	 *
	 * @param LanguageServiceInterface|null $languages The language service.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(?LanguageServiceInterface $languages = null): MagicSearchHandler {
		$logger = $this->createMock(LoggerInterface::class);
		return new MagicSearchHandler(
			db: $this->createMock(IDBConnection::class),
			logger: $logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class),
			languageService: $languages
		);
	}//end handler()

	/**
	 * Invoke a private method.
	 *
	 * @param MagicSearchHandler $handler The handler.
	 * @param string             $method  The method.
	 * @param mixed              ...$args The arguments.
	 *
	 * @return mixed The result.
	 */
	private function call(MagicSearchHandler $handler, string $method, mixed ...$args): mixed {
		return (new ReflectionMethod(MagicSearchHandler::class, $method))->invoke($handler, ...$args);
	}//end call()

	/**
	 * One case per branch of convertRowValue().
	 *
	 * @return array<string, array{0: mixed, 1: array, 2: mixed}>
	 */
	public static function rowValues(): array {
		return [
			'typed, no format'     => ['5', ['type' => 'integer', 'format' => null], 5],
			'null'                 => [null, ['type' => 'string', 'format' => 'date'], null],
			'string, no format'    => ['abc', ['type' => 'string', 'format' => null], 'abc'],
			'date'                 => ['2026-03-04 05:06:07', ['type' => 'string', 'format' => 'date'], '2026-03-04'],
			'unreadable date'      => ['not a date', ['type' => 'string', 'format' => 'date'], null],
			'date-time'            => ['2026-03-04 05:06:07', ['type' => 'string', 'format' => 'date-time'], '2026-03-04T05:06:07+00:00'],
			'other format'         => ['x@y.z', ['type' => 'string', 'format' => 'email'], 'x@y.z'],
			'not a string, a date' => [7, ['type' => 'integer', 'format' => 'date'], 7],
		];
	}//end rowValues()

	/**
	 * convertRowValue() matches the golden value.
	 *
	 * @param mixed $value    The column value.
	 * @param array $property The property type and format.
	 * @param mixed $expected The value read.
	 *
	 * @return void
	 */
	#[DataProvider('rowValues')]
	public function testConvertRowValueMatchesGolden(mixed $value, array $property, mixed $expected): void {
		$this->assertSame($expected, $this->call($this->handler(), 'convertRowValue', $value, $property));
	}//end testConvertRowValueMatchesGolden()

	/**
	 * One case per kind of rowMetadataValues().
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: list<mixed>}>
	 */
	public static function metadataValues(): array {
		return [
			'scalar'             => ['scalar', 'a', ['a']],
			'datetime'           => ['datetime', '2026-03-04 05:06:07', ['DateTime:2026-03-04T05:06:07+00:00']],
			'deleted json'       => ['deleted', '{"deleted":"x"}', [['deleted' => 'x']]],
			'deleted array'      => ['deleted', ['by' => 'u'], [['by' => 'u']]],
			'deleted date'       => ['deleted', '2026-03-04 05:06:07', [['deleted' => '2026-03-04T05:06:07+00:00']]],
			'float'              => ['float', '1.5', [1.5]],
			'json list'          => ['json', '[1,2]', [[], [1, 2]]],
			'json unreadable'    => ['json', 'nope', [[]]],
			'json array'         => ['json', ['a' => 1], [[], ['a' => 1]]],
			'json-or-null'       => ['json-or-null', '{"a":1}', [null, ['a' => 1]]],
			'json-or-null, none' => ['json-or-null', 'nope', [null]],
			'unknown kind'       => ['other', 'v', ['v']],
		];
	}//end metadataValues()

	/**
	 * rowMetadataValues() matches the golden values.
	 *
	 * @param string      $kind     The metadata kind.
	 * @param mixed       $value    The column value.
	 * @param list<mixed> $expected The values set, a DateTime rendered as text.
	 *
	 * @return void
	 */
	#[DataProvider('metadataValues')]
	public function testRowMetadataValuesMatchGolden(string $kind, mixed $value, array $expected): void {
		$values = $this->call($this->handler(), 'rowMetadataValues', $kind, $value);
		$values = array_map(static fn ($v) => $v instanceof DateTime ? 'DateTime:'.$v->format('c') : $v, $values);

		$this->assertSame($expected, $values);
	}//end testRowMetadataValuesMatchGolden()

	/**
	 * One case per branch of buildFilterColumnRef().
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: bool, 3: string}>
	 */
	public static function columnRefs(): array {
		return [
			'text column'             => ['string', 'abc', true, 't.c'],
			'number, numeric string'  => ['integer', '5', true, 't.c'],
			'number, integer'         => ['integer', 5, true, 't.c'],
			'number, text, pg'        => ['integer', 'abc', true, 't.c::text'],
			'number, text, mysql'     => ['number', 'abc', false, 'CAST(t.c AS CHAR)'],
			'list with text'          => ['integer', ['1', 'x'], true, 't.c::text'],
			'list of numbers'         => ['integer', ['1', '2'], false, 't.c'],
			'empty list'              => ['integer', [], true, 't.c'],
			'null'                    => ['integer', null, true, 't.c'],
			'boolean'                 => ['integer', true, true, 't.c::text'],
			'nested list'             => ['integer', [[1]], true, 't.c'],
			'boolean column'          => ['boolean', 'abc', true, 't.c'],
		];
	}//end columnRefs()

	/**
	 * buildFilterColumnRef() matches the golden reference.
	 *
	 * @param string $type       The property type.
	 * @param mixed  $value      The compared value.
	 * @param bool   $isPostgres Whether the platform is PostgreSQL.
	 * @param string $expected   The column reference.
	 *
	 * @return void
	 */
	#[DataProvider('columnRefs')]
	public function testBuildFilterColumnRefMatchesGolden(string $type, mixed $value, bool $isPostgres, string $expected): void {
		$column = new TypedColumnRef(ref: 't.c', propertyType: $type, isPostgres: $isPostgres);

		$this->assertSame($expected, $this->call($this->handler(), 'buildFilterColumnRef', $column, $value));
	}//end testBuildFilterColumnRefMatchesGolden()

	/**
	 * The column maps of a schema: types, formats, columns, memoised by schema id only.
	 *
	 * @return void
	 */
	public function testSchemaColumnMapsMatchGoldenAndAreMemoisedById(): void {
		$handler = $this->handler();
		$schema  = new Schema();
		$schema->setId(4);
		$schema->setProperties(['firstName' => ['type' => 'string', 'format' => 'email'], 'count' => ['type' => 'integer'], 'note' => []]);

		$expected = [
			'types'   => ['firstName' => 'string', 'count' => 'integer', 'note' => 'string'],
			'formats' => ['firstName' => 'email'],
			'columns' => ['first_name' => 'firstName', 'count' => 'count', 'note' => 'note'],
		];
		$this->assertSame($expected, $this->call($handler, 'getSchemaColumnMaps', $schema));

		// Memoised: a changed schema with the same id still gets the first maps.
		$schema->setProperties(['other' => ['type' => 'number']]);
		$this->assertSame($expected, $this->call($handler, 'getSchemaColumnMaps', $schema));

		// No id: never memoised, so a change shows.
		$unsaved = new Schema();
		$unsaved->setProperties(['a' => ['type' => 'boolean']]);
		$this->assertSame(['types' => ['a' => 'boolean'], 'formats' => [], 'columns' => ['a' => 'a']], $this->call($handler, 'getSchemaColumnMaps', $unsaved));
		$unsaved->setProperties(['b' => ['type' => 'string']]);
		$this->assertSame(['types' => ['b' => 'string'], 'formats' => [], 'columns' => ['b' => 'b']], $this->call($handler, 'getSchemaColumnMaps', $unsaved));

		$empty = new Schema();
		$empty->setId(9);
		$this->assertSame(['types' => [], 'formats' => [], 'columns' => []], $this->call($handler, 'getSchemaColumnMaps', $empty));
	}//end testSchemaColumnMapsMatchGoldenAndAreMemoisedById()

	/**
	 * A language service that prefers `$preferred` and accepts `$accepted`.
	 *
	 * @param string   $preferred The preferred language.
	 * @param string[] $accepted  The accepted languages.
	 *
	 * @return LanguageServiceInterface The service.
	 */
	private function languages(string $preferred, array $accepted): LanguageServiceInterface {
		$languages = $this->createMock(LanguageServiceInterface::class);
		$languages->method('getPreferredLanguage')->willReturn($preferred);
		$languages->method('getAcceptedLanguages')->willReturn($accepted);
		return $languages;
	}//end languages()

	/**
	 * A register offering the given languages.
	 *
	 * @param array|null $languages The languages.
	 *
	 * @return Register The register.
	 */
	private function register(?array $languages): Register {
		$register = new Register();
		if ($languages !== null) {
			$register->setLanguages($languages);
		}

		return $register;
	}//end register()

	/**
	 * sortLanguageChain() per branch: no service, a register without languages,
	 * accepted languages matched in full and by base, non-string languages dropped.
	 *
	 * @return void
	 */
	public function testSortLanguageChainMatchesGolden(): void {
		$this->assertSame(['nl', 'fr'], $this->call($this->handler(), 'sortLanguageChain', $this->register(['nl', 5, 'fr'])));
		$this->assertSame(['nl'], $this->call($this->handler(), 'sortLanguageChain', $this->register(null)));
		$this->assertSame(
			['de', 'nl'],
			$this->call($this->handler($this->languages('de', ['en', 'de'])), 'sortLanguageChain', $this->register([]))
		);
		$this->assertSame(
			['en', 'nl', 'fr'],
			$this->call($this->handler($this->languages('fr', ['en-GB', 'de', 'NL', 'nl'])), 'sortLanguageChain', $this->register(['nl', 'en', 'fr']))
		);
		$this->assertSame(
			['en-GB', 'en', 'nl'],
			$this->call($this->handler($this->languages('fr', ['en-GB'])), 'sortLanguageChain', $this->register(['nl', 'en', 'en-GB']))
		);
	}//end testSortLanguageChainMatchesGolden()
}//end class
