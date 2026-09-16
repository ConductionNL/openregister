<?php

/**
 * Unit tests for the file sink the audit trail is shipped to.
 *
 * Covers the spec delta's "the security operations centre can read the trail"
 * and "a broken sink is loud": the line that appears in the file, the append
 * that does not disturb what is already there, the retry, the gap entry that
 * fires once per healthy-to-broken transition rather than once per audited
 * act, and the unconfigured instance that behaves exactly as it did before.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Audit\AuditSink;
use OCA\OpenRegister\Service\Audit\AuditSinkStatus;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AuditSinkTest extends TestCase {
	/**
	 * The throwaway directory each test writes its sink into.
	 *
	 * @var string
	 */
	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/or-audit-sink-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700, true);
	}//end setUp()

	protected function tearDown(): void {
		$files = glob($this->dir . '/*');
		if (is_array($files) === false) {
			$files = [];
		}

		foreach ($files as $file) {
			@unlink($file);
		}

		@rmdir($this->dir);
		parent::tearDown();
	}//end tearDown()

	/**
	 * An in-memory IAppConfig, because the sink reads five keys and a mock
	 * with five willReturnMap entries says less about the test than a store.
	 *
	 * @param array $values The keys the sink should read back.
	 *
	 * @return IAppConfig The fake, with one store behind get and set.
	 */
	private function appConfig(array $values): IAppConfig {
		// ONE store behind both the reads and the writes. A fake whose setter
		// writes somewhere its getter never looks is a fake that reports the
		// sink permanently healthy, which is the one state these tests exist
		// to disprove.
		$store = $values;

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

		return $config;
	}//end appConfig()

	private function entry(string $uuid = 'entry-1', string $action = 'read'): AuditTrail {
		$entry = new AuditTrail();
		$entry->setId(4711);
		$entry->setUuid($uuid);
		$entry->setAction($action);
		$entry->setUser('jan');
		$entry->setUserName('Jan Jansen');
		$entry->setCreated(new DateTime('2026-09-16T08:00:00+02:00'));
		$entry->setPurpose('brp-adresonderzoek');

		return $entry;
	}//end entry()

	private function sink(array $config): array {
		$appConfig = $this->appConfig($config);
		$status = new AuditSinkStatus($appConfig);
		$sink = new AuditSink($appConfig, $status, new NullLogger());

		return [$sink, $status];
	}//end sink()

	public function testAnAuditedActAppearsAsALineInTheFile(): void {
		$path = $this->dir . '/audit.jsonl';
		[$sink] = $this->sink([AuditSink::CONFIG_PATH => $path]);

		self::assertTrue($sink->ship($this->entry()));

		$lines = file($path, FILE_IGNORE_NEW_LINES);
		self::assertCount(1, $lines);

		$decoded = json_decode($lines[0], true);
		self::assertSame(4711, $decoded['id']);
		self::assertSame('entry-1', $decoded['uuid']);
		self::assertSame('read', $decoded['action']);
		self::assertSame('brp-adresonderzoek', $decoded['purpose']);
		self::assertSame(AuditSink::LINE_VERSION, $decoded['v']);
		// The row is not sealed yet, and the line says so rather than
		// implying a row that has no hash at all.
		self::assertFalse($decoded['sealed']);
	}//end testAnAuditedActAppearsAsALineInTheFile()

	public function testASealedRowSaysSo(): void {
		$path = $this->dir . '/audit.jsonl';
		[$sink] = $this->sink([AuditSink::CONFIG_PATH => $path]);

		$entry = $this->entry();
		$entry->setHash(str_repeat('a', 64));

		$sink->ship($entry);

		$decoded = json_decode((string)file_get_contents($path), true);
		self::assertTrue($decoded['sealed']);
	}//end testASealedRowSaysSo()

	public function testTheFileIsAppendedToNeverRewritten(): void {
		$path = $this->dir . '/audit.jsonl';
		[$sink] = $this->sink([AuditSink::CONFIG_PATH => $path]);

		$sink->ship($this->entry('first'));
		$sink->ship($this->entry('second'));
		$sink->ship($this->entry('third'));

		$lines = file($path, FILE_IGNORE_NEW_LINES);
		self::assertCount(3, $lines);
		self::assertSame('first', json_decode($lines[0], true)['uuid']);
		self::assertSame('third', json_decode($lines[2], true)['uuid']);
	}//end testTheFileIsAppendedToNeverRewritten()

	public function testAnInstanceWithNoSinkConfiguredShipsNothingAndSucceeds(): void {
		[$sink, $status] = $this->sink([]);

		self::assertFalse($sink->isConfigured());
		self::assertTrue($sink->ship($this->entry()));
		self::assertSame([], glob($this->dir . '/*'));
		self::assertSame(0, $status->read()['unshipped']);
	}//end testAnInstanceWithNoSinkConfiguredShipsNothingAndSucceeds()

	public function testABrokenSinkIsLoudOnceAndCountsTheRest(): void {
		// A path inside a file rather than a directory: every write fails, and
		// it fails the way a real one does rather than through a stubbed
		// filesystem that can only fail the way the test imagined.
		$blocker = $this->dir . '/not-a-directory';
		file_put_contents($blocker, 'x');
		$path = $blocker . '/audit.jsonl';

		[$sink, $status] = $this->sink(
			[
				AuditSink::CONFIG_PATH => $path,
				AuditSink::CONFIG_ATTEMPTS => 2,
				AuditSink::CONFIG_RETRY_DELAY => 0,
			]
		);

		$gaps = [];
		$sink->setFailureRecorder(
			static function (AuditTrail $entry, string $error) use (&$gaps): void {
				$gaps[] = [$entry->getUuid(), $error];
			}
		);

		self::assertFalse($sink->ship($this->entry('one')));
		self::assertFalse($sink->ship($this->entry('two')));
		self::assertFalse($sink->ship($this->entry('three')));

		// Loud ONCE. Three failures would be three extra rows on the largest
		// table this app has, per audited act, while the operator sleeps.
		self::assertCount(1, $gaps);
		self::assertSame('one', $gaps[0][0]);
		self::assertStringContainsString('failed', $gaps[0][1]);

		// The size of the gap is still knowable.
		$read = $status->read();
		self::assertFalse($read['healthy']);
		self::assertSame(3, $read['unshipped']);
		self::assertNotNull($read['lastFailureAt']);
	}//end testABrokenSinkIsLoudOnceAndCountsTheRest()

	public function testASuccessDoesNotQuietlyHealAnUnacknowledgedGap(): void {
		$path = $this->dir . '/audit.jsonl';
		$appConfig = $this->appConfig([AuditSink::CONFIG_PATH => $path]);
		$status = new AuditSinkStatus($appConfig);
		$sink = new AuditSink($appConfig, $status, new NullLogger());

		$status->recordFailure('disk full');
		self::assertSame(1, $status->read()['unshipped']);

		$sink->ship($this->entry());

		$read = $status->read();
		self::assertFalse($read['healthy'], 'a sink with entries still missing must not report itself healthy');
		self::assertSame(1, $read['unshipped']);
		self::assertNotNull($read['lastSuccessAt']);

		$cleared = $status->acknowledge();
		self::assertTrue($cleared['healthy']);
		self::assertSame(0, $cleared['unshipped']);
	}//end testASuccessDoesNotQuietlyHealAnUnacknowledgedGap()

	public function testAnUnrecognisedFormatFallsBackRatherThanStoppingTheTrail(): void {
		[$sink] = $this->sink(
			[
				AuditSink::CONFIG_PATH => $this->dir . '/audit.jsonl',
				AuditSink::CONFIG_FORMAT => 'jsonl-but-misspelled',
			]
		);

		self::assertSame(AuditSink::FORMAT_JSONL, $sink->format());
	}//end testAnUnrecognisedFormatFallsBackRatherThanStoppingTheTrail()

	public function testShipManyReportsHowManyDidNotMakeIt(): void {
		$blocker = $this->dir . '/blocked';
		file_put_contents($blocker, 'x');

		[$sink] = $this->sink(
			[
				AuditSink::CONFIG_PATH => $blocker . '/audit.jsonl',
				AuditSink::CONFIG_ATTEMPTS => 1,
				AuditSink::CONFIG_RETRY_DELAY => 0,
			]
		);

		self::assertSame(2, $sink->shipMany([$this->entry('a'), $this->entry('b')]));
	}//end testShipManyReportsHowManyDidNotMakeIt()
}//end class
