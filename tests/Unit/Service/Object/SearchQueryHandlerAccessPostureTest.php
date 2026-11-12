<?php

/**
 * A request cannot set the access posture (_rbac, _multitenancy) of an
 * object search; only PHP callers can.
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
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
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
class SearchQueryHandlerAccessPostureTest extends TestCase {

	private function makeHandler(array $properties = ['origin' => ['type' => 'string']]): SearchQueryHandler {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);
		$schema->method('getObjectSource')->willReturn(null);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		return new SearchQueryHandler(
			$this->createMock(ViewScopeApplier::class),
			$schemaMapper,
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IRequest::class),
			$this->createMock(SearchTrailService::class)
		);
	}//end makeHandler()

	public function testARequestCannotSetTheAccessPosture(): void {
		$query = $this->makeHandler()->buildSearchQuery(
			['_rbac' => false, '_multitenancy' => false, '_multitenancy_explicit' => true, '_limit' => '5', 'origin' => 'manual'],
			1,
			777
		);

		$this->assertArrayNotHasKey('_rbac', $query);
		$this->assertArrayNotHasKey('_multitenancy', $query);
		$this->assertArrayNotHasKey('_multitenancy_explicit', $query);
		$this->assertSame('5', $query['_limit']);
		$this->assertSame('manual', $query['origin']);
	}//end testARequestCannotSetTheAccessPosture()
}//end class
