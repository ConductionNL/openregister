<?php

declare(strict_types=1);

/**
 * DestructionCheckJob Unit Tests
 *
 * Tests the daily background job that checks for objects due for destruction.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\DestructionCheckJob;
use OCA\OpenRegister\Db\DestructionList;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ArchivalService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Test class for DestructionCheckJob
 */
class DestructionCheckJobTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * Create the job instance.
     */
    private function makeJob(): DestructionCheckJob
    {
        $timeFactory = $this->createMock(ITimeFactory::class);

        return new DestructionCheckJob($timeFactory, $this->logger);
    }

    /**
     * Invoke the protected run() method via reflection.
     */
    private function runJob(DestructionCheckJob $job, mixed $argument = []): void
    {
        $ref    = new ReflectionClass($job);
        $method = $ref->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($job, $argument);
    }

    /**
     * Test that the job interval is set to 86400 seconds (daily).
     */
    public function testIntervalIsDaily(): void
    {
        $job = $this->makeJob();

        $ref    = new ReflectionClass($job);
        $prop   = $ref->getProperty('interval');
        $prop->setAccessible(true);

        $this->assertSame(86400, $prop->getValue($job));
    }

    /**
     * Test run with no objects due for destruction.
     */
    public function testRunNoObjectsDue(): void
    {
        $job = $this->makeJob();

        $archivalService = $this->createMock(ArchivalService::class);
        $archivalService->method('findObjectsDueForDestruction')->willReturn([]);

        \OC::$server->registerService(ArchivalService::class, function () use ($archivalService) {
            return $archivalService;
        });

        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $this->runJob($job);
    }

    /**
     * Test run with objects found generates destruction list.
     */
    public function testRunWithObjectsGeneratesList(): void
    {
        $job = $this->makeJob();

        $object = new ObjectEntity();
        $object->setUuid('obj-1');

        $list = new DestructionList();
        $list->setUuid('dl-1');

        $archivalService = $this->createMock(ArchivalService::class);
        $archivalService->method('findObjectsDueForDestruction')->willReturn([$object]);
        $archivalService->expects($this->once())
            ->method('generateDestructionList')
            ->willReturn($list);

        \OC::$server->registerService(ArchivalService::class, function () use ($archivalService) {
            return $archivalService;
        });

        $this->runJob($job);
    }

    /**
     * Test run handles exceptions gracefully.
     */
    public function testRunHandlesException(): void
    {
        $job = $this->makeJob();

        $archivalService = $this->createMock(ArchivalService::class);
        $archivalService->method('findObjectsDueForDestruction')
            ->willThrowException(new \RuntimeException('DB error'));

        \OC::$server->registerService(ArchivalService::class, function () use ($archivalService) {
            return $archivalService;
        });

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        // Should not throw.
        $this->runJob($job);
    }
}
