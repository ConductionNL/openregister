<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\EndpointLog;
use PHPUnit\Framework\TestCase;

class EndpointLogTest extends TestCase
{
    private EndpointLog $log;

    protected function setUp(): void
    {
        $this->log = new EndpointLog();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->log->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('integer', $fieldTypes['statusCode']);
        $this->assertSame('string', $fieldTypes['statusMessage']);
        $this->assertSame('json', $fieldTypes['request']);
        $this->assertSame('json', $fieldTypes['response']);
        $this->assertSame('integer', $fieldTypes['endpointId']);
        $this->assertSame('string', $fieldTypes['userId']);
        $this->assertSame('string', $fieldTypes['sessionId']);
        $this->assertSame('datetime', $fieldTypes['expires']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('integer', $fieldTypes['size']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->log->getUuid());
        $this->assertNull($this->log->getStatusCode());
        $this->assertNull($this->log->getStatusMessage());
        $this->assertNull($this->log->getRequest());
        $this->assertNull($this->log->getResponse());
        $this->assertNull($this->log->getEndpointId());
        $this->assertNull($this->log->getUserId());
        $this->assertNull($this->log->getSessionId());
        $this->assertNull($this->log->getCreated());
        // Expires should default to +1 week
        $this->assertNotNull($this->log->getExpires());
        $this->assertInstanceOf(DateTime::class, $this->log->getExpires());
        // Size should be at least 4096
        $this->assertGreaterThanOrEqual(4096, $this->log->getSize());
    }

    public function testSetAndGetStringFields(): void
    {
        $this->log->setUuid('log-uuid');
        $this->log->setStatusMessage('OK');
        $this->log->setUserId('admin');
        $this->log->setSessionId('session-123');

        $this->assertSame('log-uuid', $this->log->getUuid());
        $this->assertSame('OK', $this->log->getStatusMessage());
        $this->assertSame('admin', $this->log->getUserId());
        $this->assertSame('session-123', $this->log->getSessionId());
    }

    public function testSetAndGetIntegerFields(): void
    {
        $this->log->setStatusCode(200);
        $this->log->setEndpointId(42);

        $this->assertSame(200, $this->log->getStatusCode());
        $this->assertSame(42, $this->log->getEndpointId());
    }

    public function testSetAndGetJsonFields(): void
    {
        $request = ['method' => 'GET', 'headers' => ['Accept' => 'application/json']];
        $response = ['body' => '{"status":"ok"}', 'statusCode' => 200];

        $this->log->setRequest($request);
        $this->log->setResponse($response);

        $this->assertSame($request, $this->log->getRequest());
        $this->assertSame($response, $this->log->getResponse());
    }

    public function testSetAndGetDateTimeFields(): void
    {
        $expires = new DateTime('2025-01-01T00:00:00Z');
        $created = new DateTime('2024-01-01T00:00:00Z');

        $this->log->setExpires($expires);
        $this->log->setCreated($created);

        $this->assertSame($expires, $this->log->getExpires());
        $this->assertSame($created, $this->log->getCreated());
    }

    public function testSetAndGetSize(): void
    {
        $this->log->setSize(8192);
        $this->assertSame(8192, $this->log->getSize());
    }

    public function testCalculateSize(): void
    {
        $this->log->setRequest(['data' => str_repeat('x', 5000)]);
        $this->log->calculateSize();

        $this->assertGreaterThanOrEqual(4096, $this->log->getSize());
    }

    public function testCalculateSizeMinimum(): void
    {
        // With minimal data, size should be at least 4096
        $this->log->calculateSize();
        $this->assertGreaterThanOrEqual(4096, $this->log->getSize());
    }

    public function testGetJsonFields(): void
    {
        $jsonFields = $this->log->getJsonFields();
        $this->assertContains('request', $jsonFields);
        $this->assertContains('response', $jsonFields);
    }

    public function testHydrate(): void
    {
        $data = [
            'uuid'          => 'hydrate-uuid',
            'statusCode'    => 201,
            'statusMessage' => 'Created',
            'userId'        => 'user1',
        ];

        $result = $this->log->hydrate($data);

        $this->assertSame($this->log, $result);
        $this->assertSame('hydrate-uuid', $this->log->getUuid());
        $this->assertSame(201, $this->log->getStatusCode());
        $this->assertSame('Created', $this->log->getStatusMessage());
        $this->assertSame('user1', $this->log->getUserId());
    }

    public function testJsonSerialize(): void
    {
        $this->log->setUuid('json-uuid');
        $this->log->setStatusCode(200);
        $this->log->setStatusMessage('OK');

        $json = $this->log->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'statusCode', 'statusMessage', 'request',
            'response', 'endpointId', 'userId', 'sessionId',
            'expires', 'created', 'size',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        $this->assertSame('json-uuid', $json['uuid']);
        $this->assertSame(200, $json['statusCode']);
        $this->assertSame('OK', $json['statusMessage']);
    }

    public function testJsonSerializeDateTimeFormatting(): void
    {
        $created = new DateTime('2024-01-01T12:00:00+00:00');
        $this->log->setCreated($created);

        $json = $this->log->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
    }
}
