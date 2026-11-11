<?php

/**
 * Golden test for the audit-trail page query and the audit payload helper.
 *
 * Records every query-builder call and the outcome of AuditTrailPageQuery::page()
 * over each filter, the period bounds (including a refused non-date), the
 * full-text term, the cursor and the page limit; and the outputs of
 * AuditTrailPayloadHelper::toDatabaseValue(), revertChanges() and
 * capChangedPayload() over their branches. Re-record with UPDATE_GOLDEN=1.
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

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditPageRequest;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailCriteria;
use OCA\OpenRegister\Db\AuditTrailPageQuery;
use OCA\OpenRegister\Db\AuditTrailPayloadHelper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailPageQuery
 * @covers \OCA\OpenRegister\Db\AuditTrailPayloadHelper
 */
final class AuditTrailQueryHelpersGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/audit-trail-query-helpers-golden.json';

	/**
	 * page() cases: filters, search, limit, cursor and the rows served.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null, 2: int, 3: int|null, 4: int}>
	 */
	private static function pageCases(): array {
		return [
			'no filters' => [[], null, 20, null, 0],
			'actor' => [['actor' => 'alice'], null, 20, null, 0],
			'comma list' => [['action' => 'create, update ,,'], null, 20, null, 0],
			'array list' => [['register' => ['1', ' 2 ', '']], null, 20, null, 0],
			'single after trimming' => [['schema' => ' 7 , '], null, 20, null, 0],
			'only blanks' => [['object' => ' , ,'], null, 20, null, 0],
			'empty values' => [['actor' => '', 'action' => null, 'register' => []], null, 20, null, 0],
			'integer value' => [['register' => 5], null, 20, null, 0],
			'unknown key' => [['user' => 'bob'], null, 20, null, 0],
			'all columns' => [['actor' => 'a', 'action' => 'b', 'register' => 'c', 'schema' => 'd', 'object' => 'e'], null, 20, null, 0],
			'from' => [['from' => '2026-10-09'], null, 20, null, 0],
			'bare to' => [['to' => '2026-10-09'], null, 20, null, 0],
			'timed to' => [['to' => '2026-10-09 12:00:00'], null, 20, null, 0],
			'blank period' => [['from' => '  ', 'to' => ''], null, 20, null, 0],
			'non-date from' => [['from' => 'not a date'], null, 20, null, 0],
			'non-date to after actor' => [['actor' => 'a', 'to' => 'nope'], null, 20, null, 0],
			'search' => [[], ' 50% off_ ', 20, null, 0],
			'blank search' => [[], '   ', 20, null, 0],
			'cursor' => [[], null, 20, 42, 0],
			'zero cursor' => [[], null, 20, 0, 0],
			'negative cursor' => [[], null, 20, -1, 0],
			'limit below one' => [[], null, 0, null, 0],
			'limit above max' => [[], null, 9999, null, 0],
			'exactly a page' => [[], null, 3, null, 3],
			'one more than a page' => [[], null, 3, null, 4],
			'everything' => [['actor' => 'a,b', 'from' => '2026-01-01', 'to' => '2026-01-31'], 'tax', 2, 10, 5],
		];
	}//end pageCases()

	/**
	 * toDatabaseValue() cases.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	private static function databaseValueCases(): array {
		$at = new DateTimeImmutable('2026-02-03T04:05:06+00:00', new DateTimeZone('UTC'));
		return [
			'null json' => [null, 'json'],
			'json array' => [['a' => 1], 'json'],
			'json string' => ['x', 'json'],
			'datetime' => [$at, 'datetime'],
			'datetime given text' => ['2026-02-03', 'datetime'],
			'boolean true' => [true, 'boolean'],
			'bool false' => [false, 'bool'],
			'bool from text' => ['1', 'bool'],
			'string' => ['text', 'string'],
			'integer' => [7, 'integer'],
			'zero' => [0, 'json'],
		];
	}//end databaseValueCases()

	/**
	 * revertChanges() cases: object data, audit action and changes.
	 *
	 * @return array<string, array{0: array<string, mixed>|null, 1: string, 2: mixed}>
	 */
	private static function revertCases(): array {
		$data = ['title' => 'new', 'added' => 'x', 'id' => 'keep', 'kept' => 1];
		return [
			'update' => [$data, 'update', ['title' => ['old' => 'old', 'new' => 'new'], 'added' => ['old' => null, 'new' => 'x']]],
			'create is never undone' => [$data, 'create', ['title' => ['old' => 'old', 'new' => 'new']]],
			'changes not an array' => [$data, 'update', 'nope'],
			'no changes' => [$data, 'update', null],
			'self and id skipped' => [$data, 'update', ['@self' => ['old' => 'x'], 'id' => ['old' => 'other']]],
			'change not an array' => [$data, 'update', ['title' => 'old']],
			'change without old' => [$data, 'update', ['title' => ['new' => 'new']]],
			'old is false' => [$data, 'update', ['kept' => ['old' => false]]],
			'new field on empty object' => [null, 'delete', ['title' => ['old' => 't']]],
			'remove from empty object' => [null, 'update', ['gone' => ['old' => null]]],
		];
	}//end revertCases()

	/**
	 * capChangedPayload() cases.
	 *
	 * @return array<string, array<string, mixed>|null>
	 */
	private static function capCases(): array {
		$big = str_repeat('a', 65535);
		return [
			'null' => null,
			'empty' => [],
			'small' => ['title' => ['old' => 'a', 'new' => 'b']],
			'at the ceiling' => ['body' => substr($big, 0, 65534)],
			'just over' => ['body' => $big, 'title' => 'x'],
			'not encodable' => ['bad' => "\xB1\x31"],
			'nested over' => ['payload' => ['old' => $big, 'new' => 'b']],
		];
	}//end capCases()

	public function testOutcomesMatchGolden(): void {
		$actual = [];
		foreach (self::pageCases() as $name => [$filters, $search, $limit, $beforeId, $rowCount]) {
			$actual['page / ' . $name] = $this->page(filters: $filters, search: $search, limit: $limit, beforeId: $beforeId, rowCount: $rowCount);
		}

		$helper = new AuditTrailPayloadHelper();
		foreach (self::databaseValueCases() as $name => [$value, $type]) {
			$actual['toDatabaseValue / ' . $name] = $helper->toDatabaseValue(value: $value, type: $type);
		}

		foreach (self::revertCases() as $name => [$data, $action, $changes]) {
			$object = new ObjectEntity();
			$object->setObject($data);
			$audit = new AuditTrail();
			$audit->setAction($action);
			if (is_array($changes) === true) {
				$audit->setChanged($changes);
			}

			$helper->revertChanges(object: $object, audit: $audit);
			$actual['revertChanges / ' . $name] = $object->getObject();
		}

		foreach (self::capCases() as $name => $changed) {
			$capped = $helper->capChangedPayload(changed: $changed);
			$actual['capChangedPayload / ' . $name] = $this->summarise(value: $capped);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual, JSON_INVALID_UTF8_SUBSTITUTE), true));
	}//end testOutcomesMatchGolden()

	public function testANonDatePeriodIsRefusedBeforeAnyQueryRuns(): void {
		$outcome = $this->page(filters: ['from' => 'not a date'], search: null, limit: 5, beforeId: null, rowCount: 0);

		$this->assertSame('InvalidArgumentException: "not a date" is not a date.', $outcome['thrown']);
		$this->assertNotContains('#1 executeQuery()', $outcome['queries']);
	}//end testANonDatePeriodIsRefusedBeforeAnyQueryRuns()

	/**
	 * Run page() against a recording connection serving rows with descending ids.
	 *
	 * @param array<string, mixed> $filters The filters.
	 * @param string|null $search The term.
	 * @param int $limit The limit.
	 * @param int|null $beforeId The cursor.
	 * @param int $rowCount How many rows the query returns.
	 *
	 * @return array<string, mixed> The result and the query log.
	 */
	private function page(array $filters, ?string $search, int $limit, ?int $beforeId, int $rowCount): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$rows = [];
		for ($i = 0; $i < $rowCount; $i++) {
			$rows[] = ['id' => (100 - $i), 'uuid' => 'u' . (100 - $i), 'action' => 'update'];
		}

		$this->queuedRows = [$rows];

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $v): string => addcslashes($v, '%_\\'));

		$outcome = [];
		try {
			$page = (new AuditTrailPageQuery(db: $db))->page(new AuditPageRequest(new AuditTrailCriteria($filters, $search), $limit, $beforeId));
			$outcome['ids'] = array_map(static fn (AuditTrail $entry): int => (int)$entry->getId(), $page['results']);
			$outcome['nextCursor'] = $page['nextCursor'];
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end page()

	/**
	 * A capped payload with long strings replaced by their length.
	 *
	 * @param mixed $value The payload.
	 *
	 * @return mixed The summary.
	 */
	private function summarise(mixed $value): mixed {
		if (is_string($value) === true && strlen($value) > 64) {
			return 'string(' . strlen($value) . ')';
		}

		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->summarise(value: $item), $value);
		}

		if ($value instanceof stdClass) {
			return (array)$value;
		}

		return $value;
	}//end summarise()
}//end class
