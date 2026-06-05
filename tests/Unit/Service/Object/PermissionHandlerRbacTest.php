<?php

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\OperatorEvaluator;
<<<<<<< HEAD
use OCP\IAppConfig;
=======
>>>>>>> origin/development
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
<<<<<<< HEAD

    private PermissionHandler $handler;

    private IUserSession&MockObject $userSession;

    private IUserManager&MockObject $userManager;

    private IGroupManager&MockObject $groupManager;

    private SchemaMapper&MockObject $schemaMapper;

    private MagicMapper&MockObject $objectEntityMapper;

    private ConditionMatcher&MockObject $conditionMatcher;

    private IAppConfig&MockObject $appConfig;

    private LoggerInterface&MockObject $logger;

    private ContainerInterface&MockObject $container;

=======
    private PermissionHandler $handler;
    private IUserSession&MockObject $userSession;
    private IUserManager&MockObject $userManager;
    private IGroupManager&MockObject $groupManager;
    private SchemaMapper&MockObject $schemaMapper;
    private MagicMapper&MockObject $objectEntityMapper;
    private ConditionMatcher&MockObject $conditionMatcher;
    private LoggerInterface&MockObject $logger;
    private ContainerInterface&MockObject $container;
>>>>>>> origin/development
    private RegisterMapper&MockObject $registerMapper;

    protected function setUp(): void
    {
<<<<<<< HEAD
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->userManager        = $this->createMock(IUserManager::class);
        $this->groupManager       = $this->createMock(IGroupManager::class);
        $this->schemaMapper       = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->conditionMatcher   = $this->createMock(ConditionMatcher::class);
        $this->appConfig          = $this->createMock(IAppConfig::class);
        $this->logger         = $this->createMock(LoggerInterface::class);
        $this->container      = $this->createMock(ContainerInterface::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);

        // Default: tenant default for inheritFromPublic is `true`, preserving
        // pre-change behaviour for tests that don't opt out explicitly.
        $this->appConfig->method('getValueBool')->willReturn(true);

=======
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->conditionMatcher = $this->createMock(ConditionMatcher::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);

>>>>>>> origin/development
        $this->handler = new PermissionHandler(
            $this->userSession,
            $this->userManager,
            $this->groupManager,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->conditionMatcher,
<<<<<<< HEAD
            $this->appConfig,
            $this->logger,
            $this->container
        );
    }//end setUp()
=======
            $this->logger,
            $this->container
        );
    }
>>>>>>> origin/development

    private function mockUser(string $uid, array $groups): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn($groups);
        return $user;
<<<<<<< HEAD
    }//end mockUser()
=======
    }
>>>>>>> origin/development

    private function createSchema(int $id, ?array $authorization): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setAuthorization($authorization);
<<<<<<< HEAD
        $schema->setTitle('Test Schema '.$id);
        return $schema;
    }//end createSchema()

    private function createRegister(int $id, ?array $authorization, ?array $configuration=null): Register
=======
        $schema->setTitle('Test Schema ' . $id);
        return $schema;
    }

    private function createRegister(int $id, ?array $authorization, ?array $configuration = null): Register
>>>>>>> origin/development
    {
        $register = new Register();
        $register->setId($id);
        $register->setAuthorization($authorization);
        $register->setConfiguration($configuration ?? []);
        return $register;
<<<<<<< HEAD
    }//end createRegister()
=======
    }
>>>>>>> origin/development

    private function setupRegisterForSchema(int $schemaId, Register $register): void
    {
        $this->container->method('get')
<<<<<<< HEAD
            ->willReturnCallback(
                    function (string $class) use ($register) {
                        if ($class === RegisterMapper::class) {
                            return $this->registerMapper;
                        }

                        if ($class === 'OCA\OpenRegister\Service\OrganisationService') {
                            throw new \RuntimeException('Not available');
                        }

                        throw new \RuntimeException('Unknown class: '.$class);
                    }
                    );
=======
            ->willReturnCallback(function (string $class) use ($register) {
                if ($class === RegisterMapper::class) {
                    return $this->registerMapper;
                }
                if ($class === 'OCA\OpenRegister\Service\OrganisationService') {
                    throw new \RuntimeException('Not available');
                }
                throw new \RuntimeException('Unknown class: ' . $class);
            });
>>>>>>> origin/development

        $this->registerMapper->method('getFirstRegisterWithSchema')
            ->willReturn($register->getId());
        $this->registerMapper->method('find')
            ->willReturn($register);
<<<<<<< HEAD
    }//end setupRegisterForSchema()

    // === Register Cascade Tests ===
=======
    }

    // === Register Cascade Tests ===

>>>>>>> origin/development
    public function testSchemaAuthorizationOverridesRegister(): void
    {
        $this->mockUser('user1', ['behandelaars']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read'   => ['behandelaars'],
                    'create' => ['admin'],
                ]
                );

        $register = $this->createRegister(
                10,
                [
                    'read'   => ['public'],
                    'create' => ['public'],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => ['behandelaars'],
            'create' => ['admin'],
        ]);

        $register = $this->createRegister(10, [
            'read' => ['public'],
            'create' => ['public'],
        ]);
