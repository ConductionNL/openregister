<?php

/**
 * Golden characterisation test for the failure path of AuditSink.
 *
 * Pins, on the code before onFailure() was split into named steps: every missed
 * entry is logged; only the first failure of a gap reaches the trail recorder; a
 * recorder that ships again while it records is not recorded twice (re-entry
 * guard); and a recorder that throws is logged and does not stop the next
 * failure from being recorded.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Audit\AuditSink;
use OCA\OpenRegister\Service\Audit\AuditSinkStatus;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class AuditSinkFailureGoldenTest extends TestCase {

	/**
	 * Log lines and recorder calls, in order.
	 *
	 * @var array<int, string>
	 */
	public array $trace = [];

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/or-audit-sink-failure-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
		file_put_contents($this->dir . '/not-a-directory', 'x');
	}

	protected function tearDown(): void {
		@unlink($this->dir . '/not-a-directory');
		@rmdir($this->dir);
		parent::tearDown();
	}

	private function brokenSink(): array {
		$store = [
			AuditSink::CONFIG_PATH => $this->dir . '/not-a-directory/audit.jsonl',
			AuditSink::CONFIG_ATTEMPTS => 1,
			AuditSink::CONFIG_RETRY_DELAY => 0,
		];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				return (string)($store[$key] ?? $default);
			}
		);
		$config->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default = 0) use (&$store): int {
				return (int)($store[$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);

		$test   = $this;
		$logger = new class ($test) extends AbstractLogger {
			public function __construct(private AuditSinkFailureGoldenTest $test) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->test->trace[] = $level . ': ' . $message . ' ' . json_encode(array_diff_key($context, ['error' => true]));
			}
		};

		$status = new AuditSinkStatus($config);

		return [new AuditSink($config, $status, $logger), $status];
	}

	private function entry(string $uuid): AuditTrail {
		$entry = new AuditTrail();
		$entry->setUuid($uuid);
		$entry->setAction('read');

		return $entry;
	}

	public function testOnlyTheFirstFailureOfAGapIsRecordedAndARecorderThatShipsIsNotReentered(): void {
		[$sink] = $this->brokenSink();
		$sink->setFailureRecorder(
			function (AuditTrail $entry, string $error) use ($sink): void {
				$this->trace[] = 'recorded: ' . $entry->getUuid();
				// Recording the gap is itself an audited act that ships.
				$sink->ship($this->entry('gap-row'));
			}
		);

		$this->assertFalse($sink->ship($this->entry('one')));
		$this->assertFalse($sink->ship($this->entry('two')));

		$this->assertSame(
			[
				'error: [AuditSink] An audit entry did not reach the configured sink. {"uuid":"one"}',
				'recorded: one',
				'error: [AuditSink] An audit entry did not reach the configured sink. {"uuid":"gap-row"}',
				'error: [AuditSink] An audit entry did not reach the configured sink. {"uuid":"two"}',
			],
			$this->trace
		);
	}

	public function testAThrowingRecorderIsLoggedAndTheGuardIsReleased(): void {
		[$sink, $status] = $this->brokenSink();
		$sink->setFailureRecorder(
			function (AuditTrail $entry, string $error): void {
				$this->trace[] = 'recorded: ' . $entry->getUuid();
				throw new RuntimeException('trail down');
			}
		);

		$this->assertFalse($sink->ship($this->entry('one')));
		$status->acknowledge();
		$this->assertFalse($sink->ship($this->entry('two')));

		$this->assertSame(
			[
				'error: [AuditSink] An audit entry did not reach the configured sink. {"uuid":"one"}',
				'recorded: one',
				'error: [AuditSink] The sink failure could not be recorded on the trail. []',
				'error: [AuditSink] An audit entry did not reach the configured sink. {"uuid":"two"}',
				'recorded: two',
				'error: [AuditSink] The sink failure could not be recorded on the trail. []',
			],
			$this->trace
		);
	}
}//end class
