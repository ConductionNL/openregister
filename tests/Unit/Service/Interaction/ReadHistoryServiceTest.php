<?php

/**
 * Unit tests for ReadHistoryService: the one read registration and the read
 * history behind the `_recent` lens.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\ProcessingLogService;
use OCA\OpenRegister\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Interaction\ReadHistoryService
 */
class ReadHistoryServiceTest extends TestCase {

	/**
	 * @var AuditTrailMapper&MockObject
	 */
	private $auditTrails;

	/**
	 * @var SettingsService&MockObject
	 */
	private $settings;

	/**
	 * @var ProcessingLogService&MockObject
	 */
	private $processingLog;

	/**
	 * Build fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->auditTrails = $this->createMock(AuditTrailMapper::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->processingLog = $this->createMock(ProcessingLogService::class);
	}//end setUp()

	/**
	 * The service under test, with the audit trail on or off.
	 *
	 * @param bool|null $auditOn True/false for the setting, null for an unreadable setting.
	 *
	 * @return ReadHistoryService
	 */
	private function service(?bool $auditOn = true): ReadHistoryService {
		if ($auditOn === null) {
			$this->settings->method('getRetentionSettingsOnly')->willThrowException(new \Exception('no settings'));
		} else {
			$this->settings->method('getRetentionSettingsOnly')->willReturn(['auditTrailsEnabled' => $auditOn]);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => $id === ProcessingLogService::class ? $this->processingLog : null
		);

		return new ReadHistoryService(
			auditTrails: $this->auditTrails,
			settings: $this->settings,
			container: $container,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * An audited read writes exactly one `read` row.
	 *
	 * @return void
	 */
	public function testAnAuditedReadWritesOneReadRow(): void {
		$object = new ObjectEntity();
		$row = new AuditTrail();
		$this->auditTrails->expects($this->once())
			->method('createAuditTrail')
			->with(null, $object, 'read')
			->willReturn($row);

		$this->assertSame($row, $this->service()->registerAuditRead(object: $object));
	}//end testAnAuditedReadWritesOneReadRow()

	/**
	 * A `_audit: false` load writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnauditedLoadWritesNothing(): void {
		$this->auditTrails->expects($this->never())->method('createAuditTrail');

		$this->assertNull($this->service()->registerAuditRead(object: new ObjectEntity(), audit: false));
	}//end testAnUnauditedLoadWritesNothing()

	/**
	 * The audit trail switched off writes nothing.
	 *
	 * @return void
	 */
	public function testAuditOffWritesNothing(): void {
		$this->auditTrails->expects($this->never())->method('createAuditTrail');

		$this->assertNull($this->service(auditOn: false)->registerAuditRead(object: new ObjectEntity()));
	}//end testAuditOffWritesNothing()

	/**
	 * An unreadable setting keeps the audit trail on, as GetObject always did.
	 *
	 * @return void
	 */
	public function testAnUnreadableSettingKeepsAuditOn(): void {
		$this->auditTrails->expects($this->once())->method('createAuditTrail')->willReturn(new AuditTrail());

		$this->service(auditOn: null)->registerAuditRead(object: new ObjectEntity());
	}//end testAnUnreadableSettingKeepsAuditOn()

	/**
	 * A processing-log read goes to ProcessingLogService and is flushed,
	 * even with the audit trail switched off.
	 *
	 * The AVG log must not follow the instance audit switch: this is the
	 * property that kept its storage separate.
	 *
	 * @return void
	 */
	public function testAProcessingReadIsLoggedEvenWithAuditOff(): void {
		$object = new ObjectEntity();
		$this->processingLog->expects($this->once())->method('logRead')->with($object, 'read');
		$this->processingLog->expects($this->once())->method('flush');
		$this->auditTrails->expects($this->never())->method('createAuditTrail');

		$this->service(auditOn: false)->registerProcessingRead(object: $object);
	}//end testAProcessingReadIsLoggedEvenWithAuditOff()

	/**
	 * A failing processing log never breaks the read.
	 *
	 * @return void
	 */
	public function testAFailingProcessingLogIsSwallowed(): void {
		$this->processingLog->method('logRead')->willThrowException(new \RuntimeException('db down'));

		$this->service()->registerProcessingRead(object: new ObjectEntity());
		$this->addToAssertionCount(1);
	}//end testAFailingProcessingLogIsSwallowed()

	/**
	 * The lens answers the caller's history when the audit trail is on.
	 *
	 * @return void
	 */
	public function testTheLensAnswersTheHistory(): void {
		$views = ['uuid-b' => '2026-10-09T10:00:00+00:00', 'uuid-a' => '2026-10-08T09:00:00+00:00'];
		$this->auditTrails->expects($this->once())
			->method('findLatestReadsByUser')
			->with('alice', ReadHistoryService::HISTORY_LIMIT)
			->willReturn($views);

		$this->assertSame(
			['available' => true, 'reason' => null, 'views' => $views],
			$this->service()->resolveRecentLens(userId: 'alice')
		);
	}//end testTheLensAnswersTheHistory()

	/**
	 * Audit off: an empty lens that says why, and no history is read.
	 *
	 * @return void
	 */
	public function testAuditOffIsAnEmptyLensWithAReason(): void {
		$this->auditTrails->expects($this->never())->method('findLatestReadsByUser');

		$this->assertSame(
			['available' => false, 'reason' => 'audit-trail-disabled', 'views' => []],
			$this->service(auditOn: false)->resolveRecentLens(userId: 'alice')
		);
	}//end testAuditOffIsAnEmptyLensWithAReason()

	/**
	 * Anonymous: empty, with its own reason.
	 *
	 * @return void
	 */
	public function testAnonymousIsAnEmptyLens(): void {
		$this->auditTrails->expects($this->never())->method('findLatestReadsByUser');

		$this->assertSame(
			['available' => false, 'reason' => 'anonymous', 'views' => []],
			$this->service()->resolveRecentLens(userId: null)
		);
	}//end testAnonymousIsAnEmptyLens()

	/**
	 * A failed lookup is reported, not dressed up as an empty history.
	 *
	 * @return void
	 */
	public function testAFailedLookupIsReported(): void {
		$this->auditTrails->method('findLatestReadsByUser')->willThrowException(new \RuntimeException('db down'));

		$this->assertSame(
			['available' => false, 'reason' => 'read-history-unavailable', 'views' => []],
			$this->service()->resolveRecentLens(userId: 'alice')
		);
	}//end testAFailedLookupIsReported()
}//end class