>>>>>>> origin/development

        $this->setupRegisterForSchema(1, $register);

        // Schema says behandelaars can read, register says public can read.
        // Schema overrides: user in behandelaars should be able to read.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));

        // Schema says only admin can create, not behandelaars.
        $this->assertFalse($this->handler->hasPermission($schema, 'create'));
<<<<<<< HEAD
    }//end testSchemaAuthorizationOverridesRegister()
=======
    }
>>>>>>> origin/development

    public function testRegisterFallbackWhenSchemaHasNoAuth(): void
    {
        $this->mockUser('user1', ['medewerkers']);

        // Schema has NO authorization.
        $schema = $this->createSchema(1, null);

<<<<<<< HEAD
        $register = $this->createRegister(
                10,
                [
                    'read'   => ['medewerkers'],
                    'create' => ['admin'],
                ]
                );
=======
        $register = $this->createRegister(10, [
            'read' => ['medewerkers'],
            'create' => ['admin'],
        ]);
>>>>>>> origin/development

        $this->setupRegisterForSchema(1, $register);

        // Should use register authorization: medewerkers can read.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));

        // Register says only admin can create.
        $this->assertFalse($this->handler->hasPermission($schema, 'create'));
<<<<<<< HEAD
    }//end testRegisterFallbackWhenSchemaHasNoAuth()
=======
    }
>>>>>>> origin/development

    public function testNeitherSchemaNorRegisterHasAuth(): void
    {
        $this->mockUser('user1', ['somegroup']);

<<<<<<< HEAD
        $schema   = $this->createSchema(1, null);
=======
        $schema = $this->createSchema(1, null);
>>>>>>> origin/development
        $register = $this->createRegister(10, null);

        $this->setupRegisterForSchema(1, $register);

        // No authorization anywhere = everyone has permission.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
        $this->assertTrue($this->handler->hasPermission($schema, 'create'));
<<<<<<< HEAD
    }//end testNeitherSchemaNorRegisterHasAuth()

    // === Role Expansion Tests ===
=======
    }

    // === Role Expansion Tests ===

>>>>>>> origin/development
    public function testRoleExpansionViewerRole(): void
    {
        $this->mockUser('user1', ['public']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'roles' => [
                        'viewer' => ['public'],
                        'editor' => ['behandelaars'],
                    ],
                ]
                );

        $register = $this->createRegister(
                10,
                null,
                [
                    'roles' => [
                        ['name' => 'viewer', 'description' => 'Read only', 'actions' => ['read']],
                        ['name' => 'editor', 'description' => 'Edit access', 'actions' => ['read', 'create', 'update']],
                    ],
                ]
                );
=======
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
>>>>>>> origin/development

        $this->setupRegisterForSchema(1, $register);

        // Public group has viewer role => read only.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
        $this->assertFalse($this->handler->hasPermission($schema, 'create'));
<<<<<<< HEAD
    }//end testRoleExpansionViewerRole()
=======
    }
>>>>>>> origin/development

    public function testRoleExpansionEditorRole(): void
    {
        $this->mockUser('user1', ['behandelaars']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'roles' => [
                        'viewer' => ['public'],
                        'editor' => ['behandelaars'],
                    ],
                ]
                );

        $register = $this->createRegister(
                10,
                null,
                [
                    'roles' => [
                        ['name' => 'viewer', 'description' => 'Read only', 'actions' => ['read']],
                        ['name' => 'editor', 'description' => 'Edit access', 'actions' => ['read', 'create', 'update']],
                    ],
                ]
                );
=======
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
>>>>>>> origin/development

        $this->setupRegisterForSchema(1, $register);

        // Behandelaars has editor role => read, create, update.
        // Actions not listed in authorization default to allowed (permissive model).
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
        $this->assertTrue($this->handler->hasPermission($schema, 'create'));
        $this->assertTrue($this->handler->hasPermission($schema, 'update'));
        $this->assertTrue($this->handler->hasPermission($schema, 'delete'));
<<<<<<< HEAD
    }//end testRoleExpansionEditorRole()
=======
    }
>>>>>>> origin/development

    public function testMixedRoleAndDirectAuth(): void
    {
        $this->mockUser('user1', ['extra-groep']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'roles' => [
                        'viewer' => ['public'],
                    ],
                    'read'  => ['extra-groep'],
                ]
                );

        $register = $this->createRegister(
                10,
                null,
                [
                    'roles' => [
                        ['name' => 'viewer', 'description' => 'Read only', 'actions' => ['read']],
                    ],
                ]
                );
=======
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
>>>>>>> origin/development

        $this->setupRegisterForSchema(1, $register);

        // extra-groep has direct read permission.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
<<<<<<< HEAD
    }//end testMixedRoleAndDirectAuth()
=======
    }
