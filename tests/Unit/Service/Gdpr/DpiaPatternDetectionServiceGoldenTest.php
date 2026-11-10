<?php

declare(strict_types=1);

/**
 * DpiaPatternDetectionService golden test.
 *
 * Pins the detection answer exactly: an unusable configuration flags nothing,
 * the window is inclusive at both ends against a fixed clock, cases outside
 * it or with an unusable `receivedAt` are ignored, every in-window case
 * counts toward its group, only cases with an id are listed, already-flagged
 * cases are listed but not as unflagged, and groups below the threshold are
 * dropped, in first-seen order.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Gdpr\DpiaDetectionRequest;
use OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectionService;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for DpiaPatternDetectionService.
 */
class DpiaPatternDetectionServiceGoldenTest extends TestCase {

	/**
	 * The case set every test evaluates.
	 *
	 * @return array<int, mixed>
	 */
	private function cases(): array {
		return [
			['receivedAt' => '2026-05-31T00:00:00+00:00', 'type' => 'Access ', 'scope' => 'All  Data', '@uuid' => 'a1'],
			['receivedAt' => '2026-05-02T00:00:00+00:00', 'type' => 'access', 'scope' => 'all data', 'id' => 'a2', 'dpiaRequired' => true],
			['receivedAt' => '2026-06-01T00:00:00+00:00', 'type' => 'ACCESS', 'scope' => "all\tdata"],
			['receivedAt' => '2026-05-01T23:59:59+00:00', 'type' => 'access', 'scope' => 'all data', '@uuid' => 'too-old'],
			['receivedAt' => '2026-06-01T00:00:01+00:00', 'type' => 'access', 'scope' => 'all data', '@uuid' => 'future'],
			['receivedAt' => 'garbage', 'type' => 'access', 'scope' => 'all data', '@uuid' => 'bad-date'],
			['receivedAt' => 12345, 'type' => 'access', 'scope' => 'all data', '@uuid' => 'non-string'],
			['type' => 'access', 'scope' => 'all data', '@uuid' => 'no-date'],
			'not-a-case',
			['receivedAt' => '2026-05-20T00:00:00+00:00', 'type' => 'erasure', 'scope' => ['x'], '@uuid' => 'e1', 'dpiaRequired' => 'yes'],
			['receivedAt' => '2026-05-21T00:00:00+00:00', 'type' => 'erasure', '@uuid' => 'e2'],
			['receivedAt' => '2026-05-22T00:00:00+00:00', 'type' => 'rectify', 'scope' => '', '@uuid' => 'r1'],
		];

	}//end cases()

	/**
	 * Default grouping, threshold 2.
	 *
	 * @return void
	 */
	public function testDetect(): void {
		$now = new DateTimeImmutable('2026-06-01T00:00:00+00:00');
		$groups = (new DpiaPatternDetectionService())->detect(request: new DpiaDetectionRequest(cases: $this->cases(), config: ['threshold' => 2, 'windowDays' => 30], now: $now));

		$this->assertSame(
			[
				['key' => 'type=access|scope=all data', 'count' => 3, 'caseUuids' => ['a1', 'a2'], 'unflaggedUuids' => ['a1']],
				['key' => 'type=erasure|scope=', 'count' => 2, 'caseUuids' => ['e1', 'e2'], 'unflaggedUuids' => ['e1', 'e2']],
			],
			$groups
		);

	}//end testDetect()

	/**
	 * Custom grouping, threshold edge, and every unusable configuration.
	 *
	 * @return void
	 */
	public function testConfigVariants(): void {
		$service = new DpiaPatternDetectionService();
		$now = new DateTimeImmutable('2026-06-01T00:00:00+00:00');

		$byType = $service->detect(request: new DpiaDetectionRequest(cases: $this->cases(), config: ['threshold' => '3', 'windowDays' => '30', 'groupBy' => ['type']], now: $now));
		$this->assertSame([['key' => 'type=access', 'count' => 3, 'caseUuids' => ['a1', 'a2'], 'unflaggedUuids' => ['a1']]], $byType);

		$this->assertSame(
			$service->detect(request: new DpiaDetectionRequest(cases: $this->cases(), config: ['threshold' => 2, 'windowDays' => 30], now: $now)),
			$service->detect(request: new DpiaDetectionRequest(cases: $this->cases(), config: ['threshold' => 2, 'windowDays' => 30, 'groupBy' => []], now: $now))
		);
		$this->assertSame(
			$service->detect(request: new DpiaDetectionRequest(cases: $this->cases(), config: ['threshold' => 2, 'windowDays' => 30], now: $now)),
			$service->detect(request: new DpiaDetectionRequest(cases: $this->cases(), config: ['threshold' => 2, 'windowDays' => 30, 'groupBy' => 'type'], now: $now))
		);
		$this->assertSame([], $service->detect(request: new DpiaDetectionRequest(cases: $this->cases(), config: ['threshold' => 4, 'windowDays' => 30], now: $now)));
		$this->assertSame([], $service->detect(request: new DpiaDetectionRequest(cases: $this->cases(), config: [], now: $now)));
		$this->assertSame([], $service->detect(request: new DpiaDetectionRequest(cases: $this->cases(), config: ['threshold' => 0, 'windowDays' => 30], now: $now)));
		$this->assertSame([], $service->detect(request: new DpiaDetectionRequest(cases: $this->cases(), config: ['threshold' => 1, 'windowDays' => 0], now: $now)));
		$this->assertCount(3, $service->detect(request: new DpiaDetectionRequest(cases: $this->cases(), config: ['threshold' => 1, 'windowDays' => 30], now: $now)));
		$this->assertSame([], $service->detect(request: new DpiaDetectionRequest(cases: [], config: ['threshold' => 1, 'windowDays' => 30], now: $now)));

		$this->assertSame('type=a b|x=', $service->groupKey(case: ['type' => " A \n B "], groupBy: ['type', 'x']));
		$this->assertSame('', $service->normalise(value: null));
		$this->assertSame('1', $service->normalise(value: true));

	}//end testConfigVariants()
}//end class
