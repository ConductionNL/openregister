<?php

/**
 * Every action decision is observable — refusals included.
 *
 * The refusal half is the reason this exists. An event that fires only on
 * success can report what happened but never "who tried", which is the one
 * question an audit rule is usually asked. Both verdicts therefore travel on
 * the same event, and this suite pins the refusal case first.
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
 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ActionEvaluatedEvent;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Coverage metadata is deliberately absent — see the note in
 * GrantableRightsInvalidationListenerTest.
 */
class PermissionHandlerActionEventTest extends TestCase {

	private PermissionHandler $handler;

	private IEventDispatcher&MockObject $eventDispatcher;

	private IUserSession&MockObject $userSession;

	private IUserManager&MockObject $userManager;

	private IGroupManager&MockObject $groupManager;

	/**
	 * Events captured from the dispatcher, in dispatch order.
	 *
	 * @var array<int, object>
	 */
	private array $dispatched = [];

	/**
	 * Wire a handler whose dispatcher records everything it is given.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatched = [];

		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);

		$this->eventDispatcher->method('dispatchTyped')
			->willReturnCallback(
				function ($event): void {
					$this->dispatched[] = $event;
				}
			);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);

		$this->handler = new PermissionHandler(
			$this->userSession,
			$this->userManager,
			$this->groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class),
			$this->eventDispatcher
		);
	}//end setUp()

	/**
	 * Stage a logged-in non-admin user.
	 *
	 * @param array<int, string> $groups The user's groups.
	 *
	 * @return void
	 */
	private function stageUser(array $groups = ['users']): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
	}//end stageUser()

	/**
	 * Build a schema with the given authorization block.
	 *
	 * @param array $authorization The block to apply.
	 *
	 * @return Schema The schema under test.
	 */
	private function schemaWith(array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('invoice');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schemaWith()

	/**
	 * Return the ActionEvaluatedEvents captured so far.
	 *
	 * Filtered by type rather than taking the last dispatch, because other
	 * events share the dispatcher and a positional assertion would silently
	 * start reading the wrong one the day another is added.
	 *
	 * @return array<int, ActionEvaluatedEvent> The captured action events.
	 */
	private function actionEvents(): array {
		return array_values(
			array_filter(
				$this->dispatched,
				static fn ($event): bool => $event instanceof ActionEvaluatedEvent
			)
		);
	}//end actionEvents()

	/**
	 * 🔴 A refusal is observable. This is the scenario the spec names first.
	 *
	 * @return void
	 */
	public function testARefusalIsDispatched(): void {
		$this->stageUser();

		$verdict = $this->handler->hasPermission(
			schema: $this->schemaWith(['read' => ['finance']]),
			action: 'read',
			userId: 'alice'
		);

		$this->assertFalse($verdict, 'precondition: alice must NOT hold this right');

		$events = $this->actionEvents();
		$this->assertCount(1, $events, 'a refused action dispatched no event — "who tried" is unanswerable.');
		$this->assertTrue($events[0]->isRefused(), 'the event reported a refusal as a grant.');
		$this->assertFalse($events[0]->isGranted(), 'isGranted() and isRefused() disagree.');
		$this->assertSame('read', $events[0]->getAction());
		$this->assertSame('alice', $events[0]->getActor());
	}//end testARefusalIsDispatched()

	/**
	 * A permitted action is observable too, carrying the same payload.
	 *
	 * @return void
	 */
	public function testAPermittedActionIsDispatched(): void {
		$this->stageUser(['finance']);

		$verdict = $this->handler->hasPermission(
			schema: $this->schemaWith(['read' => ['finance']]),
			action: 'read',
			userId: 'alice'
		);

		$this->assertTrue($verdict, 'precondition: alice must hold this right');

		$events = $this->actionEvents();
		$this->assertCount(1, $events);
		$this->assertTrue($events[0]->isGranted());
		$this->assertSame('read', $events[0]->getAction());
		$this->assertSame(7, $events[0]->getSchema()->getId());
	}//end testAPermittedActionIsDispatched()

	/**
	 * A DECLARED action carries the same telemetry as a canonical one — the
	 * whole point of declaring it is that the surrounding machinery treats it
	 * as an equal.
	 *
	 * @return void
	 */
	public function testADeclaredActionIsDispatched(): void {
		$this->stageUser(['staff']);

		$this->handler->hasPermission(
			schema: $this->schemaWith(['sendMail' => ['staff']]),
			action: 'sendMail',
			userId: 'alice'
		);

		$events = $this->actionEvents();
		$this->assertCount(1, $events, 'a declared action produced no telemetry.');
		$this->assertSame('sendMail', $events[0]->getAction());
	}//end testADeclaredActionIsDispatched()

	/**
	 * The event carries the object and its register when the decision
	 * concerned one — the identifiers an audit record is useless without.
	 *
	 * @return void
	 */
	public function testTheObjectAndRegisterTravelWithTheEvent(): void {
		$this->stageUser();

		$object = new ObjectEntity();
		$object->setUuid('00000000-0000-0000-0000-000000000000');
		$object->setRegister('4');
		$object->setObject([]);

		$this->handler->hasPermission(
			schema: $this->schemaWith(['read' => ['finance']]),
			action: 'read',
			userId: 'alice',
			object: $object
		);

		$events = $this->actionEvents();
		$this->assertCount(1, $events);
		$this->assertSame('00000000-0000-0000-0000-000000000000', $events[0]->getObjectId());
		$this->assertSame('4', $events[0]->getRegister());
	}//end testTheObjectAndRegisterTravelWithTheEvent()

	/**
	 * ⚠️ Without an object there is no single register: a schema can belong to
	 * several. Null is the honest answer, and a listener must be able to tell
	 * it apart from a real id rather than receive a guess.
	 *
	 * @return void
	 */
	public function testASchemaLevelDecisionReportsNoRegisterRatherThanGuessing(): void {
		$this->stageUser();

		$this->handler->hasPermission(
			schema: $this->schemaWith(['read' => ['finance']]),
			action: 'read',
			userId: 'alice'
		);

		$events = $this->actionEvents();
		$this->assertNull($events[0]->getRegister());
		$this->assertNull($events[0]->getObjectId());
	}//end testASchemaLevelDecisionReportsNoRegisterRatherThanGuessing()

	/**
	 * 🔴 A listener that throws must not change the verdict, in either
	 * direction. Telemetry that can deny access is not telemetry.
	 *
	 * @return void
	 */
	public function testAThrowingListenerCannotChangeTheVerdict(): void {
		$this->stageUser(['finance']);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')
			->willThrowException(new \RuntimeException('listener exploded'));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);

		$handler = new PermissionHandler(
			$this->userSession,
			$this->userManager,
			$this->groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class),
			$dispatcher
		);

		$this->assertTrue(
			$handler->hasPermission(
				schema: $this->schemaWith(['read' => ['finance']]),
				action: 'read',
				userId: 'alice'
			),
			'A crashing audit listener revoked an access the rules had granted.'
		);
	}//end testAThrowingListenerCannotChangeTheVerdict()

	/**
	 * The event marks a DECISION, not an attempt: repeated identical checks in
	 * one request are memoised, so they produce one event.
	 *
	 * Asserted rather than left implicit because a listener counting attempts
	 * would otherwise silently under-count, and a silent under-count in an
	 * audit trail reads exactly like nothing having happened.
	 *
	 * @return void
	 */
	public function testRepeatedIdenticalChecksProduceOneDecisionEvent(): void {
		$this->stageUser();

		$schema = $this->schemaWith(['read' => ['finance']]);
		for ($i = 0; $i < 3; $i++) {
			$this->handler->hasPermission(schema: $schema, action: 'read', userId: 'alice');
		}

		$this->assertCount(
			1,
			$this->actionEvents(),
			'The verdict cache stopped deduplicating, or the event moved off the decision path.'
		);
	}//end testRepeatedIdenticalChecksProduceOneDecisionEvent()

	/**
	 * An RBAC bypass is not a decision this handler made, so it must not be
	 * reported as one. Attributing a system-context grant to the acting user
	 * would put a permission the rules never evaluated into the audit trail.
	 *
	 * @return void
	 */
	public function testABypassIsNotReportedAsADecision(): void {
		$this->stageUser();

		$this->handler->hasPermission(
			schema: $this->schemaWith(['read' => ['finance']]),
			action: 'read',
			userId: 'alice',
			_rbac: false
		);

		$this->assertSame([], $this->actionEvents(), 'An RBAC bypass was recorded as an evaluated decision.');
	}//end testABypassIsNotReportedAsADecision()
}//end class