>>>>>>> origin/development

    public function testUnknownRoleNameIsIgnored(): void
    {
        $this->mockUser('user1', ['public']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'roles' => [
                        'archiver' => ['public'],
                    ],
                ]
                );

        $register = $this->createRegister(
                10,
                null,
                [
                    'roles' => [
                        ['name' => 'viewer', 'description' => 'Read only', 'actions' => ['read']],
                    ],
                ]
                );
=======
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
>>>>>>> origin/development

        $this->setupRegisterForSchema(1, $register);

        // archiver role doesn't exist => warning logged, no permissions granted.
        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // With empty authorization (only unknown roles), all actions should be permitted
        // because the effective authorization ends up empty after role expansion.
        $result = $this->handler->resolveAuthorization($schema);
        $this->assertEmpty($result);
<<<<<<< HEAD
    }//end testUnknownRoleNameIsIgnored()

    // === Manage Action Tests ===
=======
    }

    // === Manage Action Tests ===

>>>>>>> origin/development
    public function testManageActionEvaluated(): void
    {
        $this->mockUser('user1', ['register-beheerders']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'manage' => ['register-beheerders'],
                    'read'   => ['public'],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'manage' => ['register-beheerders'],
            'read' => ['public'],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // User in register-beheerders should have manage permission.
        $this->assertTrue($this->handler->hasPermission($schema, 'manage'));
<<<<<<< HEAD
    }//end testManageActionEvaluated()
=======
    }
>>>>>>> origin/development

    public function testManageActionDenied(): void
    {
        $this->mockUser('user1', ['behandelaars']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'manage' => ['register-beheerders'],
                    'read'   => ['behandelaars'],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'manage' => ['register-beheerders'],
            'read' => ['behandelaars'],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // User NOT in register-beheerders should NOT have manage permission.
        $this->assertFalse($this->handler->hasPermission($schema, 'manage'));
        // But should still be able to read.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
<<<<<<< HEAD
    }//end testManageActionDenied()
=======
    }
>>>>>>> origin/development

    public function testAdminBypassesManageCheck(): void
    {
        $this->mockUser('admin1', ['admin']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'manage' => ['register-beheerders'],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'manage' => ['register-beheerders'],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // Admin always has all permissions.
        $this->assertTrue($this->handler->hasPermission($schema, 'manage'));
<<<<<<< HEAD
    }//end testAdminBypassesManageCheck()
=======
    }
>>>>>>> origin/development

    // ------------------------------------------------------------------
    // Conditional rule delegation tests (ADR-011 — ConditionMatcher).
    //
    // These tests verify that hasGroupPermission delegates conditional
    // rule evaluation to the shared ConditionMatcher service and that the
    // admin/owner bypasses short-circuit before delegation.
    // ------------------------------------------------------------------
<<<<<<< HEAD
    private function createObjectEntity(array $data, ?string $owner=null, ?string $organisation=null): ObjectEntity
=======

    private function createObjectEntity(array $data, ?string $owner = null, ?string $organisation = null): ObjectEntity
>>>>>>> origin/development
    {
        $object = new ObjectEntity();
        $object->setObject($data);
        if ($owner !== null) {
            $object->setOwner($owner);
        }
<<<<<<< HEAD

        if ($organisation !== null) {
            $object->setOrganisation($organisation);
        }

        return $object;
    }//end createObjectEntity()
=======
        if ($organisation !== null) {
            $object->setOrganisation($organisation);
        }
        return $object;
    }
>>>>>>> origin/development

    public function testConditionalPublicRuleDelegatesToConditionMatcher(): void
    {
        // Anonymous caller, public-with-match rule.
        $this->userSession->method('getUser')->willReturn(null);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'public', 'match' => ['publishDate' => ['$lte' => '$now']]],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishDate' => ['$lte' => '$now']]],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['publishDate' => '2025-01-01']);

        // Expect delegation — ConditionMatcher returns true (past date).
        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->with(
<<<<<<< HEAD
                $this->callback(
                        function (array $envelope): bool {
                            return ($envelope['publishDate'] ?? null) === '2025-01-01';
                        }
                        ),
=======
                $this->callback(function (array $envelope): bool {
                    return ($envelope['publishDate'] ?? null) === '2025-01-01';
                }),
>>>>>>> origin/development
                ['publishDate' => ['$lte' => '$now']]
            )
            ->willReturn(true);

        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            )
        );
<<<<<<< HEAD
    }//end testConditionalPublicRuleDelegatesToConditionMatcher()
=======
    }
>>>>>>> origin/development

    public function testConditionalRuleReturnsFalseWhenConditionMatcherReturnsFalse(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'public', 'match' => ['publishDate' => ['$lte' => '$now']]],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishDate' => ['$lte' => '$now']]],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['publishDate' => '2099-01-01']);

        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->willReturn(false);

        $this->assertFalse(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            )
        );
<<<<<<< HEAD
    }//end testConditionalRuleReturnsFalseWhenConditionMatcherReturnsFalse()
=======
    }
