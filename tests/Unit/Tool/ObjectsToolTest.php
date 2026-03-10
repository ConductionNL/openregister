<?php

namespace Unit\Tool;

use DateTime;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tool\ObjectsTool;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ObjectsToolTest extends TestCase
{
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private ObjectService&MockObject $objectService;
    private ObjectsTool $tool;

    protected function setUp(): void
    {
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->objectService = $this->createMock(ObjectService::class);

        $this->tool = new ObjectsTool(
            $this->userSession,
            $this->logger,
            $this->objectService
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function createObjectEntity(
        int $id,
        string $uuid,
        string $register,
        string $schema,
        array $object,
        ?string $organisation = null,
        ?string $owner = null
    ): ObjectEntity {
        $entity = new ObjectEntity();
        $entity->setId($id);
        $entity->setUuid($uuid);
        $entity->setRegister($register);
        $entity->setSchema($schema);
        $entity->setObject($object);
        if ($organisation !== null) {
            $entity->setOrganisation($organisation);
        }
        if ($owner !== null) {
            $entity->setOwner($owner);
        }
        $entity->setCreated(new DateTime('2024-01-01 12:00:00'));
        $entity->setUpdated(new DateTime('2024-01-02 12:00:00'));
        return $entity;
    }

    // ------------------------------------------------------------------
    // getName / getDescription / getFunctions
    // ------------------------------------------------------------------

    public function testGetName(): void
    {
        $this->assertSame('objects', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $desc = $this->tool->getDescription();
        $this->assertStringContainsString('objects', strtolower($desc));
    }

    public function testGetFunctionsContainsAllCrud(): void
    {
        $functions = $this->tool->getFunctions();
        $names     = array_column($functions, 'name');
        $this->assertContains('search_objects', $names);
        $this->assertContains('get_object', $names);
        $this->assertContains('create_object', $names);
        $this->assertContains('update_object', $names);
        $this->assertContains('delete_object', $names);
        $this->assertCount(5, $functions);
    }

    public function testGetFunctionsStructure(): void
    {
        foreach ($this->tool->getFunctions() as $fn) {
            $this->assertArrayHasKey('name', $fn);
            $this->assertArrayHasKey('description', $fn);
            $this->assertArrayHasKey('parameters', $fn);
            $this->assertArrayHasKey('properties', $fn['parameters']);
            $this->assertArrayHasKey('required', $fn['parameters']);
        }
    }

    // ------------------------------------------------------------------
    // executeFunction — no user context
    // ------------------------------------------------------------------

    public function testExecuteFunctionNoUserContext(): void
    {
        $noUserSession = $this->createMock(IUserSession::class);
        $noUserSession->method('getUser')->willReturn(null);

        $tool = new ObjectsTool($noUserSession, $this->logger, $this->objectService);

        $result = $tool->executeFunction('search_objects', []);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No user context', $result['error']);
    }

    // ------------------------------------------------------------------
    // executeFunction — unknown function
    // ------------------------------------------------------------------

    public function testExecuteFunctionUnknownFunction(): void
    {
        $result = $this->tool->executeFunction('non_existent', []);
        $this->assertFalse($result['success']);
    }

    // ------------------------------------------------------------------
    // searchObjects
    // ------------------------------------------------------------------

    public function testSearchObjectsSuccess(): void
    {
        $obj = $this->createObjectEntity(1, 'uuid-1', '10', '20', ['name' => 'Test']);

        $this->objectService->method('findAll')->willReturn([
            'results' => [$obj],
            'total'   => 1,
        ]);

        $result = $this->tool->searchObjects();
        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['objects']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('uuid-1', $result['data']['objects'][0]['uuid']);
    }

    public function testSearchObjectsWithFilters(): void
    {
        $this->objectService->expects($this->once())
            ->method('findAll')
            ->with($this->callback(function ($config) {
                return $config['limit'] === 5
                    && $config['offset'] === 10
                    && $config['filters']['register'] === '1'
                    && $config['filters']['schema'] === '2'
                    && $config['filters']['_search'] === 'query';
            }))
            ->willReturn(['results' => [], 'total' => 0]);

        $result = $this->tool->searchObjects(5, 10, '1', '2', 'query');
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['total']);
    }

    public function testSearchObjectsEmptyQuery(): void
    {
        $this->objectService->expects($this->once())
            ->method('findAll')
            ->with($this->callback(function ($config) {
                return !isset($config['filters']['_search']);
            }))
            ->willReturn(['results' => [], 'total' => 0]);

        $this->tool->searchObjects(20, 0, null, null, '');
    }

    public function testSearchObjectsNullResultsKey(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $result = $this->tool->searchObjects();
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['total']);
    }

    public function testSearchObjectsViaExecuteFunction(): void
    {
        $this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

        $result = $this->tool->executeFunction('search_objects', []);
        $this->assertTrue($result['success']);
    }

    // ------------------------------------------------------------------
    // getObject
    // ------------------------------------------------------------------

    public function testGetObjectSuccess(): void
    {
        $obj = $this->createObjectEntity(1, 'uuid-1', '10', '20', ['name' => 'Test'], 'org-1', 'owner-1');
        $this->objectService->method('find')->willReturn($obj);

        $result = $this->tool->getObject('1');
        $this->assertTrue($result['success']);
        $this->assertSame('uuid-1', $result['data']['uuid']);
        $this->assertSame('org-1', $result['data']['organisation']);
        $this->assertSame('owner-1', $result['data']['owner']);
    }

    public function testGetObjectNotFound(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $this->tool->getObject('999');
    }

    public function testGetObjectNotFoundViaExecuteFunction(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $result = $this->tool->executeFunction('get_object', ['999']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    // ------------------------------------------------------------------
    // createObject
    // ------------------------------------------------------------------

    public function testCreateObjectSuccess(): void
    {
        $obj = $this->createObjectEntity(1, 'new-uuid', '10', '20', ['name' => 'New']);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturn($obj);

        $result = $this->tool->createObject('10', '20', ['name' => 'New']);
        $this->assertTrue($result['success']);
        $this->assertSame('new-uuid', $result['data']['uuid']);
        $this->assertStringContainsString('created', $result['message']);
    }

    public function testCreateObjectException(): void
    {
        $this->objectService->method('saveObject')
            ->willThrowException(new \Exception('Validation failed'));

        $result = $this->tool->executeFunction('create_object', ['10', '20', ['bad' => 'data']]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Validation failed', $result['error']);
    }

    // ------------------------------------------------------------------
    // updateObject
    // ------------------------------------------------------------------

    public function testUpdateObjectSuccess(): void
    {
        $existing = $this->createObjectEntity(1, 'uuid-1', '10', '20', ['name' => 'Old']);
        $updated  = $this->createObjectEntity(1, 'uuid-1', '10', '20', ['name' => 'Updated']);

        $this->objectService->method('find')->willReturn($existing);
        $this->objectService->method('saveObject')->willReturn($updated);

        $result = $this->tool->updateObject('1', ['name' => 'Updated']);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('updated', $result['message']);
    }

    public function testUpdateObjectException(): void
    {
        $this->objectService->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->tool->executeFunction('update_object', ['999', ['x' => 1]]);
        $this->assertFalse($result['success']);
    }

    // ------------------------------------------------------------------
    // deleteObject
    // ------------------------------------------------------------------

    public function testDeleteObjectSuccess(): void
    {
        $obj = $this->createObjectEntity(1, 'uuid-1', '10', '20', []);
        $this->objectService->method('find')->willReturn($obj);
        $this->objectService->expects($this->once())->method('deleteObject');

        $result = $this->tool->deleteObject('1');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('deleted', $result['message']);
    }

    public function testDeleteObjectNotFound(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->tool->deleteObject('999');
    }

    public function testDeleteObjectNotFoundViaExecuteFunction(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $result = $this->tool->executeFunction('delete_object', ['999']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testDeleteObjectUsesIdWhenUuidNull(): void
    {
        $entity = new ObjectEntity();
        $entity->setId(42);
        // UUID is null by default.

        $this->objectService->method('find')->willReturn($entity);
        $this->objectService->expects($this->once())
            ->method('deleteObject')
            ->with('42');

        $this->tool->deleteObject('42');
    }

    // ------------------------------------------------------------------
    // setAgent
    // ------------------------------------------------------------------

    public function testSetAgentAppliesViewFilters(): void
    {
        $agent = new Agent();
        $agent->setViews([1, 2]);
        $this->tool->setAgent($agent);

        $this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);
        $result = $this->tool->searchObjects();
        $this->assertTrue($result['success']);
    }
}
