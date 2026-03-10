<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\Consumer;
use PHPUnit\Framework\TestCase;

class ConsumerTest extends TestCase
{
    private Consumer $consumer;

    protected function setUp(): void
    {
        $this->consumer = new Consumer();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->consumer->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('string', $fieldTypes['name']);
        $this->assertSame('string', $fieldTypes['description']);
        $this->assertSame('json', $fieldTypes['domains']);
        $this->assertSame('json', $fieldTypes['ips']);
        $this->assertSame('string', $fieldTypes['authorizationType']);
        $this->assertSame('json', $fieldTypes['authorizationConfiguration']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('datetime', $fieldTypes['updated']);
        $this->assertSame('string', $fieldTypes['userId']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->consumer->getUuid());
        $this->assertNull($this->consumer->getName());
        $this->assertNull($this->consumer->getDescription());
        $this->assertSame([], $this->consumer->getDomains());
        $this->assertSame([], $this->consumer->getIps());
        $this->assertNull($this->consumer->getAuthorizationType());
        $this->assertSame([], $this->consumer->getAuthorizationConfiguration());
        $this->assertNull($this->consumer->getCreated());
        $this->assertNull($this->consumer->getUpdated());
        $this->assertNull($this->consumer->getUserId());
    }

    public function testSetAndGetStringFields(): void
    {
        $this->consumer->setUuid('consumer-uuid');
        $this->consumer->setName('Test Consumer');
        $this->consumer->setDescription('A test consumer');
        $this->consumer->setAuthorizationType('jwt');
        $this->consumer->setUserId('user123');

        $this->assertSame('consumer-uuid', $this->consumer->getUuid());
        $this->assertSame('Test Consumer', $this->consumer->getName());
        $this->assertSame('A test consumer', $this->consumer->getDescription());
        $this->assertSame('jwt', $this->consumer->getAuthorizationType());
        $this->assertSame('user123', $this->consumer->getUserId());
    }

    public function testSetAndGetJsonFields(): void
    {
        $domains = ['example.com', 'test.nl'];
        $ips = ['192.168.1.1', '10.0.0.1'];
        $authConfig = ['algorithm' => 'RS256', 'publicKey' => 'key-data'];

        $this->consumer->setDomains($domains);
        $this->consumer->setIps($ips);
        $this->consumer->setAuthorizationConfiguration($authConfig);

        $this->assertSame($domains, $this->consumer->getDomains());
        $this->assertSame($ips, $this->consumer->getIps());
        $this->assertSame($authConfig, $this->consumer->getAuthorizationConfiguration());
    }

    public function testGetDomainsReturnsEmptyArrayWhenNull(): void
    {
        $this->consumer->setDomains(null);
        $this->assertSame([], $this->consumer->getDomains());
    }

    public function testGetIpsReturnsEmptyArrayWhenNull(): void
    {
        $this->consumer->setIps(null);
        $this->assertSame([], $this->consumer->getIps());
    }

    public function testGetAuthorizationConfigurationReturnsEmptyArrayWhenNull(): void
    {
        $this->consumer->setAuthorizationConfiguration(null);
        $this->assertSame([], $this->consumer->getAuthorizationConfiguration());
    }

    public function testSetAndGetDateTimeFields(): void
    {
        $created = new DateTime('2024-01-01T00:00:00Z');
        $updated = new DateTime('2024-06-01T00:00:00Z');

        $this->consumer->setCreated($created);
        $this->consumer->setUpdated($updated);

        $this->assertSame($created, $this->consumer->getCreated());
        $this->assertSame($updated, $this->consumer->getUpdated());
    }

    public function testGetJsonFields(): void
    {
        $jsonFields = $this->consumer->getJsonFields();
        $this->assertContains('domains', $jsonFields);
        $this->assertContains('ips', $jsonFields);
        $this->assertContains('authorizationConfiguration', $jsonFields);
    }

    public function testHydrate(): void
    {
        $data = [
            'uuid'              => 'hydrate-uuid',
            'name'              => 'Hydrated Consumer',
            'authorizationType' => 'basic',
            'domains'           => ['example.com'],
        ];

        $result = $this->consumer->hydrate($data);

        $this->assertSame($this->consumer, $result);
        $this->assertSame('hydrate-uuid', $this->consumer->getUuid());
        $this->assertSame('Hydrated Consumer', $this->consumer->getName());
        $this->assertSame('basic', $this->consumer->getAuthorizationType());
        $this->assertSame(['example.com'], $this->consumer->getDomains());
    }

    public function testJsonSerialize(): void
    {
        $this->consumer->setUuid('json-uuid');
        $this->consumer->setName('JSON Consumer');
        $this->consumer->setAuthorizationType('apiKey');

        $json = $this->consumer->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'name', 'description', 'domains', 'ips',
            'authorizationType', 'authorizationConfiguration', 'userId',
            'created', 'updated',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        $this->assertSame('json-uuid', $json['uuid']);
        $this->assertSame('JSON Consumer', $json['name']);
        $this->assertSame('apiKey', $json['authorizationType']);
    }

    public function testJsonSerializeDateTimeFormatting(): void
    {
        $created = new DateTime('2024-01-01T12:00:00+00:00');
        $updated = new DateTime('2024-06-01T12:00:00+00:00');
        $this->consumer->setCreated($created);
        $this->consumer->setUpdated($updated);

        $json = $this->consumer->jsonSerialize();

        $this->assertNotNull($json['created']);
        $this->assertNotNull($json['updated']);
    }

    public function testJsonSerializeNullDates(): void
    {
        $json = $this->consumer->jsonSerialize();

        $this->assertNull($json['created']);
        $this->assertNull($json['updated']);
    }
}
