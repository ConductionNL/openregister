<?php

/**
 * Characterisation golden for the rule-run summary write, the contact-link role
 * lookup and the endpoint-log page read.
 *
 * Pins, on the code as it was before those units were split, which summary
 * RuleRunSummaryMapper::record() writes (insert, update or nothing) and with which
 * values, the role condition ContactLinkMapper::findByObjectContactAndRole() adds,
 * and the paging EndpointLogMapper::findByEndpoints() applies.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\EndpointLogMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\RuleRunSummary;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SmallMappersGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/small-mappers-golden.json';

	private const MOMENT = '2026-03-04 05:06:07';

	/**
	 * A stored summary row.
	 *
	 * @param string $verdict The last verdict.
	 * @param string|null $lastRun The last run, or null.
	 *
	 * @return array<string, mixed>
	 */
	private static function summaryRow(string $verdict, ?string $lastRun): array {
		return [
			'id' => 9, 'rule_id' => 'r:s:k', 'schema_slug' => 'old-slug', 'last_run' => $lastRun,
			'last_verdict' => $verdict, 'last_error' => null, 'last_error_at' => null,
		];
	}//end summaryRow()

	/**
	 * record() cases: arguments after the rule id, and the stored row (none for a first run).
	 *
	 * @return array<string, array{0: string, 1: string, 2: string|null, 3: array<int, array<string, mixed>>}>
	 */
	private static function recordCases(): array {
		$recent = '2026-03-04 05:06:00';
		$old = '2026-03-03 05:06:07';

		return [
			'first run passes' => ['slug', 'pass', null, []],
			'first run fails with error' => ['slug', 'fail', 'boom', []],
			'first run with empty error' => ['slug', 'fail', '', []],
			'same verdict within throttle' => ['slug', 'pass', null, [self::summaryRow('pass', $recent)]],
			'same verdict within throttle, empty error' => ['new-slug', 'pass', '', [self::summaryRow('pass', $recent)]],
			'same verdict within throttle, error' => ['new-slug', 'pass', 'boom', [self::summaryRow('pass', $recent)]],
			'same verdict after throttle' => ['new-slug', 'pass', null, [self::summaryRow('pass', $old)]],
			'verdict changed' => ['new-slug', 'fail', null, [self::summaryRow('pass', $recent)]],
			'never run before' => ['new-slug', 'pass', null, [self::summaryRow('pass', null)]],
		];
	}//end recordCases()

	/**
	 * Every outcome matches the recording.
	 *
	 * @return void
	 */
	public function testOutcomesMatchGolden(): void {
		$actual = [];
		foreach (self::recordCases() as $name => [$slug, $verdict, $error, $rows]) {
			$actual['record: ' . $name] = $this->recorded(
				fn () => $this->summaryState(
					(new RuleRunSummaryMapper($this->connection()))->record('r:s:k', $slug, $verdict, new DateTime(self::MOMENT), $error)
				),
				[$rows]
			);
		}

		$found = [['id' => 4, 'object_uuid' => 'o-1', 'contact_uid' => 'c-1', 'role' => 'owner']];
		foreach (['null' => null, 'empty' => '', 'owner' => 'owner', 'zero' => '0'] as $name => $role) {
			foreach (['found' => $found, 'missing' => []] as $rowsName => $rows) {
				$actual['contact role ' . $name . ', ' . $rowsName] = $this->recorded(
					function () use ($role): ?int {
						$link = (new ContactLinkMapper($this->connection()))->findByObjectContactAndRole('o-1', 'c-1', $role);
						return $link instanceof ContactLink ? $link->getId() : null;
					},
					[$rows]
				);
			}
		}

		$paging = ['none' => [null, null], 'limit' => [5, null], 'offset' => [null, 10], 'both' => [0, 0]];
		foreach ($paging as $name => [$limit, $offset]) {
			$actual['endpoint logs ' . $name] = $this->recorded(
				fn () => count((new EndpointLogMapper($this->connection()))->findByEndpoints(['a' => 3, 'b' => 4], new ListQuery(limit: $limit ?? ListQuery::UNBOUNDED, offset: $offset))),
				[[]]
			);
		}

		$actual['endpoint logs without endpoints'] = $this->recorded(
			fn () => (new EndpointLogMapper($this->connection()))->findByEndpoints([], new ListQuery(limit: 5, offset: 5)),
			[]
		);

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual), true));
	}//end testOutcomesMatchGolden()

	/**
	 * The fields of a summary a caller reads back.
	 *
	 * @param RuleRunSummary $summary The summary.
	 *
	 * @return array<string, mixed>
	 */
	private function summaryState(RuleRunSummary $summary): array {
		return [
			'id' => $summary->getId(),
			'ruleId' => $summary->getRuleId(),
			'schemaSlug' => $summary->getSchemaSlug(),
			'lastRun' => $summary->getLastRun()?->format('Y-m-d H:i:s'),
			'lastVerdict' => $summary->getLastVerdict(),
			'lastError' => $summary->getLastError(),
			'lastErrorAt' => $summary->getLastErrorAt()?->format('Y-m-d H:i:s'),
			'updated' => array_keys($summary->getUpdatedFields()),
		];
	}//end summaryState()

	/**
	 * The queries a call made and what it answered or threw.
	 *
	 * @param callable $call The call.
	 * @param array<int, array<int, array<string, mixed>>> $rows Row sets to serve.
	 *
	 * @return array<string, mixed>
	 */
	private function recorded(callable $call, array $rows): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;
		try {
			$outcome = ['result' => $call()];
		} catch (\Throwable $e) {
			$outcome = ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end recorded()

	/**
	 * A connection handing out recording builders.
	 *
	 * @return IDBConnection
	 */
	private function connection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return $db;
	}//end connection()
}//end class
