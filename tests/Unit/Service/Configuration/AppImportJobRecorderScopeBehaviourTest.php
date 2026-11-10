<?php

/**
 * Behaviour tests for AppImportJobRecorder: nested job scopes, silent skips and tolerant reads.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Configuration\FinishedImportJob;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\AppImportJobRecorder
 * @uses   \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 */
class AppImportJobRecorderScopeBehaviourTest extends TestCase
{
    /** @var AuditTrailMapperInterface&MockObject */
    private AuditTrailMapperInterface $audit;

    /** @var IAppConfig&MockObject */
    private IAppConfig $config;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private ?string $stamp = null;

    /** @var list<?string> */
    private array $stamps = [];

    protected function setUp(): void
    {
        $this->audit  = $this->createMock(AuditTrailMapperInterface::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->audit->method('getRequestImportJobId')->willReturnCallback(fn(): ?string => $this->stamp);
        $this->audit->method('setRequestImportJobId')->willReturnCallback(
            function (?string $id): void {
                $this->stamp    = $id;
                $this->stamps[] = $id;
            }
        );
    }//end setUp()

    private function recorder(): AppImportJobRecorder
    {
        return new AppImportJobRecorder($this->audit, $this->config, $this->logger);
    }//end recorder()

    public function testNestedJobsRestoreTheirOuterStampInOrder(): void
    {
        $this->stamp = 'request-job';
        $recorder    = $this->recorder();

        $outer = $recorder->begin();
        $inner = $recorder->begin();
        $recorder->end();
        $this->assertSame($outer, $this->stamp);
        $recorder->end();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $outer);
        $this->assertNotSame($outer, $inner);
        $this->assertSame([$outer, $inner, $outer, 'request-job'], $this->stamps);
    }//end testNestedJobsRestoreTheirOuterStampInOrder()

    public function testAnUnpairedEndClearsTheStamp(): void
    {
        $this->stamp = 'left-over';

        $this->recorder()->end();

        $this->assertSame([null], $this->stamps);
    }//end testAnUnpairedEndClearsTheStamp()

    public function testAnImportThatWroteNothingIsSkippedWithoutAskingForTraces(): void
    {
        $this->audit->expects($this->once())->method('countByImportJobId')->with('job-0', 'create')->willReturn(0);
        $this->logger->expects($this->never())->method('warning');
        $this->config->expects($this->never())->method('setValueString');

        $this->assertFalse($this->recorder()->recordFinished(new FinishedImportJob('demo', 'job-0', '1.0.0', 0)));
    }//end testAnImportThatWroteNothingIsSkippedWithoutAskingForTraces()

    public function testMalformedStoredJobsAreDroppedWhenRead(): void
    {
        $this->config->method('getValueString')->willReturn(
            (string) json_encode(
                ['appId' => 'demo', 'jobs' => ['nonsense', ['jobId' => 7], ['jobId' => 'j-1', 'created' => '3']]]
            )
        );

        $this->assertSame(
            [['jobId' => 'j-1', 'version' => '', 'created' => 3, 'importedAt' => '']],
            $this->recorder()->jobs('demo')
        );
    }//end testMalformedStoredJobsAreDroppedWhenRead()

    public function testAppForJobLooksOnlyAtImportKeys(): void
    {
        $this->config->method('getKeys')->with('openregister')->willReturn(['enabled', 'import_jobs_demo']);
        $this->config->expects($this->once())
            ->method('getValueString')
            ->with('openregister', 'import_jobs_demo')
            ->willReturn((string) json_encode(['appId' => 'demo', 'jobs' => [['jobId' => 'j-1']]]));

        $this->assertSame('demo', $this->recorder()->appForJob('j-1'));
    }//end testAppForJobLooksOnlyAtImportKeys()
}//end class
