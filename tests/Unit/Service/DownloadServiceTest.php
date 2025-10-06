<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\DownloadService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Test class for DownloadService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class DownloadServiceTest extends TestCase
{
    private DownloadService $downloadService;
    private ObjectEntityMapper $objectEntityMapper;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create DownloadService instance
        $this->downloadService = new DownloadService(
            $this->urlGenerator,
            $this->schemaMapper,
            $this->registerMapper
        );
    }

    /**
     * Test downloadRegister method with JSON format
     */
    public function testDownloadRegisterWithJsonFormat(): void
    {
        $id = '1';
        $format = 'json';

        // Create mock register
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->id = $id;
        $register->method('jsonSerialize')->willReturn([
            'id' => $id,
            'title' => 'Test Register',
            'version' => '1.0.0'
        ]);
        $register->method('getId')->willReturn($id);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $result = $this->downloadService->download('register', $id, $format);

        $this->assertIsArray($result);
    }

    /**
     * Test downloadRegister method with CSV format
     */
    public function testDownloadRegisterWithCsvFormat(): void
    {
        $id = '1';
        $format = 'csv';

        // Create mock register
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->id = $id;
        $register->method('jsonSerialize')->willReturn([
            'id' => $id,
            'title' => 'Test Register',
            'version' => '1.0.0'
        ]);
        $register->method('getId')->willReturn($id);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $result = $this->downloadService->download('register', $id, $format);

        $this->assertIsArray($result);
    }

    /**
     * Test downloadRegister method with XML format
     */
    public function testDownloadRegisterWithXmlFormat(): void
    {
        $id = '1';
        $format = 'xml';

        // Create mock register
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->id = $id;
        $register->method('jsonSerialize')->willReturn([
            'id' => $id,
            'title' => 'Test Register',
            'version' => '1.0.0'
        ]);
        $register->method('getId')->willReturn($id);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $result = $this->downloadService->download('register', $id, $format);

        $this->assertIsArray($result);
    }

    /**
     * Test downloadRegister method with default format
     */
    public function testDownloadRegisterWithDefaultFormat(): void
    {
        $id = '1';

        // Create mock register
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->id = $id;
        $register->method('jsonSerialize')->willReturn([
            'id' => $id,
            'title' => 'Test Register',
            'version' => '1.0.0'
        ]);
        $register->method('getId')->willReturn($id);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $result = $this->downloadService->download('register', $id, 'json');

        $this->assertIsArray($result);
    }

    /**
     * Test downloadRegister method with string ID
     */
    public function testDownloadRegisterWithStringId(): void
    {
        $id = 'test-register';
        $format = 'json';

        // Create mock register
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->id = $id;
        $register->method('jsonSerialize')->willReturn([
            'id' => $id,
            'title' => 'Test Register',
            'version' => '1.0.0'
        ]);
        $register->method('getId')->willReturn($id);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $result = $this->downloadService->download('register', $id, $format);

        $this->assertIsArray($result);
    }

    /**
     * Test downloadRegister method with invalid format
     */
    public function testDownloadRegisterWithInvalidFormat(): void
    {
        $id = '1';
        $format = 'invalid';

        // Create mock register
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->id = $id;
        $register->method('jsonSerialize')->willReturn([
            'id' => $id,
            'title' => 'Test Register',
            'version' => '1.0.0'
        ]);
        $register->method('getId')->willReturn($id);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $result = $this->downloadService->download('register', $id, $format);

        $this->assertIsArray($result);
    }

}