<?php

/**
 * FlowRunMigrationTimers and FlowRunMigrationService: a moved timer that cannot be superseded is logged and does not undo the migration, and only the superseded timers are counted.
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

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Kernel\Contracts\TimerAnchorMove;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationService;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationTarget;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationTimers;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationValidator;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunMigrationTimers
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunMigrationService
 * @uses \OCA\OpenRegister\Db\FlowRun
 * @uses \OCA\OpenRegister\Db\FlowTimer
 * @uses \OCA\OpenRegister\Db\FlowVersion
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunCommit
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunMarkingRemap
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunMigration
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunMigrationTarget
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunMigrationValidator
 */
class FlowRunMigrationTimerFailureBehaviourTest extends TestCase {

	private function timer(string $uuid, string $nodeId): FlowTimer {
		$timer = new FlowTimer();
		$timer->setUuid($uuid);
		$timer->setNodeId($nodeId);

		return $timer;
	}//end timer()

	public function testAFailedSupersessionIsLoggedAndTheRunStillMoves(): void {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('flow-1');
		$run->setFlowVersion(2);
		$run->setStatus('suspended');
		$run->setMarking(['review' => 1]);
		$run->setLog([['type' => 'started']]);
		$runs = $this->createMock(FlowRunMapper::class);
		$runs->method('findByUuid')->willReturn($run);
		$runs->expects(self::once())->method('update')->with($run)->willReturn($run);

		$graphs = [
			2 => ['nodes' => [['id' => 'review', 'type' => 'user-task'], ['id' => 'second', 'type' => 'user-task']]],
			3 => ['nodes' => [['id' => 'assess', 'type' => 'user-task'], ['id' => 'second-v3', 'type' => 'user-task']]],
		];
		$versions = $this->createMock(FlowVersionService::class);
		$versions->method('versionOf')->willReturnCallback(static function (string $flowUuid, int $number): FlowVersion {
			$version = new FlowVersion();
			$version->setVersion($number);
			$version->setDefinitionHash('hash-' . $number);

			return $version;
		});
		$versions->method('graphOfVersion')->willReturnCallback(fn (FlowVersion $version): array => $graphs[$version->getVersion()]);

		$timers = $this->createMock(FlowTimerMapper::class);
		$timers->method('findOpenByRun')->with('run-1')->willReturn([$this->timer('t-locked', 'review'), $this->timer('t-ok', 'second'), $this->timer('t-stays', 'other')]);
		$timerService = $this->createMock(FlowTimerService::class);
		$timerService->expects(self::exactly(2))->method('supersede')->willReturnCallback(
			static function (TimerAnchorMove $move): FlowTimer {
				if ($move->uuid === 't-locked') {
					throw new RuntimeException('row locked');
				}

				return new FlowTimer();
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')
			->with(self::stringContains('run run-1 migrated, but timer t-locked could not be superseded: row locked'));
		$logger->expects(self::once())->method('info')
			->with(self::stringContains('migrated from version 2 to 3 by anna'), self::callback(static fn (array $context): bool => $context['timersSuperseded'] === 1));

		$service = new FlowRunMigrationService(
			$runs,
			new FlowRunMigrationValidator(versions: $versions),
			new FlowRunMigrationTimers(timers: $timers, timerService: $timerService),
			$logger
		);

		$result = $service->migrate(
			runUuid: 'run-1',
			target: new FlowRunMigrationTarget(targetVersion: 3, reason: 'renamed', actor: 'anna', mapping: ['review' => 'assess', 'second' => 'second-v3'])
		);

		self::assertSame(3, $run->getFlowVersion());
		self::assertSame(['assess' => 1], $run->getMarking());
		self::assertTrue($result['migrated']);
		self::assertSame(2, $result['from']);
	}//end testAFailedSupersessionIsLoggedAndTheRunStillMoves()
}//end class
