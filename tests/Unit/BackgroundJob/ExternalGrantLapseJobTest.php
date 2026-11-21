<?php

/**
 * The daily sweep that warns the holders of external grants about to lapse.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\BackgroundJob\ExternalGrantLapseJob;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rbac\ExternalGrantGuard;
use OCA\OpenRegister\Service\Rbac\ExternalGrantLapseNotifierInterface;
use OCA\OpenRegister\Service\Rbac\GrantConstraints;
use OCA\OpenRegister\Service\Rbac\LapseWindow;
use OCA\OpenRegister\Service\Rbac\RbacChecks;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(ExternalGrantLapseJob::class)]
#[UsesClass(RbacChecks::class)]
#[UsesClass(ExternalGrantGuard::class)]
#[UsesClass(Schema::class)]
#[UsesClass(LapseWindow::class)]
final class ExternalGrantLapseJobTest extends TestCase {
	private SchemaMapperInterface&MockObject $schemas;
	private ExternalGrantLapseNotifierInterface&MockObject $notifier;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->notifier = $this->createMock(ExternalGrantLapseNotifierInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	public function testOnlyGrantsLapsingWithinTheWarningPeriodAreWarnedAbout(): void {
		$schema = $this->schema([
			'read' => [
				['name' => 'bijna-weg', ExternalGrantGuard::EXTERNAL_KEY => true, GrantConstraints::UNTIL_KEY => $this->inDays(3)],
				['name' => 'nog-lang', ExternalGrantGuard::EXTERNAL_KEY => true, GrantConstraints::UNTIL_KEY => $this->inDays(200)],
				['name' => 'al-weg', ExternalGrantGuard::EXTERNAL_KEY => true, GrantConstraints::UNTIL_KEY => $this->inDays(-2)],
				['name' => 'intern', GrantConstraints::UNTIL_KEY => $this->inDays(3)],
			],
		]);
		$this->schemas->expects(self::once())->method('findAll')
			->with(self::anything(), self::anything(), self::anything(), self::anything(), self::anything(), self::anything(), false, false)
			->willReturn([$schema]);
		$warned = [];
		$this->notifier->method('warn')->willReturnCallback(
			static function (Schema $warnedSchema, array $grant) use (&$warned, $schema): int {
				self::assertSame($schema, $warnedSchema);
				$warned[] = $grant['principal'];

				return 1;
			}
		);
		$this->logger->expects(self::once())->method('info')
			->with('[ExternalGrantLapseJob] Warned {count} holders of lapsing grants', ['count' => 1]);

		$this->runJob();

		self::assertSame(['bijna-weg'], $warned);
	}

	public function testTheCountAddsWhatTheNotifierReachedAcrossSchemas(): void {
		$lapsing = ['read' => [['name' => 'adviseur', ExternalGrantGuard::EXTERNAL_KEY => true, GrantConstraints::UNTIL_KEY => $this->inDays(5)]]];
		$this->schemas->method('findAll')->willReturn([$this->schema($lapsing), $this->schema(null), $this->schema($lapsing)]);
		// The first grant reaches two holders, the second none.
		$this->notifier->expects(self::exactly(2))->method('warn')->willReturnOnConsecutiveCalls(2, 0);
		$this->logger->expects(self::once())->method('info')->with(self::anything(), ['count' => 2]);

		$this->runJob();
	}

	public function testASweepThatFailsIsLoggedAndDoesNotThrow(): void {
		$error = new RuntimeException('database away');
		$this->schemas->method('findAll')->willThrowException($error);
		$this->notifier->expects(self::never())->method('warn');
		$this->logger->expects(self::never())->method('info');
		$this->logger->expects(self::once())->method('warning')
			->with('[ExternalGrantLapseJob] Sweep failed: {error}', ['error' => 'database away', 'exception' => $error]);

		$this->runJob();
	}

	public function testTheJobRunsDaily(): void {
		self::assertSame(86400, $this->job()->getInterval());
	}

	/**
	 * @param array<string, mixed>|null $authorization
	 */
	private function schema(?array $authorization): Schema {
		$schema = new Schema();
		$schema->setAuthorization($authorization);

		return $schema;
	}

	private function inDays(int $days): string {
		return (new DateTimeImmutable())->modify(($days >= 0 ? '+' : '') . $days . ' days')->format(DATE_ATOM);
	}

	private function job(): ExternalGrantLapseJob {
		return new ExternalGrantLapseJob($this->createMock(ITimeFactory::class), $this->logger, $this->schemas, $this->notifier);
	}

	private function runJob(): void {
		$job = $this->job();
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
