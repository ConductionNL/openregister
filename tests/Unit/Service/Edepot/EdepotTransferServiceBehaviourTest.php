<?php

/**
 * Behaviour tests for EdepotTransferService: the attempt flow from gathering
 * through packaging and delivery, and the profile catalogue.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\PackagedFileChecksum;
use OCA\OpenRegister\Service\Edepot\SipPackageBuilder;
use OCA\OpenRegister\Service\Edepot\TransferAuditLog;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferObjectGatherer;
use OCA\OpenRegister\Service\Edepot\TransferOutcomeRecorder;
use OCA\OpenRegister\Service\Edepot\TransferPipeline;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\Edepot\TransferResultProcessor;
use OCA\OpenRegister\Service\Edepot\TransferSettings;
use OCA\OpenRegister\Service\Edepot\Transport\TransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\TransportResult;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Behaviour of one e-Depot transfer attempt.
 */
class EdepotTransferServiceBehaviourTest extends TestCase {

	/** @var array<string, ObjectEntity> */
	private array $objects = [];

	/** @var SipPackageBuilder&MockObject */
	private SipPackageBuilder $sipBuilder;

	/** @var TransportInterface&MockObject */
	private TransportInterface $transport;

	/** @var INotificationManager&MockObject */
	private INotificationManager $notifications;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private TransferPipeline $pipeline;

