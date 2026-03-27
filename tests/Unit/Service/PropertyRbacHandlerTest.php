<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class PropertyRbacHandlerTest extends TestCase
{
    private PropertyRbacHandler $handler;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private ConditionMatcher&MockObject $conditionMatcher;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->conditionMatcher = $this->createMock(ConditionMatcher::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new PropertyRbacHandler(
            $this->userSession,
            $this->groupManager,
            $this->conditionMatcher,
            $this->logger
        );
    }

    private function mockUser(string $uid, array $groups): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn($groups);
        return $user;
    }

    private function createSchema(array $properties): Schema
    {
        $schema = new Schema();
        $schema->setProperties($properties);
        return $schema;
    }

    // ── isAdmin ──

    public function testIsAdminReturnsTrueForAdminUser(): void
    {
        $this->mockUser('admin', ['admin']);
        $this->assertTrue($this->handler->isAdmin());
    }

    public function testIsAdminReturnsFalseForRegularUser(): void
    {
        $this->mockUser('user1', ['users']);
        $this->assertFalse($this->handler->isAdmin());
    }

    public function testIsAdminReturnsFalseWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->assertFalse($this->handler->isAdmin());
    }

    // ── filterReadableProperties ──

    public function testFilterReadablePropertiesReturnsAllForAdmin(): void
    {
        $this->mockUser('admin', ['admin']);
        $schema = $this->createSchema([
            'field1' => ['type' => 'string', 'authorization' => ['read' => [['group' => 'editors']]]],
        ]);
        $object = ['field1' => 'value1', 'field2' => 'value2'];

        $result = $this->handler->filterReadableProperties($schema, $object);
        $this->assertSame($object, $result);
    }

    public function testFilterReadablePropertiesReturnsAllWhenNoPropertyAuth(): void
    {
        $this->mockUser('user1', ['users']);
        $schema = $this->createSchema([
            'field1' => ['type' => 'string'],
            'field2' => ['type' => 'string'],
        ]);
        $object = ['field1' => 'value1', 'field2' => 'value2'];

        $result = $this->handler->filterReadableProperties($schema, $object);
        $this->assertSame($object, $result);
    }

    // ── canReadProperty (no authorization) ──

    public function testCanReadPropertyReturnsTrueWhenNoAuthorizationDefined(): void
    {
        $this->mockUser('user1', ['users']);
        $schema = $this->createSchema([
            'field1' => ['type' => 'string'],
        ]);

        $this->assertTrue($this->handler->canReadProperty($schema, 'field1', []));
    }

    // ── canReadProperty with public group ──

    public function testCanReadPropertyAllowsPublicGroup(): void
    {
        $this->mockUser('user1', ['users']);
        $schema = $this->createSchema([
            'field1' => [
                'type' => 'string',
                'authorization' => ['read' => [['group' => 'public']]],
            ],
        ]);

        $this->assertTrue($this->handler->canReadProperty($schema, 'field1', []));
    }

    // ── canReadProperty with authenticated group ──

    public function testCanReadPropertyAllowsAuthenticatedUserForAuthenticatedGroup(): void
    {
        $this->mockUser('user1', ['users']);
        $schema = $this->createSchema([
            'field1' => [
                'type' => 'string',
                'authorization' => ['read' => [['group' => 'authenticated']]],
            ],
        ]);

        $this->assertTrue($this->handler->canReadProperty($schema, 'field1', []));
    }

    // ── canReadProperty denied ──

    public function testCanReadPropertyDeniedWhenUserNotInGroup(): void
    {
        $this->mockUser('user1', ['users']);
        $schema = $this->createSchema([
            'field1' => [
                'type' => 'string',
                'authorization' => ['read' => [['group' => 'editors']]],
            ],
        ]);

        $this->assertFalse($this->handler->canReadProperty($schema, 'field1', []));
    }

    // ── getUnauthorizedProperties ──

    public function testGetUnauthorizedPropertiesReturnsEmptyForAdmin(): void
    {
        $this->mockUser('admin', ['admin']);
        $schema = $this->createSchema([
            'secret' => [
                'type' => 'string',
                'authorization' => ['update' => [['group' => 'editors']]],
            ],
        ]);

        $result = $this->handler->getUnauthorizedProperties($schema, [], ['secret' => 'new'], false);
        $this->assertSame([], $result);
    }

    public function testGetUnauthorizedPropertiesReturnsEmptyWhenNoPropertyAuth(): void
    {
        $this->mockUser('user1', ['users']);
        $schema = $this->createSchema([
            'field1' => ['type' => 'string'],
        ]);

        $result = $this->handler->getUnauthorizedProperties($schema, [], ['field1' => 'val'], false);
        $this->assertSame([], $result);
    }

    public function testGetUnauthorizedPropertiesSkipsUnchangedFields(): void
    {
        $this->mockUser('user1', ['users']);
        $schema = $this->createSchema([
            'secret' => [
                'type' => 'string',
                'authorization' => ['update' => [['group' => 'editors']]],
            ],
        ]);

        $existing = ['secret' => 'same'];
        $incoming = ['secret' => 'same'];

        $result = $this->handler->getUnauthorizedProperties($schema, $existing, $incoming, false);
        $this->assertSame([], $result);
    }

    public function testGetUnauthorizedPropertiesReturnsUnauthorizedFields(): void
    {
        $this->mockUser('user1', ['users']);
        $schema = $this->createSchema([
            'secret' => [
                'type' => 'string',
                'authorization' => ['update' => [['group' => 'editors']]],
            ],
        ]);

        $result = $this->handler->getUnauthorizedProperties($schema, ['secret' => 'old'], ['secret' => 'new'], false);
        $this->assertSame(['secret'], $result);
    }

    public function testGetUnauthorizedPropertiesSkipsFieldsNotInIncoming(): void
    {
        $this->mockUser('user1', ['users']);
        $schema = $this->createSchema([
            'secret' => [
                'type' => 'string',
                'authorization' => ['update' => [['group' => 'editors']]],
            ],
        ]);

        $result = $this->handler->getUnauthorizedProperties($schema, [], ['other_field' => 'val'], false);
        $this->assertSame([], $result);
    }

    // ── canUpdateProperty ──

    public function testCanUpdatePropertyAllowsAdminGroup(): void
    {
        $this->mockUser('admin', ['admin']);
        $schema = $this->createSchema([
            'field1' => [
                'type' => 'string',
                'authorization' => ['update' => [['group' => 'editors']]],
            ],
        ]);

        $this->assertTrue($this->handler->canUpdateProperty($schema, 'field1', [], false));
    }

    // ── Conditional rule with match ──

    public function testCanReadPropertyWithConditionalMatchPassing(): void
    {
        $this->mockUser('user1', ['users']);
        $schema = $this->createSchema([
            'field1' => [
                'type' => 'string',
                'authorization' => [
                    'read' => [
                        ['group' => 'public', 'match' => ['_organisation' => '$organisation']],
                    ],
                ],
            ],
        ]);

        $this->conditionMatcher->method('objectMatchesConditions')->willReturn(true);

        $this->assertTrue($this->handler->canReadProperty($schema, 'field1', ['_organisation' => 'org1']));
    }

    public function testCanReadPropertyWithConditionalMatchFailing(): void
    {
        $this->mockUser('user1', ['users']);
        $schema = $this->createSchema([
            'field1' => [
                'type' => 'string',
                'authorization' => [
                    'read' => [
                        ['group' => 'public', 'match' => ['_organisation' => '$organisation']],
                    ],
                ],
            ],
        ]);

        $this->conditionMatcher->method('objectMatchesConditions')->willReturn(false);

        $this->assertFalse($this->handler->canReadProperty($schema, 'field1', ['_organisation' => 'wrong']));
    }
}
