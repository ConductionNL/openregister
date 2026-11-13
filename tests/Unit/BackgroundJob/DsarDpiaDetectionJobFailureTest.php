<?php

/**
 * The DPIA detection sweep survives a failing enumeration, row or write, and says so.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ConfiguredJobRuntime;
use OCA\OpenRegister\BackgroundJob\DsarDpiaDetectionJob;
use OCA\OpenRegister\BackgroundJob\Support\DsarDpiaDetectionServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectionService;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Failures are counted and logged per case; the rest of the sweep carries on.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\DsarDpiaDetectionJob
 * @uses   \OCA\OpenRegister\BackgroundJob\ConfiguredJobRuntime
 * @uses   \OCA\OpenRegister\BackgroundJob\Support\DsarDpiaDetectionServices
 * @uses   \OCA\OpenRegister\Service\Gdpr\DpiaPatternDetectionService
 * @uses   \OCA\OpenRegister\Service\Gdpr\DpiaDetectionRequest
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent
 * @uses   \OCA\OpenRegister\Service\WriteCause
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses   \OCA\OpenRegister\Service\CauseFrame
 * @uses   \OCA\OpenRegister\Service\Gdpr\DpiaDetectionRun
 * @uses   \OCA\OpenRegister\Db\AuditTrail\NamedActor
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions
 */
class DsarDpiaDetectionJobFailureTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private ObjectService&MockObject $objectService;

	private DsarPolicyPackResolver&MockObject $packResolver;

	private AuditTrailMapper&MockObject $auditTrailMapper;

	private LoggerInterface&MockObject $logger;

	private DsarDpiaDetectionJob $job;

	protected function setUp(): void {
		$this->appConfig        = $this->createMock(IAppConfig::class);
		$this->objectService    = $this->createMock(ObjectService::class);
		$this->packResolver     = $this->createMock(DsarPolicyPackResolver::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->logger           = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $default
		);

		$this->job = new DsarDpiaDetectionJob(
			runtime: new ConfiguredJobRuntime(time: $this->createMock(ITimeFactory::class), appConfig: $this->appConfig, logger: $this->logger),
			services: new DsarDpiaDetectionServices(
				objectService: $this->objectService,
				packResolver: $this->packResolver,
				detectionService: new DpiaPatternDetectionService(),
				auditTrailMapper: $this->auditTrailMapper
			)
		);
	}//end setUp()

	public function testAFailingCaseEnumerationIsLoggedAndFlagsNothing(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('index down'));
		$this->packResolver->expects(self::never())->method('activePackForCase');
		$this->objectService->expects(self::never())->method('saveObject');

		$warnings = $this->captureWarnings();
		// Nothing was flagged and nothing failed per case, so no summary line.
		$this->logger->expects(self::never())->method('info');

		$this->runJob();

		self::assertSame(['[DsarDpiaDetectionJob] case enumeration failed: index down'], $warnings->messages);
	}//end testAFailingCaseEnumerationIsLoggedAndFlagsNothing()

	public function testRowsThatAreNotArraysOrHaveNoUuidAreLeftOut(): void {
		$this->wirePack(threshold: 2);
		$this->objectService->method('findAll')->willReturn(
			[
				'not-a-row',
				['@self' => [], 'jurisdiction' => 'default', 'type' => 'access', 'receivedAt' => $this->recent()],
				$this->renderedCase(uuid: 'a'),
			]
		);
		// Only one case survives, which is below the threshold of two: no flagging.
		$this->objectService->expects(self::never())->method('find');
		$this->objectService->expects(self::never())->method('saveObject');

		$this->runJob();
	}//end testRowsThatAreNotArraysOrHaveNoUuidAreLeftOut()

	public function testAUuidFallsBackToSelfIdThenToTheRowId(): void {
		$this->wirePack(threshold: 2);
		$this->objectService->method('findAll')->willReturn(
			[
				['@self' => ['id' => 'from-self-id'], 'jurisdiction' => 'default', 'type' => 'access', 'receivedAt' => $this->recent(), 'dpiaRequired' => false],
				['id' => 'from-row-id', 'jurisdiction' => 'default', 'type' => 'access', 'receivedAt' => $this->recent(), 'dpiaRequired' => false],
			]
		);

		$looked = [];
		$this->objectService->method('find')->willReturnCallback(
			static function ($id) use (&$looked) {
				$looked[] = $id;
				$entity   = new ObjectEntity();
				$entity->setUuid((string) $id);
				// Already flagged by hand in the meantime: nothing to write.
				$entity->setObject(['dpiaRequired' => true]);
				return $entity;
			}
		);

		$this->runJob();

		self::assertEqualsCanonicalizing(['from-self-id', 'from-row-id'], $looked);
	}//end testAUuidFallsBackToSelfIdThenToTheRowId()

	public function testACaseFlaggedByHandSinceTheScanIsNotRewritten(): void {
		$this->wirePack(threshold: 2);
		$this->objectService->method('findAll')->willReturn([$this->renderedCase(uuid: 'a'), $this->renderedCase(uuid: 'b')]);
		$this->objectService->method('find')->willReturnCallback(
			static function ($id) {
				$entity = new ObjectEntity();
				$entity->setUuid((string) $id);
				$entity->setObject(['type' => 'access', 'dpiaRequired' => true]);
				return $entity;
			}
		);
		$this->objectService->expects(self::never())->method('saveObject');
		$this->auditTrailMapper->expects(self::never())->method('recordObjectEvent');
		// Nothing flagged, nothing failed: the run stays quiet.
		$this->logger->expects(self::never())->method('info');

		$this->runJob();
	}//end testACaseFlaggedByHandSinceTheScanIsNotRewritten()

	public function testAFailedWriteIsCountedAndTheOtherCasesAreStillFlagged(): void {
		$this->wirePack(threshold: 2);
		$this->objectService->method('findAll')->willReturn([$this->renderedCase(uuid: 'a'), $this->renderedCase(uuid: 'b')]);
		$this->objectService->method('find')->willReturnCallback(
			static function ($id) {
				$entity = new ObjectEntity();
				$entity->setUuid((string) $id);
				$entity->setObject(['type' => 'access', 'dpiaRequired' => false]);
				return $entity;
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			static function (array|ObjectEntity $object, $options) {
				if ($options->uuid === 'a') {
					throw new RuntimeException('locked');
				}

				$saved = new ObjectEntity();
				$saved->setUuid((string) $options->uuid);
				return $saved;
			}
		);
		$this->auditTrailMapper->expects(self::once())->method('recordObjectEvent');

		$warnings = $this->captureWarnings();
		$summary  = [];
		$this->logger->method('info')->willReturnCallback(
			static function (string $message, array $context = []) use (&$summary): void {
				$summary = ['message' => $message, 'context' => $context];
			}
		);

		$this->runJob();

		self::assertSame(['[DsarDpiaDetectionJob] flagging failed for case a: locked'], $warnings->messages);
		self::assertSame('[DsarDpiaDetectionJob] detection complete', $summary['message']);
		self::assertSame(1, $summary['context']['flagged']);
		self::assertSame(1, $summary['context']['errors']);
		self::assertSame(2, $summary['context']['cases']);
		self::assertSame(1, $summary['context']['partitions']);
		self::assertSame(1, $summary['context']['groupsTriggered']);
	}//end testAFailedWriteIsCountedAndTheOtherCasesAreStillFlagged()

	public function testAFailureOutsideAnyCaseIsLoggedAsAnError(): void {
		$this->objectService->method('findAll')->willReturn([$this->renderedCase(uuid: 'a')]);
		$this->packResolver->method('activePackForCase')->willThrowException(new RuntimeException('pack store gone'));

		$errors = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message) use (&$errors): void {
				$errors[] = $message;
			}
		);

		$this->runJob();

		self::assertSame(['[DsarDpiaDetectionJob] detection failed: pack store gone'], $errors);
	}//end testAFailureOutsideAnyCaseIsLoggedAsAnError()

	/**
	 * Collect the logger's warning messages.
	 *
	 * @return object{messages: array<int, string>}
	 */
	private function captureWarnings(): object {
		$sink = new class {

			/**
			 * The messages.
			 *
			 * @var array<int, string>
			 */
			public array $messages = [];
		};
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use ($sink): void {
				$sink->messages[] = $message;
			}
		);

		return $sink;
	}//end captureWarnings()

	/**
	 * Run the protected run().
	 *
	 * @return void
	 */
	private function runJob(): void {
		(new ReflectionMethod($this->job, 'run'))->invoke($this->job, null);
	}//end runJob()

	/**
	 * A pack that flags groups of `type` reaching the threshold within 30 days.
	 *
	 * @param int $threshold The group size that triggers.
	 *
	 * @return void
	 */
	private function wirePack(int $threshold): void {
		$this->packResolver->method('activePackForCase')->willReturn(
			['jurisdiction' => 'default', 'dpiaDetection' => ['threshold' => $threshold, 'windowDays' => 30, 'groupBy' => ['type']]]
		);
	}//end wirePack()

	/**
	 * A receivedAt inside the window.
	 *
	 * @return string
	 */
	private function recent(): string {
		return (new \DateTimeImmutable('-2 days'))->format(DATE_ATOM);
	}//end recent()

	/**
	 * An unflagged access case as the object list renders it.
	 *
	 * @param string $uuid The case uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function renderedCase(string $uuid): array {
		return [
			'id'           => $uuid,
			'@self'        => ['uuid' => $uuid],
			'jurisdiction' => 'default',
			'type'         => 'access',
			'receivedAt'   => $this->recent(),
			'dpiaRequired' => false,
		];
	}//end renderedCase()
}//end class
