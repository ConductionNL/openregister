<?php

/**
 * Every reader of a magic table names a property's column exactly as the writer does.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Query\RelatedRowExistsClause;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationRunner
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowExistsClause
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicColumnNameTest extends TestCase {

	private const LONG = 'aVeryLongPropertyNameThatKeepsGoingWellPastTheSixtyFourCharacterLimitOfMysql';

	/**
	 * Property name => the column the writer creates and fills.
	 *
	 * @return array<string, string>
	 */
	private static function expectedColumns(): array {
		return [
			'inStock'    => 'in_stock',
			'firstName'  => 'first_name',
			'1abc'       => '1abc',
			'9to5Shift'  => '9to5_shift',
			'a--b c'     => 'a_b_c',
			'_meta__'    => '_meta',
			'Ünïcode'    => '_n_code',
			self::LONG   => 'a_very_long_property_name_that_keeps_going_well_past_the_sixty_four_character_limit_of_mysql',
		];
	}//end expectedColumns()

	/**
	 * @return array<string, array{class-string, string}>
	 */
	public static function readers(): array {
		return [
			'writer MagicMapper'     => [MagicMapper::class, 'sanitizeColumnName'],
			'bulk'                   => [MagicBulkHandler::class, 'sanitizeColumnName'],
			'statistics'             => [MagicStatisticsHandler::class, 'sanitizeColumnName'],
			'search'                 => [MagicSearchHandler::class, 'sanitizeColumnName'],
			'facet'                  => [MagicFacetHandler::class, 'sanitizeColumnName'],
			'aggregation'            => [AggregationRunner::class, 'sanitizeColumnName'],
			'related-row filter'     => [RelatedRowExistsClause::class, 'sanitizeColumnName'],
		];
	}//end readers()

	/**
	 * @param class-string $class  The class under test.
	 * @param string       $method Its private column-name method.
	 *
	 * @return void
	 *
	 * @dataProvider readers
	 */
	public function testEveryReaderNamesTheColumnTheWriterCreates(string $class, string $method): void {
		$subject = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		$call    = (new ReflectionClass($class))->getMethod($method);

		$actual = [];
		foreach (array_keys(self::expectedColumns()) as $property) {
			$actual[$property] = $call->invoke($subject, $property);
		}

		$this->assertSame(self::expectedColumns(), $actual);
	}//end testEveryReaderNamesTheColumnTheWriterCreates()

	public function testSearchMapsStoredColumnsBackToTheirProperties(): void {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(['1abc' => ['type' => 'string'], self::LONG => ['type' => 'string']]);

		$search = (new ReflectionClass(MagicSearchHandler::class))->newInstanceWithoutConstructor();
		$maps   = (new ReflectionClass(MagicSearchHandler::class))->getMethod('getSchemaColumnMaps')->invoke($search, $schema);

		$this->assertSame(
			['1abc' => '1abc', self::expectedColumns()[self::LONG] => self::LONG],
			$maps['columns']
		);
	}//end testSearchMapsStoredColumnsBackToTheirProperties()

	public function testFacetFindsThePropertyOfAStoredDigitLeadingColumn(): void {
		$schema = new Schema();
		$schema->setProperties(['1abc' => ['type' => 'string', 'referenceType' => 'group']]);

		$facet = (new ReflectionClass(MagicFacetHandler::class))->newInstanceWithoutConstructor();
		$holds = (new ReflectionClass(MagicFacetHandler::class))->getMethod('holdsObjectReferences');

		$this->assertFalse($holds->invoke($facet, $schema, '1abc'));
	}//end testFacetFindsThePropertyOfAStoredDigitLeadingColumn()
}//end class
