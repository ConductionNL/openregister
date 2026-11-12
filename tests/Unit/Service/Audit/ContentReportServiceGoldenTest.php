<?php

/**
 * Golden tests for ContentReportService::mayReadCopy and ::noteRemoval.
 *
 * Pins who may read a report's copy (allowed and refused) and every outcome of
 * noting a removal, so both can be split into named steps without changing them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use DateTime;
use OCA\OpenRegister\Db\ContentReport;
use OCA\OpenRegister\Db\ContentReportMapper;
use OCA\OpenRegister\Service\Audit\ContentReportService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the copy-read gate and the removal bookkeeping.
 */
final class ContentReportServiceGoldenTest extends TestCase {

	private ContentReportMapper&MockObject $reports;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->reports = $this->createMock(ContentReportMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * @param array<int, string> $groups The caller's groups.
	 */
	private function service(string $configuredGroup, array $groups): ContentReportService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($configuredGroup);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new ContentReportService($this->reports, $config, $groupManager, $this->logger);
	}//end service()

	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('reviewer');
		return $user;
	}//end user()

	private function report(?string $reviewerGroup, string $uuid = 'r-1', bool $removed = false): ContentReport {
		$report = new ContentReport();
		$report->setUuid($uuid);
		if ($reviewerGroup !== null) {
			$report->setReviewerGroup($reviewerGroup);
		}

		if ($removed === true) {
			$report->setRemovedAt(new DateTime('2026-01-01T00:00:00Z'));
			$report->setRemovalAudit('first-audit');
		}

		return $report;
	}//end report()

	/**
	 * @param array<int, string> $groups The caller's groups.
	 */
	#[DataProvider('copyReaders')]
	public function testMayReadCopy(?string $reportGroup, string $configured, array $groups, bool $expected): void {
		$service = $this->service($configured, $groups);

		self::assertSame($expected, $service->mayReadCopy($this->report($reportGroup), $this->user()));
	}//end testMayReadCopy()

	/**
	 * @return array<string, array{0: string|null, 1: string, 2: array<int, string>, 3: bool}>
	 */
	public static function copyReaders(): array {
		return [
			'in the report group' => ['moderatie', 'other', ['moderatie'], true],
			'not in the report group' => ['moderatie', 'other', ['other'], false],
			'no report group, in the configured group' => [null, 'configured', ['configured'], true],
			'empty report group, in the configured group' => ['', 'configured', ['configured'], true],
			'empty report group, not in the configured group' => ['', 'configured', ['moderatie'], false],
			'no report group, no configured group, in the default group' => [null, '', [ContentReportService::DEFAULT_REVIEWER_GROUP], true],
			'no groups at all' => ['moderatie', '', [], false],
		];
	}//end copyReaders()

	public function testAnAnonymousCallerMayNotReadACopy(): void {
		self::assertFalse($this->service('', ['moderatie'])->mayReadCopy($this->report('moderatie'), null));
	}//end testAnAnonymousCallerMayNotReadACopy()

	public function testAFailingGroupLookupRefuses(): void {
		$config = $this->createMock(IAppConfig::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willThrowException(new RuntimeException('ldap down'));
		$service = new ContentReportService($this->reports, $config, $groupManager, $this->logger);

		self::assertFalse($service->mayReadCopy($this->report('moderatie'), $this->user()));
	}//end testAFailingGroupLookupRefuses()

	public function testAnEmptyUuidNotesNothingAndLooksNothingUp(): void {
		$this->reports->expects($this->never())->method('findByObjectUuid');

		self::assertSame([], $this->service('', [])->noteRemoval(''));
	}//end testAnEmptyUuidNotesNothingAndLooksNothingUp()

	public function testAFailedLookupIsLoggedAndNamesNothing(): void {
		$this->reports->method('findByObjectUuid')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[ContentReportService] Could not look up reports for a removed object: db down',
			['app' => 'openregister', 'objectUuid' => 'obj-1']
		);

		self::assertSame([], $this->service('', [])->noteRemoval('obj-1', 'audit'));
	}//end testAFailedLookupIsLoggedAndNamesNothing()

	public function testOpenReportsAreNotedAlreadyRemovedOnesKeptAndFailedWritesSkipped(): void {
		$open = $this->report('g', 'open');
		$removed = $this->report('g', 'removed', true);
		$failing = $this->report('g', 'failing');
		$later = $this->report('g', 'later');
		$this->reports->method('findByObjectUuid')->with('obj-1')->willReturn([$open, $removed, $failing, $later]);
		$written = [];
		$this->reports->method('update')->willReturnCallback(
			function (ContentReport $report) use (&$written): ContentReport {
				if ($report->getUuid() === 'failing') {
					throw new RuntimeException('write refused');
				}

				$written[] = $report->getUuid();
				return $report;
			}
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'[ContentReportService] Could not note a removal on a report: write refused',
			['app' => 'openregister', 'report' => 'failing']
		);

		$named = $this->service('', [])->noteRemoval('obj-1', 'audit-2');

		self::assertSame(['open', 'removed', 'later'], $named);
		self::assertSame(['open', 'later'], $written);
		self::assertSame('audit-2', $open->getRemovalAudit());
		self::assertSame('first-audit', $removed->getRemovalAudit());
		self::assertSame('2026-01-01', $removed->getRemovedAt()->format('Y-m-d'));
		self::assertSame($open->getRemovedAt(), $later->getRemovedAt());
	}//end testOpenReportsAreNotedAlreadyRemovedOnesKeptAndFailedWritesSkipped()

	public function testARemovalWithoutAnAuditUuidStillNotesTheInstant(): void {
		$open = $this->report('g', 'open');
		$this->reports->method('findByObjectUuid')->willReturn([$open]);
		$this->reports->method('update')->willReturnArgument(0);

		self::assertSame(['open'], $this->service('', [])->noteRemoval('obj-1'));
		self::assertTrue($open->isRemoved());
		self::assertNull($open->getRemovalAudit());
	}//end testARemovalWithoutAnAuditUuidStillNotesTheInstant()
}//end class
