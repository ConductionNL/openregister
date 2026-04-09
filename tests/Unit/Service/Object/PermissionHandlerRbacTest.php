<?php

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PermissionHandler register cascade, role expansion, and manage action.
 */
class PermissionHandlerRbacTest extends TestCase
{
    private PermissionHandler $handler;
    private IUserSession&MockObject $userSession;
    private IUserManager&MockObject $userManager;
    private IGroupManager&MockObject $groupManager;
    private SchemaMapper&MockObject $schemaMapper;
    private MagicMapper&MockObject $objectEntityMapper;
    private LoggerInterface&MockObject $logger;
    private ContainerInterface&MockObject $container;
    private RegisterMapper&MockObject $registerMapper;

    protected function setUp(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);

        $this->handler = new PermissionHandler(
            $this->userSession,
            $this->userManager,
            $this->groupManager,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->logger,
            $this->container
        );
    }

    private function mockUser(string $uid, array $groups): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn($groups);
        return $user;
    }

    private function createSchema(int $id, ?array $authorization): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setAuthorization($authorization);
        $schema->setTitle('Test Schema ' . $id);
        return $schema;
    }

    private function createRegister(int $id, ?array $authorization, ?array $configuration = null): Register
    {
        $register = new Register();
        $register->setId($id);
        $register->setAuthorization($authorization);
        $register->setConfiguration($configuration ?? []);
        return $register;
    }

    private function setupRegisterForSchema(int $schemaId, Register $register): void
    {
        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($register) {
                if ($class === RegisterMapper::class) {
                    return $this->registerMapper;
                }
                if ($class === 'OCA\OpenRegister\Service\OrganisationService') {
                    throw new \RuntimeException('Not available');
                }
                throw new \RuntimeException('Unknown class: ' . $class);
            });

        $this->registerMapper->method('getFirstRegisterWithSchema')
            ->willReturn($register->getId());
        $this->registerMapper->method('find')
            ->willReturn($register);
    }

    // === Register Cascade Tests ===

    public function testSchemaAuthorizationOverridesRegister(): void
    {
        $this->mockUser('user1', ['behandelaars']);

        $schema = $this->createSchema(1, [
            'read' => ['behandelaars'],
            'create' => ['admin'],
        ]);

        $register = $this->createRegister(10, [
            'read' => ['public'],
            'create' => ['public'],
        ]);

        $this->setupRegisterForSchema(1, $register);

        // Schema says behandelaars can read, register says public can read.
        // Schema overrides: user in behandelaars should be able to read.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));

        // Schema says only admin can create, not behandelaars.
        $this->assertFalse($this->handler->hasPermission($schema, 'create'));
    }

    public function testRegisterFallbackWhenSchemaHasNoAuth(): void
    {
        $this->mockUser('user1', ['medewerkers']);

        // Schema has NO authorization.
        $schema = $this->createSchema(1, null);

        $register = $this->createRegister(10, [
            'read' => ['medewerkers'],
            'create' => ['admin'],
        ]);

        $this->setupRegisterForSchema(1, $register);

        // Should use register authorization: medewerkers can read.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));

        // Register says only admin can create.
        $this->assertFalse($this->handler->hasPermission($schema, 'create'));
    }

    public function testNeitherSchemaNorRegisterHasAuth(): void
    {
        $this->mockUser('user1', ['somegroup']);

        $schema = $this->createSchema(1, null);
        $register = $this->createRegister(10, null);

        $this->setupRegisterForSchema(1, $register);

        // No authorization anywhere = everyone has permission.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
        $this->assertTrue($this->handler->hasPermission($schema, 'create'));
    }

    // === Role Expansion Tests ===

    public function testRoleExpansionViewerRole(): void
    {
        $this->mockUser('user1', ['public']);

        $schema = $this->createSchema(1, [
            'roles' => [
                'viewer' => ['public'],
                'editor' => ['behandelaars'],
            ],
        ]);

        $register = $this->createRegister(10, null, [
            'roles' => [
                ['name' => 'viewer', 'description' => 'Read only', 'actions' => ['read']],
                ['name' => 'editor', 'description' => 'Edit access', 'actions' => ['read', 'create', 'update']],
            ],
        ]);

        $this->setupRegisterForSchema(1, $register);

        // Public group has viewer role => read only.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
        $this->assertFalse($this->handler->hasPermission($schema, 'create'));
    }

    public function testRoleExpansionEditorRole(): void
    {
        $this->mockUser('user1', ['behandelaars']);

        $schema = $this->createSchema(1, [
            'roles' => [
                'viewer' => ['public'],
                'editor' => ['behandelaars'],
            ],
        ]);

        $register = $this->createRegister(10, null, [
            'roles' => [
                ['name' => 'viewer', 'description' => 'Read only', 'actions' => ['read']],
                ['name' => 'editor', 'description' => 'Edit access', 'actions' => ['read', 'create', 'update']],
            ],
        ]);

        $this->setupRegisterForSchema(1, $register);

        // Behandelaars has editor role => read, create, update.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
        $this->assertTrue($this->handler->hasPermission($schema, 'create'));
        $this->assertTrue($this->handler->hasPermission($schema, 'update'));
        $this->assertFalse($this->handler->hasPermission($schema, 'delete'));
    }

    public function testMixedRoleAndDirectAuth(): void
    {
        $this->mockUser('user1', ['extra-groep']);

        $schema = $this->createSchema(1, [
            'roles' => [
                'viewer' => ['public'],
            ],
            'read' => ['extra-groep'],
        ]);

        $register = $this->createRegister(10, null, [
            'roles' => [
                ['name' => 'viewer', 'description' => 'Read only', 'actions' => ['read']],
            ],
        ]);

        $this->setupRegisterForSchema(1, $register);

        // extra-groep has direct read permission.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
    }

    public function testUnknownRoleNameIsIgnored(): void
    {
        $this->mockUser('user1', ['public']);

        $schema = $this->createSchema(1, [
            'roles' => [
                'archiver' => ['public'],
            ],
        ]);

        $register = $this->createRegister(10, null, [
            'roles' => [
                ['name' => 'viewer', 'description' => 'Read only', 'actions' => ['read']],
            ],
        ]);

        $this->setupRegisterForSchema(1, $register);

        // archiver role doesn't exist => warning logged, no permissions granted.
        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // With empty authorization (only unknown roles), all actions should be permitted
        // because the effective authorization ends up empty after role expansion.
        $result = $this->handler->resolveAuthorization($schema);
        $this->assertEmpty($result);
    }

    // === Manage Action Tests ===

    public function testManageActionEvaluated(): void
    {
        $this->mockUser('user1', ['register-beheerders']);

        $schema = $this->createSchema(1, [
            'manage' => ['register-beheerders'],
            'read' => ['public'],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // User in register-beheerders should have manage permission.
        $this->assertTrue($this->handler->hasPermission($schema, 'manage'));
    }

    public function testManageActionDenied(): void
    {
        $this->mockUser('user1', ['behandelaars']);

        $schema = $this->createSchema(1, [
            'manage' => ['register-beheerders'],
            'read' => ['behandelaars'],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // User NOT in register-beheerders should NOT have manage permission.
        $this->assertFalse($this->handler->hasPermission($schema, 'manage'));
        // But should still be able to read.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
    }

    public function testAdminBypassesManageCheck(): void
    {
        $this->mockUser('admin1', ['admin']);

        $schema = $this->createSchema(1, [
            'manage' => ['register-beheerders'],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // Admin always has all permissions.
        $this->assertTrue($this->handler->hasPermission($schema, 'manage'));
    }
}
