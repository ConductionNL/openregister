<?php

/**
 * Golden test for the one-column reads and run-scoped deletes of the flow, task and pack mappers.
 *
 * Records every query-builder call and the result of each method, with a row
 * and without. Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Db\AnalyticsSeriesMapper;
use OCA\OpenRegister\Db\CalendarLinkMapper;
use OCA\OpenRegister\Db\ExportProfileMapper;
use OCA\OpenRegister\Db\FlowClaimMapper;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStreamMapper;
use OCA\OpenRegister\Db\FlowTimerEventMapper;
use OCA\OpenRegister\Db\FlowTimerFireMapper;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Db\MigrationPackMapper;
use OCA\OpenRegister\Db\TaskProjectionStateMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\AnalyticsSeriesMapper
 * @covers \OCA\OpenRegister\Db\CalendarLinkMapper
 * @covers \OCA\OpenRegister\Db\ExportProfileMapper
 * @covers \OCA\OpenRegister\Db\FlowClaimMapper
 * @covers \OCA\OpenRegister\Db\FlowRunStepMapper
 * @covers \OCA\OpenRegister\Db\FlowStreamMapper
 * @covers \OCA\OpenRegister\Db\FlowTimerEventMapper
 * @covers \OCA\OpenRegister\Db\FlowTimerFireMapper
 * @covers \OCA\OpenRegister\Db\FlowVersionMapper
 * @covers \OCA\OpenRegister\Db\MigrationPackMapper
 * @covers \OCA\OpenRegister\Db\TaskProjectionStateMapper
 * @covers \OCA\OpenRegister\Db\TaskRelationMapper
 * @covers \OCA\OpenRegister\Db\TaskSequenceMapper
 * @uses   \OCA\OpenRegister\Db\AnalyticsSeries
 * @uses   \OCA\OpenRegister\Db\CalendarLink
 * @uses   \OCA\OpenRegister\Db\ExportProfile
 * @uses   \OCA\OpenRegister\Db\FlowClaim
 * @uses   \OCA\OpenRegister\Db\FlowRunStep
 * @uses   \OCA\OpenRegister\Db\FlowStream
 * @uses   \OCA\OpenRegister\Db\FlowTimerEvent
 * @uses   \OCA\OpenRegister\Db\FlowTimerFire
 * @uses   \OCA\OpenRegister\Db\FlowVersion
 * @uses   \OCA\OpenRegister\Db\MigrationPack
 * @uses   \OCA\OpenRegister\Db\TaskProjectionState
 * @uses   \OCA\OpenRegister\Db\TaskRelation
 * @uses   \OCA\OpenRegister\Db\TaskSequence
 * @uses   \OCA\OpenRegister\Db\LinkColumn
 */
class MapperSelectByColumnGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/mapper-select-by-column-golden.json';

	private static function calls(): array {
		return [
			[AnalyticsSeriesMapper::class, 'findByKey', ['series-1']],
			[CalendarLinkMapper::class, 'findByObjectUuid', ['obj-1']],
			[CalendarLinkMapper::class, 'findByObjectAndEvent', ['obj-1', 'ev-1']],
			[ExportProfileMapper::class, 'find', [11]],
			[ExportProfileMapper::class, 'findByUuid', ['uu-1']],
			[ExportProfileMapper::class, 'findByOwner', ['alice']],
			[ExportProfileMapper::class, 'findAll', []],
			[FlowClaimMapper::class, 'findByRun', ['run-1']],
			[FlowClaimMapper::class, 'deleteByRun', ['run-1']],
			[FlowClaimMapper::class, 'deleteOrphans', []],
			[FlowRunStepMapper::class, 'findByRun', ['run-1']],
			[FlowStreamMapper::class, 'findByRun', ['run-1']],
			[FlowStreamMapper::class, 'findByRunAndStream', ['run-1', 'st-1']],
			[FlowStreamMapper::class, 'deleteByRun', ['run-1']],
			[FlowStreamMapper::class, 'deleteOrphans', []],
			[FlowTimerEventMapper::class, 'findByTimer', ['tm-1']],
			[FlowTimerFireMapper::class, 'findByTimer', ['tm-1']],
			[FlowVersionMapper::class, 'find', ['fl-1', 2]],
			[FlowVersionMapper::class, 'findPublished', ['fl-1']],
			[FlowVersionMapper::class, 'findAllForFlow', ['fl-1']],
			[MigrationPackMapper::class, 'find', [12]],
			[MigrationPackMapper::class, 'findByPackSlug', ['pack-1']],
			[MigrationPackMapper::class, 'findAll', []],
			[TaskProjectionStateMapper::class, 'findForTask', ['tk-1']],
			[TaskProjectionStateMapper::class, 'findForTask', ['tk-1', 'push']],
			[TaskRelationMapper::class, 'findForTask', [13]],
			[TaskRelationMapper::class, 'findByObject', ['obj-1']],
			[TaskRelationMapper::class, 'findByObject', ['obj-1', 'subject']],
			[TaskSequenceMapper::class, 'findByUuid', ['uu-2']],
			[TaskSequenceMapper::class, 'findRunning', ['obj-1', 'tp-1']],
			[TaskSequenceMapper::class, 'findNewestForTemplate', ['tp-1']],
			[TaskSequenceMapper::class, 'findForAnchor', ['obj-1', 'tp-1']],
			[TaskSequenceMapper::class, 'findNewestForAnchor', ['obj-1', 'tp-1']],
		];
	}//end calls()

	public function testReadsAndDeletesMatchGolden(): void {
		$actual = [];
		foreach (self::calls() as [$class, $method, $args]) {
			$short = substr($class, strrpos($class, '\\') + 1);
			foreach (['found' => [['id' => 3]], 'missing' => []] as $rowName => $rows) {
				$name = $short . ' ' . $method . '(' . implode(', ', $args) . ') / ' . $rowName;
				$actual[$name] = $this->outcomeOf(class: $class, call: [$method, $args, $rows]);
			}
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
	}//end testReadsAndDeletesMatchGolden()

	private function outcomeOf(string $class, array $call): array {
		[$method, $args, $rows] = $call;
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [$rows];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		$outcome = [];
		try {
			$outcome['result'] = $this->shape(value: (new $class($db))->$method(...$args));
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end outcomeOf()

	private function shape(mixed $value): mixed {
		if (is_object($value) === true) {
			return get_class($value) . '#' . $value->getId();
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->shape(value: $item), $value);
		}

		return $value;
	}//end shape()
}//end class
