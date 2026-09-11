<?php

/**
 * Declarative JSONLogic `condition` enforcement on a lifecycle transition.
 *
 * Exercises the save-path behaviour added by
 * `lifecycle-declarative-conditions`:
 *  - a condition that holds lets the transition through;
 *  - one that does not refuses with `lifecycle-condition-unmet`;
 *  - the refusal carries the author's `message` (string or per-locale map)
 *    verbatim, and the engine's translated fallback when none is declared;
 *  - the order is `authorization` → `condition` → `requires`, and a refused
 *    condition never resolves the guard;
 *  - an expression that cannot be evaluated REFUSES rather than allows;
 *  - a transition declaring no condition behaves exactly as before.
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
 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\LifecycleValidationListener;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistry;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runtime enforcement of a transition's declarative condition.
 */
class LifecycleConditionTest extends TestCase {

	private SchemaMapper&MockObject $schemaMapper;

	private ContainerInterface&MockObject $guardContainer;

	private IUserSession&MockObject $userSession;

	private PermissionHandler&MockObject $permissionHandler;

	private IGroupManager&MockObject $groupManager;

	private IL10N&MockObject $l10n;

	private LoggerInterface&MockObject $logger;

	private LifecycleValidationListener $listener;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroupIds')->willReturn(['handhaving']);

