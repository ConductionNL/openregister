<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

/**
 * Gap tests for UploadService covering uncovered methods and branches.
 */
class UploadServiceGapTest extends TestCase
{
    private UploadService $uploadService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadService = new UploadService();
    }

    /**
     * Test getUploadedJson with no valid source keys (covers validation error).
     */
    public function testGetUploadedJsonMissingSource(): void
    {
        $data = ['some_key' => 'some_value'];
        $result = $this->uploadService->getUploadedJson($data);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
        $responseData = $result->getData();
        $this->assertStringContainsString('Missing one of these keys', $responseData['error']);
    }

    /**
     * Test getUploadedJson with internal parameters being removed.
     */
    public function testGetUploadedJsonRemovesInternalParams(): void
    {
        $data = [
            '_route' => 'some.route',
            '_limit' => 10,
            'json' => ['key' => 'value'],
        ];
        $result = $this->uploadService->getUploadedJson($data);

        $this->assertIsArray($result);
        $this->assertEquals('value', $result['key']);
    }

    /**
     * Test getUploadedJson with JSON array input.
     */
    public function testGetUploadedJsonWithArrayInput(): void
    {
        $data = ['json' => ['name' => 'test', 'type' => 'document']];
        $result = $this->uploadService->getUploadedJson($data);

        $this->assertIsArray($result);
        $this->assertEquals('test', $result['name']);
    }

    /**
     * Test getUploadedJson with JSON string input.
     */
    public function testGetUploadedJsonWithStringInput(): void
    {
        $data = ['json' => '{"name": "test", "count": 5}'];
        $result = $this->uploadService->getUploadedJson($data);

        $this->assertIsArray($result);
        $this->assertEquals('test', $result['name']);
        $this->assertEquals(5, $result['count']);
    }

    /**
     * Test getUploadedJson with invalid JSON string.
     */
    public function testGetUploadedJsonWithInvalidJsonString(): void
    {
        $data = ['json' => 'not valid json {{{'];
        $result = $this->uploadService->getUploadedJson($data);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
        $responseData = $result->getData();
        $this->assertStringContainsString('Failed to decode', $responseData['error']);
    }

    /**
     * Test getUploadedJson with file key (covers file upload branch - throws exception).
     */
    public function testGetUploadedJsonWithFileThrowsException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File upload handling is not yet implemented');

        $data = ['file' => 'some-file-data'];
        $this->uploadService->getUploadedJson($data);
    }

    /**
     * Test getUploadedJson with null JSON value.
     */
    public function testGetUploadedJsonWithNullJsonValue(): void
    {
        $data = ['json' => null];

        // null input - processJsonUpload handles this: null === null check
        $result = $this->uploadService->getUploadedJson($data);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    /**
     * Test getUploadedJson with false JSON value (covers === false check).
     */
    public function testGetUploadedJsonWithFalseJsonValue(): void
    {
        $data = ['json' => false];

        $result = $this->uploadService->getUploadedJson($data);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    /**
     * Test that only internal parameters (starting with _) are removed.
     */
    public function testGetUploadedJsonOnlyInternalParamsRemoved(): void
    {
        $data = [
            '_internal' => 'removed',
            '_another' => 'also_removed',
            'json' => ['key' => 'value'],
        ];

        $result = $this->uploadService->getUploadedJson($data);

        $this->assertIsArray($result);
        $this->assertEquals('value', $result['key']);
    }

    /**
     * Test getUploadedJson with empty data (no keys at all).
     */
    public function testGetUploadedJsonWithEmptyData(): void
    {
        $result = $this->uploadService->getUploadedJson([]);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    /**
     * Test getUploadedJson where all keys are internal params.
     */
    public function testGetUploadedJsonAllInternalParams(): void
    {
        $data = [
            '_route' => 'test',
            '_page' => 1,
        ];
        $result = $this->uploadService->getUploadedJson($data);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }
}
