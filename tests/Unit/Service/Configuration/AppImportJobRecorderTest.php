<?php

/**
 * Tests for the app import job recorder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-the-job-id-of-an-app-import-that-created-objects-must-be-recorded-per-app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\AppImportJobRecorder
 */
class AppImportJobRecorderTest extends TestCase {
	/**
	 * Audit trail mapper double.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper&MockObject $auditTrailMapper;

	/**
	 * In-memory app config values, key => value, for the openregister app.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * App config double backed by $config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The request-scoped stamp the mapper double holds.
	 *
	 * @var string|null
	 */
	private ?string $scope = null;

	/**
	 * Wire the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->config = [];
		$this->scope = null;

		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->auditTrailMapper->method('getRequestImportJobId')->willReturnCallback(fn (): ?string => $this->scope);
		$this->auditTrailMapper->method('setRequestImportJobId')->willReturnCallback(
			function (?string $importJobId): void {
				$this->scope = $importJobId;
			}
		);

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$this->appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->config[$key]);
			}
		);
		$this->appConfig->method('getKeys')->willReturnCallback(fn (): array => array_keys($this->config));

		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * The recorder under test, with the mapper counting the given rows.
	 *
	 * @param int $created Create rows per job.
	 * @param int $all     All rows per job.
	 *
	 * @return AppImportJobRecorder
	 */
	private function recorder(int $created = 3, int $all = 3): AppImportJobRecorder {
		$this->auditTrailMapper->method('countByImportJobId')->willReturnCallback(
			static fn (string $importJobId, ?string $action = 'create'): int => ($action === 'create' ? $created : $all)
		);

		return new AppImportJobRecorder($this->auditTrailMapper, $this->appConfig, $this->logger);
	}

	/**
	 * begin() stamps a fresh UUID; end() restores the outer stamp, not null.
	 *
	 * @return void
	 */
	public function testBeginStampsAndEndRestoresTheOuterScope(): void {
		$recorder = $this->recorder();
		$this->scope = 'outer-job';

		$inner = $recorder->begin();

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $inner);
		$this->assertSame($inner, $this->scope);

		$recorder->end();
		$this->assertSame('outer-job', $this->scope);
	}

	/**
	 * Without an outer import, end() clears the stamp.
	 *
	 * @return void
	 */
	public function testEndClearsTheStampWhenNoImportWasOuter(): void {
		$recorder = $this->recorder();

		$recorder->begin();
		$recorder->end();

		$this->assertNull($this->scope);
	}

	/**
	 * A job that created objects is recorded under its app id.
	 *
	 * @return void
	 */
	public function testRecordAppendsAJobThatCreatedObjects(): void {
		$recorder = $this->recorder(created: 405, all: 405);

		$this->assertTrue($recorder->record('learniq.demo', 'job-1', '0.4.0', 405));

		$jobs = $recorder->jobs('learniq.demo');
		$this->assertCount(1, $jobs);
		$this->assertSame('job-1', $jobs[0]['jobId']);
		$this->assertSame(405, $jobs[0]['created']);
		$this->assertSame('0.4.0', $jobs[0]['version']);
		$this->assertSame([], $recorder->jobs('learniq'), 'Another app id keeps its own list.');
	}

	/**
	 * A job that created nothing is not recorded, so re-imports do not grow the list.
	 *
	 * @return void
	 */
	public function testRecordSkipsAJobThatCreatedNothing(): void {
		$recorder = $this->recorder(created: 0, all: 12);
		$this->logger->expects($this->never())->method('warning');

		$this->assertFalse($recorder->record('learniq.demo', 'job-2', '0.4.1', 12));
		$this->assertSame([], $recorder->jobs('learniq.demo'));
	}

	/**
	 * Objects written but nothing traced means the audit trail is off, and it is said.
	 *
	 * @return void
	 */
	public function testRecordWarnsWhenObjectsWereWrittenButNothingWasTraced(): void {
		$recorder = $this->recorder(created: 0, all: 0);
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('learniq.demo'), $this->anything());

		$this->assertFalse($recorder->record('learniq.demo', 'job-3', '0.4.0', 405));
	}

	/**
	 * The list keeps the fifty most recent jobs.
	 *
	 * @return void
	 */
	public function testRecordKeepsTheFiftyMostRecentJobs(): void {
		$recorder = $this->recorder();

		for ($i = 1; $i <= 52; $i++) {
			$recorder->record('decidesk.profile.municipality', 'job-' . $i, '1.0.0', 3);
		}

		$jobs = $recorder->jobs('decidesk.profile.municipality');
		$this->assertCount(AppImportJobRecorder::MAX_JOBS, $jobs);
		$this->assertSame('job-3', $jobs[0]['jobId']);
		$this->assertSame('job-52', $jobs[49]['jobId']);
	}

	/**
	 * forget() drops one job and deletes the key when none is left.
	 *
	 * @return void
	 */
	public function testForgetDropsOneJobAndTheKeyWithTheLast(): void {
		$recorder = $this->recorder();
		$recorder->record('learniq.demo', 'job-a', '1', 3);
		$recorder->record('learniq.demo', 'job-b', '1', 3);

		$recorder->forget('learniq.demo', 'job-a');
		$this->assertSame(['job-b'], array_column($recorder->jobs('learniq.demo'), 'jobId'));

		$recorder->forget('learniq.demo', 'job-b');
		$this->assertSame([], $this->config);
	}

	/**
	 * appForJob() finds the app id a job belongs to, also behind a hashed key.
	 *
	 * @return void
	 */
	public function testAppForJobFindsTheOwningAppIdEvenBehindAHashedKey(): void {
		$recorder = $this->recorder();
		$longAppId = 'decidesk.profile.' . str_repeat('x', 60);
		$recorder->record('learniq.demo', 'job-a', '1', 3);
		$recorder->record($longAppId, 'job-long', '1', 3);

		$this->assertSame('learniq.demo', $recorder->appForJob('job-a'));
		$this->assertSame($longAppId, $recorder->appForJob('job-long'));
		$this->assertNull($recorder->appForJob('a-csv-import-job'));
		foreach (array_keys($this->config) as $key) {
			$this->assertLessThanOrEqual(64, strlen($key));
		}
	}
}
