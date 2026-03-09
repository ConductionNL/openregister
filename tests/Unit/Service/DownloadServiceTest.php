<?php

namespace Unit\Service;

use OCA\OpenRegister\Service\DownloadService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DownloadService.
 *
 * DownloadService is currently an empty class (placeholder).
 * This test verifies it can be instantiated.
 */
class DownloadServiceTest extends TestCase
{

    public function testCanBeInstantiated(): void
    {
        $service = new DownloadService();

        $this->assertInstanceOf(DownloadService::class, $service);
    }
}
