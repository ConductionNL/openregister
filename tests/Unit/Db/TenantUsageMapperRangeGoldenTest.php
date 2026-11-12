<?php

/**
 * Golden test for TenantUsageMapper::findByOrgAndDateRange().
 *
 * Records every query-builder call and the result of the per-organisation
 * usage range read: the organisation predicate, both period bounds in their
 * stored text form, and the ordering. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\TenantUsage;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Db\TenantUsage\UsageRange;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\TenantUsageMapper
 */
final class TenantUsageMapperRangeGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/tenant-usage-mapper-range-golden.json';

	/**
	 * Arguments and served rows, by name.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, array<int, array<string, mixed>>>}>
	 */
	private static function cases(): array {
		$utc = new DateTimeZone('UTC');
		$from = new DateTime('2026-01-01T00:00:00+00:00', $utc);
		$to = new DateTime('2026-01-31T23:59:59+00:00', $utc);
		$row = ['id' => 3, 'organisation_uuid' => 'org-1', 'request_count' => 4];
		return [
			'a month with rows' => [['organisationUuid' => 'org-1', 'from' => $from, 'to' => $to], [[$row, $row]]],
			'no rows' => [['organisationUuid' => 'org-2', 'from' => $from, 'to' => $to], [[]]],
			'reversed bounds' => [['organisationUuid' => 'org-1', 'from' => $to, 'to' => $from], [[]]],
		];
	}//end cases()

	public function testOutcomesMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$args, $rows]) {
			$this->queryLog = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = $rows;

			$db = $this->createMock(IDBConnection::class);
			$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
			$mapper = new TenantUsageMapper($db);

			$found = $mapper->findByOrgAndDateRange($args['organisationUuid'], new UsageRange($args['from'], $args['to']));
			$actual[$name] = [
				'result' => array_map(static fn (TenantUsage $usage): array => [$usage->getOrganisationUuid(), $usage->getRequestCount()], $found),
				'queries' => $this->queryLog,
			];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual), true));
	}//end testOutcomesMatchGolden()
}//end class