>>>>>>> origin/development

    public function testUserIdVariableRuleDelegatesToConditionMatcher(): void
    {
        $this->mockUser('jan', ['medewerkers']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'medewerkers', 'match' => ['assignedTo' => '$userId']],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'medewerkers', 'match' => ['assignedTo' => '$userId']],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['assignedTo' => 'jan']);

        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->with(
                $this->anything(),
                ['assignedTo' => '$userId']
            )
            ->willReturn(true);

        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: 'jan',
                objectOwner: null,
                _rbac: true,
                object: $object
            )
        );
<<<<<<< HEAD
    }//end testUserIdVariableRuleDelegatesToConditionMatcher()
=======
    }
>>>>>>> origin/development

    public function testInOperatorRuleDelegatesToConditionMatcher(): void
    {
        $this->mockUser('jan', ['behandelaars']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'behandelaars', 'match' => ['status' => ['$in' => ['open', 'review']]]],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'behandelaars', 'match' => ['status' => ['$in' => ['open', 'review']]]],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['status' => 'open']);

        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->willReturn(true);

        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'jan', null, true, $object));
<<<<<<< HEAD
    }//end testInOperatorRuleDelegatesToConditionMatcher()
=======
    }
>>>>>>> origin/development

    public function testOrganisationVariableFoldsIntoEnvelopeViaSelf(): void
    {
        $this->mockUser('jan', ['behandelaars']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'behandelaars', 'match' => ['_organisation' => '$organisation']],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'behandelaars', 'match' => ['_organisation' => '$organisation']],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['name' => 'zaak-1'], null, 'org-abc-123');

        // Verify the envelope passed to ConditionMatcher folds objectOrganisation into @self.organisation
        // so ConditionMatcher::getObjectValue() can resolve `_organisation` via its standard
        // _-prefixed @self lookup.
        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->with(
<<<<<<< HEAD
                $this->callback(
                        function (array $envelope): bool {
                            return (($envelope['@self']['organisation'] ?? null) === 'org-abc-123')
                            && (($envelope['name'] ?? null) === 'zaak-1');
                        }
                        ),
=======
                $this->callback(function (array $envelope): bool {
                    return (($envelope['@self']['organisation'] ?? null) === 'org-abc-123')
                        && (($envelope['name'] ?? null) === 'zaak-1');
                }),
>>>>>>> origin/development
                ['_organisation' => '$organisation']
            )
            ->willReturn(true);

        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'jan', null, true, $object));
<<<<<<< HEAD
    }//end testOrganisationVariableFoldsIntoEnvelopeViaSelf()
=======
    }
>>>>>>> origin/development

    public function testAdminBypassSkipsConditionMatcher(): void
    {
        $this->mockUser('admin1', ['admin']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'behandelaars', 'match' => ['status' => 'open']],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'behandelaars', 'match' => ['status' => 'open']],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['status' => 'closed']);

        // Admin bypass MUST short-circuit before any delegation.
        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'admin1', null, true, $object));
<<<<<<< HEAD
    }//end testAdminBypassSkipsConditionMatcher()
=======
    }
>>>>>>> origin/development

    public function testOwnerBypassSkipsConditionMatcher(): void
    {
        $this->mockUser('jan', ['medewerkers']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'behandelaars', 'match' => ['status' => 'open']],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'behandelaars', 'match' => ['status' => 'open']],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['status' => 'closed'], 'jan');

        // Owner bypass MUST short-circuit before any delegation.
        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: 'jan',
                objectOwner: 'jan',
                _rbac: true,
                object: $object
            )
        );
<<<<<<< HEAD
    }//end testOwnerBypassSkipsConditionMatcher()
=======
    }
>>>>>>> origin/development

    public function testSimpleStringRuleDoesNotInvokeConditionMatcher(): void
    {
        // Simple group match without a `match` clause never reaches ConditionMatcher.
        $this->mockUser('jan', ['juridisch-team']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => ['juridisch-team'],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => ['juridisch-team'],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'jan'));
<<<<<<< HEAD
    }//end testSimpleStringRuleDoesNotInvokeConditionMatcher()
=======
    }
>>>>>>> origin/development

    public function testConditionalRuleWithoutMatchClauseDoesNotInvokeConditionMatcher(): void
    {
        // Conditional rule with an empty/missing match is treated as a plain group match.
        $this->mockUser('jan', ['behandelaars']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [['group' => 'behandelaars']],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [['group' => 'behandelaars']],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'jan'));
<<<<<<< HEAD
    }//end testConditionalRuleWithoutMatchClauseDoesNotInvokeConditionMatcher()
=======
    }
>>>>>>> origin/development

    public function testAnonymousCallerAgainstNonPublicRuleReturnsFalseWithoutDelegation(): void
    {
        // Anonymous user against a rule that doesn't list 'public' → rejected
        // without consulting ConditionMatcher (no conditional `public` rule to evaluate).
        $this->userSession->method('getUser')->willReturn(null);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => ['juridisch-team'],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => ['juridisch-team'],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertFalse($this->handler->hasPermission($schema, 'read'));
<<<<<<< HEAD
    }//end testAnonymousCallerAgainstNonPublicRuleReturnsFalseWithoutDelegation()
=======
    }
