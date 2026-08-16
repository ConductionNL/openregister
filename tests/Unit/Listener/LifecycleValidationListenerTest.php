<?php

/**
 * LifecycleValidationListener runtime-enforcement tests.
 *
 * Exercises the saveObject-path enforcement of `x-openregister-lifecycle`
 * via the `ObjectUpdatingEvent` listener:
 *  - an allowed transition passes (no error, propagation not stopped);
 *  - an illegal transition is rejected (`lifecycle-invalid-transition`);
 *  - a `requires` guard that denies rejects (`lifecycle-guard-denied`),
 *    one that allows passes;
 *  - a declarative `authorization` transition rejects an unauthorized
 *    caller (`lifecycle-transition-unauthorized`) and passes an authorized
 *    one;
 *  - a schema WITHOUT a lifecycle declaration is completely unaffected.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-declarative-per-transition-authorization-gate
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Listener\LifecycleValidationListener;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistry;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Listener\LifecycleValidationListener
 */
class LifecycleValidationListenerTest extends TestCase {

	private SchemaMapper&MockObject $schemaMapper;

	private ContainerInterface&MockObject $guardContainer;

	private LifecycleGuardRegistry $guardRegistry;

	private IUserSession&MockObject $userSession;

	private PermissionHandler&MockObject $permissionHandler;

	private LifecycleValidationListener $listener;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$logger = $this->createMock(LoggerInterface::class);

		// LifecycleGuardRegistry is final and cannot be doubled; drive a real
		// instance through a mocked container that returns the test guard.
		$this->guardContainer = $this->createMock(ContainerInterface::class);
		$serverContainer = $this->createMock(IServerContainer::class);
		$serverContainer->method('get')->willThrowException(new \RuntimeException('not found'));
		$this->guardRegistry = new LifecycleGuardRegistry(
			$this->guardContainer,
			$serverContainer,
			$logger
		);