		// 🔴 THE GUARD CONTAINER IS THE ORDERING ASSERTION.
		// A refused condition must never reach `requires`. Rather than trust a
		// call-order expectation, the container THROWS: if resolution is ever
		// attempted on a refused-condition test, the test dies loudly instead
		// of passing with the guard quietly consulted.
		$this->guardContainer = $this->createMock(ContainerInterface::class);
		$this->guardContainer->method('get')->willThrowException(
			new RuntimeException('guard resolved on a path that must not resolve one')
		);
		$serverContainer = $this->createMock(IServerContainer::class);
		$serverContainer->method('get')->willThrowException(new RuntimeException('not found'));

		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('getLanguageCode')->willReturn('en');
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => 'TRANSLATED:' . vsprintf($text, $parameters)
		);

		$this->listener = new LifecycleValidationListener(
			$this->schemaMapper,
			new LifecycleGuardRegistry($this->guardContainer, $serverContainer, $this->logger),
			$this->userSession,
			$this->permissionHandler,
			$this->logger,
			$this->groupManager,
			$this->l10n
		);
	}//end setUp()

	/**
	 * Build a schema carrying the given transition spec for `beslissen`.
	 *
	 * @param array<string, mixed> $transition The `beslissen` transition spec.
	 *
	 * @return void
	 */
	private function schemaWithTransition(array $transition): void {
		$schema = new Schema();
		$schema->setSlug('bezwaar');
		$schema->setConfiguration(
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'initial' => 'in-behandeling',
					'transitions' => ['beslissen' => $transition],
				],
			]
		);
		$this->schemaMapper->method('find')->willReturn($schema);
	}//end schemaWithTransition()

	/**
	 * Build an event moving `status` to `besloten` with the given payload.
	 *
	 * @param array<string, mixed> $newFields Extra fields on the new object.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function event(array $newFields = []): ObjectUpdatingEvent {
		$old = new ObjectEntity();
		$old->setSchema('bezwaar');
		$old->setObject(['status' => 'in-behandeling']);

		$new = new ObjectEntity();
		$new->setSchema('bezwaar');
		$new->setObject(array_merge(['status' => 'besloten'], $newFields));

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

	private const HAS_MOTIVERING = ['!!' => ['var' => 'object.motivering']];

	private const TRANSITION = ['from' => ['in-behandeling'], 'to' => 'besloten'];

	public function testConditionThatHoldsPasses(): void {
		$this->schemaWithTransition(self::TRANSITION + ['condition' => self::HAS_MOTIVERING]);

		$event = $this->event(['motivering' => 'Het bezwaar is ongegrond.']);
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testConditionThatHoldsPasses()

	public function testConditionThatDoesNotHoldIsRefused(): void {
		$this->schemaWithTransition(self::TRANSITION + ['condition' => self::HAS_MOTIVERING]);

		$event = $this->event();
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('lifecycle-condition-unmet', $event->getErrors()['code']);
		$this->assertSame('beslissen', $event->getErrors()['action']);
		$this->assertSame('status', $event->getErrors()['field']);
	}//end testConditionThatDoesNotHoldIsRefused()

	public function testRefusalCarriesTheDeclaredStringMessage(): void {
		$this->schemaWithTransition(
			self::TRANSITION + [
				'condition' => self::HAS_MOTIVERING,
				'message' => 'Een besluit vereist een motivering.',
			]
		);

		$event = $this->event();
		$this->listener->handle($event);

		$this->assertSame('Een besluit vereist een motivering.', $event->getErrors()['message']);
	}//end testRefusalCarriesTheDeclaredStringMessage()

	public function testRefusalResolvesAPerLocaleMessageToTheCallerLanguage(): void {
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('getLanguageCode')->willReturn('nl');
		$this->l10n->method('t')->willReturnArgument(0);
		$this->listener = new LifecycleValidationListener(
			$this->schemaMapper,
			new LifecycleGuardRegistry(
				$this->guardContainer,
				$this->createMock(IServerContainer::class),
				$this->logger
			),
			$this->userSession,
			$this->permissionHandler,
			$this->logger,
			$this->groupManager,
			$this->l10n
		);

		$this->schemaWithTransition(
			self::TRANSITION + [
				'condition' => self::HAS_MOTIVERING,
				'message' => [
					'nl' => 'Een besluit vereist een motivering.',
					'en' => 'A decision requires a motivation.',
				],
			]
		);

		$event = $this->event();
		$this->listener->handle($event);

		$this->assertSame('Een besluit vereist een motivering.', $event->getErrors()['message']);
	}//end testRefusalResolvesAPerLocaleMessageToTheCallerLanguage()

	public function testAMissingLocaleFallsBackThroughDefaultLocale(): void {
		// Caller language is `en`; the map declares only nl and fr, and names
		// fr as its default. The fallback chain must reach fr, not the
		// engine's generic message.
		$this->schemaWithTransition(
			self::TRANSITION + [
				'condition' => self::HAS_MOTIVERING,
				'message' => [
					'nl' => 'Nederlandse tekst.',
					'fr' => 'Texte francais.',
					'defaultLocale' => 'fr',
				],
			]
		);

		$event = $this->event();
		$this->listener->handle($event);

		$this->assertSame('Texte francais.', $event->getErrors()['message']);
	}//end testAMissingLocaleFallsBackThroughDefaultLocale()

	public function testFallbackMessageComesFromTheTranslationLayer(): void {
		$this->schemaWithTransition(self::TRANSITION + ['condition' => self::HAS_MOTIVERING]);

		$event = $this->event();
		$this->listener->handle($event);

		// The engine's own text is translated; an author's message is not.
		// The double prefixes what it translates, so a bare literal would fail
		// this assertion even though the refusal itself looked correct.
		$this->assertStringStartsWith('TRANSLATED:', $event->getErrors()['message']);
		$this->assertStringContainsString('beslissen', $event->getErrors()['message']);
		$this->assertStringContainsString('status', $event->getErrors()['message']);
	}//end testFallbackMessageComesFromTheTranslationLayer()

	public function testAnAuthorMessageIsNeverTranslated(): void {
		$this->schemaWithTransition(
			self::TRANSITION + [
				'condition' => self::HAS_MOTIVERING,
				'message' => 'Een besluit vereist een motivering.',
			]
		);

		$event = $this->event();
		$this->listener->handle($event);

		$this->assertStringNotContainsString('TRANSLATED:', $event->getErrors()['message']);
	}//end testAnAuthorMessageIsNeverTranslated()

	public function testTheRefusalNeverContainsTheExpression(): void {
		$this->schemaWithTransition(self::TRANSITION + ['condition' => self::HAS_MOTIVERING]);

		$event = $this->event();
		$this->listener->handle($event);

		$this->assertStringNotContainsString('var', $event->getErrors()['message']);
		$this->assertArrayNotHasKey('condition', $event->getErrors());
	}//end testTheRefusalNeverContainsTheExpression()

	public function testARefusedConditionNeverResolvesTheGuard(): void {
		// The guard container throws on any resolution. Reaching `requires`
		// after a refused condition would surface as an error, not a pass.
		$this->schemaWithTransition(
			self::TRANSITION + [
				'condition' => self::HAS_MOTIVERING,
				'requires' => 'dossiq.bezwaar.deadlineGuard',
			]
		);
		$this->loginAs('alice');

		$event = $this->event();
		$this->listener->handle($event);

		$this->assertSame('lifecycle-condition-unmet', $event->getErrors()['code']);
	}//end testARefusedConditionNeverResolvesTheGuard()

	public function testAuthorizationIsEvaluatedBeforeTheCondition(): void {
		// Both gates would refuse. The authorization code must win, proving
		// the condition is not evaluated for a caller who may not act at all.
		$this->schemaWithTransition(
			self::TRANSITION + [
				'authorization' => ['handhaving'],
				'condition' => self::HAS_MOTIVERING,
			]
		);
		$this->loginAs('alice');
		$this->permissionHandler->method('isTransitionAuthorized')->willReturn(false);

		$event = $this->event();
		$this->listener->handle($event);

		$this->assertSame('lifecycle-transition-unauthorized', $event->getErrors()['code']);
	}//end testAuthorizationIsEvaluatedBeforeTheCondition()

	public function testAnUnevaluableExpressionRefusesRatherThanAllows(): void {
		// 🔴 THE FAIL-CLOSED RULE. A stored expression naming an operator this
		// instance cannot evaluate must REFUSE. The dangerous direction is the
		// other one: an expression nobody can evaluate silently authorizing
		// every attempt is a gate that reports success while gating nothing.
		$this->schemaWithTransition(
			self::TRANSITION + ['condition' => ['nosuchoperator' => [1, 2]]]
		);

		$event = $this->event(['motivering' => 'Ruim gemotiveerd.']);
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('lifecycle-condition-unmet', $event->getErrors()['code']);
	}//end testAnUnevaluableExpressionRefusesRatherThanAllows()

	public function testAMistypedVarPathRefusesRatherThanAllows(): void {
		// A `var` path that resolves to nothing is a legal evaluation, so this
		// cannot be caught at save time. It must still refuse.
		$this->schemaWithTransition(
			self::TRANSITION + ['condition' => ['!!' => ['var' => 'object.motivatie']]]
		);

		$event = $this->event(['motivering' => 'Ruim gemotiveerd.']);
		$this->listener->handle($event);

		$this->assertSame('lifecycle-condition-unmet', $event->getErrors()['code']);
	}//end testAMistypedVarPathRefusesRatherThanAllows()

	public function testTheConditionReadsPreviousUserAndTransitionKeys(): void {
		// One expression touching all four document keys at once: if any key
		// were missing or misnamed, `isTrue()` would answer false and the
		// transition would be refused.
		$this->loginAs('alice');
		$this->schemaWithTransition(
			self::TRANSITION + [
				'condition' => [
					'and' => [
						['==' => [['var' => 'previous.status'], 'in-behandeling']],
						['==' => [['var' => 'user.uid'], 'alice']],
						['in' => ['handhaving', ['var' => 'user.groups']]],
						['==' => [['var' => 'transition.action'], 'beslissen']],
						['==' => [['var' => 'transition.from'], 'in-behandeling']],
						['==' => [['var' => 'transition.to'], 'besloten']],
						['!!' => ['var' => 'object.motivering']],
					],
				],
			]
		);

		$event = $this->event(['motivering' => 'Ongegrond.']);
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testTheConditionReadsPreviousUserAndTransitionKeys()

	public function testASessionlessCallerGetsAnEmptyUserBlock(): void {
		// `occ` has no session, so every ObjectService call there is anonymous.
		// The keys must still be present and empty rather than absent.
		$this->userSession->method('getUser')->willReturn(null);
		$this->schemaWithTransition(
			self::TRANSITION + [
				'condition' => [
					'and' => [
						['==' => [['var' => 'user.uid'], '']],
						['==' => [['var' => 'user.groups'], []]],
					],
				],
			]
		);

		$event = $this->event();
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testASessionlessCallerGetsAnEmptyUserBlock()

	public function testATransitionWithoutAConditionIsUnaffected(): void {
		$this->schemaWithTransition(self::TRANSITION);

		$event = $this->event();
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testATransitionWithoutAConditionIsUnaffected()

	public function testTheRefusalIsLoggedAtDebugLevel(): void {
		// A mistyped `var` path is indistinguishable from an honest refusal in
		// the response, so the debug line is the only thing that makes one
		// diagnosable. It must name the schema, action and field.
		$this->logger->expects($this->once())
			->method('debug')
			->with(
				$this->stringContains('condition did not hold'),
				$this->callback(
					static fn (array $context): bool => $context['schema'] === 'bezwaar'
						&& $context['action'] === 'beslissen'
						&& $context['field'] === 'status'
				)
			);

		$this->schemaWithTransition(self::TRANSITION + ['condition' => self::HAS_MOTIVERING]);
		$this->listener->handle($this->event());
	}//end testTheRefusalIsLoggedAtDebugLevel()

	public function testAPassingConditionLogsNothing(): void {
		$this->logger->expects($this->never())->method('debug');

		$this->schemaWithTransition(self::TRANSITION + ['condition' => self::HAS_MOTIVERING]);
		$this->listener->handle($this->event(['motivering' => 'Ongegrond.']));
	}//end testAPassingConditionLogsNothing()
}//end class