>>>>>>> origin/development

    // ------------------------------------------------------------------
    // End-to-end wiring test with REAL ConditionMatcher + OperatorEvaluator.
    //
    // Reproduces the user-reported bug: schema with
<<<<<<< HEAD
    // { "read": [{ "group": "public", "match": { "publishedAt": { "$lte": "$now" } } }] }
=======
    //   { "read": [{ "group": "public", "match": { "publishedAt": { "$lte": "$now" } } }] }
>>>>>>> origin/development
    // must grant access to objects whose publishedAt is in the past AND deny
    // access to objects with publishedAt = null (so the list endpoint and the
    // find endpoint agree — SQL's NULL semantics is the contract).
    // ------------------------------------------------------------------
<<<<<<< HEAD
=======

>>>>>>> origin/development
    private function buildHandlerWithRealMatcher(): PermissionHandler
    {
        $operatorEvaluator = new OperatorEvaluator($this->logger);
        $realMatcher       = new ConditionMatcher(
            $this->userSession,
            $this->container,
            $operatorEvaluator,
            $this->logger
        );
        return new PermissionHandler(
            $this->userSession,
            $this->userManager,
            $this->groupManager,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $realMatcher,
<<<<<<< HEAD
            $this->appConfig,
            $this->logger,
            $this->container
        );
    }//end buildHandlerWithRealMatcher()
=======
            $this->logger,
            $this->container
        );
    }
>>>>>>> origin/development

    public function testPublicLteNowRuleMatchesPastPublishedAt(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

<<<<<<< HEAD
        $object  = $this->createObjectEntity(['publishedAt' => '2025-01-01 00:00:00']);
=======
        $object = $this->createObjectEntity(['publishedAt' => '2025-01-01 00:00:00']);
>>>>>>> origin/development
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertTrue(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            ),
            'Past-dated publication should be accessible via $lte $now rule'
        );
<<<<<<< HEAD
    }//end testPublicLteNowRuleMatchesPastPublishedAt()
=======
    }
>>>>>>> origin/development

    public function testPublicLteNowRuleRejectsNullPublishedAt(): void
    {
        // This is the exact user-reported bug: previously returned true because
        // OperatorEvaluator used raw PHP <= with null coerced to empty string.
        $this->userSession->method('getUser')->willReturn(null);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // Object has no publishedAt value at all — the property is absent from
        // the data map, so getObjectValue returns null.
<<<<<<< HEAD
        $object  = $this->createObjectEntity(['title' => 'draft']);
=======
        $object = $this->createObjectEntity(['title' => 'draft']);
>>>>>>> origin/development
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertFalse(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            ),
            'Publication with null publishedAt must NOT match $lte $now (SQL-aligned semantics)'
        );
<<<<<<< HEAD
    }//end testPublicLteNowRuleRejectsNullPublishedAt()
=======
    }
>>>>>>> origin/development

    public function testPublicLteNowRuleRejectsExplicitNullPublishedAt(): void
    {
        // Same as above but with the property explicitly set to null in the data map.
        $this->userSession->method('getUser')->willReturn(null);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

<<<<<<< HEAD
        $object  = $this->createObjectEntity(['publishedAt' => null, 'title' => 'draft']);
=======
        $object = $this->createObjectEntity(['publishedAt' => null, 'title' => 'draft']);
>>>>>>> origin/development
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertFalse(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            )
        );
<<<<<<< HEAD
    }//end testPublicLteNowRuleRejectsExplicitNullPublishedAt()
=======
    }
>>>>>>> origin/development

    public function testPublicLteNowRuleRejectsFuturePublishedAt(): void
    {
        // Sanity: future-dated publication should also be denied (not yet published).
        $this->userSession->method('getUser')->willReturn(null);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

<<<<<<< HEAD
        $object  = $this->createObjectEntity(['publishedAt' => '2099-01-01 00:00:00']);
=======
        $object = $this->createObjectEntity(['publishedAt' => '2099-01-01 00:00:00']);
>>>>>>> origin/development
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertFalse(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            )
        );
<<<<<<< HEAD
    }//end testPublicLteNowRuleRejectsFuturePublishedAt()
=======
    }
>>>>>>> origin/development

    // ------------------------------------------------------------------
    // $now format alignment tests.
    //
    // ConditionMatcher::resolveDynamicValue and
    // MagicRbacHandler::resolveDynamicValue MUST both emit `$now` in the same
    // string format. Otherwise, for text/JSON columns storing dates, a raw
    // lexicographic comparison diverges between list (SQL) and find (PHP).
    //
    // Canonical format: Y-m-d H:i:s (SQL-native).
    // ------------------------------------------------------------------
<<<<<<< HEAD
=======

