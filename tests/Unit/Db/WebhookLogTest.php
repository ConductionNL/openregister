<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\WebhookLog;
use PHPUnit\Framework\TestCase;

class WebhookLogTest extends TestCase
{
    private WebhookLog $webhookLog;

    protected function setUp(): void
    {
        $this->webhookLog = new WebhookLog();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->webhookLog->getFieldTypes();

        $this->assertSame('integer', $fieldTypes['webhook']);
        $this->assertSame('string', $fieldTypes['eventClass']);
        $this->assertSame('string', $fieldTypes['payload']);
        $this->assertSame('string', $fieldTypes['url']);
        $this->assertSame('string', $fieldTypes['method']);
        $this->assertSame('boolean', $fieldTypes['success']);
        $this->assertSame('integer', $fieldTypes['statusCode']);
        $this->assertSame('string', $fieldTypes['requestBody']);
        $this->assertSame('string', $fieldTypes['responseBody']);
        $this->assertSame('string', $fieldTypes['errorMessage']);
        $this->assertSame('integer', $fieldTypes['attempt']);
        $this->assertSame('datetime', $fieldTypes['nextRetryAt']);
        $this->assertSame('datetime', $fieldTypes['created']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertSame(0, $this->webhookLog->getWebhook());
        $this->assertSame('', $this->webhookLog->getEventClass());
        $this->assertNull($this->webhookLog->getPayload());
        $this->assertSame('', $this->webhookLog->getUrl());
        $this->assertSame('POST', $this->webhookLog->getMethod());
        $this->assertFalse($this->webhookLog->getSuccess());
        $this->assertNull($this->webhookLog->getStatusCode());
        $this->assertNull($this->webhookLog->getRequestBody());
        $this->assertNull($this->webhookLog->getResponseBody());
        $this->assertNull($this->webhookLog->getErrorMessage());
        $this->assertSame(1, $this->webhookLog->getAttempt());
        $this->assertNull($this->webhookLog->getNextRetryAt());
    }

    public function testConstructorInitializesCreatedTimestamp(): void
    {
        $created = $this->webhookLog->getCreated();
        $this->assertInstanceOf(DateTime::class, $created);
    }

    // --- Getters/Setters ---

    public function testSetAndGetWebhook(): void
    {
        $this->webhookLog->setWebhook(42);
        $this->assertSame(42, $this->webhookLog->getWebhook());
    }

    public function testSetAndGetEventClass(): void
    {
        $this->webhookLog->setEventClass('OCA\\OpenRegister\\Events\\ObjectCreated');
        $this->assertSame('OCA\\OpenRegister\\Events\\ObjectCreated', $this->webhookLog->getEventClass());
    }

    public function testSetAndGetPayload(): void
    {
        $this->webhookLog->setPayload('{"key":"value"}');
        $this->assertSame('{"key":"value"}', $this->webhookLog->getPayload());
    }

    public function testSetAndGetPayloadNull(): void
    {
        $this->webhookLog->setPayload('some payload');
        $this->webhookLog->setPayload(null);
        $this->assertNull($this->webhookLog->getPayload());
    }

    public function testSetAndGetUrl(): void
    {
        $this->webhookLog->setUrl('https://example.com/webhook');
        $this->assertSame('https://example.com/webhook', $this->webhookLog->getUrl());
    }

    public function testSetAndGetMethod(): void
    {
        $this->webhookLog->setMethod('PUT');
        $this->assertSame('PUT', $this->webhookLog->getMethod());
    }

    public function testSetAndGetSuccess(): void
    {
        $this->webhookLog->setSuccess(true);
        $this->assertTrue($this->webhookLog->getSuccess());

        $this->webhookLog->setSuccess(false);
        $this->assertFalse($this->webhookLog->getSuccess());
    }

    public function testSetAndGetStatusCode(): void
    {
        $this->webhookLog->setStatusCode(200);
        $this->assertSame(200, $this->webhookLog->getStatusCode());
    }

    public function testSetAndGetStatusCodeNull(): void
    {
        $this->webhookLog->setStatusCode(200);
        $this->webhookLog->setStatusCode(null);
        $this->assertNull($this->webhookLog->getStatusCode());
    }

    public function testSetAndGetRequestBody(): void
    {
        $this->webhookLog->setRequestBody('{"data":"test"}');
        $this->assertSame('{"data":"test"}', $this->webhookLog->getRequestBody());
    }

    public function testSetAndGetResponseBody(): void
    {
        $this->webhookLog->setResponseBody('{"status":"ok"}');
        $this->assertSame('{"status":"ok"}', $this->webhookLog->getResponseBody());
    }

    public function testSetAndGetErrorMessage(): void
    {
        $this->webhookLog->setErrorMessage('Connection refused');
        $this->assertSame('Connection refused', $this->webhookLog->getErrorMessage());
    }

    public function testSetAndGetAttempt(): void
    {
        $this->webhookLog->setAttempt(3);
        $this->assertSame(3, $this->webhookLog->getAttempt());
    }

    public function testSetAndGetNextRetryAt(): void
    {
        $dt = new DateTime('2024-06-01 13:00:00');
        $this->webhookLog->setNextRetryAt($dt);
        $this->assertSame($dt, $this->webhookLog->getNextRetryAt());
    }

    public function testSetAndGetCreated(): void
    {
        $dt = new DateTime('2024-01-01 00:00:00');
        $this->webhookLog->setCreated($dt);
        $this->assertSame($dt, $this->webhookLog->getCreated());
    }

    // --- getPayloadArray / setPayloadArray ---

    public function testGetPayloadArrayReturnsEmptyArrayWhenNull(): void
    {
        $this->webhookLog->setPayload(null);
        $this->assertSame([], $this->webhookLog->getPayloadArray());
    }

    public function testGetPayloadArrayParsesJson(): void
    {
        $this->webhookLog->setPayload('{"key":"value","num":42}');
        $this->assertSame(['key' => 'value', 'num' => 42], $this->webhookLog->getPayloadArray());
    }

    public function testGetPayloadArrayReturnsEmptyForInvalidJson(): void
    {
        $this->webhookLog->setPayload('not-json');
        $this->assertSame([], $this->webhookLog->getPayloadArray());
    }

    /**
     * Note: setPayloadArray internally calls setPayload with named args
     * which breaks __call magic. The payload is not actually set.
     * This test documents the current (buggy) behavior.
     */
    public function testSetPayloadArrayDoesNotStoreValueDueToNamedArgBug(): void
    {
        $this->webhookLog->setPayloadArray(['event' => 'created', 'id' => 1]);
        // Due to named-arg bug in __call, setPayload(payload: ...) does not work
        $this->assertNull($this->webhookLog->getPayload());
    }

    public function testSetPayloadArrayNullClearsPayload(): void
    {
        $this->webhookLog->setPayload('{"key":"value"}');
        $this->webhookLog->setPayloadArray(null);
        $this->assertNull($this->webhookLog->getPayload());
    }

    // --- jsonSerialize ---

    public function testJsonSerializeAllFieldsPresent(): void
    {
        $json = $this->webhookLog->jsonSerialize();

        $expectedKeys = [
            'id', 'webhook', 'eventClass', 'payload', 'url', 'method',
            'success', 'statusCode', 'requestBody', 'responseBody',
            'errorMessage', 'attempt', 'nextRetryAt', 'created',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }
    }

    public function testJsonSerializeDefaultValues(): void
    {
        $json = $this->webhookLog->jsonSerialize();

        $this->assertNull($json['id']);
        $this->assertSame(0, $json['webhook']);
        $this->assertSame('', $json['eventClass']);
        $this->assertSame([], $json['payload']);
        $this->assertSame('', $json['url']);
        $this->assertSame('POST', $json['method']);
        $this->assertFalse($json['success']);
        $this->assertNull($json['statusCode']);
        $this->assertNull($json['requestBody']);
        $this->assertNull($json['responseBody']);
        $this->assertNull($json['errorMessage']);
        $this->assertSame(1, $json['attempt']);
        $this->assertNull($json['nextRetryAt']);
        // created is auto-initialized, so it should be a formatted string
        $this->assertIsString($json['created']);
    }

    public function testJsonSerializePayloadAsArray(): void
    {
        $this->webhookLog->setPayload('{"event":"object.created"}');
        $json = $this->webhookLog->jsonSerialize();
        $this->assertSame(['event' => 'object.created'], $json['payload']);
    }

    public function testJsonSerializeFormatsDatetimes(): void
    {
        $created = new DateTime('2024-01-01 10:00:00');
        $nextRetry = new DateTime('2024-01-01 11:00:00');

        $this->webhookLog->setCreated($created);
        $this->webhookLog->setNextRetryAt($nextRetry);

        $json = $this->webhookLog->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
        $this->assertSame($nextRetry->format('c'), $json['nextRetryAt']);
    }

    public function testJsonSerializeNextRetryAtNullWhenNotSet(): void
    {
        $json = $this->webhookLog->jsonSerialize();
        $this->assertNull($json['nextRetryAt']);
    }

    public function testJsonSerializeWithFullData(): void
    {
        $created = new DateTime('2024-06-01 12:00:00');
        $nextRetry = new DateTime('2024-06-01 12:05:00');

        $this->webhookLog->setWebhook(5);
        $this->webhookLog->setEventClass('object.created');
        $this->webhookLog->setPayload('{"id":1}');
        $this->webhookLog->setUrl('https://example.com/hook');
        $this->webhookLog->setMethod('POST');
        $this->webhookLog->setSuccess(true);
        $this->webhookLog->setStatusCode(200);
        $this->webhookLog->setResponseBody('OK');
        $this->webhookLog->setAttempt(2);
        $this->webhookLog->setCreated($created);
        $this->webhookLog->setNextRetryAt($nextRetry);

        $json = $this->webhookLog->jsonSerialize();

        $this->assertSame(5, $json['webhook']);
        $this->assertSame('object.created', $json['eventClass']);
        $this->assertSame(['id' => 1], $json['payload']);
        $this->assertSame('https://example.com/hook', $json['url']);
        $this->assertSame('POST', $json['method']);
        $this->assertTrue($json['success']);
        $this->assertSame(200, $json['statusCode']);
        $this->assertSame('OK', $json['responseBody']);
        $this->assertSame(2, $json['attempt']);
    }
}
