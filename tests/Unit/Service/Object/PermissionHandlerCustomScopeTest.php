<?php

/**
 * PermissionHandler custom-scope event dispatch tests.
 *
 * Verifies that non-canonical action verbs route through the
 * `CustomScopeEvaluatingEvent` listener-driven dispatch (rbac-scopes
 * change, decision 2026-05-02 option A) and that canonical actions
 * skip the event entirely.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rbac-scopes/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\CustomScopeEvaluatedEvent;
use OCA\OpenRegister\Event\CustomScopeEvaluatingEvent;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class PermissionHandlerCustomScopeTest extends TestCase
{

    private PermissionHandler $handler;

    private IUserSession&MockObject $userSession;

    private IUserManager&MockObject $userManager;

    private IGroupManager&MockObject $groupManager;

    private SchemaMapper&MockObject $schemaMapper;

    private MagicMapper&MockObject $objectEntityMapper;

    private ConditionMatcher&MockObject $conditionMatcher;

    private LoggerInterface&MockObject $logger;

    private ContainerInterface&MockObject $container;

    private IEventDispatcher&MockObject $eventDispatcher;

    protected function setUp(): void
    {
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->userManager        = $this->createMock(IUserManager::class);
        $this->groupManager       = $this->createMock(IGroupManager::class);
        $this->schemaMapper       = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->conditionMatcher   = $this->createMock(ConditionMatcher::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);

        $this->handler = new PermissionHandler(
            $this->userSession,
            $this->userManager,
            $this->groupManager,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->conditionMatcher,
            $this->logger,
            $this->container,
            $this->eventDispatcher
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('behandelaar-1');
        $user->method('getDisplayName')->willReturn('Behandelaar 1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['behandelaar']);
    }//end setUp()

    public function testListenerVotingAllowGrantsCustomAction(): void
    {
        $schema = $this->createSchema(id: 1, authorization: null);

        $dispatched = [];
        $this->eventDispatcher->method('dispatchTyped')
            ->willReturnCallback(
                function ($event) use (&$dispatched) {
                    $dispatched[] = $event;
                    if ($event instanceof CustomScopeEvaluatingEvent) {
                        $event->allow();
                    }
                }
            );

        $verdict = $this->handler->hasPermission(
            schema: $schema,
            action: 'besluit_nemen',
            userId: 'behandelaar-1'
        );

        $this->assertTrue($verdict, 'listener allow MUST grant the custom action');
        $this->assertCount(2, $dispatched, 'evaluating + evaluated events MUST both fire');
        $this->assertInstanceOf(CustomScopeEvaluatingEvent::class, $dispatched[0]);
        $this->assertInstanceOf(CustomScopeEvaluatedEvent::class, $dispatched[1]);
        $this->assertSame('besluit_nemen', $dispatched[0]->getAction());
        $this->assertSame(['behandelaar'], $dispatched[0]->getUserGroups());
        $this->assertTrue($dispatched[1]->getVerdict());
        $this->assertTrue($dispatched[1]->isFromListener());
    }//end testListenerVotingAllowGrantsCustomAction()

    public function testListenerVotingDenyRejectsCustomAction(): void
    {
        $schema = $this->createSchema(id: 1, authorization: null);

        $dispatched = [];
        $this->eventDispatcher->method('dispatchTyped')
            ->willReturnCallback(
                function ($event) use (&$dispatched) {
                    $dispatched[] = $event;
                    if ($event instanceof CustomScopeEvaluatingEvent) {
                        $event->deny();
                    }
                }
            );

        $verdict = $this->handler->hasPermission(
            schema: $schema,
            action: 'besluit_nemen',
            userId: 'behandelaar-1'
        );

        $this->assertFalse($verdict, 'listener deny MUST reject the custom action');
        $this->assertCount(2, $dispatched, 'paired telemetry event MUST fire on deny too');
        $this->assertFalse($dispatched[1]->getVerdict());
        $this->assertTrue($dispatched[1]->isFromListener());
    }//end testListenerVotingDenyRejectsCustomAction()

    public function testFirstVerdictWinsRegardlessOfRegistrationOrder(): void
    {
        $schema = $this->createSchema(id: 1, authorization: null);

        // Two listeners: first allows, second tries to deny. The
        // first verdict MUST win — `allow()`/`deny()` are no-ops once
        // a verdict has been set.
        $this->eventDispatcher->method('dispatchTyped')
            ->willReturnCallback(
                function ($event) {
                    if ($event instanceof CustomScopeEvaluatingEvent) {
                        $event->allow();
                        $event->deny();
                        // MUST be a no-op.
                    }
                }
            );

        $verdict = $this->handler->hasPermission(
            schema: $schema,
            action: 'besluit_nemen',
            userId: 'behandelaar-1'
        );

        $this->assertTrue(
            $verdict,
            'first verdict (allow) MUST win even if a later listener calls deny()'
        );
    }//end testFirstVerdictWinsRegardlessOfRegistrationOrder()

    public function testNoListenerVoteFallsThroughToStandardChain(): void
    {
        // Schema with an explicit empty rule list for the custom
        // action — the standard chain returns false because the rule
        // list exists but contains no group entries that match the
        // user. This is the canonical "no listener voted, no static
        // rule matched, so deny" outcome.
        $schema = $this->createSchema(
            id: 1,
            authorization: ['besluit_nemen' => []]
        );

        $dispatched = [];
        $this->eventDispatcher->method('dispatchTyped')
            ->willReturnCallback(
                function ($event) use (&$dispatched) {
                    $dispatched[] = $event;
                    // No vote — listener observes but does not decide.
                }
            );

        $verdict = $this->handler->hasPermission(
            schema: $schema,
            action: 'besluit_nemen',
            userId: 'behandelaar-1'
        );

        $this->assertFalse($verdict, 'standard chain MUST decide when no listener votes');
        $this->assertCount(1, $dispatched, 'only the evaluating event fires when no listener votes');
        $this->assertInstanceOf(CustomScopeEvaluatingEvent::class, $dispatched[0]);
    }//end testNoListenerVoteFallsThroughToStandardChain()

    public function testCanonicalActionsSkipEventDispatch(): void
    {
        $schema = $this->createSchema(id: 1, authorization: null);

        $dispatched = [];
        $this->eventDispatcher->method('dispatchTyped')
            ->willReturnCallback(
                function ($event) use (&$dispatched) {
                    $dispatched[] = $event;
                }
            );

        // Each of the canonical 5 actions MUST NOT trip the event dispatch.
        foreach (['read', 'create', 'update', 'delete', 'list'] as $action) {
            $this->handler->clearPermissionCache();
            $this->handler->hasPermission(
                schema: $schema,
                action: $action,
                userId: 'behandelaar-1'
            );
        }

        $this->assertCount(0, $dispatched, 'canonical actions MUST NOT dispatch CustomScopeEvaluatingEvent');
    }//end testCanonicalActionsSkipEventDispatch()

    private function createSchema(int $id, ?array $authorization): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setAuthorization($authorization);
        $schema->setTitle('Test Schema '.$id);
        return $schema;
    }//end createSchema()
}//end class
