<?php

/**
 * Tests for SeedDirectoryJob: the digest gate, through run().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\RecordedJobRuntime;
use OCA\OpenRegister\BackgroundJob\RecordedTimedJob;
use OCA\OpenRegister\BackgroundJob\SeedDirectoryJob;
use OCA\OpenRegister\Service\AppConfigurationImporterInterface;
use OCA\OpenRegister\Service\CauseFrame;
use OCA\OpenRegister\Service\Configuration\SeedDirectoryImporter;
use OCA\OpenRegister\Service\Configuration\SeedReport;
use OCA\OpenRegister\Service\Operations\JobRunContext;
use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCA\OpenRegister\Service\Operations\JobRunStart;
use OCA\OpenRegister\Service\Operations\JobScheduleServiceInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

#[CoversClass(SeedDirectoryJob::class)]
#[UsesClass(RecordedTimedJob::class)]
#[UsesClass(RecordedJobRuntime::class)]
#[UsesClass(SeedDirectoryImporter::class)]
#[UsesClass(SeedReport::class)]
#[UsesClass(WriteCause::class)]
#[UsesClass(CauseFrame::class)]
#[UsesClass(JobRunStart::class)]
#[UsesClass(JobRunContext::class)]
class SeedDirectoryJobTest extends TestCase {

	private string $dir;

	/** @var array<string, string> */
	private array $store = [];

	/** @var list<string> The app ids imported, in order. */
	private array $imported = [];

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/seedjob-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
		file_put_contents($this->dir . '/10-registers.json', '{"version":"1.0.0"}');
		file_put_contents($this->dir . '/20-objects.json', '{"version":"1.0.0","objects":[{"title":"a"}]}');
	}//end setUp()

	protected function tearDown(): void {
		array_map('unlink', glob($this->dir . '/*'));
		rmdir($this->dir);
		WriteCause::reset();
	}//end tearDown()

	private function job(): SeedDirectoryJob {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn($this->dir);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->store[$key] ?? $default);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		$importer = $this->createMock(AppConfigurationImporterInterface::class);
		$importer->method('importFromApp')->willReturnCallback(
			function (string $appId): array {
				$this->imported[] = $appId;
				return ['registers' => [], 'schemas' => [], 'objects' => ['o'], 'failed' => ['schemas' => [], 'objects' => []]];
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-13T10:00:00+00:00'));

		$recorder = $this->createMock(JobRunRecorderInterface::class);
		$recorder->method('around')->willReturnCallback(
			static function (JobRunStart $start, callable $work): mixed {
				$work();
				return null;
			}
		);
		$schedule = $this->createMock(JobScheduleServiceInterface::class);
		$schedule->method('mayRun')->willReturn(true);

		return new SeedDirectoryJob(
			runtime: new RecordedJobRuntime(time: $time, recorder: $recorder),
			schedule: $schedule,
			importer: new SeedDirectoryImporter(
				config: $config,
				appConfig: $appConfig,
				importer: $importer,
				time: $time,
				logger: $this->createMock(LoggerInterface::class)
			)
		);
	}//end job()

	private function tick(SeedDirectoryJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end tick()

	public function testAnUnchangedDigestSkipsTheImport(): void {
		$job = $this->job();

		$this->tick(job: $job);
		self::assertSame(['openregister-seed.10-registers', 'openregister-seed.20-objects'], $this->imported);
		self::assertSame(SeedDirectoryJob::INTERVAL_SECONDS, $job->getInterval());

		$this->tick(job: $job);
		self::assertCount(2, $this->imported, 'the second tick finds the same digest and imports nothing');
	}//end testAnUnchangedDigestSkipsTheImport()

	public function testAChangedFileTriggersTheImport(): void {
		$job = $this->job();
		$this->tick(job: $job);

		file_put_contents($this->dir . '/20-objects.json', '{"version":"1.0.0","objects":[{"title":"b"}]}');
		$this->tick(job: $job);

		self::assertCount(4, $this->imported);
		self::assertSame('success', json_decode($this->store[SeedDirectoryImporter::REPORT_KEY], true)['status']);
	}//end testAChangedFileTriggersTheImport()
}//end class
