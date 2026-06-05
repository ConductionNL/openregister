<?php

/**
 * PermissionHandler role-hierarchy expansion tests.
 *
 * Verifies that schema-level `authorization.roles: {roleName: [groups]}`
 * assignments expand against the parent register's
 * `configuration.roles` definitions, including inheritance via the
 * `extends` keyword and graceful handling of cycles + unknown
 * references.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rbac-scopes/specs/rbac-scopes/spec.md#requirement-role-definitions-and-hierarchy
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class PermissionHandlerRoleHierarchyTest extends TestCase
{

    private PermissionHandler $handler;

    private RegisterMapper&MockObject $registerMapper;

    private LoggerInterface&MockObject $logger;

    /**
     * Build a PermissionHandler with the minimum mock surface needed
     * for the role-hierarchy code path; other dependencies are
     * untouched by `expandRoles()`.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $userSession          = $this->createMock(IUserSession::class);
        $userManager          = $this->createMock(IUserManager::class);
        $groupManager         = $this->createMock(IGroupManager::class);
        $schemaMapper         = $this->createMock(SchemaMapper::class);
        $objectEntityMapper   = $this->createMock(MagicMapper::class);
        $conditionMatcher     = $this->createMock(ConditionMatcher::class);
        $this->logger         = $this->createMock(LoggerInterface::class);
        $container            = $this->createMock(ContainerInterface::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);

        $container->method('get')
            ->willReturnCallback(
                    function (string $service) {
                        if ($service === RegisterMapper::class) {
                            return $this->registerMapper;
                        }

                        return null;
                    }
                    );

        $this->handler = new PermissionHandler(
            $userSession,
            $userManager,
            $groupManager,
            $schemaMapper,
            $objectEntityMapper,
            $conditionMatcher,
            $this->logger,
            $container
        );
    }//end setUp()

    /**
     * Wire up a register that owns the given role definitions and is
     * the parent of $schemaId. Returns the Schema mock.
     *
     * @param array<int, array<string, mixed>> $roleDefinitions Role definitions on the register.
     * @param int                              $schemaId        Schema id used to look up the parent.
     */
    private function makeSchemaWithRegisterRoles(array $roleDefinitions, int $schemaId=1): Schema
    {
        $register = new Register();
        $register->setId(99);
        $register->setTitle('Test Register');
        $register->setAuthorization(null);
        $register->setConfiguration(['roles' => $roleDefinitions]);

        $this->registerMapper->method('getFirstRegisterWithSchema')->willReturn(99);
        $this->registerMapper->method('find')->with(99)->willReturn($register);

        $schema = new Schema();
        $schema->setId($schemaId);
        $schema->setTitle('Test Schema');
        return $schema;
    }//end makeSchemaWithRegisterRoles()

    /**
     * A simple `viewer` role on the register that grants `read`
     * expands to `read: [behandelaars]` when the schema assigns the
     * `behandelaars` group.
     *
     * @return void
     */
    public function testFlatRoleExpansion(): void
    {
        $schema = $this->makeSchemaWithRegisterRoles(
            [
                ['name' => 'viewer', 'actions' => ['read']],
            ]
        );

        $expanded = $this->handler->expandRoles(
            authorization: ['roles' => ['viewer' => ['behandelaars']]],
            schema: $schema
        );

        $this->assertArrayHasKey('read', $expanded);
        $this->assertSame(['behandelaars'], $expanded['read']);
        $this->assertArrayNotHasKey('roles', $expanded);
    }//end testFlatRoleExpansion()

    /**
     * Two roles assigned in one schema both expand and their groups
     * are merged per-action without duplicates.
     *
     * @return void
     */
    public function testMultipleRolesMergeGroups(): void
    {
        $schema = $this->makeSchemaWithRegisterRoles(
            [
                ['name' => 'viewer', 'actions' => ['read']],
                ['name' => 'editor', 'actions' => ['read', 'create', 'update']],
            ]
        );

        $expanded = $this->handler->expandRoles(
            authorization: [
                'roles' => [
                    'viewer' => ['public'],
                    'editor' => ['behandelaars'],
                ],
            ],
            schema: $schema
        );

        $this->assertSame(['public', 'behandelaars'], $expanded['read']);
        $this->assertSame(['behandelaars'], $expanded['create']);
        $this->assertSame(['behandelaars'], $expanded['update']);
    }//end testMultipleRolesMergeGroups()

    /**
     * `editor extends viewer` inherits `read`. Assigning `editor` to a
     * group grants both inherited and own actions to that group.
     *
     * @return void
     */
    public function testRoleExtendsInheritsActions(): void
    {
        $schema = $this->makeSchemaWithRegisterRoles(
            [
                ['name' => 'viewer', 'actions' => ['read']],
                ['name' => 'editor', 'extends' => 'viewer', 'actions' => ['create', 'update']],
            ]
        );

        $expanded = $this->handler->expandRoles(
            authorization: ['roles' => ['editor' => ['behandelaars']]],
            schema: $schema
        );

        $this->assertSame(['behandelaars'], $expanded['read']);
        $this->assertSame(['behandelaars'], $expanded['create']);
        $this->assertSame(['behandelaars'], $expanded['update']);
    }//end testRoleExtendsInheritsActions()

    /**
     * Multi-level inheritance: `admin extends editor extends viewer`
     * accumulates actions across all levels.
     *
     * @return void
     */
    public function testMultiLevelRoleExtension(): void
    {
        $schema = $this->makeSchemaWithRegisterRoles(
            [
                ['name' => 'viewer', 'actions' => ['read']],
                ['name' => 'editor', 'extends' => 'viewer', 'actions' => ['create', 'update']],
                ['name' => 'admin', 'extends' => 'editor', 'actions' => ['delete']],
            ]
        );

        $expanded = $this->handler->expandRoles(
            authorization: ['roles' => ['admin' => ['root']]],
            schema: $schema
        );

        $this->assertSame(['root'], $expanded['read']);
        $this->assertSame(['root'], $expanded['create']);
        $this->assertSame(['root'], $expanded['update']);
        $this->assertSame(['root'], $expanded['delete']);
    }//end testMultiLevelRoleExtension()

    /**
     * `extends` accepts an array for multiple inheritance; actions
     * from all parents accumulate, deduplicated.
     *
     * @return void
     */
    public function testExtendsArraySupportsMultipleInheritance(): void
    {
        $schema = $this->makeSchemaWithRegisterRoles(
            [
                ['name' => 'reader', 'actions' => ['read']],
                ['name' => 'lister', 'actions' => ['list']],
                ['name' => 'auditor', 'extends' => ['reader', 'lister'], 'actions' => []],
            ]
        );

        $expanded = $this->handler->expandRoles(
            authorization: ['roles' => ['auditor' => ['compliance']]],
            schema: $schema
        );

        $this->assertSame(['compliance'], $expanded['read']);
        $this->assertSame(['compliance'], $expanded['list']);
    }//end testExtendsArraySupportsMultipleInheritance()

    /**
     * Cyclic `extends` chain (`a extends b extends a`) does not
     * recurse forever; each role gets its own actions only and the
     * cycle is logged as a warning.
     *
     * @return void
     */
    public function testCyclicExtendsLoggedAndContained(): void
    {
        $schema = $this->makeSchemaWithRegisterRoles(
            [
                ['name' => 'a', 'extends' => 'b', 'actions' => ['read']],
                ['name' => 'b', 'extends' => 'a', 'actions' => ['create']],
            ]
        );

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('Cyclic role-hierarchy reference'),
                $this->anything()
            );

        $expanded = $this->handler->expandRoles(
            authorization: ['roles' => ['a' => ['x']]],
            schema: $schema
        );

        // a inherits from b which inherits from a (cycle); the cycle
        // breaks at the second visit so a ends up with its own
        // actions plus b's own (because b's chain breaks before a).
        $this->assertContains('read', array_keys($expanded));
        $this->assertContains('create', array_keys($expanded));
    }//end testCyclicExtendsLoggedAndContained()

    /**
     * Unknown role names referenced from `extends` are silently ignored
     * — the rest of the role's actions still apply.
     *
     * @return void
     */
    public function testUnknownExtendsIgnoredButOwnActionsSurvive(): void
    {
        $schema = $this->makeSchemaWithRegisterRoles(
            [
                ['name' => 'editor', 'extends' => 'doesNotExist', 'actions' => ['create']],
            ]
        );

        $expanded = $this->handler->expandRoles(
            authorization: ['roles' => ['editor' => ['behandelaars']]],
            schema: $schema
        );

        $this->assertSame(['behandelaars'], $expanded['create']);
    }//end testUnknownExtendsIgnoredButOwnActionsSurvive()

    /**
     * Schema authorization without a `roles` key is returned untouched.
     *
     * @return void
     */
    public function testAuthorizationWithoutRolesReturnedUntouched(): void
    {
        $schema = new Schema();
        $schema->setId(7);
        $schema->setTitle('Plain Schema');

        $authorization = ['read' => ['admin']];
        $expanded      = $this->handler->expandRoles(
            authorization: $authorization,
            schema: $schema
        );

        $this->assertSame($authorization, $expanded);
    }//end testAuthorizationWithoutRolesReturnedUntouched()

    /**
     * Schema role assignments referencing a role the register hasn't
     * declared are logged and skipped, leaving the authorization
     * unchanged.
     *
     * @return void
     */
    public function testUnknownRoleAssignmentLoggedAndSkipped(): void
    {
        $schema = $this->makeSchemaWithRegisterRoles(
            [
                ['name' => 'viewer', 'actions' => ['read']],
            ]
        );

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('Unknown role name referenced'),
                $this->anything()
            );

        $expanded = $this->handler->expandRoles(
            authorization: ['roles' => ['ghost' => ['public']]],
            schema: $schema
        );

        // viewer wasn't assigned and ghost is unknown; nothing got
        // added to the authorization map.
        $this->assertArrayNotHasKey('read', $expanded);
        $this->assertArrayNotHasKey('roles', $expanded);
    }//end testUnknownRoleAssignmentLoggedAndSkipped()

    /**
     * Register without role definitions but a schema that references
     * roles logs a warning and returns the (rolesless) authorization.
     *
     * @return void
     */
    public function testRegisterWithoutRoleDefinitionsLoggedAndSkipped(): void
    {
        $schema = $this->makeSchemaWithRegisterRoles([]);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('register has no role definitions'),
                $this->anything()
            );

        $expanded = $this->handler->expandRoles(
            authorization: ['roles' => ['viewer' => ['public']]],
            schema: $schema
        );

        $this->assertArrayNotHasKey('roles', $expanded);
    }//end testRegisterWithoutRoleDefinitionsLoggedAndSkipped()
}//end class
