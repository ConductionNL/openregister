<?php

/**
 * Behaviour tests for {@see \OCA\OpenRegister\Service\AnalyticsLinkService}
 * when the Analytics ReportService resolves: the report picker, report reads
 * with their timestamp parsing, the stale-link refresh and create failures.
 *
 * The Analytics app is replaced by the double in
 * Fixtures/AnalyticsAppDoubles.php (the app is not installed in CI).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

require_once __DIR__ . '/Fixtures/AnalyticsAppDoubles.php';

use OCA\OpenRegister\Service\AnalyticsReportDraft;
use DateTime;
use OCA\OpenRegister\Db\AnalyticsLink;
use OCA\OpenRegister\Db\AnalyticsLinkMapper;
use OCA\OpenRegister\Service\AnalyticsLinkService;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\LinkTarget;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Unit\Service\Fixtures\AnalyticsReportServiceDouble;

/**
 * AnalyticsLinkServiceAppPresentTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class AnalyticsLinkServiceAppPresentTest extends TestCase {
	private AnalyticsLinkMapper&MockObject $linkMapper;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private AnalyticsReportServiceDouble $reports;
	private ?string $containerFailure = null;
	private AnalyticsLinkService $service;

	protected function setUp(): void {
		$this->linkMapper = $this->getMockBuilder(AnalyticsLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'findByObjectAndReport', 'deleteByObjectAndReport', 'insert', 'update'])
			->getMock();
		$this->linkMapper->method('insert')->willReturnArgument(0);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->with('analytics')->willReturn(true);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->reports = new AnalyticsReportServiceDouble();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('\OCA\Analytics\Service\ReportService')->willReturnCallback(function (): object {
			if ($this->containerFailure !== null) {
				throw new RuntimeException($this->containerFailure);
			}
			return $this->reports;
		});

		$this->service = new AnalyticsLinkService(
			$this->linkMapper,
			new LinkServiceRuntime($appManager, $this->userSession, $this->logger),
			$container
		);
	}

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function target(): LinkTarget {
		return (new LinkTarget('obj-1', 3))->inSchema(4);
	}

	public function testGetAvailableReportsFiltersByNameCaseInsensitivelyAndSkipsIdlessReports(): void {
		$this->reports->index = [
			['id' => 1, 'name' => 'Monthly Cases', 'type' => 2, 'subheader' => 'per team'],
			['id' => 2, 'name' => 'Budget'],
			['name' => 'No id'],
		];

		$this->assertSame(
			[['id' => 1, 'name' => 'Monthly Cases', 'type' => '2', 'subheader' => 'per team', 'url' => '/index.php/apps/analytics/#/r/1']],
			$this->service->getAvailableReports('CASES')
		);
	}

	public function testGetAvailableReportsWithoutSearchListsEveryReportWithNullOptionals(): void {
		$this->reports->index = [['id' => 2, 'name' => 'Budget']];

		$this->assertSame(
			[['id' => 2, 'name' => 'Budget', 'type' => null, 'subheader' => null, 'url' => '/index.php/apps/analytics/#/r/2']],
			$this->service->getAvailableReports('')
		);
	}

	public function testGetAvailableReportsReturnsEmptyAndWarnsWhenIndexFails(): void {
		$this->reports->index = new RuntimeException('db');
		$this->logger->expects($this->once())->method('warning')
			->with('AnalyticsLinkService::getAvailableReports failed: db');

		$this->assertSame([], $this->service->getAvailableReports());
	}

	public function testGetAvailableReportsReturnsEmptyWhenServiceUnresolvable(): void {
		$this->containerFailure = 'gone';
		$this->logger->expects($this->once())->method('debug')
			->with('AnalyticsLinkService: ReportService unavailable: gone');

		$this->assertSame([], $this->service->getAvailableReports());
	}

	public function testLinkReportParsesNumericAndStringTimestamps(): void {
		$this->loginAs('alice');
		$this->linkMapper->method('findByObjectAndReport')->willReturn(null);
		$this->reports->reports[5] = ['name' => 'R', 'type' => 1, 'created_at' => '1700000000', 'updated_at' => '2026-01-02T03:04:05+00:00'];

		$link = $this->service->linkReport($this->target(), 5);

		$this->assertSame(1700000000, $link->getCreatedAt()->getTimestamp());
		$this->assertSame('2026-01-02T03:04:05+00:00', $link->getModifiedAt()->format(DATE_ATOM));
		$this->assertSame('1', $link->getReportType());
		$this->assertNull($link->getSubheader());
	}

	public function testLinkReportPrefersModifiedAtAndNullsUnsetOrUnparsableTimestamps(): void {
		$this->loginAs('alice');
		$this->linkMapper->method('findByObjectAndReport')->willReturn(null);
		$this->reports->reports[5] = ['name' => 'R', 'created_at' => 0, 'modified_at' => 'not a date', 'updated_at' => '2026-01-02'];

		$link = $this->service->linkReport($this->target(), 5);

		$this->assertNull($link->getCreatedAt());
		$this->assertNull($link->getModifiedAt());
	}

	public function testLinkReportReportsNotFoundWhenReadThrows(): void {
		$this->loginAs('alice');
		$this->linkMapper->method('findByObjectAndReport')->willReturn(null);
		$this->reports->reports[5] = new RuntimeException('denied');
		$this->logger->expects($this->once())->method('debug')
			->with('AnalyticsLinkService::fetchReportInfo read failed: denied');
		$this->linkMapper->expects($this->never())->method('insert');

		$this->expectExceptionCode(404);
		$this->service->linkReport($this->target(), 5);
	}

	public function testCreateAndLinkReportWrapsCreateFailure(): void {
		$this->loginAs('alice');
		$this->reports->createResult = new RuntimeException('quota');
		$this->logger->expects($this->once())->method('warning')
			->with('AnalyticsLinkService::createAndLinkReport failed: quota');

		$this->expectExceptionCode(500);
		$this->expectExceptionMessage('Failed to create Analytics report');
		$this->service->createAndLinkReport($this->target(), new AnalyticsReportDraft('New', null));
	}

	public function testCreateAndLinkReportPassesTheRequestedTypeToAnalytics(): void {
		$this->loginAs('alice');
		$this->reports->createResult = 9;
		$this->reports->reports[9] = ['name' => 'New', 'type' => 3];

		$link = $this->service->createAndLinkReport($this->target(), new AnalyticsReportDraft('  New  ', 3));

		$this->assertSame(['New', '', 0, 3, 0, '', '', '', '', '', ''], $this->reports->created[0]);
		$this->assertSame(9, $link->getReportId());
		$this->assertSame('3', $link->getReportType());
	}

	public function testGetLinkedReportsRefreshesStaleLinkKeepingCreatedAtWhenUnknown(): void {
		$created = new DateTime('2025-01-01T00:00:00+00:00');
		$link = new AnalyticsLink();
		$link->setReportId(5);
		$link->setReportTitle('Old');
		$link->setCreatedAt($created);
		$link->setLinkedAt((new DateTime())->setTimestamp(time() - 90000));
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->reports->reports[5] = ['name' => 'Renamed', 'subheader' => 'sub'];
		$this->linkMapper->expects($this->once())->method('update')->with($link)->willReturnArgument(0);

		$row = $this->service->getLinkedReports('obj-1')[0];

		$this->assertSame('Renamed', $row['reportTitle']);
		$this->assertSame('sub', $row['subheader']);
		$this->assertSame($created->getTimestamp(), $link->getCreatedAt()->getTimestamp());
		$this->assertSame('/index.php/apps/analytics/#/r/5', $row['url']);
	}

	public function testGetLinkedReportsReplacesCreatedAtWhenTheReportHasOne(): void {
		$link = new AnalyticsLink();
		$link->setReportId(5);
		$link->setCreatedAt(new DateTime('2025-01-01T00:00:00+00:00'));
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->reports->reports[5] = ['name' => 'R', 'created_at' => 1700000000];
		$this->linkMapper->method('update')->willReturnArgument(0);

		$this->service->getLinkedReports('obj-1');

		$this->assertSame(1700000000, $link->getCreatedAt()->getTimestamp());
	}

	public function testGetLinkedReportsKeepsCachedLinkWhenReportIsGone(): void {
		$link = new AnalyticsLink();
		$link->setReportId(5);
		$link->setReportTitle('Cached');
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->linkMapper->expects($this->never())->method('update');

		$this->assertSame('Cached', $this->service->getLinkedReports('obj-1')[0]['reportTitle']);
	}

	public function testGetLinkedReportsLeavesFreshLinkAlone(): void {
		$link = new AnalyticsLink();
		$link->setReportId(5);
		$link->setReportTitle('Cached');
		$link->setLinkedAt(new DateTime());
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->reports->reports[5] = ['name' => 'Renamed'];
		$this->linkMapper->expects($this->never())->method('update');

		$this->assertSame('Cached', $this->service->getLinkedReports('obj-1')[0]['reportTitle']);
	}

	public function testGetLinkedReportsReturnsRefreshedLinkWhenUpdateFails(): void {
		$link = new AnalyticsLink();
		$link->setReportId(5);
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->reports->reports[5] = ['name' => 'Renamed'];
		$this->linkMapper->method('update')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('debug')
			->with('AnalyticsLinkService::refreshLink update failed: db down');

		$this->assertSame('Renamed', $this->service->getLinkedReports('obj-1')[0]['reportTitle']);
	}
}
