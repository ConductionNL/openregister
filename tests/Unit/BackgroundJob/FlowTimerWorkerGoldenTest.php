<?php

/**
 * Golden test for FlowTimerWorker::run().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ConfiguredJobRuntime;
use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\BackgroundJob\FlowTimerWorker;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweep;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the sweep call (clock and batch), and every log line for each result shape.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\FlowTimerWorker
 */
class FlowTimerWorkerGoldenTest extends TestCase {

	/**
	 * The recorded trace.
	 *
	 * @var array<int, array>
	 */
	private array $trace = [];

	/**
	 * Run the job once.
	 *
	 * @param string $configured The configured batch value.
	 * @param mixed  $result     The sweep result, or a RuntimeException it throws.
	 *
	 * @return array The trace.
	 */
	private function act(string $configured, mixed $result): array {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2025-03-04 05:06:07', new \DateTimeZone('UTC')));

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use ($configured): string {
				$this->trace[] = ['config', $app, $key, $default];
				return $configured;
			}
		);

		$sweep = $this->createMock(FlowTimerSweep::class);
		$sweep->method('run')->willReturnCallback(
			function (DateTimeImmutable $now, int $batch) use ($result): array {
				$this->trace[] = ['sweep', $now->format(DATE_ATOM), $batch];
				if ($result instanceof RuntimeException) {
					throw $result;
				}

				return $result;
			}
		);

		$logger = new class($this->trace) extends AbstractLogger {
			public function __construct(private array &$sink) {
			}

			public function log($level, $message, array $context = []): void {
				unset($context['file'], $context['line']);
				if (isset($context['exception']) === true) {
					$context['exception'] = get_class($context['exception']);
				}

				$this->sink[] = ['log', $level, (string) $message, $context];
			}
		};

		$job = new FlowTimerWorker(new ConfiguredJobRuntime($time, $config, $logger), $sweep);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		return $this->trace;
	}

	private function tally(int $expiries, int $rungs, int $timeouts, bool $truncated, int $errors): array {
		return [
			'expiriesFired' => $expiries,
			'rungsFired' => $rungs,
			'taskTimeouts' => $timeouts,
			'truncated' => $truncated,
			'errors' => $errors,
		];
	}

	private const CONFIG = ['config', 'openregister', 'flow_timer_batch', '200'];

	public static function batchProvider(): array {
		return [
			'zero clamps to one' => ['0', 1],
			'negative clamps to one' => ['-5', 1],
			'garbage clamps to one' => ['abc', 1],
			'configured value' => ['50', 50],
			'default' => ['200', 200],
		];
	}

	#[DataProvider('batchProvider')]
	public function testQuietPassLogsNothing(string $configured, int $batch): void {
		$this->assertSame(
			[self::CONFIG, ['sweep', '2025-03-04T05:06:07+00:00', $batch]],
			$this->act($configured, $this->tally(0, 0, 0, false, 0))
		);
	}

	public static function noisyProvider(): array {
		return [
			'expiry' => [1, 0, 0, false, 0, 'false'],
			'rung' => [0, 2, 0, false, 0, 'false'],
			'timeout' => [0, 0, 3, false, 0, 'false'],
			'truncated only' => [0, 0, 0, true, 0, 'true'],
			'errors only' => [0, 0, 0, false, 4, 'false'],
			'everything' => [5, 6, 7, true, 8, 'true'],
		];
	}

	#[DataProvider('noisyProvider')]
	public function testNoisyPassIsLogged(int $exp, int $rung, int $tout, bool $trunc, int $err, string $truncText): void {
		$result = $this->tally($exp, $rung, $tout, $trunc, $err);
		$this->assertSame(
			[
				self::CONFIG,
				['sweep', '2025-03-04T05:06:07+00:00', 200],
				[
					'log',
					'info',
					sprintf(
						'[FlowTimerWorker] Fired %d expiry timer(s), %d escalation rung(s) and %d task timeout(s); truncated: %s; errors: %d',
						$exp,
						$rung,
						$tout,
						$truncText,
						$err
					),
					$result,
				],
			],
			$this->act('200', $result)
		);
	}

	public function testSweepFailureIsLogged(): void {
		$this->assertSame(
			[
				self::CONFIG,
				['sweep', '2025-03-04T05:06:07+00:00', 200],
				['log', 'error', '[FlowTimerWorker] Sweep pass failed: down', ['exception' => RuntimeException::class]],
			],
			$this->act('200', new RuntimeException('down'))
		);
	}
}
