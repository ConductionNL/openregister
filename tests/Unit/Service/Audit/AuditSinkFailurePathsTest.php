<?php

/**
 * Failure paths of the audit file sink.
 *
 * An app config that cannot be read leaves the sink unconfigured and on its
 * defaults, a batch skips what is not an audit entry and counts what did not
 * reach the file, and a failure that cannot be recorded on the trail is still
 * logged.
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
use OCA\OpenRegister\Service\Audit\SinkAppendRun;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(AuditSink::class)]
#[UsesClass(AuditSinkStatus::class)]
#[UsesClass(AuditTrail::class)]
#[UsesClass(SinkAppendRun::class)]
#[UsesClass(\OCA\OpenRegister\Db\AuditTrail\AuditTrailSealedForm::class)]
final class AuditSinkFailurePathsTest extends TestCase {

	/**
	 * A sink path that can never be written: a file inside a directory that does not exist.
	 *
	 * @return string
	 */
	private function unwritablePath(): string {
		return sys_get_temp_dir() . '/or-audit-sink-missing-' . bin2hex(random_bytes(6)) . '/audit.jsonl';
	}//end unwritablePath()

	private function entry(string $uuid): AuditTrail {
		$entry = new AuditTrail();
		$entry->setUuid($uuid);
		$entry->setAction('read');

		return $entry;
	}//end entry()

	public function testAnUnreadableConfigLeavesTheSinkUnconfiguredAndOnItsDefaults(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willThrowException(new RuntimeException('config down'));
		$config->expects(self::never())->method('setValueString');
		$sink = new AuditSink($config, new AuditSinkStatus($config), $this->createMock(LoggerInterface::class));

		self::assertNull($sink->path());
		self::assertFalse($sink->isConfigured());
		self::assertSame(AuditSink::FORMAT_JSONL, $sink->format());
		// Unconfigured is not a failure: the act is not held up by a sink nobody set.
		self::assertTrue($sink->ship($this->entry('e-1')));
	}//end testAnUnreadableConfigLeavesTheSinkUnconfiguredAndOnItsDefaults()

	public function testAKnownFormatIsReadCaseInsensitivelyAndAnUnknownOneFallsBack(): void {
		$known = $this->createMock(IAppConfig::class);
		$known->method('getValueString')->willReturnMap([['openregister', AuditSink::CONFIG_FORMAT, '', ' JSONL ']]);
		$unknown = $this->createMock(IAppConfig::class);
		$unknown->method('getValueString')->willReturnMap([['openregister', AuditSink::CONFIG_FORMAT, '', 'xml']]);
		$logger = $this->createMock(LoggerInterface::class);

		self::assertSame('jsonl', (new AuditSink($known, new AuditSinkStatus($known), $logger))->format());
		self::assertSame(AuditSink::FORMAT_JSONL, (new AuditSink($unknown, new AuditSinkStatus($unknown), $logger))->format());
	}//end testAKnownFormatIsReadCaseInsensitivelyAndAnUnknownOneFallsBack()

	public function testABatchSkipsNonEntriesAndCountsEveryEntryThatMissedTheFile(): void {
		$path   = $this->unwritablePath();
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				AuditSink::CONFIG_PATH => $path,
				default => $default,
			}
		);
		// Unreadable attempt and delay settings fall back to the defaults (3 attempts, 5 ms).
		$config->method('getValueInt')->willThrowException(new RuntimeException('config down'));
		$config->method('setValueString')->willReturn(true);

		$errors = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string|\Stringable $message, array $context = []) use (&$errors): void {
				$errors[] = [(string)$message, $context];
			}
		);
		$sink = new AuditSink($config, new AuditSinkStatus($config), $logger);

		$failed = $sink->shipMany([$this->entry('e-1'), 'not an entry', $this->entry('e-2')]);

		self::assertSame(2, $failed);
		$missed = array_values(array_filter($errors, static fn (array $e): bool => str_contains($e[0], 'did not reach')));
		self::assertCount(2, $missed);
		self::assertSame('e-1', $missed[0][1]['uuid']);
		self::assertSame('e-2', $missed[1][1]['uuid']);
		self::assertStringStartsWith('write to ' . $path . ' failed: ', $missed[0][1]['error']);
	}//end testABatchSkipsNonEntriesAndCountsEveryEntryThatMissedTheFile()

	public function testAFailureRecorderThatThrowsIsLoggedAndTheShipStillAnswersFalse(): void {
		$path   = $this->unwritablePath();
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				AuditSink::CONFIG_PATH => $path,
				default => $default,
			}
		);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => match ($key) {
				AuditSink::CONFIG_ATTEMPTS => 1,
				AuditSink::CONFIG_RETRY_DELAY => 0,
				default => $default,
			}
		);
		$config->method('setValueString')->willReturn(true);

		$messages = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string|\Stringable $message, array $context = []) use (&$messages): void {
				$messages[] = [(string)$message, $context];
			}
		);
		$sink = new AuditSink($config, new AuditSinkStatus($config), $logger);
		$calls = 0;
		$sink->setFailureRecorder(
			static function (AuditTrail $entry, string $error) use (&$calls): void {
				$calls++;
				throw new RuntimeException('trail down');
			}
		);

		self::assertFalse($sink->ship($this->entry('e-9')));
		self::assertSame(1, $calls);
		self::assertSame('[AuditSink] An audit entry did not reach the configured sink.', $messages[0][0]);
		self::assertSame('[AuditSink] The sink failure could not be recorded on the trail.', $messages[1][0]);
		self::assertSame(['error' => 'trail down'], $messages[1][1]);
	}//end testAFailureRecorderThatThrowsIsLoggedAndTheShipStillAnswersFalse()
}//end class
