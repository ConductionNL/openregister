<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use PHPUnit\Framework\TestCase;

class AuditTrailTest extends TestCase
{
    private AuditTrail $auditTrail;

    protected function setUp(): void
    {
        $this->auditTrail = new AuditTrail();
    }

    public function testConstructorRegistersFieldTypes(): void
    {
        $fieldTypes = $this->auditTrail->getFieldTypes();

        $this->assertSame('string', $fieldTypes['uuid']);
        $this->assertSame('integer', $fieldTypes['schema']);
        $this->assertSame('integer', $fieldTypes['register']);
        $this->assertSame('integer', $fieldTypes['object']);
        $this->assertSame('string', $fieldTypes['objectUuid']);
        $this->assertSame('string', $fieldTypes['registerUuid']);
        $this->assertSame('string', $fieldTypes['schemaUuid']);
        $this->assertSame('string', $fieldTypes['action']);
        $this->assertSame('json', $fieldTypes['changed']);
        $this->assertSame('string', $fieldTypes['user']);
        $this->assertSame('string', $fieldTypes['userName']);
        $this->assertSame('string', $fieldTypes['session']);
        $this->assertSame('string', $fieldTypes['request']);
        $this->assertSame('string', $fieldTypes['ipAddress']);
        $this->assertSame('string', $fieldTypes['version']);
        $this->assertSame('datetime', $fieldTypes['created']);
        $this->assertSame('string', $fieldTypes['organisationId']);
        $this->assertSame('string', $fieldTypes['organisationIdType']);
        $this->assertSame('string', $fieldTypes['processingActivityId']);
        $this->assertSame('string', $fieldTypes['processingActivityUrl']);
        $this->assertSame('string', $fieldTypes['processingId']);
        $this->assertSame('string', $fieldTypes['confidentiality']);
        $this->assertSame('string', $fieldTypes['retentionPeriod']);
        $this->assertSame('integer', $fieldTypes['size']);
        $this->assertSame('datetime', $fieldTypes['expires']);
        $this->assertSame('string', $fieldTypes['hash']);
        $this->assertSame('string', $fieldTypes['previousHash']);
    }

    public function testConstructorDefaultValues(): void
    {
        $this->assertNull($this->auditTrail->getUuid());
        $this->assertNull($this->auditTrail->getSchema());
        $this->assertNull($this->auditTrail->getRegister());
        $this->assertNull($this->auditTrail->getObject());
        $this->assertNull($this->auditTrail->getObjectUuid());
        $this->assertNull($this->auditTrail->getAction());
        $this->assertSame([], $this->auditTrail->getChanged());
        $this->assertNull($this->auditTrail->getUser());
        $this->assertNull($this->auditTrail->getUserName());
        $this->assertNull($this->auditTrail->getCreated());
    }

    public function testSetAndGetStringFields(): void
    {
        $this->auditTrail->setUuid('audit-uuid-123');
        $this->auditTrail->setObjectUuid('obj-uuid');
        $this->auditTrail->setRegisterUuid('reg-uuid');
        $this->auditTrail->setSchemaUuid('schema-uuid');
        $this->auditTrail->setAction('create');
        $this->auditTrail->setUser('admin');
        $this->auditTrail->setUserName('Admin User');
        $this->auditTrail->setIpAddress('192.168.1.1');
        $this->auditTrail->setVersion('1.0.0');
        $this->auditTrail->setOrganisationId('OIN-12345');
        $this->auditTrail->setOrganisationIdType('OIN');
        $this->auditTrail->setProcessingActivityId('pa-001');
        $this->auditTrail->setProcessingActivityUrl('https://example.com/pa');
        $this->auditTrail->setProcessingId('proc-001');
        $this->auditTrail->setConfidentiality('confidential');
        $this->auditTrail->setRetentionPeriod('P5Y');

        $this->assertSame('audit-uuid-123', $this->auditTrail->getUuid());
        $this->assertSame('obj-uuid', $this->auditTrail->getObjectUuid());
        $this->assertSame('reg-uuid', $this->auditTrail->getRegisterUuid());
        $this->assertSame('schema-uuid', $this->auditTrail->getSchemaUuid());
        $this->assertSame('create', $this->auditTrail->getAction());
        $this->assertSame('admin', $this->auditTrail->getUser());
        $this->assertSame('Admin User', $this->auditTrail->getUserName());
        $this->assertSame('192.168.1.1', $this->auditTrail->getIpAddress());
        $this->assertSame('1.0.0', $this->auditTrail->getVersion());
        $this->assertSame('OIN-12345', $this->auditTrail->getOrganisationId());
        $this->assertSame('OIN', $this->auditTrail->getOrganisationIdType());
        $this->assertSame('pa-001', $this->auditTrail->getProcessingActivityId());
        $this->assertSame('https://example.com/pa', $this->auditTrail->getProcessingActivityUrl());
        $this->assertSame('proc-001', $this->auditTrail->getProcessingId());
        $this->assertSame('confidential', $this->auditTrail->getConfidentiality());
        $this->assertSame('P5Y', $this->auditTrail->getRetentionPeriod());
    }

