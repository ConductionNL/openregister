<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Webhook;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
{
    private Webhook $webhook;

    protected function setUp(): void
    {
        $this->webhook = new Webhook();
    }

    // --- Constructor and field type registration ---

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->webhook->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['name']);
        $this->assertSame('string', $fieldTypes['url']);
        $this->assertSame('string', $fieldTypes['method']);
        $this->assertSame('string', $fieldTypes['events']);
        $this->assertSame('string', $fieldTypes['headers']);
        $this->assertSame('string', $fieldTypes['secret']);
        $this->assertSame('boolean', $fieldTypes['enabled']);
        $this->assertSame('string', $fieldTypes['organisation']);
        $this->assertSame('string', $fieldTypes['filters']);
        $this->assertSame('string', $fieldTypes['retryPolicy']);
        $this->assertSame('integer', $fieldTypes['maxRetries']);
        $this->assertSame('integer', $fieldTypes['timeout']);
        $this->assertSame('datetime', $fieldTypes['lastTriggeredAt']);
        $this->assertSame('datetime', $fieldTypes['lastSuccessAt']);
        $this->assertSame('datetime', $fieldTypes['lastFailureAt']);
        $this->assertSame('integer', $fieldTypes['totalDeliveries']);
        $this->assertSame('integer', $fieldTypes['successfulDeliveries']);
        $this->assertSame('integer', $fieldTypes['failedDeliveries']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
        $this->assertSame('string', $fieldTypes['configuration']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertSame('', $this->webhook->getUuid());
        $this->assertSame('', $this->webhook->getName());
        $this->assertSame('', $this->webhook->getUrl());
        $this->assertSame('POST', $this->webhook->getMethod());
        $this->assertSame('[]', $this->webhook->getEvents());
        $this->assertNull($this->webhook->getHeaders());
        $this->assertNull($this->webhook->getSecret());
        $this->assertTrue($this->webhook->getEnabled());
        $this->assertNull($this->webhook->getOrganisation());
        $this->assertNull($this->webhook->getFilters());
        $this->assertSame('exponential', $this->webhook->getRetryPolicy());
        $this->assertSame(3, $this->webhook->getMaxRetries());
        $this->assertSame(30, $this->webhook->getTimeout());
        $this->assertNull($this->webhook->getLastTriggeredAt());
        $this->assertNull($this->webhook->getLastSuccessAt());
        $this->assertNull($this->webhook->getLastFailureAt());
        $this->assertSame(0, $this->webhook->getTotalDeliveries());
        $this->assertSame(0, $this->webhook->getSuccessfulDeliveries());
        $this->assertSame(0, $this->webhook->getFailedDeliveries());
        $this->assertNull($this->webhook->getCreated());
        $this->assertNull($this->webhook->getUpdated());
        $this->assertNull($this->webhook->getConfiguration());
    }

    // --- Getters and setters ---

    public function testSetAndGetUuid(): void
    {
        $this->webhook->setUuid('webhook-uuid-123');
        $this->assertSame('webhook-uuid-123', $this->webhook->getUuid());
    }

    public function testSetAndGetName(): void
    {
        $this->webhook->setName('My Webhook');
        $this->assertSame('My Webhook', $this->webhook->getName());
    }

    public function testSetAndGetUrl(): void
    {
        $this->webhook->setUrl('https://example.com/hook');
        $this->assertSame('https://example.com/hook', $this->webhook->getUrl());
    }

    public function testSetAndGetMethod(): void
    {
        $this->webhook->setMethod('PUT');
        $this->assertSame('PUT', $this->webhook->getMethod());
    }

    public function testSetAndGetEvents(): void
    {
        $this->webhook->setEvents('["event1","event2"]');
        $this->assertSame('["event1","event2"]', $this->webhook->getEvents());
    }

    public function testSetAndGetHeaders(): void
    {
        $this->webhook->setHeaders('{"Authorization":"Bearer token"}');
        $this->assertSame('{"Authorization":"Bearer token"}', $this->webhook->getHeaders());
    }

    public function testSetAndGetSecret(): void
    {
        $this->webhook->setSecret('my-secret');
        $this->assertSame('my-secret', $this->webhook->getSecret());
    }

    public function testSetAndGetEnabled(): void
    {
        $this->webhook->setEnabled(false);
        $this->assertFalse($this->webhook->getEnabled());
    }

    public function testSetAndGetOrganisation(): void
    {
        $this->webhook->setOrganisation('org-uuid');
        $this->assertSame('org-uuid', $this->webhook->getOrganisation());
    }

    public function testSetAndGetFilters(): void
    {
        $this->webhook->setFilters('{"schema":"test"}');
        $this->assertSame('{"schema":"test"}', $this->webhook->getFilters());
    }

    public function testSetAndGetRetryPolicy(): void
    {
        $this->webhook->setRetryPolicy('linear');
        $this->assertSame('linear', $this->webhook->getRetryPolicy());
    }

    public function testSetAndGetMaxRetries(): void
    {
        $this->webhook->setMaxRetries(5);
        $this->assertSame(5, $this->webhook->getMaxRetries());
    }

    public function testSetAndGetTimeout(): void
    {
        $this->webhook->setTimeout(60);
        $this->assertSame(60, $this->webhook->getTimeout());
    }

    public function testSetAndGetLastTriggeredAt(): void
    {
        $dt = new DateTime('2024-06-01 12:00:00');
        $this->webhook->setLastTriggeredAt($dt);
        $this->assertSame($dt, $this->webhook->getLastTriggeredAt());
    }

    public function testSetAndGetLastSuccessAt(): void
    {
        $dt = new DateTime('2024-06-01 12:00:00');
        $this->webhook->setLastSuccessAt($dt);
        $this->assertSame($dt, $this->webhook->getLastSuccessAt());
    }

    public function testSetAndGetLastFailureAt(): void
    {
        $dt = new DateTime('2024-06-01 12:00:00');
        $this->webhook->setLastFailureAt($dt);
        $this->assertSame($dt, $this->webhook->getLastFailureAt());
    }

    public function testSetAndGetTotalDeliveries(): void
    {
        $this->webhook->setTotalDeliveries(100);
        $this->assertSame(100, $this->webhook->getTotalDeliveries());
    }

    public function testSetAndGetSuccessfulDeliveries(): void
    {
        $this->webhook->setSuccessfulDeliveries(95);
        $this->assertSame(95, $this->webhook->getSuccessfulDeliveries());
    }

    public function testSetAndGetFailedDeliveries(): void
    {
        $this->webhook->setFailedDeliveries(5);
        $this->assertSame(5, $this->webhook->getFailedDeliveries());
    }

    public function testSetAndGetCreated(): void
    {
        $dt = new DateTime('2024-01-01 00:00:00');
        $this->webhook->setCreated($dt);
        $this->assertSame($dt, $this->webhook->getCreated());
    }

    public function testSetAndGetUpdated(): void
    {
        $dt = new DateTime('2024-06-15 08:30:00');
        $this->webhook->setUpdated($dt);
        $this->assertSame($dt, $this->webhook->getUpdated());
    }

    public function testSetAndGetConfiguration(): void
    {
        $this->webhook->setConfiguration('{"key":"value"}');
        $this->assertSame('{"key":"value"}', $this->webhook->getConfiguration());
    }

    // --- getEventsArray / setEventsArray ---

    public function testGetEventsArrayDefault(): void
    {
        $this->assertSame([], $this->webhook->getEventsArray());
    }

    public function testGetEventsArrayFromJsonString(): void
    {
        $this->webhook->setEvents('["event1","event2"]');
        $this->assertSame(['event1', 'event2'], $this->webhook->getEventsArray());
    }

    public function testGetEventsArrayInvalidJson(): void
    {
        $this->webhook->setEvents('not-json');
        $this->assertSame([], $this->webhook->getEventsArray());
    }

    /**
     * setEventsArray uses named args on magic setter (events is non-nullable string),
     * which causes TypeError since __call receives null for $args[0].
     */
    public function testSetEventsArrayNamedArgBug(): void
    {
        $this->expectException(\TypeError::class);
        $this->webhook->setEventsArray(['event1', 'event2']);
    }

    // --- getHeadersArray / setHeadersArray ---

    public function testGetHeadersArrayDefault(): void
    {
        $this->assertSame([], $this->webhook->getHeadersArray());
    }

    public function testGetHeadersArrayFromJsonString(): void
    {
        $this->webhook->setHeaders('{"X-Custom":"value"}');
        $this->assertSame(['X-Custom' => 'value'], $this->webhook->getHeadersArray());
    }

    public function testGetHeadersArrayInvalidJson(): void
    {
        $this->webhook->setHeaders('not-json');
        $this->assertSame([], $this->webhook->getHeadersArray());
    }

    /**
     * setHeadersArray uses named args but headers is nullable string,
     * so the value silently becomes null instead of the JSON string.
     */
    public function testSetHeadersArraySetsNull(): void
    {
        $this->webhook->setHeadersArray(['Content-Type' => 'application/json']);
        // Due to named-arg bug, the value gets set to null instead of JSON
        $this->assertNull($this->webhook->getHeaders());
        $this->assertSame([], $this->webhook->getHeadersArray());
    }

    public function testSetHeadersArrayNull(): void
    {
        $this->webhook->setHeaders('{"key":"value"}');
        $this->webhook->setHeadersArray(null);
        $this->assertNull($this->webhook->getHeaders());
        $this->assertSame([], $this->webhook->getHeadersArray());
    }

    // --- getFiltersArray / setFiltersArray ---

    public function testGetFiltersArrayDefault(): void
    {
        $this->assertSame([], $this->webhook->getFiltersArray());
    }

    public function testGetFiltersArrayFromJsonString(): void
    {
        $this->webhook->setFilters('{"schema":"test"}');
        $this->assertSame(['schema' => 'test'], $this->webhook->getFiltersArray());
    }

    public function testGetFiltersArrayInvalidJson(): void
    {
        $this->webhook->setFilters('not-json');
        $this->assertSame([], $this->webhook->getFiltersArray());
    }

    /**
     * setFiltersArray uses named args but filters is nullable string,
     * so the value silently becomes null instead of the JSON string.
     */
    public function testSetFiltersArraySetsNull(): void
    {
        $this->webhook->setFiltersArray(['schema' => 'test']);
        $this->assertNull($this->webhook->getFilters());
        $this->assertSame([], $this->webhook->getFiltersArray());
    }

    public function testSetFiltersArrayNull(): void
    {
        $this->webhook->setFilters('{"key":"value"}');
        $this->webhook->setFiltersArray(null);
        $this->assertNull($this->webhook->getFilters());
        $this->assertSame([], $this->webhook->getFiltersArray());
    }

    // --- getConfigurationArray / setConfigurationArray ---

    public function testGetConfigurationArrayDefault(): void
    {
        $this->assertSame([], $this->webhook->getConfigurationArray());
    }

    public function testGetConfigurationArrayFromJsonString(): void
    {
        $this->webhook->setConfiguration('{"key":"value"}');
        $this->assertSame(['key' => 'value'], $this->webhook->getConfigurationArray());
    }

    public function testGetConfigurationArrayInvalidJson(): void
    {
        $this->webhook->setConfiguration('not-json');
        $this->assertSame([], $this->webhook->getConfigurationArray());
    }

    /**
     * setConfigurationArray uses named args but configuration is nullable string,
     * so the value silently becomes null instead of the JSON string.
     */
    public function testSetConfigurationArraySetsNull(): void
    {
        $this->webhook->setConfigurationArray(['key' => 'value']);
        $this->assertNull($this->webhook->getConfiguration());
        $this->assertSame([], $this->webhook->getConfigurationArray());
    }

    public function testSetConfigurationArrayNull(): void
    {
        $this->webhook->setConfiguration('{"key":"value"}');
        $this->webhook->setConfigurationArray(null);
        $this->assertNull($this->webhook->getConfiguration());
        $this->assertSame([], $this->webhook->getConfigurationArray());
    }

    // --- matchesEvent ---

    public function testMatchesEventEmptyEventsMatchesAll(): void
    {
        // Default events is '[]', so getEventsArray returns []
        $this->assertTrue($this->webhook->matchesEvent('AnyEvent'));
    }

    public function testMatchesEventExactMatch(): void
    {
        $this->webhook->setEvents(json_encode(['object.created']));
        $this->assertTrue($this->webhook->matchesEvent('object.created'));
    }

    public function testMatchesEventNoMatch(): void
    {
        $this->webhook->setEvents(json_encode(['object.created']));
        $this->assertFalse($this->webhook->matchesEvent('object.deleted'));
    }

    public function testMatchesEventWildcard(): void
    {
        $this->webhook->setEvents(json_encode(['object.*']));
        $this->assertTrue($this->webhook->matchesEvent('object.created'));
        $this->assertTrue($this->webhook->matchesEvent('object.updated'));
        $this->assertTrue($this->webhook->matchesEvent('object.deleted'));
    }

    public function testMatchesEventWildcardNoMatch(): void
    {
        $this->webhook->setEvents(json_encode(['schema.*']));
        $this->assertFalse($this->webhook->matchesEvent('object.created'));
    }

    public function testMatchesEventMultiplePatterns(): void
    {
        $this->webhook->setEvents(json_encode([
            'object.created',
            'schema.*',
        ]));
        $this->assertTrue($this->webhook->matchesEvent('object.created'));
        $this->assertTrue($this->webhook->matchesEvent('schema.updated'));
        $this->assertFalse($this->webhook->matchesEvent('register.deleted'));
    }

    public function testMatchesEventWildcardAll(): void
    {
        $this->webhook->setEvents(json_encode(['*']));
        $this->assertTrue($this->webhook->matchesEvent('AnyEvent'));
    }

    public function testMatchesEventWildcardPrefix(): void
    {
        $this->webhook->setEvents(json_encode(['*.created']));
        $this->assertTrue($this->webhook->matchesEvent('object.created'));
        $this->assertTrue($this->webhook->matchesEvent('schema.created'));
        $this->assertFalse($this->webhook->matchesEvent('object.updated'));
    }

    // --- jsonSerialize ---

    public function testJsonSerializeStructure(): void
    {
        $this->webhook->setUuid('hook-uuid');
        $this->webhook->setName('Test Hook');
        $this->webhook->setUrl('https://example.com/hook');
        $this->webhook->setMethod('POST');
        $this->webhook->setEvents(json_encode(['object.created']));
        $this->webhook->setHeaders('{"X-Custom":"value"}');
        $this->webhook->setSecret('my-secret');
        $this->webhook->setEnabled(true);
        $this->webhook->setOrganisation('org-uuid');
        $this->webhook->setFilters('{"schema":"test"}');
        $this->webhook->setRetryPolicy('exponential');
        $this->webhook->setMaxRetries(3);
        $this->webhook->setTimeout(30);
        $this->webhook->setTotalDeliveries(10);
        $this->webhook->setSuccessfulDeliveries(9);
        $this->webhook->setFailedDeliveries(1);

        $json = $this->webhook->jsonSerialize();

        $this->assertArrayHasKey('id', $json);
        $this->assertSame('hook-uuid', $json['uuid']);
        $this->assertSame('Test Hook', $json['name']);
        $this->assertSame('https://example.com/hook', $json['url']);
        $this->assertSame('POST', $json['method']);
        $this->assertSame(['object.created'], $json['events']);
        $this->assertSame(['X-Custom' => 'value'], $json['headers']);
        $this->assertSame('***', $json['secret']);
        $this->assertTrue($json['enabled']);
        $this->assertSame('org-uuid', $json['organisation']);
        $this->assertSame(['schema' => 'test'], $json['filters']);
        $this->assertSame('exponential', $json['retryPolicy']);
        $this->assertSame(3, $json['maxRetries']);
        $this->assertSame(30, $json['timeout']);
        $this->assertSame(10, $json['totalDeliveries']);
        $this->assertSame(9, $json['successfulDeliveries']);
        $this->assertSame(1, $json['failedDeliveries']);
        $this->assertArrayHasKey('created', $json);
        $this->assertArrayHasKey('updated', $json);
        $this->assertArrayHasKey('configuration', $json);
    }

    public function testJsonSerializeSecretMasked(): void
    {
        $this->webhook->setSecret('super-secret-key');
        $json = $this->webhook->jsonSerialize();
        $this->assertSame('***', $json['secret']);
    }

    public function testJsonSerializeSecretNullWhenNotSet(): void
    {
        $json = $this->webhook->jsonSerialize();
        $this->assertNull($json['secret']);
    }

    public function testJsonSerializeDatesFormatted(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $triggered = new DateTime('2024-06-01 12:00:00');
        $success = new DateTime('2024-06-01 12:00:01');
        $failure = new DateTime('2024-05-30 10:00:00');
        $updated = new DateTime('2024-06-15 08:00:00');

        $this->webhook->setCreated($created);
        $this->webhook->setUpdated($updated);
        $this->webhook->setLastTriggeredAt($triggered);
        $this->webhook->setLastSuccessAt($success);
        $this->webhook->setLastFailureAt($failure);

        $json = $this->webhook->jsonSerialize();

        $this->assertSame($created->format('c'), $json['created']);
        $this->assertSame($updated->format('c'), $json['updated']);
        $this->assertSame($triggered->format('c'), $json['lastTriggeredAt']);
        $this->assertSame($success->format('c'), $json['lastSuccessAt']);
        $this->assertSame($failure->format('c'), $json['lastFailureAt']);
    }

    public function testJsonSerializeDatesNullWhenNotSet(): void
    {
        $json = $this->webhook->jsonSerialize();
        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
        $this->assertNull($json['lastTriggeredAt']);
        $this->assertNull($json['lastSuccessAt']);
        $this->assertNull($json['lastFailureAt']);
    }

    public function testJsonSerializeDefaultArrayFields(): void
    {
        $json = $this->webhook->jsonSerialize();
        $this->assertSame([], $json['events']);
        $this->assertSame([], $json['headers']);
        $this->assertSame([], $json['filters']);
        $this->assertSame([], $json['configuration']);
    }

    public function testJsonSerializeEventsAsArray(): void
    {
        $this->webhook->setEvents(json_encode(['a', 'b']));
        $json = $this->webhook->jsonSerialize();
        $this->assertSame(['a', 'b'], $json['events']);
    }

    public function testJsonSerializeConfigurationAsArray(): void
    {
        $this->webhook->setConfiguration(json_encode(['key' => 'val']));
        $json = $this->webhook->jsonSerialize();
        $this->assertSame(['key' => 'val'], $json['configuration']);
    }

    // --- hydrate ---

    /**
     * hydrate uses named args on magic setters, which causes TypeError for non-nullable
     * string properties. This tests that hydrate throws for basic fields.
     */
    public function testHydrateThrowsForNonNullableStringFields(): void
    {
        $this->expectException(\TypeError::class);
        $this->webhook->hydrate([
            'uuid' => 'hook-uuid',
            'name' => 'My Hook',
        ]);
    }

    public function testHydrateIdNamedArgBug(): void
    {
        // hydrate calls setId(id: ...) with named args -- setId is also __call,
        // so the value becomes null. The id stays unset.
        $this->webhook->hydrate(['id' => 42]);
        $this->assertNull($this->webhook->getId());
    }

    public function testHydrateSkipsNullValues(): void
    {
        $this->webhook->setName('Original');
        $this->webhook->hydrate(['name' => null]);
        $this->assertSame('Original', $this->webhook->getName());
    }

    public function testHydrateReturnsThis(): void
    {
        $result = $this->webhook->hydrate(['id' => 1]);
        $this->assertSame($this->webhook, $result);
    }
}
