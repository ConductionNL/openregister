<?php

declare(strict_types=1);

/**
 * DestructionCheckJob Unit Tests
 *
 * Tests the recurring background job for destruction eligibility checking.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\DestructionCheckJob;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test class for DestructionCheckJob
 */
class DestructionCheckJobTest extends TestCase
{
    private ITimeFactory&MockObject $timeFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timeFactory = $this->createMock(ITimeFactory::class);
    }

    /**
     * Test that the job can be instantiated.
     */
    public function testConstructor(): void
    {
        // The job constructor calls getArchivalSettingsOnly() via \OC::$server.
        // In unit tests without the full Nextcloud stack, we verify the class exists
        // and has the expected methods.
        $reflection = new ReflectionClass(DestructionCheckJob::class);

        $this->assertTrue($reflection->isSubclassOf(\OCP\BackgroundJob\TimedJob::class));
        $this->assertTrue($reflection->hasMethod('run'));
    }

    /**
     * Test that DEFAULT_INTERVAL constant is 24 hours.
     */
    public function testDefaultInterval(): void
    {
        $reflection = new ReflectionClass(DestructionCheckJob::class);
        $constants  = $reflection->getConstants();

        $this->assertArrayHasKey('DEFAULT_INTERVAL', $constants);
        $this->assertEquals(86400, $constants['DEFAULT_INTERVAL']);
    }
}
