<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use PHPUnit\Framework\TestCase;

/**
 * Test class for ExportService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class ExportServiceTest extends TestCase
{
    private ExportService $exportService;
    private ObjectEntityMapper $objectEntityMapper;
    private RegisterMapper $registerMapper;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $userManager = $this->createMock(\OCP\IUserManager::class);
        $groupManager = $this->createMock(\OCP\IGroupManager::class);
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        // Create ExportService instance
        $this->exportService = new ExportService(
            $this->objectEntityMapper,
            $this->registerMapper,
            $userManager,
            $groupManager,
            $objectService
        );
    }

    /**
     * Test constructor
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(ExportService::class, $this->exportService);
    }

    /**
     * Test service instantiation
     */
    public function testServiceInstantiation(): void
    {
        $objectMapper = $this->createMock(ObjectEntityMapper::class);
        $registerMapper = $this->createMock(RegisterMapper::class);
        $userManager = $this->createMock(\OCP\IUserManager::class);
        $groupManager = $this->createMock(\OCP\IGroupManager::class);
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $service = new ExportService($objectMapper, $registerMapper, $userManager, $groupManager, $objectService);

        $this->assertInstanceOf(ExportService::class, $service);
    }

    /**
     * Test service with different mappers
     */
    public function testServiceWithDifferentMappers(): void
    {
        $objectMapper1 = $this->createMock(ObjectEntityMapper::class);
        $registerMapper1 = $this->createMock(RegisterMapper::class);
        $userManager1 = $this->createMock(\OCP\IUserManager::class);
        $groupManager1 = $this->createMock(\OCP\IGroupManager::class);
        $objectService1 = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $objectMapper2 = $this->createMock(ObjectEntityMapper::class);
        $registerMapper2 = $this->createMock(RegisterMapper::class);
        $userManager2 = $this->createMock(\OCP\IUserManager::class);
        $groupManager2 = $this->createMock(\OCP\IGroupManager::class);
        $objectService2 = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $service1 = new ExportService($objectMapper1, $registerMapper1, $userManager1, $groupManager1, $objectService1);
        $service2 = new ExportService($objectMapper2, $registerMapper2, $userManager2, $groupManager2, $objectService2);

        $this->assertInstanceOf(ExportService::class, $service1);
        $this->assertInstanceOf(ExportService::class, $service2);
    }
}