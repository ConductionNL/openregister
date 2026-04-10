<?php

declare(strict_types=1);

/**
 * DestructionExecutionJob Unit Tests
 *
 * Tests the queued background job for destruction execution.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\DestructionExecutionJob;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test class for DestructionExecutionJob
 */
class DestructionExecutionJobTest extends TestCase
{
    private ITimeFactory&MockObject $timeFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timeFactory = $this->createMock(ITimeFactory::class);
    }

    /**
     * Test that the job extends QueuedJob.
     */
    public function testIsQueuedJob(): void
    {
        $reflection = new ReflectionClass(DestructionExecutionJob::class);

        $this->assertTrue($reflection->isSubclassOf(\OCP\BackgroundJob\QueuedJob::class));
        $this->assertTrue($reflection->hasMethod('run'));
    }

    /**
     * Test that DEFAULT_BATCH_SIZE is reasonable.
     */
    public function testDefaultBatchSize(): void
    {
        $reflection = new ReflectionClass(DestructionExecutionJob::class);
        $constants  = $reflection->getConstants();

        $this->assertArrayHasKey('DEFAULT_BATCH_SIZE', $constants);
        $this->assertGreaterThan(0, $constants['DEFAULT_BATCH_SIZE']);
        $this->assertLessThanOrEqual(100, $constants['DEFAULT_BATCH_SIZE']);
    }
}
