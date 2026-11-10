<?php

/**
 * Golden test for FlowRunMigrationService: the answers of migrate(),
 * preview(), migrateRunsOfVersion(), runsOnVersion() and
 * migrateSubjectRuns(), the run writes, and which timers are superseded.
 * The log's `at` stamp and the supersede anchor are the only clock reads and
 * are left out. Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowRunMigrationTarget;
use Closure;
use DateTimeInterface;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunFilter;
use OCA\OpenRegister\Db\FlowRunListing;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationService;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationTimers;
use OCA\OpenRegister\Service\Flow\SubjectRunsMigration;
use OCA\OpenRegister\Service\Flow\VersionRunsMigration;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationValidator;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunMigrationService
 * @uses \OCA\OpenRegister\Db\FlowRun
 * @uses \OCA\OpenRegister\Db\FlowTimer
 * @uses \OCA\OpenRegister\Db\FlowVersion
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunMigrationValidator
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunMigration
 */
final class FlowRunMigrationGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/flow-run-migration-golden.json';

	/**
	 * Everything the collaborators were asked, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	private static function aRun(string $uuid, int $version, array $marking, string $status = 'suspended', mixed $log = [['type' => 'started']]): FlowRun {
		$run = new FlowRun();
		$run->setUuid($uuid);
		$run->setFlowId('flow-1');
		$run->setFlowVersion($version);
		$run->setStatus($status);
		$run->setMarking($marking);
		$run->setLog($log);

		return $run;
	}//end aRun()

	private static function timer(string $uuid, ?string $nodeId): FlowTimer {
		$timer = new FlowTimer();
		$timer->setUuid($uuid);
		$timer->setNodeId($nodeId);

		return $timer;
	}//end timer()

	/**
	 * @param array<string, FlowRun> $byUuid   Runs findByUuid answers.
	 * @param Closure                $active   Answers findActive.
	 * @param array<int, mixed>      $all      Answers findAllRuns.
	 * @param array<int, FlowTimer>  $timers   Open timers of every run.
	 */
	private function service(array $byUuid, Closure $active, array $all, array $timers): FlowRunMigrationService {
		$runs = $this->createMock(FlowRunMapper::class);
		$runs->method('findByUuid')->willReturnCallback(
			static fn (string $uuid): FlowRun => ($byUuid[$uuid] ?? throw new RuntimeException('not found'))
		);
		$runs->method('findActive')->willReturnCallback($active);
		$runs->method('findAllRuns')->willReturnCallback(
			function (...$args) use ($all): array {
				$this->calls[] = ['findAllRuns', $args];
				return $all;
			}
		);
		$runs->method('update')->willReturnCallback(
			function (FlowRun $run): FlowRun {
				$log = $run->getLog();
				if (is_array($log) === true) {
					$log = array_map(static fn ($entry) => is_array($entry) ? array_diff_key($entry, ['at' => true]) : $entry, $log);
				}

				$this->calls[] = ['update', $run->getUuid(), $run->getFlowVersion(), $run->getMarking(), $log];
				return $run;
			}
		);

		$versions = $this->createMock(FlowVersionService::class);
		$graphs = [
			2 => ['nodes' => [['id' => 'intake', 'type' => 'action'], ['id' => 'review', 'type' => 'user-task'], ['id' => 'decide', 'type' => 'gateway']]],
			3 => ['nodes' => [['id' => 'intake', 'type' => 'action'], ['id' => 'assess', 'type' => 'user-task'], ['id' => 'decide', 'type' => 'gateway']]],
			4 => ['nodes' => [['id' => 'intake', 'type' => 'action'], ['id' => 'review', 'type' => 'gateway']]],
		];
		$versions->method('versionOf')->willReturnCallback(
			static function (string $flowUuid, int $number) use ($graphs): ?FlowVersion {
				if (array_key_exists($number, $graphs) === false) {
					return null;
				}

				$version = new FlowVersion();
				$version->setVersion($number);
				$version->setDefinitionHash('hash-' . $number);
				return $version;
			}
		);
		$versions->method('graphOfVersion')->willReturnCallback(static fn (FlowVersion $version): ?array => ($graphs[$version->getVersion()] ?? null));

		$timerMapper = $this->createMock(FlowTimerMapper::class);
		$timerMapper->method('findOpenByRun')->willReturnCallback(
			function (string $runUuid) use ($timers): array {
				$this->calls[] = ['findOpenByRun', $runUuid];
				return $timers;
			}
		);
		$timerService = $this->createMock(FlowTimerService::class);
		$timerService->method('supersede')->willReturnCallback(
			function (string $uuid, DateTimeInterface $anchorEventAt, string $reason, ?string $actor) {
				$this->calls[] = ['supersede', $uuid, $reason, $actor];
				if ($uuid === 'timer-fails') {
					throw new RuntimeException('boom');
				}

				return new FlowTimer();
			}
		);

		$logger = new class($this->calls) extends AbstractLogger {
			public function __construct(private array &$calls) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->calls[] = ['log', $level, (string)$message, array_diff_key($context, ['file' => true, 'line' => true])];
			}
		};

		return new FlowRunMigrationService(
			$runs,
			new FlowRunMigrationValidator(versions: $versions),
			new FlowRunMigrationTimers(timers: $timerMapper, timerService: $timerService),
			$logger
		);
	}//end service()

	public function testMigrationMatchesGolden(): void {
		$actual = [];
		$timers = [self::timer('timer-moved', ' review '), self::timer('timer-kept', 'intake'), self::timer('timer-blank', ''), self::timer('timer-null', null), self::timer('timer-fails', 'review')];
		$none = static fn (): array => [];

		$cases = [
			'migrate renamed with timers' => fn () => $this->service(['r1' => self::aRun('r1', 2, ['review' => 1])], $none, [], $timers)
				->migrate(runUuid: 'r1', target: new FlowRunMigrationTarget(targetVersion: 3, reason: '  renamed  ', actor: 'anna', mapping: ['review' => 'assess'])),
			'migrate without mapping' => fn () => $this->service(['r1' => self::aRun('r1', 2, ['intake' => 1], 'running', 'not-a-list')], $none, [], $timers)
				->migrate(runUuid: 'r1', target: new FlowRunMigrationTarget(targetVersion: 3, reason: 'why', actor: 'anna')),
			'migrate blank reason' => fn () => $this->service([], $none, [], [])->migrate(runUuid: 'r1', target: new FlowRunMigrationTarget(targetVersion: 3, reason: '  ', actor: 'anna')),
			'migrate unknown run' => fn () => $this->service([], $none, [], [])->migrate(runUuid: 'nope', target: new FlowRunMigrationTarget(targetVersion: 3, reason: 'why', actor: 'anna')),
			'migrate does not fit' => fn () => $this->service(['r1' => self::aRun('r1', 2, ['review' => 1])], $none, [], $timers)
				->migrate(runUuid: 'r1', target: new FlowRunMigrationTarget(targetVersion: 4, reason: 'why', actor: 'anna')),
			'migrate unknown version' => fn () => $this->service(['r1' => self::aRun('r1', 2, ['review' => 1])], $none, [], [])
				->migrate(runUuid: 'r1', target: new FlowRunMigrationTarget(targetVersion: 9, reason: 'why', actor: 'anna')),
			'migrate finished run' => fn () => $this->service(['r1' => self::aRun('r1', 2, ['review' => 1], 'completed')], $none, [], [])
				->migrate(runUuid: 'r1', target: new FlowRunMigrationTarget(targetVersion: 3, reason: 'why', actor: 'anna', mapping: ['review' => 'assess'])),
			'preview fits' => fn () => $this->service(['r1' => self::aRun('r1', 2, ['review' => 1])], $none, [], $timers)
				->preview(runUuid: 'r1', target: new FlowRunMigrationTarget(targetVersion: 3, reason: '', actor: 'anna', mapping: ['review' => 'assess'])),
			'preview does not fit' => fn () => $this->service(['r1' => self::aRun('r1', 2, ['review' => 1])], $none, [], [])
				->preview(runUuid: 'r1', target: new FlowRunMigrationTarget(targetVersion: 3, reason: '', actor: 'anna')),
			'runsOnVersion' => fn () => array_map(
				static fn (FlowRun $run): string => (string)$run->getUuid(),
				$this->service([], $none, ['junk', self::aRun('a', 2, []), self::aRun('b', 3, []), self::aRun('c', 2, [], 'completed'), self::aRun('d', 2, [], 'waiting')], [])
					->runsOnVersion(listing: new FlowRunListing(filter: new FlowRunFilter(flowId: 'flow-1'), limit: 7), version: 2)
			),
			'migrateRunsOfVersion' => fn () => $this->service(
				['a' => self::aRun('a', 2, ['review' => 1]), 'd' => self::aRun('d', 2, ['decide' => 1], 'waiting'), 'e' => self::aRun('e', 2, ['intake' => 1])],
				$none,
				[self::aRun('a', 2, []), self::aRun('d', 2, [], 'waiting'), self::aRun('e', 2, [])],
				[]
			)->migrateRunsOfVersion(request: new VersionRunsMigration(flowId: 'flow-1', sourceVersion: 2, target: new FlowRunMigrationTarget(targetVersion: 4, reason: 'bulk', actor: 'bo', mapping: ['decide' => 'review']))),
			'subject store unreadable' => fn () => $this->service([], static fn () => throw new RuntimeException('db'), [], [])
				->migrateSubjectRuns(request: new SubjectRunsMigration(subjectUuid: 's1', targetDefinitionRef: '3', actorUid: 'carl')),
			'subject no runs' => fn () => $this->service([], $none, [], [])->migrateSubjectRuns(request: new SubjectRunsMigration(subjectUuid: 's1', targetDefinitionRef: '3', actorUid: 'carl')),
			'subject only junk rows' => fn () => $this->service([], static fn () => ['x', null], [], [])->migrateSubjectRuns(request: new SubjectRunsMigration(subjectUuid: 's1', targetDefinitionRef: 'flow-uuid', actorUid: 'carl')),
			'subject other flow, one run' => fn () => $this->service([], static fn () => [self::aRun('a', 2, [])], [], [])
				->migrateSubjectRuns(request: new SubjectRunsMigration(subjectUuid: 's1', targetDefinitionRef: 'abc-uuid', actorUid: 'carl')),
			'subject other flow, two runs' => fn () => $this->service([], static fn () => [self::aRun('a', 2, []), self::aRun('b', 2, [])], [], [])
				->migrateSubjectRuns(request: new SubjectRunsMigration(subjectUuid: 's1', targetDefinitionRef: '-3', actorUid: 'carl')),
			'subject all move' => fn () => $this->service(
				['a' => self::aRun('a', 2, ['intake' => 1]), 'b' => self::aRun('b', 2, ['decide' => 1])],
				static fn () => [self::aRun('a', 2, []), self::aRun('b', 2, [])],
				[],
				$timers
			)->migrateSubjectRuns(request: new SubjectRunsMigration(subjectUuid: 's1', targetDefinitionRef: '3', actorUid: 'carl')),
			'subject one cannot move' => fn () => $this->service(
				['a' => self::aRun('a', 2, ['intake' => 1]), 'b' => self::aRun('b', 2, ['review' => 1])],
				static fn () => [self::aRun('a', 2, []), self::aRun('b', 2, [])],
				[],
				[]
			)->migrateSubjectRuns(request: new SubjectRunsMigration(subjectUuid: 's1', targetDefinitionRef: '3', actorUid: 'carl')),
			'subject first missing' => fn () => $this->service(
				['b' => self::aRun('b', 2, ['intake' => 1])],
				static fn () => [self::aRun('a', 2, []), self::aRun('b', 2, [])],
				[],
				[]
			)->migrateSubjectRuns(request: new SubjectRunsMigration(subjectUuid: 's1', targetDefinitionRef: '03', actorUid: 'carl')),
		];

		foreach ($cases as $name => $case) {
			$this->calls = [];
			$result = $case();
			$actual[$name] = ['result' => $result, 'calls' => $this->calls];
		}

		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testMigrationMatchesGolden()
}//end class
