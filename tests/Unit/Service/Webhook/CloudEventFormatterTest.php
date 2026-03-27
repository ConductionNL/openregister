<?php

declare(strict_types=1);

/**
 * CloudEventFormatter Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Webhook
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Webhook;

use OCA\OpenRegister\Service\Webhook\CloudEventFormatter;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for CloudEventFormatter
 */
class CloudEventFormatterTest extends TestCase
{
    /** @var CloudEventFormatter */
    private CloudEventFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new CloudEventFormatter();
    }

    /**
     * Test formatAsCloudEvent with default source
     */
    public function testFormatAsCloudEventDefaultSource(): void
    {
        $result = $this->formatter->formatAsCloudEvent(
            'object.created',
            ['key' => 'value']
        );

        $this->assertSame('1.0', $result['specversion']);
        $this->assertSame('object.created', $result['type']);
        $this->assertSame('/apps/openregister', $result['source']);
        $this->assertSame('application/json', $result['datacontenttype']);
        $this->assertNull($result['subject']);
        $this->assertNull($result['dataschema']);
        $this->assertSame(['key' => 'value'], $result['data']);
        $this->assertSame('openregister', $result['openregister']['app']);
        $this->assertSame('1.0.0', $result['openregister']['version']);
        // UUID format check.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result['id']
        );
        // Time should be ISO 8601 format.
        $this->assertNotEmpty($result['time']);
    }

    /**
     * Test formatAsCloudEvent with custom source
     */
    public function testFormatAsCloudEventCustomSource(): void
    {
        $result = $this->formatter->formatAsCloudEvent(
            'object.updated',
            ['id' => '123'],
            'https://example.com/api',
            'object:reg1/schema1/123'
        );

        $this->assertSame('https://example.com/api', $result['source']);
        $this->assertSame('object:reg1/schema1/123', $result['subject']);
        $this->assertSame('object.updated', $result['type']);
    }

    /**
     * Test formatAsCloudEvent with empty payload
     */
    public function testFormatAsCloudEventEmptyPayload(): void
    {
        $result = $this->formatter->formatAsCloudEvent('object.deleted', []);

        $this->assertSame([], $result['data']);
        $this->assertSame('1.0', $result['specversion']);
    }

    /**
     * Test formatRequestAsCloudEvent
     */
    public function testFormatRequestAsCloudEvent(): void
    {
        /** @var IRequest&MockObject $request */
        $request = $this->createMock(IRequest::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getPathInfo')->willReturn('/api/objects/1/2/3');
        $request->method('getParams')->willReturn(['foo' => 'bar']);
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('example.com');
        $request->method('getHeader')->willReturnCallback(function ($key) {
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => '',
                'User-Agent' => 'TestAgent',
                'X-Requested-With' => '',
            ];
            return $headers[$key] ?? '';
        });

        $result = $this->formatter->formatRequestAsCloudEvent(
            $request,
            'object.creating',
            ['extra' => 'data']
        );

        $this->assertSame('1.0', $result['specversion']);
        $this->assertSame('object.creating', $result['type']);
        $this->assertSame('https://example.com/apps/openregister', $result['source']);
        $this->assertSame('application/json', $result['datacontenttype']);
        $this->assertSame('object:1/2/3', $result['subject']);
        $this->assertSame('POST', $result['data']['method']);
        $this->assertSame('/api/objects/1/2/3', $result['data']['path']);
        $this->assertSame('data', $result['data']['extra']);
    }

    /**
     * Test formatRequestAsCloudEvent with HTTP protocol
     */
    public function testFormatRequestAsCloudEventHttpProtocol(): void
    {
        /** @var IRequest&MockObject $request */
        $request = $this->createMock(IRequest::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getPathInfo')->willReturn('/api/registers');
        $request->method('getParams')->willReturn([]);
        $request->method('getServerProtocol')->willReturn('http');
        $request->method('getServerHost')->willReturn('localhost');
        $request->method('getHeader')->willReturn('');

        $result = $this->formatter->formatRequestAsCloudEvent($request, 'register.listing');

        $this->assertSame('http://localhost/apps/openregister', $result['source']);
        // No match for /api/objects/ pattern so subject should be null.
        $this->assertNull($result['subject']);
    }

    /**
     * Test subject extraction with register+schema path (no object id)
     */
    public function testSubjectExtractionRegisterSchemaOnly(): void
    {
        /** @var IRequest&MockObject $request */
        $request = $this->createMock(IRequest::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getPathInfo')->willReturn('/api/objects/reg1/schema1');
        $request->method('getParams')->willReturn([]);
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('example.com');
        $request->method('getHeader')->willReturn('');

        $result = $this->formatter->formatRequestAsCloudEvent($request, 'object.listing');

        $this->assertSame('object:reg1/schema1', $result['subject']);
    }

    /**
     * Test content type defaults to application/json when empty
     */
    public function testContentTypeDefaultsToJson(): void
    {
        /** @var IRequest&MockObject $request */
        $request = $this->createMock(IRequest::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getPathInfo')->willReturn('/api/test');
        $request->method('getParams')->willReturn([]);
        $request->method('getServerProtocol')->willReturn('http');
        $request->method('getServerHost')->willReturn('localhost');
        $request->method('getHeader')->willReturn('');

        $result = $this->formatter->formatRequestAsCloudEvent($request, 'test');

        $this->assertSame('application/json', $result['datacontenttype']);
    }

    /**
     * Test that each call generates a unique ID
     */
    public function testUniqueIdsGenerated(): void
    {
        $result1 = $this->formatter->formatAsCloudEvent('test', []);
        $result2 = $this->formatter->formatAsCloudEvent('test', []);

        $this->assertNotSame($result1['id'], $result2['id']);
    }
}
