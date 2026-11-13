<?php

/**
 * Golden test for the writes of SyncRecordMapper.
 *
 * Records every query-builder call and the resulting record of a pending
 * insert and of legal, failing and illegal status transitions.
 * Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SyncExecution;
use OCA\OpenRegister\Db\SyncFailure;
use OCA\OpenRegister\Db\SyncRecord;
use OCA\OpenRegister\Db\SyncRecordMapper;
use OCA\OpenRegister\Service\Sync\SyncRecordStatus;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\SyncRecordMapper
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Db\SyncExecution
 * @uses   \OCA\OpenRegister\Db\SyncFailure
 * @uses   \OCA\OpenRegister\Db\SyncRecord
 * @uses   \OCA\OpenRegister\Service\Sync\SyncRecordStatus
 * @uses \OCA\OpenRegister\Db\TypedFieldsJson
 */
class SyncRecordMapperGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/sync-record-mapper-golden.json';

	private function cases(): array {
		return [
			'pending with organisation' => fn (SyncRecordMapper $m) => $m->createPending(execution: $this->execution(sourceId: 3, organisation: 'org-1'), externalId: 'ext-9'),
			'pending without organisation' => fn (SyncRecordMapper $m) => $m->createPending(execution: $this->execution(sourceId: 4, organisation: null), externalId: 'ext-8'),
			'legal transition' => fn (SyncRecordMapper $m) => $m->transitionStatus(record: $this->record(), newStatus: SyncRecordStatus::FETCHED),
			'legal failure with message' => fn (SyncRecordMapper $m) => $m->recordFailure(record: $this->record(), failure: new SyncFailure(status: SyncRecordStatus::FETCH_ERROR, message: 'boom')),
			'illegal transition' => fn (SyncRecordMapper $m) => $m->transitionStatus(record: $this->record(), newStatus: SyncRecordStatus::IMPORTED),
			'illegal failure with message' => fn (SyncRecordMapper $m) => $m->recordFailure(record: $this->record(), failure: new SyncFailure(status: SyncRecordStatus::IMPORT_ERROR, message: 'late')),
		];
	}//end cases()

	public function testWritesMatchGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $call) {
			$actual[$name] = $this->outcomeOf(call: $call);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testWritesMatchGolden()

	private function execution(int $sourceId, ?string $organisation): SyncExecution {
		$source = new Source();
		$source->setId($sourceId);
		$source->setOrganisation($organisation);
		return new SyncExecution(source: $source, executionId: 'exec-' . ($sourceId - 2));
	}//end execution()

	private function record(): SyncRecord {
		$record = new SyncRecord();
		$record->setId(12);
		$record->setStatus(SyncRecordStatus::PENDING);
		$record->resetUpdatedFields();
		return $record;
	}//end record()

	private function outcomeOf(callable $call): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		$outcome = [];
		try {
			$record = $call(new SyncRecordMapper($db));
			$data = $record->jsonSerialize();
			$outcome['uuidIsV4'] = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $data['uuid']) === 1;
			$outcome['datesSet'] = [$data['created'] !== null, $data['updated'] !== null];
			unset($data['uuid'], $data['created'], $data['updated']);
			$outcome['record'] = $data;
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = preg_replace('/= [0-9a-f]{8}-[0-9a-f-]{27}, /', '= <uuid>, ', $this->queryLog);
		return $outcome;
	}//end outcomeOf()
}//end class
