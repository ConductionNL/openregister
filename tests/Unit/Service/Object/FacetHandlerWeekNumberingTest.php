<?php

/**
 * The facet handler reports week buckets in the week numbering of the faceted schemas' calendar.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\FacetLookups;
use OCA\OpenRegister\Service\Object\FacetResponseCache;
use OCA\OpenRegister\Service\Object\FacetWeekLabels;
use OCA\OpenRegister\Service\Object\FacetWeekNumbering;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Search\Service\Search\PropertySearchProfile;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Object\FacetHandler
 * @uses   \OCA\OpenRegister\Service\Object\FacetCalculation
 * @uses   \OCA\OpenRegister\Service\Object\FacetEntry
 * @uses   \OCA\OpenRegister\Service\Object\FacetListing
 * @uses   \OCA\OpenRegister\Service\Object\FacetLookups
 * @uses   \OCA\OpenRegister\Service\Object\FacetRun
 * @uses   \OCA\OpenRegister\Service\Object\FacetWeekLabels
 * @uses   \OCA\OpenRegister\Search\Service\Search\PropertySearchProfile
 * @uses   \OCA\OpenRegister\Service\Object\FacetableFieldDiscovery
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\CalendarBlackouts
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\CalendarWeekNumbering
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
class FacetHandlerWeekNumberingTest extends TestCase {

	/**
	 * A computed request asks the week numbering about the query's schemas and reports its weeks.
	 *
	 * @return void
	 */
	public function testWeekBucketsFollowTheFacetedSchemasCalendar(): void {
		$schema = $this->createMock(Schema::class);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->expects($this->once())->method('findMultiple')->with([7])->willReturn([$schema]);

		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('getSimpleFacets')->willReturn(
			['@self' => ['created' => ['type' => 'date_histogram', 'interval' => 'week', 'buckets' => [['key' => '2026-53', 'results' => 3]]]]]
		);

		$cache = $this->createMock(FacetResponseCache::class);
		$cache->method('keyFor')->willReturn('key');
		$cache->method('get')->willReturn(null);
		$stored = [];
		$cache->method('put')->willReturnCallback(static function (string $cacheKey, array $result) use (&$stored): void {
			$stored[$cacheKey] = $result;
		});

		$calendar = WorkingCalendar::fromArray(
			['slug' => 'x', 'workingWeekdays' => [1, 2, 3, 4, 5], 'hoursPerWorkingDay' => 8, 'timezone' => 'UTC', 'firstWeekOfYear' => 'containsJanuary1']
		);
		$numbering = $this->createMock(FacetWeekNumbering::class);
		$numbering->expects($this->once())->method('labelsFor')->with([$schema])->willReturn(new FacetWeekLabels(calendar: $calendar));

		$handler = new FacetHandler(
			lookups: new FacetLookups(unifiedObjectMapper: $mapper, schemaMapper: $schemas),
			responseCache: $cache,
			logger: new NullLogger(),
			searchProfile: new PropertySearchProfile(),
			weekNumbering: $numbering,
		);

		$result = $handler->getFacetsForObjects(query: ['_facets' => ['@self' => ['created' => ['type' => 'date_histogram', 'interval' => 'week']]], '_schemas' => [7]]);

		$this->assertSame(
			[['value' => '2027-01', 'count' => 3, 'label' => '2027-01']],
			$result['facets']['_created']['data']['buckets']
		);
		$this->assertSame($result, $stored['key']);
	}//end testWeekBucketsFollowTheFacetedSchemasCalendar()
}//end class
