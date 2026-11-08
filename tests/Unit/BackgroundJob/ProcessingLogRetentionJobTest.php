<?php

/**
 * ProcessingLogRetentionJob prunes by the configured retention and records each purge.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/processing-activity-register/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\ProcessingLogRetentionJob;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use OCA\OpenRegister\BackgroundJob\Support\ProcessingLogRetentionStores;

class ProcessingLogRetentionJobTest extends TestCase {

	/** @var array<int, DateTime> The cutoffs the mapper was given. */
	private array $cutoffs = [];

	/** @var array<int, AuditTrail> The purge records written. */
	private array $records = [];

	/**
	 * Run the job with the given setting and mapper answer.
	 *
	 * @param string          $configured The configured retention.
	 * @param int|\Throwable  $deleted    What the delete answers.
	 *
	 * @return void
	 */
	private function runJob(string $configured, int|\Throwable $deleted = 0): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn () => new DateTime('2026-11-05T12:00:00+00:00'));

		$entries = $this->createMock(ProcessingLogMapper::class);
		$entries->method('deleteCreatedBefore')->willReturnCallback(
			function (DateTime $cutoff) use ($deleted): int {
				$this->cutoffs[] = $cutoff;
				if ($deleted instanceof \Throwable) {
					throw $deleted;
				}

				return $deleted;
			}
		);

		$audit = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['insertAuditTrails'])
			->getMock();
		$audit->method('insertAuditTrails')->willReturnCallback(
			function (array $entries, int $chunkSize = 100): array {
				$this->records = array_merge($this->records, $entries);
				return $entries;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (
				$app === 'openregister' && $key === 'processing_log_retention' ? $configured : $default
			)
		);

		$job = new ProcessingLogRetentionJob($time, new ProcessingLogRetentionStores($entries, $audit), $config, $this->createMock(LoggerInterface::class));
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testTheDefaultKeepsThreeYears(): void {
		$this->runJob(ProcessingLogRetentionJob::DEFAULT_RETENTION);

		$this->assertSame('2023-11-05', $this->cutoffs[0]->format('Y-m-d'));
	}

	public function testTheConfiguredPeriodApplies(): void {
		$this->runJob('P5Y');

		$this->assertSame('2021-11-05', $this->cutoffs[0]->format('Y-m-d'));
	}

	/**
	 * @dataProvider unusableRetention
	 */
	public function testAnUnusableSettingFallsBackToTheDefault(string $configured): void {
		$this->runJob($configured);

		$this->assertSame('2023-11-05', $this->cutoffs[0]->format('Y-m-d'), 'a bad setting must not shorten retention');
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unusableRetention(): array {
		return ['not a duration' => ['three years'], 'empty' => [''], 'zero' => ['P0D']];
	}

	public function testAPurgeIsRecordedWithPeriodAndCount(): void {
		$this->runJob('P3Y', 42);

		$this->assertCount(1, $this->records);
		$this->assertSame(ProcessingLogRetentionJob::ACTION_PRUNED, $this->records[0]->getAction());
		$changed = $this->records[0]->getChanged();
		$this->assertSame('P3Y', $changed['retention']);
		$this->assertSame(42, $changed['deleted']);
		$this->assertStringStartsWith('2023-11-05', $changed['before']);
	}

	public function testARunThatRemovesNothingRecordsNothing(): void {
		$this->runJob('P3Y', 0);

		$this->assertSame([], $this->records);
	}

	public function testAFailedPruneRecordsNothingAndDoesNotThrow(): void {
		$this->runJob('P3Y', new RuntimeException('db down'));

		$this->assertSame([], $this->records);
	}
}
