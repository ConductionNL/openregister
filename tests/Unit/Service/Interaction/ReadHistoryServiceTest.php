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
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ReadRuleMatch;
use OCA\OpenRegister\Kernel\Contracts\ReadRuleResolverInterface;
use OCA\OpenRegister\Service\Audit\RuleDigest;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\ProcessingLogService;
use OCA\OpenRegister\Service\ProcessingRead;
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
	private function service(?bool $auditOn = true, ?ReadRuleResolverInterface $readRules = null): ReadHistoryService {
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
			logger: $this->createMock(LoggerInterface::class),
			readRules: $readRules
		);
	}//end service()

	/**
	 * A read a rule mediated writes that rule's digest and schema version on the `read` row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
	 */
	public function testAMediatedReadWritesTheRuleDigestAndVersion(): void {
		$object = new ObjectEntity();
		$rule = ['group' => 'behandelaars', 'match' => ['afdeling' => '$activeDepartment']];
		$readRules = $this->createMock(ReadRuleResolverInterface::class);
		$readRules->expects($this->once())->method('resolve')->with($object)
			->willReturn(new ReadRuleMatch(rule: $rule, digest: RuleDigest::ofEntry(entry: $rule), version: '1.4.0'));
		$row = new AuditTrail();
		$this->auditTrails->expects($this->once())->method('recordRead')
			->with($object, RuleDigest::ofEntry(entry: $rule), '1.4.0')
			->willReturn($row);

		$this->assertSame($row, $this->service(readRules: $readRules)->registerAuditRead(object: $object));
	}//end testAMediatedReadWritesTheRuleDigestAndVersion()

	/**
	 * A read no rule mediated writes a `read` row without a digest.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
	 */
	public function testAnUnmediatedReadWritesNoDigest(): void {
		$object = new ObjectEntity();
		$readRules = $this->createMock(ReadRuleResolverInterface::class);
		$readRules->method('resolve')->willReturn(null);
		$this->auditTrails->expects($this->once())->method('recordRead')->with($object, null, null)->willReturn(new AuditTrail());

		$this->service(readRules: $readRules)->registerAuditRead(object: $object);
	}//end testAnUnmediatedReadWritesNoDigest()

	/**
	 * An unaudited load does not even ask which rule applied.
	 *
	 * @return void
	 */
	public function testAnUnauditedLoadDoesNotResolveARule(): void {
		$readRules = $this->createMock(ReadRuleResolverInterface::class);
		$readRules->expects($this->never())->method('resolve');
		$this->auditTrails->expects($this->never())->method('recordRead');

		$this->assertNull($this->service(readRules: $readRules)->registerAuditRead(object: new ObjectEntity(), audit: false));
	}//end testAnUnauditedLoadDoesNotResolveARule()

	/**
	 * An audited read writes exactly one `read` row.
	 *
	 * @return void
	 */
	public function testAnAuditedReadWritesOneReadRow(): void {
		$object = new ObjectEntity();
		$row = new AuditTrail();
		$this->auditTrails->expects($this->once())
			->method('recordRead')
			->with($object, null, null)
			->willReturn($row);

		$this->assertSame($row, $this->service()->registerAuditRead(object: $object));
	}//end testAnAuditedReadWritesOneReadRow()

	/**
	 * A second read of the same object and version in one request returns the row already written.
	 *
	 * @return void
	 */
	public function testASecondReadOfTheSameObjectWritesNoSecondRow(): void {
		$object = new ObjectEntity();
		$object->setUuid('11111111-1111-4111-8111-111111111111');
		$object->setVersion('1.0.0');
		$row = new AuditTrail();
		$this->auditTrails->expects($this->once())->method('recordRead')->willReturn($row);

		$service = $this->service();
		$this->assertSame(expected: $row, actual: $service->registerAuditRead(object: $object));
		$this->assertSame(expected: $row, actual: $service->registerAuditRead(object: clone $object));
	}//end testASecondReadOfTheSameObjectWritesNoSecondRow()

	/**
	 * A new version, another object, or an object without a uuid is a read of its own.
	 *
	 * @return void
	 */
	public function testAnotherVersionOrObjectWritesItsOwnRow(): void {
		$first = new ObjectEntity();
		$first->setUuid('11111111-1111-4111-8111-111111111111');
		$first->setVersion('1.0.0');
		$changed = clone $first;
		$changed->setVersion('1.0.1');
		$other = new ObjectEntity();
		$other->setUuid('22222222-2222-4222-8222-222222222222');
		$this->auditTrails->expects($this->exactly(count: 5))->method('recordRead')->willReturnCallback(fn () => new AuditTrail());

		$service = $this->service();
		$service->registerAuditRead(object: $first);
		$service->registerAuditRead(object: $changed);
		$service->registerAuditRead(object: $other);
		$service->registerAuditRead(object: new ObjectEntity());
		$service->registerAuditRead(object: new ObjectEntity());
	}//end testAnotherVersionOrObjectWritesItsOwnRow()

	/**
	 * A `_audit: false` load writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnauditedLoadWritesNothing(): void {
		$this->auditTrails->expects($this->never())->method('recordRead');

		$this->assertNull($this->service()->registerAuditRead(object: new ObjectEntity(), audit: false));
	}//end testAnUnauditedLoadWritesNothing()

	/**
	 * The audit trail switched off writes nothing.
	 *
	 * @return void
	 */
	public function testAuditOffWritesNothing(): void {
		$this->auditTrails->expects($this->never())->method('recordRead');

		$this->assertNull($this->service(auditOn: false)->registerAuditRead(object: new ObjectEntity()));
	}//end testAuditOffWritesNothing()

	/**
	 * An unreadable setting keeps the audit trail on, as GetObject always did.
	 *
	 * @return void
	 */
	public function testAnUnreadableSettingKeepsAuditOn(): void {
		$this->auditTrails->expects($this->once())->method('recordRead')->willReturn(new AuditTrail());

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
		$this->processingLog->expects($this->once())->method('recordRead')->with($object, new ProcessingRead(action: 'read'));
		$this->processingLog->expects($this->once())->method('flush');
		$this->auditTrails->expects($this->never())->method('recordRead');

		$this->service(auditOn: false)->registerProcessingRead(object: $object);
	}//end testAProcessingReadIsLoggedEvenWithAuditOff()

	/**
	 * A failing processing log never breaks the read.
	 *
	 * @return void
	 */
	public function testAFailingProcessingLogIsSwallowed(): void {
		$this->processingLog->method('recordRead')->willThrowException(new \RuntimeException('db down'));

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
