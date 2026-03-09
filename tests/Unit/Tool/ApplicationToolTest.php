<?php

namespace Unit\Tool;

use BadMethodCallException;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\ApplicationMapper;
use OCA\OpenRegister\Tool\ApplicationTool;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApplicationToolTest extends TestCase
{
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private ApplicationMapper&MockObject $applicationMapper;
    private ApplicationTool $tool;

    protected function setUp(): void
    {
        $this->userSession       = $this->createMock(IUserSession::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->applicationMapper = $this->createMock(ApplicationMapper::class);

        $this->tool = new ApplicationTool(
            $this->applicationMapper,
            $this->userSession,
            $this->logger
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function createApplicationEntity(
        string $uuid,
        string $name,
        ?string $description = null
    ): Application {
        $entity = new Application();
        $entity->setUuid($uuid);
        $entity->setName($name);
        if ($description !== null) {
            $entity->setDescription($description);
        }
        return $entity;
    }

    // ------------------------------------------------------------------
    // getName / getDescription / getFunctions
    // ------------------------------------------------------------------

    public function testGetName(): void
    {
        $this->assertSame('Application Management', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertStringContainsString('application', strtolower($this->tool->getDescription()));
    }

    public function testGetFunctionsContainsAllCrud(): void
    {
        $functions = $this->tool->getFunctions();
        $names     = array_column($functions, 'name');
        $this->assertContains('list_applications', $names);
        $this->assertContains('get_application', $names);
        $this->assertContains('create_application', $names);
        $this->assertContains('update_application', $names);
        $this->assertContains('delete_application', $names);
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
    // executeFunction
    // ------------------------------------------------------------------

    public function testExecuteFunctionCallsCorrectMethod(): void
    {
        $this->applicationMapper->method('findAll')->willReturn([]);
        $this->applicationMapper->method('countAll')->willReturn(0);

        $result = $this->tool->executeFunction('list_applications', []);
        $this->assertTrue($result['success']);
    }

    public function testExecuteFunctionUnknownMethodThrows(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->tool->executeFunction('non_existent', []);
    }

    // ------------------------------------------------------------------
    // listApplications
    // ------------------------------------------------------------------

    public function testListApplicationsSuccess(): void
    {
        $app = $this->createApplicationEntity('uuid-1', 'MyApp', 'Desc');
        $this->applicationMapper->method('findAll')->willReturn([$app]);
        $this->applicationMapper->method('countAll')->willReturn(1);

        $result = $this->tool->listApplications();
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertCount(1, $result['data']['applications']);
        $this->assertSame(50, $result['data']['limit']);
        $this->assertSame(0, $result['data']['offset']);
    }

    public function testListApplicationsWithPagination(): void
    {
        $this->applicationMapper->expects($this->once())
            ->method('findAll')
            ->with(10, 5)
            ->willReturn([]);
        $this->applicationMapper->method('countAll')->willReturn(0);

        $result = $this->tool->listApplications(10, 5);
        $this->assertTrue($result['success']);
        $this->assertSame(10, $result['data']['limit']);
        $this->assertSame(5, $result['data']['offset']);
    }

    public function testListApplicationsEmpty(): void
    {
        $this->applicationMapper->method('findAll')->willReturn([]);
        $this->applicationMapper->method('countAll')->willReturn(0);

        $result = $this->tool->listApplications();
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['total']);
    }

    public function testListApplicationsException(): void
    {
        $this->applicationMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->tool->listApplications();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('DB error', $result['error']);
    }

    // ------------------------------------------------------------------
    // getApplication
    // ------------------------------------------------------------------

    public function testGetApplicationSuccess(): void
    {
        $app = $this->createApplicationEntity('uuid-1', 'App', 'Desc');
        $this->applicationMapper->method('findByUuid')->willReturn($app);

        $result = $this->tool->getApplication('uuid-1');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('App', $result['message']);
    }

    public function testGetApplicationNotFound(): void
    {
        $this->applicationMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->tool->getApplication('bad-uuid');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testGetApplicationGenericException(): void
    {
        $this->applicationMapper->method('findByUuid')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->tool->getApplication('uuid');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('DB error', $result['error']);
    }

    // ------------------------------------------------------------------
    // createApplication
    // ------------------------------------------------------------------

    public function testCreateApplicationSuccess(): void
    {
        $app = $this->createApplicationEntity('new-uuid', 'NewApp', 'Desc');

        $this->applicationMapper->expects($this->once())
            ->method('insert')
            ->willReturn($app);

        $result = $this->tool->createApplication('NewApp', 'Desc', 'example.com');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('NewApp', $result['message']);
        $this->assertStringContainsString('new-uuid', $result['message']);
    }

    public function testCreateApplicationMinimalParams(): void
    {
        $app = $this->createApplicationEntity('uuid', 'SimpleApp');
        $this->applicationMapper->method('insert')->willReturn($app);

        $result = $this->tool->createApplication('SimpleApp');
        $this->assertTrue($result['success']);
    }

    public function testCreateApplicationWithEmptyDescription(): void
    {
        $app = $this->createApplicationEntity('uuid', 'App');
        $this->applicationMapper->method('insert')->willReturn($app);

        $result = $this->tool->createApplication('App', '');
        $this->assertTrue($result['success']);
    }

    public function testCreateApplicationException(): void
    {
        $this->applicationMapper->method('insert')
            ->willThrowException(new \Exception('Duplicate name'));

        $result = $this->tool->createApplication('Dup');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Duplicate name', $result['error']);
    }

    // ------------------------------------------------------------------
    // updateApplication
    // ------------------------------------------------------------------

    public function testUpdateApplicationAllFields(): void
    {
        $app = $this->createApplicationEntity('uuid-1', 'Old', 'Old desc');

        $this->applicationMapper->method('findByUuid')->willReturn($app);
        $this->applicationMapper->method('update')->willReturnCallback(function ($entity) {
            return $entity;
        });

        $result = $this->tool->updateApplication('uuid-1', 'New', 'New desc');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('updated', $result['message']);
    }

    public function testUpdateApplicationNoFields(): void
    {
        $app = $this->createApplicationEntity('uuid-1', 'Name', 'Desc');

        $this->applicationMapper->method('findByUuid')->willReturn($app);
        $this->applicationMapper->method('update')->willReturnCallback(function ($entity) {
            return $entity;
        });

        $result = $this->tool->updateApplication('uuid-1');
        $this->assertTrue($result['success']);
    }

    public function testUpdateApplicationNotFound(): void
    {
        $this->applicationMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->tool->updateApplication('bad-uuid', 'x');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testUpdateApplicationGenericException(): void
    {
        $this->applicationMapper->method('findByUuid')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->tool->updateApplication('uuid');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('DB error', $result['error']);
    }

    // ------------------------------------------------------------------
    // deleteApplication
    // ------------------------------------------------------------------

    public function testDeleteApplicationSuccess(): void
    {
        $app = $this->createApplicationEntity('uuid-1', 'App');
        $this->applicationMapper->method('findByUuid')->willReturn($app);
        $this->applicationMapper->expects($this->once())->method('delete');

        $result = $this->tool->deleteApplication('uuid-1');
        $this->assertTrue($result['success']);
        $this->assertSame('uuid-1', $result['data']['uuid']);
        $this->assertStringContainsString('App', $result['message']);
    }

    public function testDeleteApplicationNotFound(): void
    {
        $this->applicationMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->tool->deleteApplication('bad-uuid');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testDeleteApplicationGenericException(): void
    {
        $this->applicationMapper->method('findByUuid')
            ->willThrowException(new \Exception('FK constraint'));

        $result = $this->tool->deleteApplication('uuid');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('FK constraint', $result['error']);
    }

    // ------------------------------------------------------------------
    // executeFunction routing
    // ------------------------------------------------------------------

    public function testExecuteFunctionGetApplication(): void
    {
        $app = $this->createApplicationEntity('uuid-1', 'App', 'Desc');
        $this->applicationMapper->method('findByUuid')->willReturn($app);

        $result = $this->tool->executeFunction('get_application', ['uuid-1']);
        $this->assertTrue($result['success']);
    }

    public function testExecuteFunctionCreateApplication(): void
    {
        $app = $this->createApplicationEntity('uuid', 'NewApp');
        $this->applicationMapper->method('insert')->willReturn($app);

        $result = $this->tool->executeFunction('create_application', ['NewApp']);
        $this->assertTrue($result['success']);
    }

    public function testExecuteFunctionDeleteApplication(): void
    {
        $app = $this->createApplicationEntity('uuid-1', 'App');
        $this->applicationMapper->method('findByUuid')->willReturn($app);

        $result = $this->tool->executeFunction('delete_application', ['uuid-1']);
        $this->assertTrue($result['success']);
    }
}
