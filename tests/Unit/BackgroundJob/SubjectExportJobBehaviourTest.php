<?php

/**
 * SubjectExportJob assembles only a named export and contains assembly failures.
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

use OCA\OpenRegister\BackgroundJob\JobRuntime;
use OCA\OpenRegister\BackgroundJob\SubjectExportJob;
use OCA\OpenRegister\Service\Gdpr\Export\SubjectExportAssemblerInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Behaviour of the job's run().
 */
class SubjectExportJobBehaviourTest extends TestCase {

	/** @var SubjectExportAssemblerInterface&MockObject */
	private SubjectExportAssemblerInterface $exports;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->exports = $this->createMock(SubjectExportAssemblerInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}

	private function runJob(mixed $argument): void {
		$job    = new SubjectExportJob(new JobRuntime($this->createMock(ITimeFactory::class), $this->logger), $this->exports);
		$method = new ReflectionMethod(SubjectExportJob::class, 'run');
		$method->invoke($job, $argument);
	}

	private function expectNothingToAssemble(): void {
		$this->exports->expects($this->never())->method('assemble');
		$this->logger->expects($this->once())->method('warning')
			->with('[SubjectExportJob] Queued with no export uuid, so there is nothing to assemble');
	}

	public function testNonArrayArgumentIsRefused(): void {
		$this->expectNothingToAssemble();
		$this->runJob('some-uuid');
	}

	public function testMissingUuidIsRefused(): void {
		$this->expectNothingToAssemble();
		$this->runJob(['other' => 'x']);
	}

	public function testWhitespaceUuidIsRefused(): void {
		$this->expectNothingToAssemble();
		$this->runJob(['uuid' => "  \t "]);
	}

	public function testNamedUuidIsAssembledTrimmed(): void {
		$this->exports->expects($this->once())->method('assemble')->with('abc-123')->willReturn(null);
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('error');

		$this->runJob(['uuid' => ' abc-123 ']);
	}

	public function testAssemblyFailureIsLoggedNotThrown(): void {
		$this->exports->expects($this->once())->method('assemble')->with('abc-123')
			->willThrowException(new RuntimeException('disk full'));
		$this->logger->expects($this->once())->method('error')->with(
			'[SubjectExportJob] The assembly threw past the service',
			['uuid' => 'abc-123', 'error' => 'disk full']
		);

		$this->runJob(['uuid' => 'abc-123']);
	}
}