>>>>>>> origin/development
    public function testNowResolvesToSqlNativeFormat(): void
    {
        // If this test ever fails, the list and find endpoints will diverge
        // on date comparisons against text columns. See also the
        // "Dynamic $now variable resolves to a canonical SQL-native format"
        // scenario in specs/rbac-scopes/spec.md.
        $this->userSession->method('getUser')->willReturn(null);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // Stored date in SQL-native Y-m-d H:i:s — the canonical format.
<<<<<<< HEAD
        $object  = $this->createObjectEntity(['publishedAt' => '2025-06-01 12:00:00']);
=======
        $object = $this->createObjectEntity(['publishedAt' => '2025-06-01 12:00:00']);
>>>>>>> origin/development
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertTrue(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            ),
            '$now must resolve to Y-m-d H:i:s so it lex-compares correctly against Y-m-d H:i:s stored dates'
        );
<<<<<<< HEAD
    }//end testNowResolvesToSqlNativeFormat()
=======
    }
>>>>>>> origin/development

    public function testNowAlignsWithSqlPathForIsoStoredDates(): void
    {
        // If dates are stored as ISO 8601 with 'T' (e.g. "2026-04-24T10:00:00Z"),
        // a raw lex comparison against Y-m-d H:i:s $now gives the SAME answer
        // on both paths: the 'T' (ASCII 84) beats the space (ASCII 32), so
        // both paths say the stored value is lexicographically AFTER $now,
        // regardless of actual clock time. Parity preserved (both paths reject).
        //
        // This is the "consistency at the cost of correctness on malformed data"
        // trade-off: rule authors who want semantic-datetime comparison should
        // normalize stored dates to Y-m-d H:i:s (OpenRegister's DateTimeNormalizer
        // handles this on input).
        $this->userSession->method('getUser')->willReturn(null);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

<<<<<<< HEAD
        $object  = $this->createObjectEntity(['publishedAt' => '2025-06-01T12:00:00Z']);
=======
        $object = $this->createObjectEntity(['publishedAt' => '2025-06-01T12:00:00Z']);
>>>>>>> origin/development
        $handler = $this->buildHandlerWithRealMatcher();

        // Both paths lex-compare: '2025-06-01T...' vs '<today> <time>'.
        // Result is deterministic — what matters is PHP and SQL agree.
        // (Assertion is whatever the lex result is; we freeze the contract here.)
        $phpVerdict = $handler->hasPermission(
            schema: $schema,
            action: 'read',
            userId: null,
            objectOwner: null,
            _rbac: true,
            object: $object
        );

        // Expected: the stored date's year (2025) is before the current year, so
        // the first 4 chars '2025' compare less than current year chars. $lte
        // succeeds regardless of whether position 10 is 'T' or ' ', because
        // comparison short-circuits before reaching that character.
        $this->assertTrue(
            $phpVerdict,
            'Past-year ISO-with-T date MUST $lte $now via lex comparison (year-level wins)'
        );
<<<<<<< HEAD
    }//end testNowAlignsWithSqlPathForIsoStoredDates()
=======
    }
>>>>>>> origin/development

    public function testNowAlignsWithSqlPathForDateOnlyStored(): void
    {
        // Date-only stored values (no time component) work on both paths.
        $this->userSession->method('getUser')->willReturn(null);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

<<<<<<< HEAD
        $object  = $this->createObjectEntity(['publishedAt' => '2025-06-01']);
=======
        $object = $this->createObjectEntity(['publishedAt' => '2025-06-01']);
>>>>>>> origin/development
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertTrue(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            ),
            'Date-only "2025-06-01" MUST $lte $now (prefix is lexicographically less than current year)'
        );
<<<<<<< HEAD
    }//end testNowAlignsWithSqlPathForDateOnlyStored()
=======
    }
