<?php

/**
 * The worker abandons queued runs that waited too long to still be worth running.
 *
 * A queued run is an intention to act NOW. Executing a schedule tick, a poll or
 * a reminder a day late does not catch anything up; it replays a decision
 * against a world that has moved on.
 *
 * The quieter reason matters more. `hasActiveRun()` counts `queued`, and
 * `FlowScheduleService` uses it as the singleton guard that stops a flow
 * overlapping itself, so a starved queued run makes that guard refuse every
 * later tick of its own flow — one stuck run silently stops a whole schedule.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Cron
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Cron;

use DateTime;
use OCA\OpenRegister\Cron\FlowRunWorker;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class FlowRunWorkerExpiryTest extends TestCase {

	private FlowRunMapper&MockObject $mapper;

	private FlowRunAdvancer&MockObject $advancer;

	private IAppConfig&MockObject $appConfig;

	private FlowRunWorker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(FlowRunMapper::class);
		$this->advancer = $this->createMock(FlowRunAdvancer::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		// findStale/findDue/findQueued all declare `: array`, so an unstubbed
		// mock already answers []. Deliberately NOT stubbed here: a stub added
		// in setUp wins over one a test adds later, which would silently
		// neutralise the ordering test below.
		$this->worker = new FlowRunWorker(
			$this->createMock(ITimeFactory::class),
			$this->mapper,
			$this->advancer,
			$this->appConfig,
			new NullLogger()
		);
	}

	/** Answer getValueString() from a map, falling back to the caller's default. */
	private function config(array $values): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => ($values[$key] ?? $default)
		);
	}

	/** Run one pass of the worker. */
	private function pass(): void {
		$method = new \ReflectionMethod(FlowRunWorker::class, 'run');
		$method->invoke($this->worker, null);
	}

	private function expiredRun(): FlowRun {
		$run = new FlowRun();
		$run->setUuid('stale-queued-1');
		$run->setFlowId('f1');
		$run->setStatus(FlowRun::STATUS_FAILED);
		$run->setError('Expired: this run waited in the queue for more than 24 hours.');

		return $run;
	}

	/**
	 * Expiry happens, and the reason says what happened and what to do.
	 */
	public function testAStaleQueuedRunIsExpiredWithAReadableReason(): void {
		$this->config(['flow_run_retention_days' => '0', 'flow_run_stale_minutes' => '0']);

		$this->mapper->expects($this->once())->method('expireQueuedBefore')
			->with(
				$this->anything(),
				$this->callback(static function (string $reason): bool {
					return str_contains($reason, 'Expired')
						&& str_contains($reason, 'Retry');
				}),
				$this->anything()
			)
			->willReturn([$this->expiredRun()]);

		$this->pass();
	}

	/**
	 * Expiry is a status change and nothing else. A cron job may record that
	 * something did not happen; deciding it should happen anyway is retry's job.
	 */
	public function testExpiringARunExecutesNothing(): void {
		$this->config(['flow_run_retention_days' => '0', 'flow_run_stale_minutes' => '0']);
		$this->mapper->method('expireQueuedBefore')->willReturn([$this->expiredRun()]);

		// `FlowRunAdvancer::advance()` is now the worker's ONLY execution
		// path (FlowRunWorker::advance() -> advancer->advance()), so asserting
		// it is never called is the whole claim — stronger than the five
		// FlowRunService/FlowLocator expectations this replaced, which named
		// collaborators the worker no longer holds.
		$this->advancer->expects($this->never())->method('advance');

		$this->pass();
	}

	/**
	 * The window reaches the query as a cut-off, not as a magic number.
	 */
	public function testTheConfiguredTtlReachesTheQueryAsACutoff(): void {
		$this->config([
			'flow_run_retention_days' => '0',
			'flow_run_stale_minutes' => '0',
			'flow_run_queued_ttl_hours' => '72',
		]);

		$this->mapper->expects($this->once())->method('expireQueuedBefore')
			->with(
				$this->callback(static function (DateTime $before): bool {
					$hoursAgo = ((time() - $before->getTimestamp()) / 3600);
					// ~72 hours ago, with room for the test's own runtime.
					return $hoursAgo > 71.9 && $hoursAgo < 72.1;
				}),
				$this->anything(),
				$this->anything()
			)
			->willReturn([]);

		$this->pass();
	}

	/**
	 * The default is 24 hours when nothing is configured.
	 */
	public function testTheDefaultTtlIsTwentyFourHours(): void {
		$this->config(['flow_run_retention_days' => '0', 'flow_run_stale_minutes' => '0']);

		$this->mapper->expects($this->once())->method('expireQueuedBefore')
			->with(
				$this->callback(static function (DateTime $before): bool {
					$hoursAgo = ((time() - $before->getTimestamp()) / 3600);
					return $hoursAgo > 23.9 && $hoursAgo < 24.1;
				}),
				$this->anything(),
				$this->anything()
			)
			->willReturn([]);

		$this->pass();
	}

	/**
	 * An instance whose cron is deliberately intermittent, and which wants every
	 * queued tick eventually run, must be able to opt out.
	 */
	public function testAZeroTtlSwitchesExpiryOff(): void {
		$this->config([
			'flow_run_retention_days' => '0',
			'flow_run_stale_minutes' => '0',
			'flow_run_queued_ttl_hours' => '0',
		]);

		$this->mapper->expects($this->never())->method('expireQueuedBefore');

		$this->pass();
	}

	/**
	 * Expiry is capped per pass: a backlog of tens of thousands must not become
	 * one enormous transaction on whichever cron slot it lands in.
	 */
	public function testExpiryIsCappedPerPass(): void {
		$this->config(['flow_run_retention_days' => '0', 'flow_run_stale_minutes' => '0']);

		$this->mapper->expects($this->once())->method('expireQueuedBefore')
			->with(
				$this->anything(),
				$this->anything(),
				$this->callback(static fn (int $limit): bool => $limit > 0 && $limit <= 1000)
			)
			->willReturn([]);

		$this->pass();
	}

	/**
	 * Housekeeping must never cost the pass its ability to run the queue.
	 */
	public function testAFailingExpiryStillDrainsTheQueue(): void {
		$this->config(['flow_run_retention_days' => '0', 'flow_run_stale_minutes' => '0']);

		$this->mapper->method('expireQueuedBefore')
			->willThrowException(new RuntimeException('database went away'));

		// findQueued is stubbed empty in setUp; what matters is that the pass
		// reaches it at all rather than dying in expiry.
		$this->mapper->expects($this->once())->method('findQueued');

		$this->pass();
	}

	/**
	 * Expiry runs BEFORE the queue is drained, so a pass never claims a run it
	 * is about to expire.
	 */
	public function testExpiryHappensBeforeTheQueueIsDrained(): void {
		$this->config(['flow_run_retention_days' => '0', 'flow_run_stale_minutes' => '0']);

		$order = [];
		$this->mapper->method('expireQueuedBefore')->willReturnCallback(
			static function () use (&$order): array {
				$order[] = 'expire';
				return [];
			}
		);
		$this->mapper->method('findQueued')->willReturnCallback(
			static function () use (&$order): array {
				$order[] = 'drain';
				return [];
			}
		);

		$this->pass();

		$this->assertSame(['expire', 'drain'], $order);
	}
}