	protected function setUp(): void {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function (string|int $uuid): ObjectEntity {
				if (isset($this->objects[$uuid]) === false) {
					throw new \RuntimeException('not found');
				}

				return $this->objects[$uuid];
			}
		);
		$this->logger        = $this->createMock(LoggerInterface::class);
		$this->notifications = $this->createMock(INotificationManager::class);
		$this->notifications->method('createNotification')->willReturn($this->createMock(INotification::class));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($key === 'edepot_endpoint_url' ? 'https://edepot.test/in' : $default)
		);
		$settings = new TransferSettings($appConfig, $this->logger);
		$auditLog = new TransferAuditLog(new \OCA\OpenRegister\Service\Edepot\AuditRecording($this->createMock(AuditTrailMapperInterface::class), $this->logger), $settings, $this->notifications);
		$recorder = new TransferOutcomeRecorder(new \OCA\OpenRegister\Service\Edepot\TransferStores(objects: $mapper, records: $this->createMock(TransferRecordService::class), settings: $this->createMock(\OCP\IAppConfig::class), logger: $this->logger), $auditLog);

		$this->pipeline   = new TransferPipeline(
			new TransferObjectGatherer(new \OCA\OpenRegister\Service\Edepot\TransferStores(objects: $mapper, records: $this->createMock(TransferRecordService::class), settings: $this->createMock(\OCP\IAppConfig::class), logger: $this->logger), new PackagedFileChecksum()),
			new TransferResultProcessor($recorder, $auditLog),
			$auditLog,
			$settings
		);
		$this->sipBuilder = $this->createMock(SipPackageBuilder::class);
		$this->transport  = $this->createMock(TransportInterface::class);
		$this->transport->method('getName')->willReturn('sftp');
	}//end setUp()

	private function service(): EdepotTransferService {
		return new EdepotTransferService($this->sipBuilder, $this->pipeline, $this->logger);
	}//end service()

	private function addObject(string $uuid, string $state): void {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRetention(['archiefstatus' => $state]);
		$object->setObject([]);
		$this->objects[$uuid] = $object;
	}//end addObject()

	private static function list(array $uuids, array $extra=[]): array {
		return array_merge(
			['uuid' => 'tl-1', 'objectReferences' => array_map(static fn (string $u): array => ['uuid' => $u], $uuids)],
			$extra
		);
	}//end list()

	public function testNothingOutstandingTakesTheTerminalStatusWithoutPackaging(): void {
		$this->addObject('a', 'transferred');
		$this->sipBuilder->expects($this->never())->method('package');
		$this->transport->expects($this->never())->method('send');

		$out = $this->service()->executeTransfer(self::list(['a']), $this->transport);

		$this->assertSame(TransferListService::STATUS_COMPLETED, $out['status']);
		$this->assertCount(1, $out['attempts']);
		$this->assertSame(1, $out['attempts'][0]['attempt']);
		$this->assertSame('sftp', $out['attempts'][0]['transport']);
		$this->assertSame(TransferListService::STATUS_COMPLETED, $out['attempts'][0]['outcome']);
		$this->assertNull($out['attempts'][0]['error']);
	}

	public function testNothingLoadableFailsTheTransfer(): void {
		$out = $this->service()->executeAttempt(self::list(['ghost']), $this->transport, 2);

		$this->assertSame(TransferListService::STATUS_FAILED, $out['status']);
		$this->assertSame(2, $out['attempts'][0]['attempt']);
	}

	public function testOnlyTheFirstAttemptAuditsTheInitiation(): void {
		$this->addObject('a', 'transferred');
		$initiated = [];
		$this->logger->method('info')->willReturnCallback(
			static function (string $message) use (&$initiated): void {
				if (str_contains($message, 'transfer_initiated') === true) {
					$initiated[] = $message;
				}
			}
		);

		$this->service()->executeAttempt(self::list(['a']), $this->transport, 1);
		$this->service()->executeAttempt(self::list(['a']), $this->transport, 3);

		$this->assertCount(1, $initiated);
	}

	public function testABuildFailureFailsTheAttemptWithoutSending(): void {
		$this->addObject('a', 'active');
		$this->sipBuilder->method('package')->willThrowException(new \RuntimeException('disk full'));
		$this->transport->expects($this->never())->method('send');
		$errors = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message) use (&$errors): void {
				$errors[] = $message;
			}
		);

		$out = $this->service()->executeTransfer(self::list(['a']), $this->transport);

		$this->assertSame(TransferListService::STATUS_FAILED, $out['status']);
		$this->assertSame('SIP build failed: disk full', $out['transferResult']['error']);
		$this->assertSame('SIP build failed: disk full', $out['attempts'][0]['error']);
		$this->assertContains('[EdepotTransferService] SIP package build failed: disk full', $errors);
		$this->assertContains('[EdepotTransferService] Audit: archival.transfer_failed', $errors);
	}

	public function testDeliverySendsOnlyOutstandingObjectsInTheListFormatAndRemovesThePackages(): void {
		$this->addObject('done', 'transferred');
		$this->addObject('a', 'active');
		$sip = (string)tempnam(sys_get_temp_dir(), 't12sip');
		$this->sipBuilder->expects($this->once())->method('package')->with(
			$this->callback(
				static fn (\OCA\OpenRegister\Service\Edepot\TransferPackageRequest $request): bool => $request->transferId === 'tl-1'
					&& count($request->objectsWithFiles) === 1 && $request->objectsWithFiles[0]['object']->getUuid() === 'a'
					&& $request->maxPackageSize === 0 && $request->format === 'bagit'
			)
		)->willReturn([$sip, '/nonexistent/t12/second.zip']);
		$this->transport->expects($this->exactly(2))->method('send')
			->with($this->isType('string'), $this->callback(static fn (array $config): bool => $config['endpointUrl'] === 'https://edepot.test/in'))
			->willReturn(new TransportResult('PKG'));
		$this->notifications->expects($this->once())->method('notify');

		$out = $this->service()->executeTransfer(self::list(['done', 'a'], ['packageFormat' => 'bagit']), $this->transport);

		$this->assertFileDoesNotExist($sip);
		$this->assertSame(TransferListService::STATUS_COMPLETED, $out['status']);
		$this->assertSame(['a'], array_keys($out['transferResult']['objectResults']));
		$this->assertNull($out['attempts'][0]['error']);
		$this->assertSame('transferred', $this->objects['a']->getRetention()['archiefstatus']);
	}

	public function testAFailedDeliveryRecordsTheFirstErrorOnTheAttempt(): void {
		$this->addObject('a', 'active');
		$this->sipBuilder->method('package')->willReturn(['/nonexistent/t12/p.zip']);
		$this->transport->method('send')->willReturn((new TransportResult())->failed('endpoint 503'));

		$out = $this->service()->executeTransfer(self::list(['a']), $this->transport);

		$this->assertSame(TransferListService::STATUS_FAILED, $out['status']);
		$this->assertSame('endpoint 503', $out['attempts'][0]['error']);
		$this->assertSame(TransferListService::STATUS_FAILED, $out['attempts'][0]['outcome']);
	}

	public function testProfilesAreTheDeclaredCatalogue(): void {
		$service = $this->service();

		$this->assertSame(EdepotTransferService::AVAILABLE_PROFILES, $service->getAvailableProfiles());
		$this->assertTrue($service->isValidProfile('tresoar-v1'));
		$this->assertFalse($service->isValidProfile('unknown-profile'));
	}

	public function testTransportConfigComesFromTheSettings(): void {
		$this->assertSame('https://edepot.test/in', $this->service()->getTransportConfig()['endpointUrl']);
	}
}//end class
