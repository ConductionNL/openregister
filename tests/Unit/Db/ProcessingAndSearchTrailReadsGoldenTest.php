<?php

/**
 * Characterisation golden for the processing-log column filters and per-activity
 * counts, and for the search-trail list read's sorting and paging.
 *
 * Pins, on the code as it was before those units were split, the exact query
 * ProcessingLogMapper::findFiltered() builds for every column and subject filter
 * shape, the organisation / register scope countByActivity() applies and the
 * counts it folds from the rows, and the ORDER BY and paging
 * SearchTrailMapper::findAll() adds for every sort shape.
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

use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @coversNothing
 */
final class ProcessingAndSearchTrailReadsGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/processing-and-search-trail-reads-golden.json';

	/**
	 * Filter sets for findFiltered().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function filterCases(): array {
		return [
			'none' => [],
			'every column' => ['register_id' => 1, 'schema_id' => '2', 'activity_id' => 'a-3', 'actor' => 'anna', 'action' => 'read'],
			'empty and null skipped' => ['register_id' => '', 'schema_id' => null, 'actor' => 0, 'action' => false],
			'unknown column ignored' => ['organisation_id' => 'org-x', 'confidential' => true],
			'subject type only' => ['subject_id_type' => 'BSN'],
			'subject value only' => ['subject_id_value' => 111],
			'subject both' => ['subject_id_type' => 'BSN', 'subject_id_value' => '111', 'actor' => 'anna'],
			'subject empty' => ['subject_id_type' => '', 'subject_id_value' => null],
			'subject zero' => ['subject_id_type' => 0, 'subject_id_value' => '0'],
		];
	}//end filterCases()

	/**
	 * Scope sets for countByActivity().
	 *
	 * @return array<string, array<int, string|null>>
	 */
	private static function countCases(): array {
		return [
			'unscoped' => [null, null],
			'organisation' => ['org-1', null],
			'register' => [null, '7'],
			'both' => ['org-1', '7'],
			'empty strings' => ['', ''],
			'zero strings' => ['0', '0'],
		];
	}//end countCases()

	/**
	 * Sort and paging sets for SearchTrailMapper::findAll().
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private static function sortCases(): array {
		return [
			'defaults' => [],
			'paged' => [5, 10],
			'zero paging' => [0, 0],
			'limit only' => [3, null],
			'offset only' => [null, 4],
			'assoc sort' => [null, null, [], ['created' => 'asc', 'user' => 'DESC', 'size' => 'desc']],
			'list sort' => [null, null, [], ['DESC', 'asc']],
			'odd directions' => [null, null, [], ['a' => null, 'b' => 1, 'c' => ['DESC'], 'd' => 'Desc ']],
			'filtered and sorted' => [1, 2, ['user' => 'alice'], ['search_term' => 'desc'], 'tax'],
		];
	}//end sortCases()

	/**
	 * Every outcome matches the recording.
	 *
	 * @return void
	 */
	public function testOutcomesMatchGolden(): void {
		$actual = [];
		foreach (self::filterCases() as $name => $filters) {
			foreach ([[null, false], ['org-1', false], [null, true], ['', true]] as [$org, $confidential]) {
				$label = 'filtered ' . var_export($org, true) . ' confidential ' . var_export($confidential, true) . ': ' . $name;
				$actual[$label] = $this->recorded(
					fn () => count($this->processingLog()->findFiltered($filters, new \OCA\OpenRegister\Db\ProcessingLogReadScope(null, null, $org, $confidential))),
					[]
				);
			}
		}

		$rows = [
			['activity_id' => 'a-1', 'cnt' => '3'],
			['activity_id' => 7, 'cnt' => 2],
			['activity_id' => null, 'cnt' => '1'],
			['activity_id' => 'a-1', 'cnt' => '5'],
		];
		foreach (self::countCases() as $name => [$org, $register]) {
			$actual['counts: ' . $name] = $this->recorded(fn () => $this->processingLog()->countByActivity($org, $register), [$rows]);
		}

		$actual['counts: no rows'] = $this->recorded(fn () => $this->processingLog()->countByActivity(), [[]]);

		foreach (self::sortCases() as $name => $args) {
			$actual['search trail: ' . $name] = $this->recorded(fn () => count($this->searchTrails()->findAll(...$args)), []);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual), true));
	}//end testOutcomesMatchGolden()

	/**
	 * The golden holds a scoped count and an unscoped one, and a confidential refusal and its absence.
	 *
	 * @return void
	 */
	public function testGoldenTellsScopedFromUnscoped(): void {
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertStringContainsString('organisation_id', implode("\n", $expected['counts: organisation']['queries']));
		$this->assertStringNotContainsString('organisation_id', implode("\n", $expected['counts: unscoped']['queries']));
		$this->assertStringNotContainsString('organisation_id', implode("\n", $expected['counts: empty strings']['queries']));
		$this->assertStringContainsString('confidential', implode("\n", $expected['filtered NULL confidential false: none']['queries']));
		$this->assertStringNotContainsString('confidential', implode("\n", $expected['filtered NULL confidential true: none']['queries']));
	}//end testGoldenTellsScopedFromUnscoped()

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
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '\\_%'));
		return $db;
	}//end connection()

	/**
	 * A processing-log mapper.
	 *
	 * @return ProcessingLogMapper
	 */
	private function processingLog(): ProcessingLogMapper {
		return new ProcessingLogMapper($this->connection());
	}//end processingLog()

	/**
	 * A search-trail mapper.
	 *
	 * @return SearchTrailMapper
	 */
	private function searchTrails(): SearchTrailMapper {
		return new SearchTrailMapper($this->connection(), $this->createMock(IRequest::class), $this->createMock(IUserSession::class), new NullLogger());
	}//end searchTrails()
}//end class