		$this->listener = new LifecycleValidationListener(
			$this->schemaMapper,
			$this->guardRegistry,
			$this->userSession,
			$this->permissionHandler,
			$logger
		);
	}//end setUp()

	/**
	 * Register a guard instance to be resolved for the given tag.
	 *
	 * @param string $tag DI tag the transition `requires`.
	 * @param LifecycleGuardInterface $guard Guard instance to return.
	 *
	 * @return void
	 */
	private function registerGuard(string $tag, LifecycleGuardInterface $guard): void {
		$this->guardContainer->method('get')->with($tag)->willReturn($guard);
	}//end registerGuard()

	/**
	 * Build a schema whose configuration carries a lifecycle annotation.
	 *
	 * @param array<string, mixed>|null $lifecycle The x-openregister-lifecycle block, or null for no declaration.
	 *
	 * @return Schema
	 */
	private function schemaWithLifecycle(?array $lifecycle): Schema {
		$schema = new Schema();
		$schema->setSlug('voorstel');
		if ($lifecycle !== null) {
			$schema->setConfiguration(['x-openregister-lifecycle' => $lifecycle]);
		} else {
			$schema->setConfiguration([]);
		}

		$this->schemaMapper->method('find')->willReturn($schema);
		return $schema;
	}//end schemaWithLifecycle()

	/**
	 * Build an ObjectUpdatingEvent moving the lifecycle field from
	 * $oldState to $newState.
	 *
	 * @param string $field Lifecycle field name.
	 * @param string $oldState Old state value.
	 * @param string $newState New state value.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function event(string $field, string $oldState, string $newState): ObjectUpdatingEvent {
		$old = new ObjectEntity();
		$old->setSchema('voorstel');
		$old->setObject([$field => $oldState]);

		$new = new ObjectEntity();
		$new->setSchema('voorstel');
		$new->setObject([$field => $newState]);

		return new ObjectUpdatingEvent(newObject: $new, oldObject: $old);
	}//end event()

	/**
	 * @return void
	 */
	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end loginAs()

	private const VOORSTEL_LIFECYCLE = [
		'field' => 'lifecycle',
		'initial' => 'concept',
		'transitions' => [
			'indienen' => ['from' => 'concept', 'to' => 'in_parafering'],
			'completeren' => ['from' => 'in_parafering', 'to' => 'geparafeerd'],
		],
	];

	public function testAllowedTransitionPasses(): void {
		$this->schemaWithLifecycle(self::VOORSTEL_LIFECYCLE);
		$event = $this->event('lifecycle', 'concept', 'in_parafering');

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAllowedTransitionPasses()

	public function testIllegalTransitionIsRejected(): void {
		// concept -> geparafeerd is not a declared transition.
		$this->schemaWithLifecycle(self::VOORSTEL_LIFECYCLE);
		$event = $this->event('lifecycle', 'concept', 'geparafeerd');

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('lifecycle-invalid-transition', $event->getErrors()['code']);
	}//end testIllegalTransitionIsRejected()

	public function testGuardDeniedIsRejected(): void {
		$lifecycle = self::VOORSTEL_LIFECYCLE;
		$lifecycle['transitions']['indienen']['requires'] = 'procest.voorstel.submitGuard';
		$this->schemaWithLifecycle($lifecycle);
		$this->loginAs('alice');

		$guard = $this->createMock(LifecycleGuardInterface::class);
		$guard->method('check')->willReturn(GuardResult::deny('Required fields missing.'));
		$this->registerGuard('procest.voorstel.submitGuard', $guard);

		$event = $this->event('lifecycle', 'concept', 'in_parafering');
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('lifecycle-guard-denied', $event->getErrors()['code']);
	}//end testGuardDeniedIsRejected()

	public function testGuardAllowedPasses(): void {
		$lifecycle = self::VOORSTEL_LIFECYCLE;
		$lifecycle['transitions']['indienen']['requires'] = 'procest.voorstel.submitGuard';
		$this->schemaWithLifecycle($lifecycle);
		$this->loginAs('alice');

		$guard = $this->createMock(LifecycleGuardInterface::class);
		$guard->method('check')->willReturn(GuardResult::allow());
		$this->registerGuard('procest.voorstel.submitGuard', $guard);

		$event = $this->event('lifecycle', 'concept', 'in_parafering');
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testGuardAllowedPasses()

	public function testUnauthorizedTransitionIsRejected(): void {
		$lifecycle = self::VOORSTEL_LIFECYCLE;
		$lifecycle['transitions']['completeren']['authorization'] = ['vergunningverleners'];
		$this->schemaWithLifecycle($lifecycle);
		$this->loginAs('bob');

		$this->permissionHandler->method('isTransitionAuthorized')->willReturn(false);

		$event = $this->event('lifecycle', 'in_parafering', 'geparafeerd');
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('lifecycle-transition-unauthorized', $event->getErrors()['code']);
	}//end testUnauthorizedTransitionIsRejected()

	public function testAuthorizedTransitionPasses(): void {
		$lifecycle = self::VOORSTEL_LIFECYCLE;
		$lifecycle['transitions']['completeren']['authorization'] = ['vergunningverleners'];
		$this->schemaWithLifecycle($lifecycle);
		$this->loginAs('carol');

		$this->permissionHandler->method('isTransitionAuthorized')->willReturn(true);

		$event = $this->event('lifecycle', 'in_parafering', 'geparafeerd');
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAuthorizedTransitionPasses()

	public function testSchemaWithoutLifecycleIsUnaffected(): void {
		// No lifecycle declaration → listener must not touch the event,
		// even for a value change. PermissionHandler/guard must never be called.
		$this->schemaWithLifecycle(null);
		$this->permissionHandler->expects($this->never())->method('isTransitionAuthorized');
		$this->guardContainer->expects($this->never())->method('get');

		$event = $this->event('status', 'anything', 'whatever');
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testSchemaWithoutLifecycleIsUnaffected()

	public function testPropertyAliasDrivesEnforcement(): void {
		// Annotation authored with `property` instead of `field` must still enforce.
		$lifecycle = [
			'property' => 'lifecycle',
			'initial' => 'concept',
			'transitions' => ['indienen' => ['from' => 'concept', 'to' => 'in_parafering']],
		];
		$this->schemaWithLifecycle($lifecycle);

		$event = $this->event('lifecycle', 'concept', 'geparafeerd');
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('lifecycle-invalid-transition', $event->getErrors()['code']);
	}//end testPropertyAliasDrivesEnforcement()
}//end class
