<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuthorizationException;
use DateTime;
use PHPUnit\Framework\TestCase;

class AuthorizationExceptionTest extends TestCase
{
    private AuthorizationException $authorizationException;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorizationException = new AuthorizationException();
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(AuthorizationException::class, $this->authorizationException);
        $this->assertNull($this->authorizationException->getUuid());
        $this->assertNull($this->authorizationException->getType());
        $this->assertNull($this->authorizationException->getSubjectType());
        $this->assertNull($this->authorizationException->getSubjectId());
        $this->assertNull($this->authorizationException->getSchemaUuid());
        $this->assertNull($this->authorizationException->getRegisterUuid());
        $this->assertNull($this->authorizationException->getOrganizationUuid());
        $this->assertNull($this->authorizationException->getAction());
        $this->assertEquals(0, $this->authorizationException->getPriority());
        $this->assertTrue($this->authorizationException->getActive());
        $this->assertNull($this->authorizationException->getDescription());
        $this->assertNull($this->authorizationException->getCreatedBy());
        $this->assertNull($this->authorizationException->getCreatedAt());
        $this->assertNull($this->authorizationException->getUpdatedAt());
    }

    public function testUuid(): void
    {
        $uuid = 'test-uuid-123';
        $this->authorizationException->setUuid($uuid);
        $this->assertEquals($uuid, $this->authorizationException->getUuid());
    }

    public function testType(): void
    {
        $type = 'inclusion';
        $this->authorizationException->setType($type);
        $this->assertEquals($type, $this->authorizationException->getType());
    }

    public function testSubjectType(): void
    {
        $subjectType = 'user';
        $this->authorizationException->setSubjectType($subjectType);
        $this->assertEquals($subjectType, $this->authorizationException->getSubjectType());
    }

    public function testSubjectId(): void
    {
        $subjectId = 'user123';
        $this->authorizationException->setSubjectId($subjectId);
        $this->assertEquals($subjectId, $this->authorizationException->getSubjectId());
    }

    public function testSchemaUuid(): void
    {
        $schemaUuid = 'schema-uuid-123';
        $this->authorizationException->setSchemaUuid($schemaUuid);
        $this->assertEquals($schemaUuid, $this->authorizationException->getSchemaUuid());
    }

    public function testAction(): void
    {
        $action = 'read';
        $this->authorizationException->setAction($action);
        $this->assertEquals($action, $this->authorizationException->getAction());
    }

    public function testDescription(): void
    {
        $description = 'Special access required';
        $this->authorizationException->setDescription($description);
        $this->assertEquals($description, $this->authorizationException->getDescription());
    }

    public function testCreatedAt(): void
    {
        $createdAt = new DateTime('2024-01-01 00:00:00');
        $this->authorizationException->setCreatedAt($createdAt);
        $this->assertEquals($createdAt, $this->authorizationException->getCreatedAt());
    }

    public function testUpdatedAt(): void
    {
        $updatedAt = new DateTime('2024-01-02 00:00:00');
        $this->authorizationException->setUpdatedAt($updatedAt);
        $this->assertEquals($updatedAt, $this->authorizationException->getUpdatedAt());
    }

    public function testJsonSerialize(): void
    {
        $this->authorizationException->setUuid('test-uuid');
        $this->authorizationException->setSchemaUuid('schema-uuid-123');
        $this->authorizationException->setSubjectId('user-456');
        $this->authorizationException->setType('exclusion');
        
        $json = $this->authorizationException->jsonSerialize();
        
        $this->assertIsArray($json);
        $this->assertEquals('test-uuid', $json['uuid']);
        $this->assertEquals('schema-uuid-123', $json['schemaUuid']);
        $this->assertEquals('user-456', $json['subjectId']);
        $this->assertEquals('exclusion', $json['type']);
    }
}