>>>>>>> origin/development

    public function testCompositePublishedAndNotDepublishedRule(): void
    {
        // Real-world rule: "(published and not yet depublished) OR (published and never expires)".
        // This is the rule the user asked about directly.
        $this->userSession->method('getUser')->willReturn(null);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        [
                            'group' => 'public',
                            'match' => [
                                'publicatiedatum'   => ['$lte' => '$now'],
                                'depublicatiedatum' => ['$gte' => '$now'],
                            ],
                        ],
                        [
                            'group' => 'public',
                            'match' => [
                                'publicatiedatum'   => ['$lte' => '$now'],
                                'depublicatiedatum' => ['$exists' => false],
                            ],
                        ],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                [
                    'group' => 'public',
                    'match' => [
                        'publicatiedatum'   => ['$lte' => '$now'],
                        'depublicatiedatum' => ['$gte' => '$now'],
                    ],
                ],
                [
                    'group' => 'public',
                    'match' => [
                        'publicatiedatum'   => ['$lte' => '$now'],
                        'depublicatiedatum' => ['$exists' => false],
                    ],
                ],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);
        $handler = $this->buildHandlerWithRealMatcher();

        // Case A: published, within window → rule 1 matches.
        $this->assertTrue(
            $handler->hasPermission(
<<<<<<< HEAD
                $schema,
                    'read',
                    null,
                    null,
                    true,
                $this->createObjectEntity(
                        [
                            'publicatiedatum'   => '2025-01-01 00:00:00',
                            'depublicatiedatum' => '2099-01-01 00:00:00',
                        ]
                        )
=======
                $schema, 'read', null, null, true,
                $this->createObjectEntity([
                    'publicatiedatum'   => '2025-01-01 00:00:00',
                    'depublicatiedatum' => '2099-01-01 00:00:00',
                ])
>>>>>>> origin/development
            ),
            'Published, within window: allow'
        );

        // Case B: published, depublicatiedatum is null → rule 2 matches.
        $this->assertTrue(
            $handler->hasPermission(
<<<<<<< HEAD
                $schema,
                    'read',
                    null,
                    null,
                    true,
                $this->createObjectEntity(
                        [
                            'publicatiedatum'   => '2025-01-01 00:00:00',
                            'depublicatiedatum' => null,
                        ]
                        )
=======
                $schema, 'read', null, null, true,
                $this->createObjectEntity([
                    'publicatiedatum'   => '2025-01-01 00:00:00',
                    'depublicatiedatum' => null,
                ])
>>>>>>> origin/development
            ),
            'Published, never expires: allow'
        );

        // Case C: published but depublicatiedatum in the past → neither rule matches.
        $this->assertFalse(
            $handler->hasPermission(
<<<<<<< HEAD
                $schema,
                    'read',
                    null,
                    null,
                    true,
                $this->createObjectEntity(
                        [
                            'publicatiedatum'   => '2025-01-01 00:00:00',
                            'depublicatiedatum' => '2025-06-01 00:00:00',
                        ]
                        )
=======
                $schema, 'read', null, null, true,
                $this->createObjectEntity([
                    'publicatiedatum'   => '2025-01-01 00:00:00',
                    'depublicatiedatum' => '2025-06-01 00:00:00',
                ])
>>>>>>> origin/development
            ),
            'Expired publication: deny'
        );

        // Case D: not yet published → neither rule matches.
        $this->assertFalse(
            $handler->hasPermission(
<<<<<<< HEAD
                $schema,
                    'read',
                    null,
                    null,
                    true,
                $this->createObjectEntity(
                        [
                            'publicatiedatum'   => '2099-01-01 00:00:00',
                            'depublicatiedatum' => null,
                        ]
                        )
=======
                $schema, 'read', null, null, true,
                $this->createObjectEntity([
                    'publicatiedatum'   => '2099-01-01 00:00:00',
                    'depublicatiedatum' => null,
                ])
>>>>>>> origin/development
            ),
            'Future-dated publication: deny'
        );

        // Case E: no publicatiedatum at all → neither rule matches (null-handling).
        $this->assertFalse(
            $handler->hasPermission(
<<<<<<< HEAD
                $schema,
                    'read',
                    null,
                    null,
                    true,
=======
                $schema, 'read', null, null, true,
>>>>>>> origin/development
                $this->createObjectEntity(['title' => 'draft'])
            ),
            'Draft with no publicatiedatum: deny'
        );
<<<<<<< HEAD
    }//end testCompositePublishedAndNotDepublishedRule()
=======
    }
>>>>>>> origin/development

    public function testResolvedRelationUnwrappingViaRealConditionMatcher(): void
    {
        // Regression test for the scenario the deleted testEvaluateMatchConditionsResolvedRelation
        // covered: when a property has been expanded into its full related object
        // (e.g. {id: 'uuid-123', name: 'Parent'}), RBAC conditions MUST still compare
        // against the scalar id. Without unwrapping, a rule like
        // {"match": {"parent": "uuid-123"}} would flip from allow to deny after the
        // unification, diverging from the SQL path (which compares the id column
        // directly regardless of expansion). Real-wired end-to-end test — no mocks.
        $this->mockUser('jan', ['behandelaars']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'behandelaars', 'match' => ['parent' => 'uuid-123']],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'behandelaars', 'match' => ['parent' => 'uuid-123']],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // Object has `parent` expanded into a resolved relation.
<<<<<<< HEAD
        $object  = $this->createObjectEntity(
                [
                    'parent' => ['id' => 'uuid-123', 'name' => 'Parent'],
                ]
                );
=======
        $object  = $this->createObjectEntity([
            'parent' => ['id' => 'uuid-123', 'name' => 'Parent'],
        ]);
>>>>>>> origin/development
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertTrue(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: 'jan',
                objectOwner: null,
                _rbac: true,
                object: $object
            ),
            'Resolved relation with matching id MUST satisfy the scalar id rule'
        );

        // Negative case: mismatched id.
<<<<<<< HEAD
        $objectMismatch = $this->createObjectEntity(
                [
                    'parent' => ['id' => 'uuid-456', 'name' => 'Other'],
                ]
                );
=======
        $objectMismatch = $this->createObjectEntity([
            'parent' => ['id' => 'uuid-456', 'name' => 'Other'],
        ]);
>>>>>>> origin/development

        $this->assertFalse(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: 'jan',
                objectOwner: null,
                _rbac: true,
                object: $objectMismatch
            ),
            'Resolved relation with mismatched id MUST NOT satisfy the rule'
        );
<<<<<<< HEAD
    }//end testResolvedRelationUnwrappingViaRealConditionMatcher()
=======
    }
