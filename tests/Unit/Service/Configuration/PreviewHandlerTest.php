<?php

declare(strict_types=1);

/**
 * PreviewHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\FetchHandler;
use OCA\OpenRegister\Service\Configuration\PreviewHandler;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for PreviewHandler
 *
 * Tests configuration preview and comparison logic.
 */
class PreviewHandlerTest extends TestCase
{
    /** @var PreviewHandler */
    private PreviewHandler $handler;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        /** @var FetchHandler&MockObject $fetchHandler */
        $fetchHandler = $this->createMock(FetchHandler::class);

        $this->handler = new PreviewHandler(
            $this->registerMapper,
            $this->schemaMapper,
            $logger,
            $fetchHandler
        );
    }

    /**
     * Test previewRegisterChange for a new register (create action)
     */
    public function testPreviewRegisterChangeCreate(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->handler->previewRegisterChange('new-register', [
            'title'   => 'New Register',
            'version' => '1.0.0',
        ]);

        $this->assertSame('register', $result['type']);
        $this->assertSame('create', $result['action']);
        $this->assertSame('new-register', $result['slug']);
        $this->assertSame('New Register', $result['title']);
        $this->assertNull($result['current']);
    }

    /**
     * Test previewRegisterChange slug is lowercased
     */
    public function testPreviewRegisterChangeSlugLowercased(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->handler->previewRegisterChange('My-Register', ['title' => 'Test']);

        $this->assertSame('my-register', $result['slug']);
    }

    /**
     * Test compareArrays returns empty (placeholder implementation)
     */
    public function testCompareArraysReturnsEmpty(): void
    {
        $result = $this->handler->compareArrays(
            ['key' => 'old'],
            ['key' => 'new']
        );

        // Placeholder returns empty array.
        $this->assertSame([], $result);
    }

    /**
     * Test importConfigurationWithSelection returns empty (placeholder)
     */
    public function testImportConfigurationWithSelectionReturnsEmpty(): void
    {
        /** @var \OCA\OpenRegister\Db\Configuration&MockObject $config */
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);

        $result = $this->handler->importConfigurationWithSelection($config, []);

        $this->assertSame([], $result);
    }
}
