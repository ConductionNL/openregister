<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/built-in-dashboards/spec.md#requirement-dashboardservice-must-assemble-or-canned-statistics-and-chart-payloads-with-fail-soft-semantics
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use OCA\OpenRegister\Db\AuditTrail\AuditStatisticsScope;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Search\Service\DashboardMappers;
use OCA\OpenRegister\Search\Service\DashboardService;
use OCA\OpenRegister\Search\Service\DashboardSizes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * GET /api/dashboard answers each register's and each register/schema pair's
 * audit totals from one grouped read, reads each register's schemas from the
 * register it holds, and falls back to a count per entry when the grouped read fails.
 */
class DashboardServiceAuditPairTotalsTest extends TestCase {

	/**
	 * @var AuditTrailMapperInterface&MockObject
	 */
	private AuditTrailMapperInterface $audit;

	/**
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface $registers;

	/**
	 * The scopes counted on their own, as "register/schema".
	 *
	 * @var array<int, string>
	 */
	private array $counted = [];

	protected function setUp(): void {
		$this->audit = $this->createMock(AuditTrailMapperInterface::class);
		$this->audit->method('statisticsTotals')->willReturnCallback(
			function (AuditStatisticsScope $scope): array {
				$this->counted[] = json_encode($scope->registerId) . '/' . json_encode($scope->schemaId);
				return ['total' => 100, 'size' => 1000];
			}
		);

		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->registers->method('findAll')->willReturn([$this->register(id: 5, schemas: [9, 10]), $this->register(id: 6, schemas: [9])]);
		$this->registers->method('getSchemasOfRegister')->willReturnCallback(
			fn (Register $register): array => array_map(fn (int $id): Schema => $this->schema(id: $id), $register->getSchemas())
		);
		$this->registers->expects($this->never())->method('getSchemasByRegisterId');
	}

	public function testRegisterAndPairTotalsComeFromOneGroupedRead(): void {
		$this->audit->expects($this->once())->method('statisticsTotalsPerPair')->with([5, 6])->willReturn([
			'5' => ['9' => ['total' => 3, 'size' => 30], '' => ['total' => 1, 'size' => 2]],
			'6' => ['9' => ['total' => 4, 'size' => 40]],
		]);

		$entries = array_column($this->service()->getRegistersWithSchemas(), null, 'id');

		$this->assertSame(['total' => 4, 'size' => 32], $entries[5]['stats']['logs'], 'a register is the sum over its stored schema values');
		$this->assertSame(['total' => 3, 'size' => 30], $entries[5]['schemas'][0]['stats']['logs']);
		$this->assertSame(['total' => 0, 'size' => 0], $entries[5]['schemas'][1]['stats']['logs'], 'a pair without rows has zero totals');
		$this->assertSame(['total' => 4, 'size' => 40], $entries[6]['stats']['logs']);
		$this->assertSame(['null/null', 'null/null'], $this->counted, 'only the instance totals and the orphans are counted on their own');
	}

	public function testAFailedGroupedReadCountsEachEntryOnItsOwn(): void {
		$this->audit->method('statisticsTotalsPerPair')->willThrowException(new RuntimeException('no grouped read'));

		$entries = array_column($this->service()->getRegistersWithSchemas(), null, 'id');

		$this->assertSame(['total' => 100, 'size' => 1000], $entries[5]['stats']['logs']);
		$this->assertSame(['null/null', '5/null', '5/9', '5/10', '6/null', '6/9', 'null/null'], $this->counted);
	}

	public function testTheGroupedTotalsDoNotOutliveTheAnswer(): void {
		$this->audit->expects($this->exactly(2))->method('statisticsTotalsPerPair')->willReturn([]);

		$service = $this->service();
		$service->getRegistersWithSchemas();
		$service->getRegistersWithSchemas();

		$this->assertCount(4, $this->counted, 'each answer reads its own grouped totals');
	}

	private function service(): DashboardService {
		$tables = $this->createMock(MagicMapper::class);
		$tables->method('rememberPairCounts')->willReturnCallback(static fn (callable $work): mixed => $work());
		$tables->method('getStatistics')->willReturn(['total' => 0, 'size' => 0, 'invalid' => 0, 'deleted' => 0, 'locked' => 0]);
		$logger = $this->createMock(LoggerInterface::class);
		$catalog = new SchemaCatalog(registers: $this->registers, schemas: $this->createMock(SchemaMapperInterface::class), tables: $tables);

		return new DashboardService(
			new DashboardMappers($catalog, $this->audit, $this->createMock(WebhookLogMapper::class)),
			$this->createMock(DashboardSizes::class),
			$logger
		);
	}

	private function register(int $id, array $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$ref = new \ReflectionProperty($register, 'schemas');
		$ref->setValue($register, $schemas);
		return $register;
	}

	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}
}//end class
