<?php

/**
 * Golden test: every builder call RuleRunMapper's run reads make.
 *
 * Recorded on the mapper before its shared paging and filter clauses were
 * extracted, over every combination of verdict, range, limit and offset the
 * reads accept, so the extraction cannot change a query or its parameters.
 *
 * Regenerate (only when a query is meant to change) with
 * UPDATE_GOLDEN=1 phpunit tests/Unit/Db/RuleRunMapperQueriesGoldenTest.php.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\RuleRun;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class RuleRunMapperQueriesGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/rule-run-mapper-queries-golden.json';

	/**
	 * Every read under every argument combination.
	 *
	 * @return array<string, callable>
	 */
	private static function calls(): array {
		$since = new DateTime('2026-03-01 00:00:00');
		$until = new DateTime('2026-03-31 23:59:59');
		$calls = [];
		foreach ([null, '', 'pass'] as $verdict) {
			foreach (['open' => [null, null], 'since' => [$since, null], 'until' => [null, $until], 'both' => [$since, $until]] as $tag => [$from, $to]) {
				$v = var_export($verdict, true);
				$calls["findByRule $v $tag"] = fn (RuleRunMapper $m) => self::ids($m->findByRule('rule-1', $verdict, $from, $to));
				$calls["countByRule $v $tag"] = fn (RuleRunMapper $m) => $m->countByRule('rule-1', $verdict, $from, $to);
				$calls["findRecent $v $tag"] = fn (RuleRunMapper $m) => self::ids($m->findRecent($verdict, $from));
			}
		}

		foreach ([[0, -5], [1, 0], [50, 10], [501, 3], [-2, 7]] as [$limit, $offset]) {
			$calls["findByRule paging $limit $offset"] = fn (RuleRunMapper $m) => self::ids($m->findByRule('rule-2', null, null, null, $limit, $offset));
			$calls["findRecent paging $limit $offset"] = fn (RuleRunMapper $m) => self::ids($m->findRecent(null, null, $limit, $offset));
		}

		return $calls;
	}//end calls()

	/**
	 * The ids of the runs read.
	 *
	 * @param array<int, RuleRun> $runs The runs.
	 *
	 * @return array<int, int|null>
	 */
	private static function ids(array $runs): array {
		return array_map(fn (RuleRun $run) => $run->getId(), $runs);
	}//end ids()

	/**
	 * Every read matches the recording.
	 *
	 * @return void
	 */
	public function testQueriesMatchGolden(): void {
		$actual = [];
		foreach (self::calls() as $name => $call) {
			$this->queryLog = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = [[['id' => 4], ['id' => 9]]];
			if (str_starts_with($name, 'countByRule') === true) {
				$this->queuedRows = [[['run_count' => '7']]];
			}

			$db = $this->createMock(IDBConnection::class);
			$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
			$actual[$name] = ['result' => $call(new RuleRunMapper($db)), 'queries' => $this->queryLog];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string) json_encode($actual), true));
	}//end testQueriesMatchGolden()
}//end class
