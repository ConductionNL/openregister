<?php

/**
 * Unit tests for the bracket filter spelling on object search
 * (openregister#3611).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-both-filter-spellings-mean-the-same-filter-on-object-search-and-the-aggregations
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `filter[origin]=manual` used to become a filter on a property literally
 * named `filter`, which no schema declares, so EVERY such query answered
 * `1 = 0` and returned the empty set. humaniq's hours widget spelled its
 * filter that way and would have rendered 0 hours on every object.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Object\SearchQueryHandler
 */
class SearchQueryHandlerFilterSpellingTest extends TestCase {

	/**
	 * A handler whose schema lookup answers with the given properties.
	 *
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @return SearchQueryHandler
	 */
	private function makeHandler(array $properties = ['origin' => ['type' => 'string']]): SearchQueryHandler {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);
		$schema->method('getObjectSource')->willReturn(null);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		return new SearchQueryHandler(
			$this->createMock(ViewMapper::class),
			$schemaMapper,
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IRequest::class),
			$this->createMock(SearchTrailService::class)
		);
	}//end makeHandler()

	/**
	 * The bracket spelling reaches the query as an ordinary property filter.
	 *
	 * @return void
	 */
	public function testBracketFilterBecomesAPropertyFilter(): void {
		$query = $this->makeHandler()->buildSearchQuery(
			['filter' => ['origin' => 'manual'], '_limit' => '200'],
			1,
			777
		);

		$this->assertSame('manual', ($query['origin'] ?? null));
		$this->assertArrayNotHasKey('filter', $query, 'the empty-set filter on a property called `filter` must be gone');
		$this->assertSame('200', $query['_limit']);
	}//end testBracketFilterBecomesAPropertyFilter()

	/**
	 * Both spellings build the same query, which is the contract the two
	 * sibling endpoints broke.
	 *
	 * @return void
	 */
	public function testBothSpellingsBuildTheSameQuery(): void {
		$bracketed = $this->makeHandler()->buildSearchQuery(['filter' => ['origin' => 'manual']], 1, 777);
		$bare = $this->makeHandler()->buildSearchQuery(['origin' => 'manual'], 1, 777);

		$this->assertSame($bare, $bracketed);
	}//end testBothSpellingsBuildTheSameQuery()

	/**
	 * A schema that really declares a `filter` property keeps the old
	 * meaning: there, `filter[x]=v` IS a filter on that property.
	 *
	 * @return void
	 */
	public function testASchemaDeclaringAFilterPropertyKeepsTheOldMeaning(): void {
		$query = $this->makeHandler(['filter' => ['type' => 'object'], 'origin' => ['type' => 'string']])
			->buildSearchQuery(['filter' => ['origin' => 'manual']], 1, 777);

		$this->assertSame(['origin' => 'manual'], ($query['filter'] ?? null));
		$this->assertArrayNotHasKey('origin', $query);
	}//end testASchemaDeclaringAFilterPropertyKeepsTheOldMeaning()

	/**
	 * The bracket spelling is not a way to reach a control parameter: an
	 * underscore-prefixed key stays where it was and never becomes `_limit`.
	 *
	 * @return void
	 */
	public function testAnUnderscoreKeyInsideTheBracketFilterIsNotLifted(): void {
		$query = $this->makeHandler()->buildSearchQuery(
			['filter' => ['_limit' => '1', 'origin' => 'manual']],
			1,
			777
		);

		$this->assertSame('manual', ($query['origin'] ?? null));
		$this->assertSame(['_limit' => '1'], ($query['filter'] ?? null));
		$this->assertArrayNotHasKey('_limit', $query, 'a filter spelling must not set a control parameter');
	}//end testAnUnderscoreKeyInsideTheBracketFilterIsNotLifted()

	/**
	 * A metadata key inside the bracket filter lands in `@self`, exactly where
	 * the bare spelling puts it.
	 *
	 * @return void
	 */
	public function testAMetadataKeyInsideTheBracketFilterLandsInSelf(): void {
		$query = $this->makeHandler()->buildSearchQuery(['filter' => ['owner' => 'alice']], 1, 777);

		$this->assertSame('alice', ($query['@self']['owner'] ?? null));
	}//end testAMetadataKeyInsideTheBracketFilterLandsInSelf()

	/**
	 * A nested bracket filter keeps the nested shape the bare dot spelling
	 * produces, so `filter[address][city]` and `address.city` agree.
	 *
	 * @return void
	 */
	public function testANestedBracketFilterKeepsItsShape(): void {
		$query = $this->makeHandler(['address' => ['type' => 'object']])
			->buildSearchQuery(['filter' => ['address' => ['city' => 'Utrecht']]], 1, 777);

		$this->assertSame(['city' => 'Utrecht'], ($query['address'] ?? null));
	}//end testANestedBracketFilterKeepsItsShape()
}//end class
