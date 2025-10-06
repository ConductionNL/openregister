<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use DateTime;
use PHPUnit\Framework\TestCase;

class AuditTrailTest extends TestCase
{
    private AuditTrail $auditTrail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditTrail = new AuditTrail();
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(AuditTrail::class, $this->auditTrail);
        $this->assertNull($this->auditTrail->getUuid());
        $this->assertNull($this->auditTrail->getSchema());
        $this->assertNull($this->auditTrail->getObject());
        $this->assertNull($this->auditTrail->getAction());
        $this->assertNull($this->auditTrail->getUser());
        $this->assertNull($this->auditTrail->getIpAddress());
        $this->assertNull($this->auditTrail->getRequest());
        $this->assertIsArray($this->auditTrail->getChanged());
        $this->assertNull($this->auditTrail->getCreated());
    }

    public function testUuid(): void
    {
        $uuid = 'test-uuid-123';
        $this->auditTrail->setUuid($uuid);
        $this->assertEquals($uuid, $this->auditTrail->getUuid());
    }

    public function testSchema(): void
    {
        $schema = 123;
        $this->auditTrail->setSchema($schema);
        $this->assertEquals($schema, $this->auditTrail->getSchema());
    }

    public function testObject(): void
    {
        $object = 123;
        $this->auditTrail->setObject($object);
        $this->assertEquals($object, $this->auditTrail->getObject());
    }

    public function testAction(): void
    {
        $action = 'create';
        $this->auditTrail->setAction($action);
        $this->assertEquals($action, $this->auditTrail->getAction());
    }

    public function testUser(): void
    {
        $user = 'user123';
        $this->auditTrail->setUser($user);
        $this->assertEquals($user, $this->auditTrail->getUser());
    }

    public function testIpAddress(): void
    {
        $ipAddress = '192.168.1.1';
        $this->auditTrail->setIpAddress($ipAddress);
        $this->assertEquals($ipAddress, $this->auditTrail->getIpAddress());
    }

    public function testRequest(): void
    {
        $request = 'POST /api/objects';
        $this->auditTrail->setRequest($request);
        $this->assertEquals($request, $this->auditTrail->getRequest());
    }

    public function testChanged(): void
    {
        $changed = ['field1' => 'value1', 'field2' => 'value2'];
        $this->auditTrail->setChanged($changed);
        $this->assertEquals($changed, $this->auditTrail->getChanged());
    }

    public function testCreated(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $this->auditTrail->setCreated($created);
        $this->assertEquals($created, $this->auditTrail->getCreated());
    }

    public function testJsonSerialize(): void
    {
        $this->auditTrail->setUuid('test-uuid');
        $this->auditTrail->setSchema(123);
        $this->auditTrail->setObject(456);
        $this->auditTrail->setAction('update');
        
        $json = $this->auditTrail->jsonSerialize();
        
        $this->assertIsArray($json);
        $this->assertEquals('test-uuid', $json['uuid']);
        $this->assertEquals(123, $json['schema']);
        $this->assertEquals(456, $json['object']);
        $this->assertEquals('update', $json['action']);
    }
}
