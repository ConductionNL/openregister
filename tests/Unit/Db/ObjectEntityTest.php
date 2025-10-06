<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ObjectEntity;
use DateTime;
use PHPUnit\Framework\TestCase;

class ObjectEntityTest extends TestCase
{
    private ObjectEntity $objectEntity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectEntity = new ObjectEntity();
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(ObjectEntity::class, $this->objectEntity);
        $this->assertNull($this->objectEntity->getUuid());
        $this->assertNull($this->objectEntity->getName());
        $this->assertNull($this->objectEntity->getDescription());
        $this->assertNull($this->objectEntity->getSummary());
        $this->assertNull($this->objectEntity->getImage());
        $this->assertIsArray($this->objectEntity->getObject());
        $this->assertNull($this->objectEntity->getRegister());
        $this->assertNull($this->objectEntity->getSchema());
        $this->assertNull($this->objectEntity->getOrganisation());
        $this->assertNull($this->objectEntity->getCreated());
        $this->assertNull($this->objectEntity->getUpdated());
    }

    public function testUuid(): void
    {
        $uuid = 'test-uuid-123';
        $this->objectEntity->setUuid($uuid);
        $this->assertEquals($uuid, $this->objectEntity->getUuid());
    }

    public function testName(): void
    {
        $name = 'Test Object';
        $this->objectEntity->setName($name);
        $this->assertEquals($name, $this->objectEntity->getName());
    }

    public function testDescription(): void
    {
        $description = 'Test Description';
        $this->objectEntity->setDescription($description);
        $this->assertEquals($description, $this->objectEntity->getDescription());
    }

    public function testSummary(): void
    {
        $summary = 'Test Summary';
        $this->objectEntity->setSummary($summary);
        $this->assertEquals($summary, $this->objectEntity->getSummary());
    }

    public function testImage(): void
    {
        $image = 'test-image.jpg';
        $this->objectEntity->setImage($image);
        $this->assertEquals($image, $this->objectEntity->getImage());
    }

    public function testObject(): void
    {
        $object = ['field1' => 'value1', 'field2' => 'value2'];
        $this->objectEntity->setObject($object);
        $result = $this->objectEntity->getObject();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('value1', $result['field1']);
        $this->assertEquals('value2', $result['field2']);
    }

    public function testRegister(): void
    {
        $register = 123;
        $this->objectEntity->setRegister($register);
        $this->assertEquals($register, $this->objectEntity->getRegister());
    }

    public function testSchema(): void
    {
        $schema = 456;
        $this->objectEntity->setSchema($schema);
        $this->assertEquals($schema, $this->objectEntity->getSchema());
    }

    public function testOrganisation(): void
    {
        $organisation = 789;
        $this->objectEntity->setOrganisation($organisation);
        $this->assertEquals($organisation, $this->objectEntity->getOrganisation());
    }

    public function testCreated(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $this->objectEntity->setCreated($created);
        $this->assertEquals($created, $this->objectEntity->getCreated());
    }

    public function testUpdated(): void
    {
        $updated = new DateTime('2024-01-02 00:00:00');
        $this->objectEntity->setUpdated($updated);
        $this->assertEquals($updated, $this->objectEntity->getUpdated());
    }

    public function testJsonSerialize(): void
    {
        $this->objectEntity->setUuid('test-uuid');
        $this->objectEntity->setName('Test Object');
        $this->objectEntity->setDescription('Test Description');
        $this->objectEntity->setRegister(123);
        $this->objectEntity->setSchema(456);
        
        $json = $this->objectEntity->jsonSerialize();
        
        $this->assertIsArray($json);
        $this->assertArrayHasKey('@self', $json);
        $this->assertEquals('Test Object', $json['@self']['name']);
        $this->assertEquals('Test Description', $json['@self']['description']);
        $this->assertEquals(123, $json['@self']['register']);
        $this->assertEquals(456, $json['@self']['schema']);
    }

    /**
     * Test __toString method with UUID
     */
    public function testToStringWithUuid(): void
    {
        $uuid = 'test-uuid-123';
        $this->objectEntity->setUuid($uuid);

        $result = (string) $this->objectEntity;

        $this->assertEquals($uuid, $result);
    }

    /**
     * Test __toString method with ID but no UUID
     */
    public function testToStringWithIdButNoUuid(): void
    {
        $id = 123;
        $this->objectEntity->setId($id);

        $result = (string) $this->objectEntity;

        $this->assertEquals('Object #123', $result);
    }

    /**
     * Test __toString method with empty UUID and ID
     */
    public function testToStringWithEmptyUuidAndId(): void
    {
        $this->objectEntity->setUuid('');
        $this->objectEntity->setId(null);

        $result = (string) $this->objectEntity;

        $this->assertEquals('Object Entity', $result);
    }

    /**
     * Test __toString method with null UUID and ID
     */
    public function testToStringWithNullUuidAndId(): void
    {
        $this->objectEntity->setUuid(null);
        $this->objectEntity->setId(null);

        $result = (string) $this->objectEntity;

        $this->assertEquals('Object Entity', $result);
    }

    /**
     * Test __toString method with UUID taking precedence
     */
    public function testToStringWithUuidTakingPrecedence(): void
    {
        $uuid = 'test-uuid-456';
        $id = 789;
        
        $this->objectEntity->setUuid($uuid);
        $this->objectEntity->setId($id);

        $result = (string) $this->objectEntity;

        $this->assertEquals($uuid, $result);
    }

    /**
     * Test __toString method with whitespace UUID
     */
    public function testToStringWithWhitespaceUuid(): void
    {
        $this->objectEntity->setUuid('   ');
        $this->objectEntity->setId(456);

        $result = (string) $this->objectEntity;

        // The actual implementation doesn't trim whitespace, so it returns the UUID as-is
        $this->assertEquals('   ', $result);
    }

    /**
     * Test __toString method with zero ID
     */
    public function testToStringWithZeroId(): void
    {
        $this->objectEntity->setUuid('');
        $this->objectEntity->setId(0);

        $result = (string) $this->objectEntity;

        $this->assertEquals('Object #0', $result);
    }

    /**
     * Test __toString method with negative ID
     */
    public function testToStringWithNegativeId(): void
    {
        $this->objectEntity->setUuid('');
        $this->objectEntity->setId(-1);

        $result = (string) $this->objectEntity;

        $this->assertEquals('Object #-1', $result);
    }

    /**
     * Test __toString method with very long UUID
     */
    public function testToStringWithVeryLongUuid(): void
    {
        $longUuid = str_repeat('a', 1000);
        $this->objectEntity->setUuid($longUuid);

        $result = (string) $this->objectEntity;

        $this->assertEquals($longUuid, $result);
    }

    /**
     * Test __toString method with special characters in UUID
     */
    public function testToStringWithSpecialCharactersInUuid(): void
    {
        $specialUuid = 'test-uuid-123!@#$%^&*()';
        $this->objectEntity->setUuid($specialUuid);

        $result = (string) $this->objectEntity;

        $this->assertEquals($specialUuid, $result);
    }

    /**
     * Test getFiles method
     */
    public function testGetFiles(): void
    {
        $files = ['file1.pdf', 'file2.docx'];
        $this->objectEntity->setFiles($files);

        $result = $this->objectEntity->getFiles();

        $this->assertEquals($files, $result);
    }

    /**
     * Test getRelations method
     */
    public function testGetRelations(): void
    {
        $relations = ['relation1', 'relation2'];
        $this->objectEntity->setRelations($relations);

        $result = $this->objectEntity->getRelations();

        $this->assertEquals($relations, $result);
    }

    /**
     * Test getLocked method
     */
    public function testGetLocked(): void
    {
        $locked = ['locked_by' => 'user123', 'locked_at' => '2024-01-01 12:00:00'];
        $this->objectEntity->setLocked($locked);

        $result = $this->objectEntity->getLocked();

        $this->assertEquals($locked, $result);
    }

    /**
     * Test getAuthorization method
     */
    public function testGetAuthorization(): void
    {
        $authorization = ['role' => 'admin', 'permissions' => ['read', 'write']];
        $this->objectEntity->setAuthorization($authorization);

        $result = $this->objectEntity->getAuthorization();

        $this->assertEquals($authorization, $result);
    }

    /**
     * Test getDeleted method
     */
    public function testGetDeleted(): void
    {
        $deleted = ['deleted_by' => 'user123', 'deleted_at' => '2024-01-01 12:00:00'];
        $this->objectEntity->setDeleted($deleted);

        $result = $this->objectEntity->getDeleted();

        $this->assertEquals($deleted, $result);
    }

    /**
     * Test getValidation method
     */
    public function testGetValidation(): void
    {
        $validation = ['status' => 'valid', 'errors' => []];
        $this->objectEntity->setValidation($validation);

        $result = $this->objectEntity->getValidation();

        $this->assertEquals($validation, $result);
    }

    /**
     * Test getJsonFields method - removed as this property doesn't exist
     */
    public function testGetJsonFields(): void
    {
        // This test is skipped as jsonFields property doesn't exist in ObjectEntity
        $this->markTestSkipped('jsonFields property does not exist in ObjectEntity');
    }

    /**
     * Test hydrate method
     */
    public function testHydrate(): void
    {
        $data = [
            'name' => 'Test Object',
            'description' => 'Test Description',
            'uuid' => 'test-uuid-123'
        ];

        $this->objectEntity->hydrate($data);

        $this->assertEquals('Test Object', $this->objectEntity->getName());
        $this->assertEquals('Test Description', $this->objectEntity->getDescription());
        $this->assertEquals('test-uuid-123', $this->objectEntity->getUuid());
    }

    /**
     * Test hydrateObject method
     */
    public function testHydrateObject(): void
    {
        $data = [
            '@self' => [
                'name' => 'Test Object',
                'description' => 'Test Description'
            ]
        ];

        $this->objectEntity->hydrateObject($data);

        $this->assertEquals('Test Object', $this->objectEntity->getName());
        $this->assertEquals('Test Description', $this->objectEntity->getDescription());
    }

    /**
     * Test getObjectArray method
     */
    public function testGetObjectArray(): void
    {
        $this->objectEntity->setUuid('test-uuid');
        $this->objectEntity->setName('Test Object');
        $this->objectEntity->setDescription('Test Description');

        $result = $this->objectEntity->getObjectArray();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('description', $result);
    }

    /**
     * Test lock method
     */
    public function testLock(): void
    {
        $userSession = $this->createMock(\OCP\IUserSession::class);
        $user = $this->createMock(\OCP\IUser::class);
        $userSession->method('getUser')->willReturn($user);
        
        $result = $this->objectEntity->lock($userSession);

        $this->assertIsBool($result);
    }

    /**
     * Test unlock method
     */
    public function testUnlock(): void
    {
        $userSession = $this->createMock(\OCP\IUserSession::class);
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('user123');
        $userSession->method('getUser')->willReturn($user);
        
        $this->objectEntity->setLocked(['user' => 'user123', 'locked_by' => 'user123', 'expiration' => date('Y-m-d H:i:s', time() + 3600)]);
        $result = $this->objectEntity->unlock($userSession);

        $this->assertIsBool($result);
    }

    /**
     * Test isLocked method
     */
    public function testIsLocked(): void
    {
        $this->objectEntity->setLocked(['locked_by' => 'user123', 'expiration' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->assertTrue($this->objectEntity->isLocked());

        $this->objectEntity->setLocked(null);
        $this->assertFalse($this->objectEntity->isLocked());
    }

    /**
     * Test getLockInfo method - removed as this property doesn't exist
     */
    public function testGetLockInfo(): void
    {
        // This test is skipped as lockInfo property doesn't exist in ObjectEntity
        $this->markTestSkipped('lockInfo property does not exist in ObjectEntity');
    }

    /**
     * Test delete method
     */
    public function testDelete(): void
    {
        $userSession = $this->createMock(\OCP\IUserSession::class);
        $user = $this->createMock(\OCP\IUser::class);
        $userSession->method('getUser')->willReturn($user);
        
        $result = $this->objectEntity->delete($userSession);

        $this->assertInstanceOf(\OCA\OpenRegister\Db\ObjectEntity::class, $result);
    }

    /**
     * Test getLastLog method
     */
    public function testGetLastLog(): void
    {
        $lastLog = ['action' => 'created', 'timestamp' => '2024-01-01 12:00:00'];
        $this->objectEntity->setLastLog($lastLog);

        $result = $this->objectEntity->getLastLog();

        $this->assertEquals($lastLog, $result);
    }

    /**
     * Test setLastLog method
     */
    public function testSetLastLog(): void
    {
        $lastLog = ['action' => 'updated', 'timestamp' => '2024-01-01 13:00:00'];
        
        $this->objectEntity->setLastLog($lastLog);

        $this->assertEquals($lastLog, $this->objectEntity->getLastLog());
    }
}
