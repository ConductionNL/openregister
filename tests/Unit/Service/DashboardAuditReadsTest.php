<?php

/**
 * Pins what DashboardAuditReads hands to the audit trail mapper.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrail\AuditStatisticsScope;
use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Search\Service\ActivityWindow;
use OCA\OpenRegister\Search\Service\DashboardAuditReads;
use OCA\OpenRegister\Search\Service\DashboardScope;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Search\Service\ActivityWindow
 * @covers \OCA\OpenRegister\Search\Service\DashboardAuditReads
 * @covers \OCA\OpenRegister\Search\Service\DashboardPeriod
 * @covers \OCA\OpenRegister\Search\Service\DashboardScope
 */
class DashboardAuditReadsTest extends TestCase {
	private AuditTrailMapperInterface&MockObject $mapper;

	private DashboardAuditReads $reads;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(AuditTrailMapperInterface::class);
		$this->reads = new DashboardAuditReads($this->mapper, $this->createMock(LoggerInterface::class));
	}

	public function testChartForwardsItsPeriodAndScope(): void {
		$from = new DateTime('2026-01-01');
		$till = new DateTime('2026-01-31');
		$this->mapper->expects($this->once())->method('actionChartData')
			->with($this->callback(static fn (AuditStatisticsScope $s): bool => [$s->from, $s->till, $s->registerId, $s->schemaId] === [$from, $till, 1, 2]))->willReturn(['labels' => ['a'], 'series' => []]);

		$result = $this->reads->getAuditTrailActionChartData((new DashboardScope(1, 2))->during($from, $till));

		$this->assertSame(['labels' => ['a'], 'series' => []], $result);
	}

	public function testStatisticsForwardScopeAndHours(): void {
		$this->mapper->expects($this->once())->method('detailedStatistics')->with($this->callback(static fn (AuditStatisticsScope $s): bool => [$s->registerId, $s->schemaId, $s->hours] === [1, 2, 48]))->willReturn(['total' => 9]);

		$this->assertSame(['total' => 9], $this->reads->getAuditTrailStatistics(new DashboardScope(1, 2), 48));
	}

	public function testDistributionForwardsScopeAndHours(): void {
		$this->mapper->expects($this->once())->method('actionDistribution')->with($this->callback(static fn (AuditStatisticsScope $s): bool => [$s->registerId, $s->schemaId, $s->hours] === [1, 2, 72]))->willReturn(['actions' => ['x']]);

		$this->assertSame(['actions' => ['x']], $this->reads->getAuditTrailActionDistribution(new DashboardScope(1, 2), 72));
	}

	public function testMostActiveForwardsScopeLimitAndHours(): void {
		$this->mapper->expects($this->once())->method('mostActiveObjects')->with($this->callback(static fn (AuditStatisticsScope $s): bool => [$s->registerId, $s->schemaId, $s->limit, $s->hours] === [1, 2, 5, 96]))->willReturn(['objects' => ['o']]);

		$this->assertSame(['objects' => ['o']], $this->reads->getMostActiveObjects((new DashboardScope(1, 2))->mostActive(5, 96)));
	}

	public function testMostActiveDefaultsToTenOverADay(): void {
		$this->mapper->expects($this->once())->method('mostActiveObjects')->with($this->callback(static fn (AuditStatisticsScope $s): bool => [$s->registerId, $s->schemaId, $s->limit, $s->hours] === [null, null, 10, 24]))->willReturn([]);

		$this->reads->getMostActiveObjects(new ActivityWindow(new DashboardScope()));
	}

	public function testHoursDefaultToADay(): void {
		$this->mapper->expects($this->once())->method('detailedStatistics')->with($this->callback(static fn (AuditStatisticsScope $s): bool => [$s->registerId, $s->schemaId, $s->hours] === [null, null, 24]))->willReturn([]);
		$this->mapper->expects($this->once())->method('actionDistribution')->with($this->callback(static fn (AuditStatisticsScope $s): bool => [$s->registerId, $s->schemaId, $s->hours] === [null, null, 24]))->willReturn([]);

		$this->reads->getAuditTrailStatistics(new DashboardScope());
		$this->reads->getAuditTrailActionDistribution(new DashboardScope());
	}
}//end class
