<?php

/**
 * The worker's consent pass: what it reports and what it releases.
 *
 * Pins the outcomes of one cron pass for runs parked awaiting consent: a
 * missing sweep is reported only when runs are actually parked, a granted
 * delegation releases the parked run, and a failing sweep is logged rather
 * than thrown.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\FlowRunRecoveryServices;
use OCA\OpenRegister\BackgroundJob\FlowRunWorker;
use OCA\OpenRegister\BackgroundJob\FlowRunWorkerServices;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Delegation\DelegationService;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use OCA\OpenRegister\Service\Flow\FlowConsentParking;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

class FlowRunWorkerConsentTest extends TestCase {
	private FlowRunMapper&MockObject $mapper;

	/**
	 * Every log line of the pass, as "level: message".
	 *
	 * @var array<int, string>
	 */
	private array $lines = [];

	/**
	 * Context of the last info line.
	 *
	 * @var array<string, mixed>
	 */
	private array $infoContext = [];

	private AbstractLogger $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(FlowRunMapper::class);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->lines = [];
		$this->infoContext = [];

		$test = $this;
		$this->logger = new class($test) extends AbstractLogger {
			public function __construct(private readonly FlowRunWorkerConsentTest $test) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->test->record((string)$level, (string)$message, $context);
			}
		};
	}

	/**
	 * Logger sink.
	 *
	 * @param string               $level   The level.
	 * @param string               $message The message.
	 * @param array<string, mixed> $context The context.
	 */
	public function record(string $level, string $message, array $context): void {
		$this->lines[] = $level . ': ' . $message;
		if ($level === 'info') {
			$this->infoContext = $context;
		}
	}

	private function worker(?DelegationService $delegation): FlowRunWorker {
		return new FlowRunWorker(
			$this->createMock(ITimeFactory::class),
			new FlowRunWorkerServices(
				$this->mapper,
				$this->createMock(FlowRunAdvancer::class),
				$this->createMock(IAppConfig::class),
				$this->logger
			),
			new FlowRunRecoveryServices(),
			$delegation === null ? null : new FlowConsentParking($this->mapper, $delegation, $this->logger)
		);
	}

	private function pass(FlowRunWorker $worker): void {
		$method = new \ReflectionMethod(FlowRunWorker::class, 'run');
		$method->invoke($worker, null);
	}

	private function parkedRun(): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus(FlowRun::STATUS_AWAITING_CONSENT);
		$run->setContext(
			[
				FlowConsentParking::CONTEXT_KEY => [
					'principal' => 'alice',
					'actingAs' => 'mayor',
					'reason' => DelegationVerdict::REASON_PENDING,
					'since' => (new DateTime('-1 hour'))->format('c'),
				],
			]
		);

		return $run;
	}

	private function granting(): DelegationService&MockObject {
		$grant = new DelegationGrant();
		$grant->setPrincipal('alice');
		$grant->setActingAs('mayor');

		$delegation = $this->createMock(DelegationService::class);
		$delegation->method('verdictFor')->willReturn(DelegationVerdict::granted($grant));

		return $delegation;
	}

	private function linesContaining(string $needle): array {
		return array_values(array_filter($this->lines, static fn (string $l): bool => str_contains($l, $needle)));
	}

	public function testAMissingSweepIsReportedWhenRunsAreParked(): void {
		$this->mapper->method('findAwaitingConsent')->willReturn([$this->parkedRun()]);

		$this->pass($this->worker(null));

		$this->assertCount(1, $this->linesContaining('error: [FlowRunWorker] Runs are parked awaiting consent'));
	}

	public function testAMissingSweepIsSilentWhenNothingIsParked(): void {
		$this->mapper->method('findAwaitingConsent')->willReturn([]);

		$this->pass($this->worker(null));

		$this->assertSame([], $this->linesContaining('error:'));
	}

	public function testAGrantedDelegationReleasesTheParkedRun(): void {
		$run = $this->parkedRun();
		$this->mapper->method('findAwaitingConsent')->willReturn([$run]);

		$this->pass($this->worker($this->granting()));

		$this->assertSame(FlowRun::STATUS_QUEUED, $run->getStatus());
		$this->assertCount(1, $this->linesContaining('info: [FlowRunWorker] Settled parked runs'));
		$this->assertSame(1, $this->infoContext['released']);
		$this->assertSame(0, $this->infoContext['failed']);
	}

	public function testNothingSettledLogsNothing(): void {
		$this->mapper->method('findAwaitingConsent')->willReturn([]);

		$this->pass($this->worker($this->granting()));

		$this->assertSame([], $this->linesContaining('Settled parked runs'));
		$this->assertSame([], $this->linesContaining('error:'));
	}

	public function testAFailingSweepIsLoggedNotThrown(): void {
		$this->mapper->method('findAwaitingConsent')->willThrowException(new RuntimeException('db gone'));

		$this->pass($this->worker($this->granting()));

		$this->assertCount(1, $this->linesContaining('error: [FlowRunWorker] The consent sweep failed: db gone'));
	}
}
