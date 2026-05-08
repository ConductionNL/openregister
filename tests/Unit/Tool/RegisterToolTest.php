<?php

namespace Unit\Tool;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Tool\RegisterTool;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegisterToolTest extends TestCase
{
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private RegisterService&MockObject $registerService;
    private RegisterTool $tool;

    protected function setUp(): void
    {
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->registerService = $this->createMock(RegisterService::class);

        $this->tool = new RegisterTool(
            $this->userSession,
            $this->logger,
            $this->registerService
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function createRegisterEntity(
        int $id,
        string $uuid,
        string $title,
        string $description = '',
        ?string $slug = null,
        ?string $folder = null,
        ?string $organisation = null
    ): Register {
        $entity = new Register();
        $entity->setId($id);
        $entity->setUuid($uuid);
        $entity->setTitle($title);
        $entity->setDescription($description);
        if ($slug !== null) {
            $entity->setSlug($slug);
        }
        if ($folder !== null) {
            $entity->setFolder($folder);
        }
        if ($organisation !== null) {
            $entity->setOrganisation($organisation);
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
        $this->assertSame('register', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertStringContainsString('register', strtolower($this->tool->getDescription()));
    }

    public function testGetFunctionsContainsAllCrud(): void
    {
        $functions = $this->tool->getFunctions();
        $names     = array_column($functions, 'name');
        $this->assertContains('list_registers', $names);
        $this->assertContains('get_register', $names);
        $this->assertContains('create_register', $names);
        $this->assertContains('update_register', $names);
        $this->assertContains('delete_register', $names);
        $this->assertCount(5, $functions);
    }

    public function testGetFunctionsStructure(): void
    {
        foreach ($this->tool->getFunctions() as $fn) {
            $this->assertArrayHasKey('name', $fn);
            $this->assertArrayHasKey('description', $fn);
            $this->assertArrayHasKey('parameters', $fn);
        }
    }

    // ------------------------------------------------------------------
    // executeFunction — no user context
    // ------------------------------------------------------------------

    public function testExecuteFunctionNoUserContext(): void
    {
        $noUserSession = $this->createMock(IUserSession::class);
        $noUserSession->method('getUser')->willReturn(null);
        $tool = new RegisterTool($noUserSession, $this->logger, $this->registerService);

        $result = $tool->executeFunction('list_registers', []);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No user context', $result['error']);
    }

    public function testExecuteFunctionUnknownFunction(): void
    {
        $result = $this->tool->executeFunction('non_existent', []);
        $this->assertFalse($result['success']);
    }

    // ------------------------------------------------------------------
    // listRegisters
    // ------------------------------------------------------------------

    public function testListRegistersSuccess(): void
    {
        $reg = $this->createRegisterEntity(1, 'uuid-1', 'My Register', 'Desc', 'my-register');

        $this->registerService->method('findAll')->willReturn([$reg]);

        $result = $this->tool->listRegisters();
        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('uuid-1', $result['data'][0]['uuid']);
        $this->assertSame('My Register', $result['data'][0]['title']);
        $this->assertStringContainsString('1 registers', $result['message']);
    }

    public function testListRegistersEmpty(): void
    {
        $this->registerService->method('findAll')->willReturn([]);

        $result = $this->tool->listRegisters();
        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['data']);
    }

    public function testListRegistersWithPagination(): void
    {
        $this->registerService->expects($this->once())
            ->method('findAll')
            ->with(5, 10, $this->anything());

        $this->tool->listRegisters(5, 10);
    }

    public function testListRegistersViaExecuteFunction(): void
    {
        $this->registerService->method('findAll')->willReturn([]);

        $result = $this->tool->executeFunction('list_registers', []);
        $this->assertTrue($result['success']);
    }

    // ------------------------------------------------------------------
    // getRegister
    // ------------------------------------------------------------------

    public function testGetRegisterSuccess(): void
    {
        $reg = $this->createRegisterEntity(1, 'uuid-1', 'Reg', 'Desc', 'reg', '/folder', 'org-1');
        $this->registerService->method('find')->willReturn($reg);

        $result = $this->tool->getRegister('1');
        $this->assertTrue($result['success']);
        $this->assertSame('uuid-1', $result['data']['uuid']);
        $this->assertSame('/folder', $result['data']['folder']);
        $this->assertSame('org-1', $result['data']['organisation']);
        $this->assertStringContainsString('retrieved', $result['message']);
    }

    public function testGetRegisterExceptionViaExecuteFunction(): void
    {
        $this->registerService->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->tool->executeFunction('get_register', ['999']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Not found', $result['error']);
    }

    // ------------------------------------------------------------------
    // createRegister
    // ------------------------------------------------------------------

    public function testCreateRegisterSuccess(): void
    {
        $reg = $this->createRegisterEntity(1, 'new-uuid', 'New Reg', 'Desc', 'new-reg');
        $this->registerService->method('createFromArray')->willReturn($reg);

        $result = $this->tool->createRegister('New Reg', 'Desc', 'new-reg');
        $this->assertTrue($result['success']);
        $this->assertSame('new-uuid', $result['data']['uuid']);
        $this->assertStringContainsString('created', $result['message']);
    }

    public function testCreateRegisterWithoutSlug(): void
    {
        $reg = $this->createRegisterEntity(1, 'uuid', 'Title');

        $this->registerService->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return $data['title'] === 'Title'
                    && $data['description'] === ''
                    && !isset($data['slug']);
            }))
            ->willReturn($reg);

        $this->tool->createRegister('Title');
    }

    public function testCreateRegisterWithSlug(): void
    {
        $reg = $this->createRegisterEntity(1, 'uuid', 'Title', '', 'my-slug');

        $this->registerService->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return $data['slug'] === 'my-slug';
            }))
            ->willReturn($reg);

        $this->tool->createRegister('Title', '', 'my-slug');
    }

    public function testCreateRegisterException(): void
    {
        $this->registerService->method('createFromArray')
            ->willThrowException(new \Exception('Duplicate'));

        $result = $this->tool->executeFunction('create_register', ['Dup']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Duplicate', $result['error']);
    }

    // ------------------------------------------------------------------
    // updateRegister
    // ------------------------------------------------------------------

    public function testUpdateRegisterSuccess(): void
    {
        $reg = $this->createRegisterEntity(1, 'uuid', 'Updated', 'New Desc', 'slug');
        $this->registerService->method('updateFromArray')->willReturn($reg);

        $result = $this->tool->updateRegister('1', 'Updated', 'New Desc');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('updated', $result['message']);
    }

    public function testUpdateRegisterTitleOnly(): void
    {
        $reg = $this->createRegisterEntity(1, 'uuid', 'Title', 'Desc', 'slug');

        $this->registerService->expects($this->once())
            ->method('updateFromArray')
            ->with(1, $this->callback(function ($data) {
                return isset($data['title']) && !isset($data['description']);
            }))
            ->willReturn($reg);

        $this->tool->updateRegister('1', 'Title');
    }

    public function testUpdateRegisterNoData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No update data provided');
        $this->tool->updateRegister('1');
    }

    public function testUpdateRegisterNoDataViaExecuteFunction(): void
    {
        $result = $this->tool->executeFunction('update_register', ['1']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No update data', $result['error']);
    }

    public function testUpdateRegisterException(): void
    {
        $this->registerService->method('updateFromArray')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->tool->executeFunction('update_register', ['1', 'x']);
        $this->assertFalse($result['success']);
    }

    // ------------------------------------------------------------------
    // deleteRegister
    // ------------------------------------------------------------------

    public function testDeleteRegisterSuccess(): void
    {
        $reg = $this->createRegisterEntity(1, 'uuid', 'Reg');
        $this->registerService->method('find')->willReturn($reg);
        $this->registerService->expects($this->once())->method('delete');

        $result = $this->tool->deleteRegister('1');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('deleted', $result['message']);
        $this->assertSame('1', $result['data']['id']);
    }

    public function testDeleteRegisterException(): void
    {
        $this->registerService->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->tool->executeFunction('delete_register', ['999']);
        $this->assertFalse($result['success']);
    }

    // ------------------------------------------------------------------
    // executeFunction with userId
    // ------------------------------------------------------------------

    public function testExecuteFunctionWithExplicitUserId(): void
    {
        $noUserSession = $this->createMock(IUserSession::class);
        $noUserSession->method('getUser')->willReturn(null);
        $tool = new RegisterTool($noUserSession, $this->logger, $this->registerService);

        $this->registerService->method('findAll')->willReturn([]);

        $result = $tool->executeFunction('list_registers', [], 'explicit-user');
        $this->assertTrue($result['success']);
    }
}
