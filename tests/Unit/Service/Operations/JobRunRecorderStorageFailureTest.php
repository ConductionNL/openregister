<?php

/**
 * Unit tests for JobRunRecorder — what is stored for a run, and what a storage failure decides.
 *
 * JobRunRecorderTest covers the happy row lifecycle. These tests pin the row
 * contents it leaves (argument digest, details, cause and actor) and the rule
 * that the run log is an observation: a row that cannot be opened, closed or
 * recorded is logged, and the job's own work still runs and returns.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Operations;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Db\JobRunMapperInterface;
use OCA\OpenRegister\Service\Operations\OperatorAct;
use OCA\OpenRegister\Service\Operations\JobRunStart;
use OCA\OpenRegister\Service\Operations\JobAlertService;
use OCA\OpenRegister\Service\Operations\JobRunContext;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class JobRunRecorderStorageFailureTest extends TestCase {

	private JobRunMapperInterface&MockObject $runs;

	private JobAlertService&MockObject $alerts;

	/** @var string[] Warning messages logged. */
	private array $warnings = [];

	/** @var JobRun[] */
	private array $inserted = [];

	private function recorder(): JobRunRecorder {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});
		return new JobRunRecorder($this->runs, $logger, $this->alerts);
	}

	protected function setUp(): void {
		$this->runs = $this->createMock(JobRunMapperInterface::class);
		$this->alerts = $this->createMock(JobAlertService::class);
	}

	private function insertsSucceed(): void {
		$this->runs->method('insert')->willReturnCallback(function (Entity $run): Entity {
			$this->inserted[] = $run;
			return $run;
		});
	}

	public function testTheRowCarriesTheCauseActorArgumentDigestAndDetails(): void {
		$this->insertsSucceed();

		$this->recorder()->around(new JobRunStart('Job\\A', new JobRunContext(JobRun::CAUSE_MANUAL, 'anna', ['register' => 3], ['batch' => 2])), static fn () => 'ok');

		$run = $this->inserted[0];
		$this->assertSame([JobRun::CAUSE_MANUAL, 'anna'], [$run->getCause(), $run->getActor()]);
		$this->assertSame(substr(sha1(json_encode(['register' => 3])), 0, 16), $run->getArgumentDigest());
		$this->assertSame('{"batch":2}', $run->getDetails());
	}

	public function testWithoutAnArgumentOrDetailsThoseColumnsStayEmpty(): void {
		$this->insertsSucceed();

		$this->recorder()->around(new JobRunStart('Job\\A'), static fn () => 'ok');

		$run = $this->inserted[0];
		$this->assertNull($run->getArgumentDigest());
		$this->assertNull($run->getDetails());
		$this->assertSame(JobRun::CAUSE_SCHEDULE, $run->getCause());
	}

	public function testAnUnencodableArgumentOrDetailsIsStoredAsNull(): void {
		$this->insertsSucceed();

		$this->recorder()->around(new JobRunStart('Job\\A', new JobRunContext(JobRun::CAUSE_SCHEDULE, null, NAN, ['x' => NAN])), static fn () => 'ok');

		$this->assertNull($this->inserted[0]->getArgumentDigest());
		$this->assertNull($this->inserted[0]->getDetails());
	}

	public function testARowThatCannotBeOpenedDoesNotStopTheWork(): void {
		$this->runs->method('insert')->willThrowException(new RuntimeException('table missing'));
		$this->runs->expects($this->never())->method('update');

		$result = $this->recorder()->around(new JobRunStart('Job\\A'), static fn () => 'the work');

		$this->assertSame('the work', $result);
		$this->assertSame(['[JobRunRecorder] Could not open a run row'], $this->warnings);
	}

	public function testARowThatCannotBeClosedIsLoggedAndTheResultStillReturned(): void {
		$this->insertsSucceed();
		$this->runs->method('update')->willThrowException(new RuntimeException('locked'));

		$this->assertSame(7, $this->recorder()->around(new JobRunStart('Job\\A'), static fn () => 7));
		$this->assertSame(['[JobRunRecorder] Could not close a run row'], $this->warnings);
	}

	public function testALongFailureMessageIsCutToTheMaximum(): void {
		$this->insertsSucceed();
		$this->runs->method('update')->willReturnArgument(0);

		try {
			$this->recorder()->around(new JobRunStart('Job\\A'), static function (): void {
				throw new RuntimeException(str_repeat('x', 5000));
			});
			$this->fail('The failure must surface.');
		} catch (RuntimeException $e) {
			$this->assertSame(5000, strlen($e->getMessage()));
		}

		$message = $this->inserted[0]->getMessage();
		$this->assertSame(JobRunRecorder::MAX_MESSAGE, strlen($message));
		$this->assertStringStartsWith('RuntimeException: xxx', $message);
	}

	public function testAnActThatCannotBeRecordedIsLoggedAndReturnsNull(): void {
		$this->runs->method('insert')->willThrowException(new RuntimeException('read-only db'));

		$this->assertNull($this->recorder()->recordAct(new OperatorAct('Console::act', 'root', ['a' => 1], 'Did it.')));
		$this->assertSame(['[JobRunRecorder] Could not record the act'], $this->warnings);
	}
}
