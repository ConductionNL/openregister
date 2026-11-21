<?php

/**
 * ImportPreviewRunner loads the named preview, runs the right phase and contains failures.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ImportPreviewRunner;
use OCA\OpenRegister\Service\Import\ImportCommit;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewFinderInterface;
use OCA\OpenRegister\Service\Import\ImportPreviewServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Behaviour of the job's run().
 */
class ImportPreviewRunnerBehaviourTest extends TestCase {

	/** @var ImportPreviewFinderInterface&MockObject */
	private ImportPreviewFinderInterface $finder;

	/** @var ImportPreviewServiceInterface&MockObject */
	private ImportPreviewServiceInterface $service;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private ImportPreview $preview;

	protected function setUp(): void {
		$this->finder  = $this->createMock(ImportPreviewFinderInterface::class);
		$this->service = $this->createMock(ImportPreviewServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->preview = new ImportPreview();
	}

	private function runJob(mixed $argument): void {
		$job = new ImportPreviewRunner(
			$this->createMock(ITimeFactory::class),
			$this->finder,
			$this->service,
			$this->logger
		);
		$method = new ReflectionMethod(ImportPreviewRunner::class, 'run');
		$method->invoke($job, $argument);
	}

	public function testMissingPreviewIdLogsErrorAndTouchesNothing(): void {
		$this->logger->expects($this->once())->method('error')
			->with('[ImportPreviewRunner] Missing preview_id argument');
		$this->finder->expects($this->never())->method('find');
		$this->service->expects($this->never())->method('runPreview');
		$this->service->expects($this->never())->method('commit');

		$this->runJob(['phase' => 'commit']);
	}

	public function testVanishedPreviewLogsWarningAndRunsNoPhase(): void {
		$this->finder->expects($this->once())->method('find')->with(17)
			->willThrowException(new DoesNotExistException('gone'));
		$this->logger->expects($this->once())->method('warning')
			->with('[ImportPreviewRunner] Preview 17 no longer exists');
		$this->logger->expects($this->never())->method('error');
		$this->service->expects($this->never())->method('runPreview');
		$this->service->expects($this->never())->method('commit');

		$this->runJob(['preview_id' => '17']);
	}

	public function testDefaultPhaseRunsThePreviewOnly(): void {
		$this->finder->expects($this->once())->method('find')->with(5)->willReturn($this->preview);
		$this->service->expects($this->once())->method('runPreview')
			->with($this->identicalTo($this->preview))->willReturn($this->preview);
		$this->service->expects($this->never())->method('commit');
		$this->logger->expects($this->never())->method('error');

		$this->runJob(['preview_id' => 5]);
	}

	public function testUnknownPhaseFallsBackToPreview(): void {
		$this->finder->method('find')->willReturn($this->preview);
		$this->service->expects($this->once())->method('runPreview')
			->with($this->identicalTo($this->preview))->willReturn($this->preview);
		$this->service->expects($this->never())->method('commit');

		$this->runJob(['preview_id' => 5, 'phase' => 'something-else']);
	}

	public function testCommitPhaseCommitsWithTheSourceHash(): void {
		$this->finder->expects($this->once())->method('find')->with(9)->willReturn($this->preview);
		$this->service->expects($this->once())->method('commit')
			->with($this->identicalTo($this->preview), new ImportCommit(sourceHash: 'abc123'))->willReturn($this->preview);
		$this->service->expects($this->never())->method('runPreview');

		$this->runJob(['preview_id' => 9, 'phase' => ImportPreviewRunner::PHASE_COMMIT, 'source_hash' => 'abc123']);
	}

	public function testCommitPhaseWithoutHashPassesNull(): void {
		$this->finder->method('find')->willReturn($this->preview);
		$this->service->expects($this->once())->method('commit')
			->with($this->identicalTo($this->preview), new ImportCommit(sourceHash: null))->willReturn($this->preview);

		$this->runJob(['preview_id' => 9, 'phase' => 'commit']);
	}

	public function testPhaseFailureIsLoggedWithThePhaseAndNotThrown(): void {
		$this->finder->method('find')->willReturn($this->preview);
		$this->service->method('commit')->willThrowException(new RuntimeException('hash mismatch'));
		$this->logger->expects($this->once())->method('error')
			->with('[ImportPreviewRunner] Preview 9 failed: hash mismatch', ['phase' => 'commit']);

		$this->runJob(['preview_id' => 9, 'phase' => 'commit', 'source_hash' => 'x']);
	}

	public function testPreviewFailureIsLoggedWithDefaultPhase(): void {
		$this->finder->method('find')->willReturn($this->preview);
		$this->service->method('runPreview')->willThrowException(new RuntimeException('bad csv'));
		$this->logger->expects($this->once())->method('error')
			->with('[ImportPreviewRunner] Preview 3 failed: bad csv', ['phase' => 'preview']);

		$this->runJob(['preview_id' => 3]);
	}
}
