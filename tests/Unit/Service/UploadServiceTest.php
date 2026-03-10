<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

class UploadServiceTest extends TestCase
{
    private UploadService $service;

    protected function setUp(): void
    {
        $this->service = new UploadService();
    }

    // ── getUploadedJson: missing keys ──

    public function testGetUploadedJsonReturnsErrorWhenNoValidKeys(): void
    {
        $result = $this->service->getUploadedJson(['random_key' => 'value']);

        $this->assertInstanceOf(JSONResponse::class, $result);
    }

    public function testGetUploadedJsonReturnsErrorWhenEmptyData(): void
    {
        $result = $this->service->getUploadedJson([]);

        $this->assertInstanceOf(JSONResponse::class, $result);
    }

    // ── getUploadedJson: internal parameters removed ──

    public function testGetUploadedJsonRemovesInternalParameters(): void
    {
        $data = [
            '_route' => 'some.route',
            '_page' => 1,
            'json' => ['key' => 'value'],
        ];

        $result = $this->service->getUploadedJson($data);

        $this->assertIsArray($result);
        $this->assertSame('value', $result['key']);
    }

    // ── getUploadedJson: json input as array ──

    public function testGetUploadedJsonHandlesArrayInput(): void
    {
        $data = ['json' => ['name' => 'test', 'age' => 30]];

        $result = $this->service->getUploadedJson($data);

        $this->assertIsArray($result);
        $this->assertSame('test', $result['name']);
        $this->assertSame(30, $result['age']);
    }

    // ── getUploadedJson: json input as string ──

    public function testGetUploadedJsonHandlesJsonString(): void
    {
        $data = ['json' => '{"name":"test","age":30}'];

        $result = $this->service->getUploadedJson($data);

        $this->assertIsArray($result);
        $this->assertSame('test', $result['name']);
    }

    // ── getUploadedJson: invalid json string ──

    public function testGetUploadedJsonReturnsErrorForInvalidJson(): void
    {
        $data = ['json' => 'not-valid-json{{{'];

        $result = $this->service->getUploadedJson($data);

        $this->assertInstanceOf(JSONResponse::class, $result);
    }

    // ── getUploadedJson: file upload throws ──

    public function testGetUploadedJsonThrowsForFileUpload(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File upload handling is not yet implemented');

        $this->service->getUploadedJson(['file' => 'something']);
    }

    // ── getUploadedJson: null json ──

    public function testGetUploadedJsonReturnsErrorForNullJson(): void
    {
        $data = ['json' => null];

        $result = $this->service->getUploadedJson($data);

        $this->assertInstanceOf(JSONResponse::class, $result);
    }
}
