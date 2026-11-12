<?php

/**
 * Golden test for how RevealFlusher and SettingsChangeAuditor write their audit rows:
 * the rows and chunk size handed to the sealing path, and the exact error logged and
 * the zero returned when that write fails.
 *
 * Both classes record a fact AFTER the thing it describes already happened, so a
 * failed write must be logged and swallowed, never thrown. This pins that outcome
 * (written count on success, 0 plus one error on failure) for both.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Rbac\PropertyReveal;
use OCA\OpenRegister\Service\Rbac\RevealCollector;
use OCA\OpenRegister\Service\Rbac\RevealFlusher;
use OCA\OpenRegister\Service\Rbac\RevealRecording;
use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\RevealFlusher
 * @covers \OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor
 */
class AuditRowWriteFailureGoldenTest extends TestCase {

	/** @var array<int, array{0: string, 1: string, 2: array}> Every log call: level, message, context. */
	private array $logged = [];

	/** @var array<int, array{0: int, 1: int}> Every insert: row count and chunk size. */
	private array $inserts = [];

	private function logger(): AbstractLogger {
		$logged = &$this->logged;
		return new class($logged) extends AbstractLogger {
			public function __construct(private array &$logged) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->logged[] = [(string)$level, (string)$message, $context];
			}
		};
	}

	private function mapper(bool $fails): AuditTrailMapper {
		$inserts = &$this->inserts;
		$mapper  = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['insertAuditTrails'])
			->getMock();
		$mapper->method('insertAuditTrails')->willReturnCallback(
			static function (array $entries, int $chunkSize = 100) use (&$inserts, $fails): array {
				$inserts[] = [count($entries), $chunkSize];
				if ($fails === true) {
					throw new RuntimeException('the database went away');
				}

				return $entries;
			}
		);

		return $mapper;
	}

	private function flusher(bool $fails): RevealFlusher {
		$collector = new RevealCollector();
		$collector->recordReveal(new PropertyReveal(userId: 'alice', objectUuid: 'object-1', property: 'bsn'));
		$collector->recordReveal(new PropertyReveal(userId: 'alice', objectUuid: 'object-2', property: 'bsn'));

		return new RevealFlusher(
			recording: new RevealRecording(logger: $this->logger(), reveals: $collector),
			mapper: $this->mapper($fails)
		);
	}

	private function auditor(bool $fails): SettingsChangeAuditor {
		return new SettingsChangeAuditor(
			mapper: $this->mapper($fails),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->logger()
		);
	}

	/**
	 * The logged entries without the file and line, which say where the call sits.
	 *
	 * @return array<int, array{0: string, 1: string, 2: array}>
	 */
	private function loggedWithoutPlace(): array {
		return array_map(
			static function (array $entry): array {
				unset($entry[2]['file'], $entry[2]['line']);
				return $entry;
			},
			$this->logged
		);
	}

	public function testTheFlusherWritesEveryRevealInChunksOfOneHundred(): void {
		$this->assertSame(2, $this->flusher(false)->flush());
		$this->assertSame([[2, 100]], $this->inserts);
		$this->assertSame([], $this->logged);
	}

	public function testAFailedFlushLogsOneErrorAndWritesNothing(): void {
		$this->assertSame(0, $this->flusher(true)->flush());
		$this->assertSame(
			[
				[
					'error',
					'[RevealFlusher] Could not write the reveal entries; they are lost for this request',
					['entries' => 2, 'exception' => 'the database went away'],
				],
			],
			$this->loggedWithoutPlace()
		);
	}

	public function testTheAuditorWritesOneRowPerChangedKey(): void {
		$this->assertSame(2, $this->auditor(false)->recordUpdate('app', ['a' => 1, 'b' => 1], ['a' => 2, 'b' => 2]));
		$this->assertSame([[2, 100]], $this->inserts);
		$this->assertSame([], $this->logged);
	}

	public function testAFailedSettingsWriteLogsOneErrorAndWritesNothing(): void {
		$this->assertSame(0, $this->auditor(true)->recordUpdate('app', ['a' => 1], ['a' => 2]));
		$this->assertSame(
			[
				[
					'error',
					'[SettingsChangeAuditor] Could not record a settings change; the setting itself was stored',
					['entries' => 1, 'exception' => 'the database went away'],
				],
			],
			$this->loggedWithoutPlace()
		);
	}

	public function testTheLoggedContextStillNamesTheFileAndLine(): void {
		$this->auditor(true)->recordUpdate('app', ['a' => 1], ['a' => 2]);

		$this->assertSame(['file', 'line', 'entries', 'exception'], array_keys($this->logged[0][2]));
	}
}