    public function testSetAndGetIntegerFields(): void
    {
        $this->auditTrail->setSchema(1);
        $this->auditTrail->setRegister(2);
        $this->auditTrail->setObject(3);
        $this->auditTrail->setSize(1024);

        $this->assertSame(1, $this->auditTrail->getSchema());
        $this->assertSame(2, $this->auditTrail->getRegister());
        $this->assertSame(3, $this->auditTrail->getObject());
        $this->assertSame(1024, $this->auditTrail->getSize());
    }

    public function testGetChangedReturnsEmptyArrayWhenNull(): void
    {
        $this->assertSame([], $this->auditTrail->getChanged());
    }

    public function testSetAndGetChanged(): void
    {
        $changed = ['field1' => ['old' => 'a', 'new' => 'b']];
        $this->auditTrail->setChanged($changed);
        $this->assertSame($changed, $this->auditTrail->getChanged());
    }

    public function testSetAndGetDateTimeFields(): void
    {
        $created = new DateTime('2024-03-15T10:00:00Z');
        $expires = new DateTime('2029-03-15T10:00:00Z');

        $this->auditTrail->setCreated($created);
        $this->auditTrail->setExpires($expires);

        $this->assertSame($created, $this->auditTrail->getCreated());
        $this->assertSame($expires, $this->auditTrail->getExpires());
    }

    public function testGetJsonFields(): void
    {
        $jsonFields = $this->auditTrail->getJsonFields();
        $this->assertContains('changed', $jsonFields);
    }

    public function testHydrate(): void
    {
        $data = [
            'uuid'   => 'hydrate-uuid',
            'action' => 'update',
            'user'   => 'testuser',
            'schema' => 5,
        ];

        $result = $this->auditTrail->hydrate($data);

        $this->assertSame($this->auditTrail, $result);
        $this->assertSame('hydrate-uuid', $this->auditTrail->getUuid());
        $this->assertSame('update', $this->auditTrail->getAction());
        $this->assertSame('testuser', $this->auditTrail->getUser());
        $this->assertSame(5, $this->auditTrail->getSchema());
    }

    public function testJsonSerialize(): void
    {
        $this->auditTrail->setUuid('json-uuid');
        $this->auditTrail->setAction('delete');
        $this->auditTrail->setUser('admin');
        $this->auditTrail->setSchema(1);
        $this->auditTrail->setRegister(2);

        $json = $this->auditTrail->jsonSerialize();

        $expectedKeys = [
            'id', 'uuid', 'schema', 'register', 'object', 'objectUuid',
            'registerUuid', 'schemaUuid', 'action', 'changed', 'user',
            'userName', 'session', 'request', 'ipAddress', 'version',
            'created', 'organisationId', 'organisationIdType',
            'processingActivityId', 'processingActivityUrl', 'processingId',
            'confidentiality', 'retentionPeriod', 'size', 'expires',
            'hash', 'previousHash',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        $this->assertSame('json-uuid', $json['uuid']);
        $this->assertSame('delete', $json['action']);
    }

    public function testJsonSerializeDateTimeFormatting(): void
    {
        $created = new DateTime('2024-01-01T12:00:00+00:00');
        $expires = new DateTime('2029-01-01T12:00:00+00:00');
        $this->auditTrail->setCreated($created);
        $this->auditTrail->setExpires($expires);

        $json = $this->auditTrail->jsonSerialize();

        $this->assertNotNull($json['created']);
        $this->assertNotNull($json['expires']);
    }

    public function testToStringWithUuid(): void
    {
        $this->auditTrail->setUuid('my-uuid');
        $this->assertSame('my-uuid', (string)$this->auditTrail);
    }

    public function testToStringWithAction(): void
    {
        $this->auditTrail->setAction('create');
        $this->assertSame('Audit: create', (string)$this->auditTrail);
    }

    public function testToStringFallback(): void
    {
        $this->assertSame('Audit Trail', (string)$this->auditTrail);
    }

    public function testSetAndGetHashFields(): void
    {
        $this->auditTrail->setHash('a1b2c3d4e5f6');
        $this->auditTrail->setPreviousHash('f6e5d4c3b2a1');

        $this->assertSame('a1b2c3d4e5f6', $this->auditTrail->getHash());
        $this->assertSame('f6e5d4c3b2a1', $this->auditTrail->getPreviousHash());
    }

    public function testHashFieldsDefaultToNull(): void
    {
        $this->assertNull($this->auditTrail->getHash());
        $this->assertNull($this->auditTrail->getPreviousHash());
    }

    public function testJsonSerializeIncludesHashFields(): void
    {
        $this->auditTrail->setHash('abc123');
        $this->auditTrail->setPreviousHash('def456');

        $json = $this->auditTrail->jsonSerialize();

        $this->assertArrayHasKey('hash', $json);
        $this->assertArrayHasKey('previousHash', $json);
        $this->assertSame('abc123', $json['hash']);
        $this->assertSame('def456', $json['previousHash']);
    }

    public function testJsonSerializeHashFieldsNullWhenNotSet(): void
    {
        $json = $this->auditTrail->jsonSerialize();

        $this->assertNull($json['hash']);
        $this->assertNull($json['previousHash']);
    }
}
