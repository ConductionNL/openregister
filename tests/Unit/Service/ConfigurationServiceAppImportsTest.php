<?php

/**
 * Tests for removing an app's recorded imports through ConfigurationService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-an-app-must-be-able-to-remove-the-objects-its-recorded-imports-created
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
use OCA\OpenRegister\Service\Configuration\CacheHandler;
use OCA\OpenRegister\Service\Configuration\ExportHandler;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\Configuration\PreviewHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ConfigurationService
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 */
class ConfigurationServiceAppImportsTest extends TestCase {
	/**
	 * Recorder double.
	 *
	 * @var AppImportJobRecorder&MockObject
	 */
	private AppImportJobRecorder&MockObject $recorder;

	/**
	 * Import service double.
	 *
	 * @var ImportService&MockObject
	 */
	private ImportService&MockObject $importService;

	/**
	 * Wire the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->recorder = $this->createMock(AppImportJobRecorder::class);
		$this->importService = $this->createMock(ImportService::class);
	}

	/**
	 * The service under test, whose container yields the two doubles.
	 *
	 * @return ConfigurationService
	 */
	private function service(): ConfigurationService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				AppImportJobRecorder::class => $this->recorder,
				ImportService::class => $this->importService,
			}
		);

		return new ConfigurationService(
			schemaMapper: $this->createMock(SchemaMapper::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			appManager: $this->createMock(IAppManager::class),
			container: $container,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			client: $this->createMock(Client::class),
			objectService: $this->createMock(ObjectService::class),
			githubHandler: $this->createMock(GitHubHandler::class),
			gitlabHandler: $this->createMock(GitLabHandler::class),
			cacheHandler: $this->createMock(CacheHandler::class),
			previewHandler: $this->createMock(PreviewHandler::class),
			exportHandler: $this->createMock(ExportHandler::class),
			uploadHandler: $this->createMock(UploadHandler::class),
			appDataPath: '/tmp'
		);
	}

	/**
	 * A job record.
	 *
	 * @param string $jobId   The job id.
	 * @param int    $created How many objects it created.
	 *
	 * @return array{jobId: string, version: string, created: int, importedAt: string}
	 */
	private function job(string $jobId, int $created): array {
		return ['jobId' => $jobId, 'version' => '1.0.0', 'created' => $created, 'importedAt' => '2026-09-27T12:00:00+00:00'];
	}

	/**
	 * Every recorded job is removed, elevated, and each clean one is forgotten.
	 *
	 * @return void
	 */
	public function testSoftDeleteAppImportsRemovesEveryRecordedJobAndForgetsCleanOnes(): void {
		$this->recorder->method('jobs')->with('learniq.demo')->willReturn([$this->job('job-a', 2), $this->job('job-b', 1)]);
		$elevated = [];
		$this->importService->method('softDeleteByImportJobId')->willReturnCallback(
			function (string $importJobId) use (&$elevated): array {
				$elevated[] = SystemOperationContext::isActive();
				$deleted = ['job-a' => ['u1', 'u2'], 'job-b' => ['u3']][$importJobId];
				return ['importJobId' => $importJobId, 'candidates' => count($deleted), 'softDeleted' => $deleted, 'errors' => []];
			}
		);
		$forgotten = [];
		$this->recorder->method('forget')->willReturnCallback(
			function (string $appId, string $importJobId) use (&$forgotten): void {
				$forgotten[] = $appId . ':' . $importJobId;
			}
		);

		$summary = $this->service()->softDeleteAppImports('learniq.demo');

		$this->assertSame('learniq.demo', $summary['appId']);
		$this->assertSame(3, $summary['softDeleted']);
		$this->assertCount(2, $summary['jobs']);
		$this->assertSame([], $summary['errors']);
		$this->assertSame(['learniq.demo:job-a', 'learniq.demo:job-b'], $forgotten);
		$this->assertSame([true, true], $elevated, 'The removal runs as a system operation, as the import did.');
		$this->assertFalse(SystemOperationContext::isActive(), 'The elevation ends with the call.');
	}

	/**
	 * A job with errors stays recorded, and the errors name the job and the object.
	 *
	 * @return void
	 */
	public function testAJobWithErrorsStaysRecorded(): void {
		$this->recorder->method('jobs')->willReturn([$this->job('job-a', 2)]);
		$this->importService->method('softDeleteByImportJobId')->willReturn(
			[
				'importJobId' => 'job-a',
				'candidates' => 2,
				'softDeleted' => ['u1'],
				'errors' => [['uuid' => 'u2', 'error' => 'locked']],
			]
		);
		$this->recorder->expects($this->never())->method('forget');

		$summary = $this->service()->softDeleteAppImports('learniq.demo');

		$this->assertSame(1, $summary['softDeleted']);
		$this->assertSame([['importJobId' => 'job-a', 'uuid' => 'u2', 'error' => 'locked']], $summary['errors']);
	}

	/**
	 * An app with nothing recorded removes nothing and asks nothing of the import service.
	 *
	 * @return void
	 */
	public function testAnAppWithNoRecordedJobsRemovesNothing(): void {
		$this->recorder->method('jobs')->willReturn([]);
		$this->importService->expects($this->never())->method('softDeleteByImportJobId');

		$summary = $this->service()->softDeleteAppImports('decidesk.profile.association');

		$this->assertSame(0, $summary['softDeleted']);
		$this->assertSame([], $summary['jobs']);
	}

	/**
	 * listImportJobs() hands back the recorder's list for that app id.
	 *
	 * @return void
	 */
	public function testImportJobsListsTheRecordedJobs(): void {
		$this->recorder->method('jobs')->with('learniq.demo')->willReturn([$this->job('job-a', 405)]);

		$this->assertSame('job-a', $this->service()->listImportJobs('learniq.demo')[0]['jobId']);
	}
}
