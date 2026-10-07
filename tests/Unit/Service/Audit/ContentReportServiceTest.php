<?php

/**
 * Unit tests for the copy taken when content is reported.
 *
 * Covers REQ-ATS-003: the copy is taken at filing, it is readable by the
 * reviewers only, it carries its own retention, and a later removal names it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\ContentReport;
use OCA\OpenRegister\Db\ContentReportMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Audit\ContentReportService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContentReportServiceTest extends TestCase {
	/** @var ContentReport[] */
	private array $stored = [];

	private function mapper(): ContentReportMapper {
		$mapper = $this->createMock(ContentReportMapper::class);
		$mapper->method('insert')->willReturnCallback(function (ContentReport $report): ContentReport {
			$report->setUuid('report-' . (count($this->stored) + 1));
			$this->stored[] = $report;

			return $report;
		});
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('findByObjectUuid')->willReturnCallback(function (string $uuid): array {
			return array_values(
				array_filter($this->stored, static fn (ContentReport $r): bool => $r->getObjectUuid() === $uuid)
			);
		});

		return $mapper;
	}//end mapper()

	private function config(string $group = '', int $days = ContentReportService::DEFAULT_RETENTION_DAYS): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($group);
		$config->method('getValueInt')->willReturn($days);

		return $config;
	}//end config()

	private function groupManager(array $membership): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $user): array => ($membership[$user->getUID()] ?? [])
		);

		return $groups;
	}//end groupManager()

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	private function service(?IAppConfig $config = null, array $membership = []): ContentReportService {
		return new ContentReportService(
			$this->mapper(),
			($config ?? $this->config()),
			$this->groupManager($membership),
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	private function object(string $text): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');
		$object->setRegister('5');
		$object->setSchema('7');
		$object->setObject(['bericht' => $text]);

		return $object;
	}//end object()

	public function testTheCopyIsTakenWhenTheReportIsFiled(): void {
		$object = $this->object('de oorspronkelijke tekst');
		$report = $this->service()->file($object, 'beledigend', 'melder');

		// The content changes after filing. The copy must not follow it: it is
		// evidence of what was reported, not a live view.
		$object->setObject(['bericht' => 'bijgewerkt na de melding']);

		self::assertSame('de oorspronkelijke tekst', $report->getCopy()['object']['bericht']);
		self::assertTrue($report->copyIsIntact());
		self::assertSame(ContentReport::STATUS_OPEN, $report->getStatus());
		self::assertSame('melder', $report->getReportedBy());
	}//end testTheCopyIsTakenWhenTheReportIsFiled()

	public function testTheCopyCarriesItsOwnRetention(): void {
		$report = $this->service($this->config('', 30))->file($this->object('x'), 'r', 'melder');

		self::assertSame('content-report:30d', $report->getRetentionPeriod());
		$expected = (new DateTime())->modify('+30 days');
		self::assertEqualsWithDelta($expected->getTimestamp(), $report->getExpires()?->getTimestamp(), 5);
	}//end testTheCopyCarriesItsOwnRetention()

	public function testAZeroRetentionIsRefusedRatherThanExpiringEveryCopy(): void {
		self::assertSame(
			ContentReportService::DEFAULT_RETENTION_DAYS,
			$this->service($this->config('', 0))->retentionDays()
		);
	}//end testAZeroRetentionIsRefusedRatherThanExpiringEveryCopy()

	public function testAReviewerCanReadTheCopy(): void {
		$service = $this->service(null, ['reviewer' => ['content-reviewers']]);
		$report = $service->file($this->object('bewijs'), 'r', 'melder');

		self::assertSame('bewijs', $service->readCopy($report, $this->user('reviewer'))['object']['bericht']);
	}//end testAReviewerCanReadTheCopy()

	public function testTheCopyIsNotGenerallyReadable(): void {
		// Includes the reporter and an administrator: neither is a reviewer by
		// virtue of that alone.
		$service = $this->service(null, ['melder' => ['users'], 'beheerder' => ['admin']]);
		$report = $service->file($this->object('bewijs'), 'r', 'melder');

		self::assertNull($service->readCopy($report, $this->user('melder')));
		self::assertNull($service->readCopy($report, $this->user('beheerder')));
		self::assertNull($service->readCopy($report, null));
	}//end testTheCopyIsNotGenerallyReadable()

	public function testWideningTheConfiguredGroupDoesNotWidenACopyAlreadyTaken(): void {
		$report = $this->service($this->config('moderatie'))->file($this->object('x'), 'r', 'melder');
		self::assertSame('moderatie', $report->getReviewerGroup());

		$later = $this->service($this->config('everyone'), ['iedereen' => ['everyone']]);

		self::assertNull($later->readCopy($report, $this->user('iedereen')));
	}//end testWideningTheConfiguredGroupDoesNotWidenACopyAlreadyTaken()

	public function testAFailingGroupLookupRefusesRatherThanAllows(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willThrowException(new \RuntimeException('ldap down'));

		$service = new ContentReportService(
			$this->mapper(),
			$this->config(),
			$groups,
			$this->createMock(LoggerInterface::class)
		);
		$report = new ContentReport();
		$report->setReviewerGroup('content-reviewers');
		$report->setCopy(['object' => []]);

		self::assertNull($service->readCopy($report, $this->user('reviewer')));
	}//end testAFailingGroupLookupRefusesRatherThanAllows()

	public function testARemovalIsNotedAndNamesTheCopy(): void {
		$service = $this->service(null, ['reviewer' => ['content-reviewers']]);
		$report = $service->file($this->object('bewijs'), 'r', 'melder');

		$named = $service->noteRemoval('object-uuid', 'audit-uuid');

		self::assertSame([$report->getUuid()], $named);
		self::assertTrue($report->isRemoved());
		self::assertSame('audit-uuid', $report->getRemovalAudit());

		// Deleting the content does not delete the evidence.
		self::assertSame('bewijs', $service->readCopy($report, $this->user('reviewer'))['object']['bericht']);
	}//end testARemovalIsNotedAndNamesTheCopy()

	public function testASecondRemovalDoesNotMoveTheFirstInstant(): void {
		$service = $this->service();
		$report = $service->file($this->object('x'), 'r', 'melder');

		$service->noteRemoval('object-uuid', 'first');
		$first = $report->getRemovedAt();
		$service->noteRemoval('object-uuid', 'second');

		self::assertSame($first, $report->getRemovedAt());
		self::assertSame('first', $report->getRemovalAudit());
	}//end testASecondRemovalDoesNotMoveTheFirstInstant()

	public function testContentNobodyReportedNamesNothing(): void {
		self::assertSame([], $this->service()->noteRemoval('never-reported'));
	}//end testContentNobodyReportedNamesNothing()

	public function testTheSerializedReportDoesNotCarryTheCopy(): void {
		// The access control is that the copy has its own endpoint. A copy in
		// jsonSerialize() would leak through every list of reports.
		$report = $this->service()->file($this->object('geheim bewijs'), 'r', 'melder');

		self::assertArrayNotHasKey('copy', $report->jsonSerialize());
		self::assertStringNotContainsString('geheim bewijs', (string)json_encode($report->jsonSerialize()));
	}//end testTheSerializedReportDoesNotCarryTheCopy()

	public function testAnEditedCopyNoLongerMatchesItsChecksum(): void {
		$report = $this->service()->file($this->object('x'), 'r', 'melder');
		self::assertTrue($report->copyIsIntact());

		$report->setCopy(['object' => ['bericht' => 'achteraf aangepast']]);

		self::assertFalse($report->copyIsIntact());
	}//end testAnEditedCopyNoLongerMatchesItsChecksum()
}//end class
