<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\BackgroundJob\DsarDpiaDetectionJob}.
 *
 * Covers the job's write semantics on top of the pure detection engine:
 * threshold crossing flags every UNFLAGGED case with an audited write whose
 * context names rule / group key / window / count; re-runs over an
 * already-flagged group are no-ops (idempotency); manual flags are never
 * cleared (one-way ratchet); no resolvable pack / no dpiaDetection block is
 * fail-safe; and the enabled toggle short-circuits the run.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\DsarDpiaDetectionJob;
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

/**
 * DsarDpiaDetectionJobTest.
 */
class DsarDpiaDetectionJobTest extends TestCase
{

    private IAppConfig&MockObject $appConfig;

    private ObjectService&MockObject $objectService;

    private DsarPolicyPackResolver&MockObject $packResolver;

    private AuditTrailMapper&MockObject $auditTrailMapper;

    private DsarDpiaDetectionJob $job;


    protected function setUp(): void
    {
        $this->appConfig        = $this->createMock(IAppConfig::class);
        $this->objectService    = $this->createMock(ObjectService::class);
        $this->packResolver     = $this->createMock(DsarPolicyPackResolver::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);

        // Default: enabled + interval defaults.
        $this->appConfig->method('getValueString')->willReturnCallback(
            static fn(string $app, string $key, string $default='') => $default
        );

        $this->job = new DsarDpiaDetectionJob(
            time: $this->createMock(ITimeFactory::class),
            appConfig: $this->appConfig,
            objectService: $this->objectService,
            packResolver: $this->packResolver,
            detectionService: new DpiaPatternDetectionService(),
            auditTrailMapper: $this->auditTrailMapper,
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end setUp()


    /**
     * Threshold crossing: unflagged members get an audited dpiaRequired=true
     * write whose context names rule / group key / window / count; flagged
     * members are never re-written.
     *
     * @return void
     */
    public function testThresholdCrossingFlagsUnflaggedCasesWithAudit(): void
    {
        $this->wirePack(
            dpiaDetection: [
                'threshold'  => 3,
                'windowDays' => 30,
                'groupBy'    => ['type'],
            ]
        );
        $this->wireCases(
            [
                $this->renderedCase(uuid: 'a', flagged: false),
                $this->renderedCase(uuid: 'b', flagged: false),
                $this->renderedCase(uuid: 'c', flagged: true),
            ]
        );

        $savedFlags = [];
        $this->objectService->method('find')->willReturnCallback(
            function ($id, $extend=[], $files=false) {
                $entity = new ObjectEntity();
                $entity->setUuid((string) $id);
                $entity->setObject(
                    [
                        'type'         => 'access',
                        'dpiaRequired' => false,
                    ]
                );
                return $entity;
            }
        );
        $this->objectService->method('saveObject')->willReturnCallback(
            static function ($object, $extend=[], $register=null, $schema=null, $uuid=null) use (&$savedFlags) {
                $savedFlags[(string) $uuid] = $object['dpiaRequired'];
                $saved = new ObjectEntity();
                $saved->setUuid((string) $uuid);
                return $saved;
            }
        );

        $auditContexts = [];
        $this->auditTrailMapper->method('createAuditTrailEntry')->willReturnCallback(
            function (ObjectEntity $object, string $action, array $context=[]) use (&$auditContexts) {
                $auditContexts[(string) $object->getUuid()] = [
                    'action'  => $action,
                    'context' => $context,
                ];
                return $this->createMock(\OCA\OpenRegister\Db\AuditTrail::class);
            }
        );

        $this->runJob();

        // Only the two unflagged cases were written + audited.
        $this->assertEqualsCanonicalizing(['a', 'b'], array_keys($savedFlags));
        $this->assertSame([true, true], array_values($savedFlags));
        $this->assertSame(DsarDpiaDetectionJob::AUDIT_ACTION, $auditContexts['a']['action']);
        $this->assertSame('dpia-pattern-detection', $auditContexts['a']['context']['rule']);
        $this->assertSame('type=access', $auditContexts['a']['context']['groupKey']);
        $this->assertSame(30, $auditContexts['a']['context']['windowDays']);
        $this->assertSame(3, $auditContexts['a']['context']['count']);

    }//end testThresholdCrossingFlagsUnflaggedCasesWithAudit()


    /**
     * Re-run over a fully-flagged group: no write, no audit (idempotent),
     * and the manual flag is never cleared (ratchet).
     *
     * @return void
     */
    public function testReRunOverFlaggedGroupIsNoOp(): void
    {
        $this->wirePack(
            dpiaDetection: [
                'threshold'  => 2,
                'windowDays' => 30,
                'groupBy'    => ['type'],
            ]
        );
        $this->wireCases(
            [
                $this->renderedCase(uuid: 'a', flagged: true),
                $this->renderedCase(uuid: 'b', flagged: true),
            ]
        );

        $this->objectService->expects($this->never())->method('saveObject');
        $this->auditTrailMapper->expects($this->never())->method('createAuditTrailEntry');

        $this->runJob();

    }//end testReRunOverFlaggedGroupIsNoOp()


    /**
     * Fail-safe: no resolvable pack, or a pack without a dpiaDetection
     * block, produces no writes, audits, or notifications.
     *
     * @return void
     */
    public function testNoPackOrNoBlockIsFailSafe(): void
    {
        $this->packResolver->method('activePackForCase')->willReturn(null);
        $this->wireCases(
            [
                $this->renderedCase(uuid: 'a', flagged: false),
                $this->renderedCase(uuid: 'b', flagged: false),
                $this->renderedCase(uuid: 'c', flagged: false),
            ]
        );

        $this->objectService->expects($this->never())->method('saveObject');
        $this->auditTrailMapper->expects($this->never())->method('createAuditTrailEntry');

        $this->runJob();

    }//end testNoPackOrNoBlockIsFailSafe()


    /**
     * The enabled toggle short-circuits the whole run.
     *
     * @return void
     */
    public function testDisabledToggleSkipsRun(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('false');

        $job = new DsarDpiaDetectionJob(
            time: $this->createMock(ITimeFactory::class),
            appConfig: $appConfig,
            objectService: $this->objectService,
            packResolver: $this->packResolver,
            detectionService: new DpiaPatternDetectionService(),
            auditTrailMapper: $this->auditTrailMapper,
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->objectService->expects($this->never())->method('findAll');

        $method = new \ReflectionMethod($job, 'run');
        $method->setAccessible(true);
        $method->invoke($job, null);

    }//end testDisabledToggleSkipsRun()


    /**
     * Invoke the protected run() (repo TimedJob test convention).
     *
     * @return void
     */
    private function runJob(): void
    {
        $method = new \ReflectionMethod($this->job, 'run');
        $method->setAccessible(true);
        $method->invoke($this->job, null);

    }//end runJob()


    /**
     * Wire the pack resolver with a pack carrying the given detection block.
     *
     * @param array<string, mixed> $dpiaDetection The dpiaDetection block.
     *
     * @return void
     */
    private function wirePack(array $dpiaDetection): void
    {
        $this->packResolver->method('activePackForCase')->willReturn(
            [
                'jurisdiction'  => 'default',
                'dpiaDetection' => $dpiaDetection,
            ]
        );

    }//end wirePack()


    /**
     * Wire the rendered case rows the job enumerates.
     *
     * @param array<int, array<string, mixed>> $cases The rendered rows.
     *
     * @return void
     */
    private function wireCases(array $cases): void
    {
        $this->objectService->method('findAll')->willReturn($cases);

    }//end wireCases()


    /**
     * A rendered case row (same type, recent receivedAt).
     *
     * @param string $uuid    The case uuid.
     * @param bool   $flagged Whether dpiaRequired is already true.
     *
     * @return array<string, mixed>
     */
    private function renderedCase(string $uuid, bool $flagged): array
    {
        return [
            'id'           => $uuid,
            '@self'        => ['uuid' => $uuid],
            'jurisdiction' => 'default',
            'type'         => 'access',
            'receivedAt'   => (new \DateTimeImmutable('-2 days'))->format(DATE_ATOM),
            'dpiaRequired' => $flagged,
        ];

    }//end renderedCase()
}//end class