>>>>>>> origin/development

    public function testUnknownOperatorFailsClosedViaRealConditionMatcher(): void
    {
        // Regression test for bbrands02's critical finding: a malformed rule with
        // an unknown operator (e.g. $foo) MUST reject the match rather than
        // granting access. Previously OperatorEvaluator returned true on unknown
        // operators (fail-open), while the SQL path produced no clause and
        // denied. Aligning both paths to fail-closed.
        $this->mockUser('jan', ['behandelaars']);

<<<<<<< HEAD
        $schema = $this->createSchema(
                1,
                [
                    'read' => [
                        ['group' => 'behandelaars', 'match' => ['publishedAt' => ['$foo' => 'bar']]],
                    ],
                ]
                );
=======
        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'behandelaars', 'match' => ['publishedAt' => ['$foo' => 'bar']]],
            ],
        ]);
>>>>>>> origin/development

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object  = $this->createObjectEntity(['publishedAt' => 'bar']);
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertFalse(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: 'jan',
                objectOwner: null,
                _rbac: true,
                object: $object
            ),
            'Malformed rule with unknown operator MUST NOT grant access'
        );
<<<<<<< HEAD
    }//end testUnknownOperatorFailsClosedViaRealConditionMatcher()
}//end class
=======
    }

    // ------------------------------------------------------------------
    // Fail-closed object writes for ANONYMOUS callers (#1955).
    //
    // An anonymous principal (no IUserSession user) is denied create/update/
    // delete unless the schema explicitly grants the `public` group that
    // action. Authenticated users are deliberately UNCHANGED (their default-open
    // behaviour is a separate, broader policy decision tracked in #1955).
    // ------------------------------------------------------------------

    public function testAnonymousCreateDeniedOnSchemaWithNoAuthorization(): void
    {
        // No authorization block anywhere → previously default-open (anonymous
        // create returned true). Now must fail closed.
        $this->userSession->method('getUser')->willReturn(null);

        $schema   = $this->createSchema(1, null);
        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $this->assertFalse(
            $this->handler->hasPermission($schema, 'create'),
            'Anonymous create on a no-authorization schema MUST be denied (fail closed)'
        );
        $this->assertFalse(
            $this->handler->hasPermission($schema, 'update'),
            'Anonymous update on a no-authorization schema MUST be denied (fail closed)'
        );
        $this->assertFalse(
            $this->handler->hasPermission($schema, 'delete'),
            'Anonymous delete on a no-authorization schema MUST be denied (fail closed)'
        );
    }

    public function testAnonymousCreateDeniedWhenActionNotListed(): void
    {
        // Authorization exists but has no `create` entry → previously default-open.
        $this->userSession->method('getUser')->willReturn(null);

        $schema   = $this->createSchema(1, ['read' => ['public']]);
        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $this->assertFalse(
            $this->handler->hasPermission($schema, 'create'),
            'Anonymous create MUST be denied when the action is not explicitly granted to public'
        );
    }

    public function testAnonymousCreateDeniedWhenCreateGrantedToOtherGroupOnly(): void
    {
        // `create` is listed but only for a non-public group.
        $this->userSession->method('getUser')->willReturn(null);

        $schema   = $this->createSchema(1, ['create' => ['behandelaars']]);
        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $this->assertFalse(
            $this->handler->hasPermission($schema, 'create'),
            'Anonymous create MUST be denied when create is granted only to a non-public group'
        );
    }

    public function testAnonymousCreateAllowedWhenPublicCreateExplicitlyGranted(): void
    {
        // Schema opted in to public submissions via a bare `public` string entry.
        $this->userSession->method('getUser')->willReturn(null);

        $schema   = $this->createSchema(1, ['create' => ['public']]);
        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $this->assertTrue(
            $this->handler->hasPermission($schema, 'create'),
            'Anonymous create MUST be allowed when the schema explicitly grants public create'
        );
    }

    public function testAnonymousCreateAllowedWhenPublicCreateGrantedAsComplexEntry(): void
    {
        // Public create declared as a complex entry (no match) → opt-in honoured.
        $this->userSession->method('getUser')->willReturn(null);

        $schema   = $this->createSchema(1, ['create' => [['group' => 'public']]]);
        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $this->assertTrue(
            $this->handler->hasPermission($schema, 'create'),
            'Anonymous create MUST be allowed when public create is declared as a complex entry'
        );
    }

    public function testAuthenticatedCreateUnaffectedOnNoAuthorizationSchema(): void
    {
        // The fail-closed write change is scoped to anonymous principals only.
        // An authenticated user on a no-authorization schema retains the existing
        // default-open behaviour (asserted by the sibling
        // testNeitherSchemaNorRegisterHasAuth) — this test pins that the new
        // anonymous gate does NOT bleed into the authenticated path.
        $this->mockUser('user1', ['somegroup']);

        $schema   = $this->createSchema(1, null);
        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $this->assertTrue(
            $this->handler->hasPermission($schema, 'create'),
            'Authenticated create behaviour MUST be unchanged by the anonymous fail-closed gate'
        );
    }
}
>>>>>>> origin/development
